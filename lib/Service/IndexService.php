<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Service;

use OCA\FtsSql\Backends\IBackend;
use OCA\FtsSql\Model\IndexRow;
use OCP\AppFramework\Db\TTransactional;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;

/**
 * Writes an IndexRow, impure: every statement that executes against an engine
 * on the write path lives here. A write is one transaction that REPLACES the
 * document — delete any row with the same (provider_id, document_id)
 * together with its tokens and tags, insert the new row, fill the artefact,
 * insert tokens and tags. A replace rather than an upsert, because it is one
 * shape on three engines and nothing outside the app references id; and a
 * replace rather than a diff, because the common case is an ACL change
 * (DESIGN.md, "Schema").
 *
 * The PostgreSQL safety net: a tsvector has a 1,048,575-byte ceiling
 * (SQLSTATE 54000) that adversarial all-distinct-token text hits at ~637 KB,
 * below the 2 MiB budget. Only on that SQLSTATE the transaction is rolled
 * back, the content halved (multibyte-safe) and the whole sequence retried,
 * up to four times — four halvings take 2 MiB below 132 KB. Any other
 * failure is rethrown at once: retrying a syntax error or a lost connection
 * would only make a fast failure slow.
 */
final class IndexService {
	use TTransactional;

	private const MAX_HALVINGS = 4;

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function write(IndexRow $row, IBackend $backend, string $language): void {
		$content = $row->content;
		$halvings = 0;

		while (true) {
			try {
				$this->atomic(fn () => $this->replace($row, $content, $backend, $language), $this->db);
				return;
			} catch (DbException $e) {
				if (!self::isTsvectorTooLarge($e)
					|| $content === null || $content === ''
					|| $halvings >= self::MAX_HALVINGS) {
					throw $e;
				}
				$halvings++;
				$content = mb_strcut($content, 0, intdiv(strlen($content), 2), 'UTF-8');
			}
		}
	}

	public function delete(string $providerId, string $documentId): void {
		$this->atomic(function () use ($providerId, $documentId): void {
			$this->deleteArtifacts($providerId, $documentId);
		}, $this->db);
	}

	public function deleteProvider(string $providerId): void {
		$this->atomic(function () use ($providerId): void {
			$this->db->executeStatement(
				'DELETE FROM *PREFIX*fts_sql_access WHERE doc_id IN (SELECT id FROM *PREFIX*fts_sql_documents WHERE provider_id = :provider)',
				['provider' => $providerId],
			);
			$this->db->executeStatement(
				'DELETE FROM *PREFIX*fts_sql_tags WHERE doc_id IN (SELECT id FROM *PREFIX*fts_sql_documents WHERE provider_id = :provider)',
				['provider' => $providerId],
			);
			$this->db->executeStatement(
				'DELETE FROM *PREFIX*fts_sql_documents WHERE provider_id = :provider',
				['provider' => $providerId],
			);
		}, $this->db);
	}

	public function deleteAll(): void {
		$this->atomic(function (): void {
			$this->db->executeStatement('DELETE FROM *PREFIX*fts_sql_access');
			$this->db->executeStatement('DELETE FROM *PREFIX*fts_sql_tags');
			$this->db->executeStatement('DELETE FROM *PREFIX*fts_sql_documents');
		}, $this->db);
	}

	/**
	 * @return array<string, mixed>|null the stored row, or null when the pair is unknown
	 */
	public function findRow(string $providerId, string $documentId): ?array {
		$row = $this->db->executeQuery(
			'SELECT * FROM *PREFIX*fts_sql_documents WHERE provider_id = :provider AND document_id = :document',
			['provider' => $providerId, 'document' => $documentId],
		)->fetch();
		return $row === false ? null : $row;
	}

	/**
	 * @return list<string>
	 */
	public function findTokens(int $docId): array {
		$result = $this->db->executeQuery(
			'SELECT token FROM *PREFIX*fts_sql_access WHERE doc_id = :doc',
			// The OCP stub documents every bound parameter as a string.
			['doc' => (string)$docId],
		);
		$tokens = [];
		while (($token = $result->fetchOne()) !== false) {
			$tokens[] = (string)$token;
		}
		return $tokens;
	}

	private function replace(IndexRow $row, ?string $content, IBackend $backend, string $language): void {
		$this->deleteArtifacts($row->providerId, $row->documentId);

		$this->db->executeStatement(
			'INSERT INTO *PREFIX*fts_sql_documents'
			. ' (provider_id, document_id, owner, title, content, link, source, modified, hash)'
			. ' VALUES (:provider_id, :document_id, :owner, :title, :content, :link, :source, :modified, :hash)',
			[
				'provider_id' => $row->providerId,
				'document_id' => $row->documentId,
				'owner' => $row->owner,
				'title' => $row->title,
				'content' => $content,
				'link' => $row->link,
				'source' => $row->source,
				'modified' => $row->modified,
				'hash' => $row->hash,
			],
		);

		// Race-free inside the transaction: the unique (provider, document)
		// pair was just inserted by us.
		$id = (int)$this->db->executeQuery(
			'SELECT id FROM *PREFIX*fts_sql_documents WHERE provider_id = :provider AND document_id = :document',
			['provider' => $row->providerId, 'document' => $row->documentId],
		)->fetchOne();

		// The artefact fill: the strategy's SET assignments over :title,
		// :content and :cfg. '' means the engine maintains the artefact
		// itself (SQLite's triggers).
		$expression = $backend->contentExpression();
		if ($expression !== '') {
			$this->db->executeStatement(
				'UPDATE *PREFIX*fts_sql_documents SET ' . $expression . ' WHERE id = :id',
				[
					'cfg' => $language,
					'title' => $backend->normaliseText($row->title ?? ''),
					'content' => $backend->normaliseText($content ?? ''),
					'id' => $id,
				],
			);
		}

		foreach ($row->tokens as $token) {
			$this->db->executeStatement(
				'INSERT INTO *PREFIX*fts_sql_access (doc_id, token) VALUES (:doc, :token)',
				['doc' => $id, 'token' => $token],
			);
		}

		foreach ($row->tags as $tag) {
			$this->db->executeStatement(
				'INSERT INTO *PREFIX*fts_sql_tags (doc_id, kind, value) VALUES (:doc, :kind, :value)',
				['doc' => $id, 'kind' => $tag['kind'], 'value' => $tag['value']],
			);
		}
	}

	private function deleteArtifacts(string $providerId, string $documentId): void {
		$this->db->executeStatement(
			'DELETE FROM *PREFIX*fts_sql_access WHERE doc_id IN'
			. ' (SELECT id FROM *PREFIX*fts_sql_documents WHERE provider_id = :provider AND document_id = :document)',
			['provider' => $providerId, 'document' => $documentId],
		);
		$this->db->executeStatement(
			'DELETE FROM *PREFIX*fts_sql_tags WHERE doc_id IN'
			. ' (SELECT id FROM *PREFIX*fts_sql_documents WHERE provider_id = :provider AND document_id = :document)',
			['provider' => $providerId, 'document' => $documentId],
		);
		$this->db->executeStatement(
			'DELETE FROM *PREFIX*fts_sql_documents WHERE provider_id = :provider AND document_id = :document',
			['provider' => $providerId, 'document' => $documentId],
		);
	}

	/**
	 * SQLSTATE 54000 — string_data_right_truncation as PostgreSQL reports a
	 * tsvector past its 1,048,575-byte ceiling. The SQLSTATE travels on the
	 * wrapped driver exception; OCP\DB\Exception's own code is not it.
	 */
	private static function isTsvectorTooLarge(DbException $e): bool {
		$previous = $e->getPrevious();
		return $previous !== null && (string)$previous->getCode() === '54000';
	}
}
