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
 * The PostgreSQL strategy (DESIGN.md, "The engine strategy"): a content_tsv
 * tsvector column filled by contentExpression() and served through a GIN
 * index with fastupdate off — with it on, index size depends on when the
 * pending list was last flushed.
 *
 * Accent folding happens in PHP because to_tsvector does not fold accents;
 * case is left alone here because to_tsvector lowercases itself.
 */
final class Postgres implements IBackend {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function name(): string {
		return 'postgres';
	}

	public function isUsable(): bool {
		return true;
	}

	/**
	 * pg_catalog only, never the whole catalogue: configurations created by
	 * hand or by an extension live in other namespaces, and what is offered
	 * is exactly what the server itself ships (DESIGN.md, Security).
	 *
	 * @return list<string>
	 */
	public function textSearchConfigurations(): array {
		$result = $this->db->executeQuery(
			'SELECT cfgname FROM pg_catalog.pg_ts_config'
			. " WHERE cfgnamespace = 'pg_catalog'::regnamespace"
			// simple first: it is the default, and the one configuration the
			// bootstrap catalog itself ships rather than the initdb scripts.
			. " ORDER BY (cfgname = 'simple') DESC, cfgname",
		);
		return array_map(static fn (mixed $cfg): string => (string)$cfg, $result->fetchFirstColumn());
	}

	public function artefactStatements(): array {
		return [
			'ALTER TABLE *PREFIX*fts_sql_documents ADD COLUMN IF NOT EXISTS content_tsv tsvector',
			'CREATE INDEX IF NOT EXISTS fts_sql_documents_tsv ON *PREFIX*fts_sql_documents USING GIN (content_tsv) WITH (fastupdate = off)',
			// A GIN index cannot serve `content_tsv IS NULL`, the staleness
			// probe the admin settings page runs per render; this partial one
			// holds only the stale rows, so the probe stays proportional to
			// what is stale, never to the table.
			'CREATE INDEX IF NOT EXISTS fts_sql_documents_unindexed ON *PREFIX*fts_sql_documents (id) WHERE content_tsv IS NULL',
		];
	}

	public function hasUnindexedDocuments(): bool {
		$result = $this->db->executeQuery('SELECT EXISTS (SELECT 1 FROM *PREFIX*fts_sql_documents WHERE content_tsv IS NULL)');
		// The COALESCE in contentExpression() guarantees a row written through
		// this app always has a tsvector, so NULL means the row predates the
		// artefact. fetchOne() comes back as bool, 't'/'f' or 1/0 depending
		// on the driver.
		return in_array($result->fetchOne(), [true, 't', 1, '1'], true);
	}

	public function contentExpression(): string {
		return 'content_tsv = setweight(to_tsvector(:cfg::regconfig, COALESCE(:title, \'\')), \'A\') || setweight(to_tsvector(:cfg::regconfig, COALESCE(:content, \'\')), \'B\')';
	}

	public function normaliseText(string $text): string {
		return AccentFold::fold($text);
	}

	public function matchExpression(SearchQuery $query, string $language): CompiledMatch {
		$prefixCandidate = $query->getPrefixCandidate();
		$should = [];
		$must = [];
		$mustNot = [];
		foreach ($query->getTerms() as $term) {
			$lexemes = $this->lexemes($term->value);
			if ($lexemes === []) {
				continue;
			}
			$operand = implode(' <-> ', $lexemes);
			if ($term === $prefixCandidate) {
				$operand .= ':*';
			}
			match ($term->occur) {
				Occur::Should => $should[] = $operand,
				// A multi-word operand is parenthesised: `&` and `!` bind
				// tighter than `|`, so a bare phrase would let what follows
				// capture its words.
				Occur::Must => $must[] = count($lexemes) === 1 ? $operand : '(' . $operand . ')',
				Occur::MustNot => $mustNot[] = '!(' . $operand . ')',
			};
		}

		$operands = [];
		if ($should !== []) {
			// The optional terms form one parenthesised OR operand, placed
			// first, so nothing required or excluded is applied to only the
			// first of them.
			$operands[] = '(' . implode(' | ', $should) . ')';
		}
		$operands = [...$operands, ...$must, ...$mustNot];

		return new CompiledMatch(
			'content_tsv @@ to_tsquery(:cfg::regconfig, :query)',
			'ts_rank_cd(content_tsv, to_tsquery(:cfg::regconfig, :query))',
			['cfg' => $language, 'query' => implode(' & ', $operands)],
		);
	}

	/**
	 * The injection defence (DESIGN.md, Security): split the folded value on
	 * every character to_tsquery would read as an operator, discard the
	 * empties, and join what survives with <-> — what to_tsquery itself does
	 * to a token it splits.
	 *
	 * @return list<string>
	 */
	private function lexemes(string $value): array {
		$lexemes = preg_split('/[\s&|!()<>:*\'\\\\]/', $this->normaliseText($value), -1, PREG_SPLIT_NO_EMPTY);
		return $lexemes === false ? [] : $lexemes;
	}
}
