<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Backends;

use OCA\FtsSql\Model\AccentFold;
use OCA\FtsSql\Model\CompiledMatch;
use OCA\FtsSql\Model\Occur;
use OCA\FtsSql\Model\SearchQuery;
use OCP\IDBConnection;

/**
 * The MySQL/MariaDB strategy (DESIGN.md, "The engine strategy"): two folded
 * copies of title and content under a composite InnoDB FULLTEXT index,
 * queried in boolean mode, because a FULLTEXT index over utf8mb4_bin folds
 * neither accents nor case on the query side — normaliseText() does both,
 * and the _norm columns hold that form.
 *
 * The one strategy whose DDL is not natively idempotent (ADD FULLTEXT INDEX
 * has no IF NOT EXISTS), hence the information_schema catalogue checks.
 */
final class Mysql implements IBackend {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function name(): string {
		return 'mysql';
	}

	public function isUsable(): bool {
		return true;
	}

	public function textSearchConfigurations(): array {
		// This engine takes no configuration — the setting is inert here —
		// so the one name offered is the one that says so.
		return ['simple'];
	}

	public function artefactStatements(): array {
		$statements = [];
		if (!$this->catalogueHasColumn('title_norm')) {
			$statements[] = 'ALTER TABLE *PREFIX*fts_sql_documents ADD COLUMN title_norm LONGTEXT';
		}
		if (!$this->catalogueHasColumn('content_norm')) {
			$statements[] = 'ALTER TABLE *PREFIX*fts_sql_documents ADD COLUMN content_norm LONGTEXT';
		}
		if (!$this->catalogueHasIndex('fts_sql_documents_fulltext')) {
			$statements[] = 'ALTER TABLE *PREFIX*fts_sql_documents ADD FULLTEXT INDEX fts_sql_documents_fulltext (title_norm, content_norm)';
		}
		// A prefix index over the folded title serves the staleness probe
		// (title_norm IS NULL): a FULLTEXT key cannot, and without this every
		// render of the admin settings page would scan the widest table once.
		if (!$this->catalogueHasIndex('fts_sql_documents_stale')) {
			$statements[] = 'ALTER TABLE *PREFIX*fts_sql_documents ADD INDEX fts_sql_documents_stale (title_norm(8))';
		}
		return $statements;
	}

	public function hasUnindexedDocuments(): bool {
		$result = $this->db->executeQuery('SELECT EXISTS (SELECT 1 FROM *PREFIX*fts_sql_documents WHERE title_norm IS NULL)');
		// Rows written through the app always fill the _norm columns, so NULL
		// means the row predates the artefact.
		return in_array($result->fetchOne(), [true, 1, '1'], true);
	}

	public function contentExpression(): string {
		return 'title_norm = :title, content_norm = :content';
	}

	public function normaliseText(string $text): string {
		return mb_strtolower(AccentFold::fold($text), 'UTF-8');
	}

	public function matchExpression(SearchQuery $query, string $language): CompiledMatch {
		$prefixCandidate = $query->getPrefixCandidate();
		$rendered = [];
		foreach ($query->getTerms() as $term) {
			$text = $this->booleanToken($term->value);
			if ($text === '') {
				continue;
			}
			if ($term->phrase) {
				// A * inside quotes is a literal on this engine: a phrase
				// never takes the prefix operator.
				$body = '"' . $text . '"';
			} else {
				$body = $term === $prefixCandidate ? $text . '*' : $text;
			}
			$rendered[] = match ($term->occur) {
				Occur::Must => '+' . $body,
				Occur::MustNot => '-' . $body,
				Occur::Should => $body,
			};
		}

		return new CompiledMatch(
			'MATCH(title_norm, content_norm) AGAINST(:query IN BOOLEAN MODE)',
			'MATCH(title_norm, content_norm) AGAINST(:query IN BOOLEAN MODE)',
			['query' => implode(' ', $rendered)],
		);
	}

	/**
	 * Strip boolean mode's own operators (+ - > < ( ) ~ * " @) from the
	 * user's text before this app's own are attached around it, then
	 * normalise: a malformed boolean query is ERROR 1064, the same code as
	 * broken SQL.
	 */
	private function booleanToken(string $value): string {
		$stripped = preg_replace('/[+\-><()~*"@]/u', '', $value) ?? '';
		$collapsed = preg_replace('/\s+/u', ' ', $stripped) ?? '';
		return $this->normaliseText(trim($collapsed));
	}

	/**
	 * The catalogue asks about this schema's table only. Two facts force the
	 * shape: information_schema holds one row per column of every index, so a
	 * COUNT over a composite index (the FULLTEXT key names two columns) is 2
	 * and an exactly-one comparison would never hold — and a shared server
	 * hosts same-prefixed Nextclouds in other schemas, which an unqualified
	 * TABLE_NAME would happily count in. EXISTS with TABLE_SCHEMA = DATABASE()
	 * answers the question both facts ask. The *PREFIX* substitution yields
	 * the real table name, so the comparison works without knowing the prefix.
	 */
	private function catalogueHasColumn(string $column): bool {
		$result = $this->db->executeQuery(
			'SELECT EXISTS (SELECT 1 FROM information_schema.COLUMNS'
			. " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '*PREFIX*fts_sql_documents'"
			. " AND COLUMN_NAME = '" . $column . "')",
		);
		return in_array($result->fetchOne(), [true, 1, '1'], true);
	}

	private function catalogueHasIndex(string $index): bool {
		$result = $this->db->executeQuery(
			'SELECT EXISTS (SELECT 1 FROM information_schema.STATISTICS'
			. " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '*PREFIX*fts_sql_documents'"
			. " AND INDEX_NAME = '" . $index . "')",
		);
		return in_array($result->fetchOne(), [true, 1, '1'], true);
	}
}
