<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Backends;

use OCA\FtsSql\Model\CompiledMatch;
use OCA\FtsSql\Model\Occur;
use OCA\FtsSql\Model\SearchQuery;
use OCP\IDBConnection;

/**
 * The SQLite strategy (DESIGN.md, "The engine strategy"): an FTS5 virtual
 * table with external content over the documents table, maintained by
 * insert/update/delete triggers, with `unicode61 remove_diacritics 2` doing
 * the folding inside the tokeniser — which is why normaliseText() is the
 * identity and contentExpression() is empty.
 */
final class Sqlite implements IBackend {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function name(): string {
		return 'sqlite';
	}

	public function isUsable(): bool {
		// FTS5 is a compile-time option of whatever pdo_sqlite the host
		// provides: it cannot be declared as a dependency, only probed
		// through the connection.
		try {
			$this->db->executeStatement('CREATE VIRTUAL TABLE temp.fts_sql_probe USING fts5(x)');
			$this->db->executeStatement('DROP TABLE temp.fts_sql_probe');
		} catch (\Throwable) {
			return false;
		}
		return true;
	}

	public function textSearchConfigurations(): array {
		// This engine takes no configuration — the tokeniser is the
		// configuration — so the one name offered is the one that says so.
		return ['simple'];
	}

	public function artefactStatements(): array {
		return [
			'CREATE VIRTUAL TABLE IF NOT EXISTS *PREFIX*fts_sql_fts USING fts5(title, content, content=\'*PREFIX*fts_sql_documents\', content_rowid=\'id\', tokenize="unicode61 remove_diacritics 2")',
			'CREATE TRIGGER IF NOT EXISTS *PREFIX*fts_sql_ai AFTER INSERT ON *PREFIX*fts_sql_documents BEGIN INSERT INTO *PREFIX*fts_sql_fts(rowid, title, content) VALUES (new.id, new.title, new.content); END',
			'CREATE TRIGGER IF NOT EXISTS *PREFIX*fts_sql_ad AFTER DELETE ON *PREFIX*fts_sql_documents BEGIN INSERT INTO *PREFIX*fts_sql_fts(*PREFIX*fts_sql_fts, rowid, title, content) VALUES (\'delete\', old.id, old.title, old.content); END',
			'CREATE TRIGGER IF NOT EXISTS *PREFIX*fts_sql_au AFTER UPDATE ON *PREFIX*fts_sql_documents BEGIN INSERT INTO *PREFIX*fts_sql_fts(*PREFIX*fts_sql_fts, rowid, title, content) VALUES (\'delete\', old.id, old.title, old.content); INSERT INTO *PREFIX*fts_sql_fts(rowid, title, content) VALUES (new.id, new.title, new.content); END',
			'INSERT INTO *PREFIX*fts_sql_fts(*PREFIX*fts_sql_fts) VALUES (\'rebuild\')',
		];
	}

	public function hasUnindexedDocuments(): bool {
		// The closing rebuild refills the index from the documents table
		// (DESIGN.md, Scenario 3): creating the artefact cannot leave rows
		// behind.
		return false;
	}

	public function contentExpression(): string {
		// The triggers maintain the artefact.
		return '';
	}

	public function normaliseText(string $text): string {
		// The tokeniser folds: unicode61 remove_diacritics 2.
		return $text;
	}

	public function matchExpression(SearchQuery $query, string $language): CompiledMatch {
		$prefixCandidate = $query->getPrefixCandidate();
		$should = [];
		$must = [];
		$mustNot = [];
		foreach ($query->getTerms() as $term) {
			$literal = $this->literal($term->value);
			if ($term === $prefixCandidate) {
				// Directly after the closing quote: the prefix operator
				// belongs to the phrase query, not to its last character.
				$literal .= '*';
			}
			match ($term->occur) {
				Occur::Should => $should[] = $literal,
				Occur::Must => $must[] = $literal,
				Occur::MustNot => $mustNot[] = $literal,
			};
		}

		$positive = [];
		if ($should !== []) {
			$positive[] = '(' . implode(' OR ', $should) . ')';
		}
		foreach ($must as $literal) {
			$positive[] = $literal;
		}
		if ($positive === []) {
			// Nothing but exclusions: the empty literal matches no row, so
			// the NOTs subtract from a base that cannot hit anything.
			$positive[] = '""';
		}

		$composed = implode(' AND ', $positive);
		foreach ($mustNot as $literal) {
			// NOT is binary in FTS5: appended after the positive expression.
			$composed .= ' NOT ' . $literal;
		}

		return new CompiledMatch(
			'*PREFIX*fts_sql_fts MATCH :query',
			// bm25() is negative and more-negative-is-better; negated so the
			// shared ORDER BY score DESC works.
			'-bm25(*PREFIX*fts_sql_fts)',
			['query' => $composed],
			// Not optional and the table cannot be aliased: bm25() resolves
			// only against a table already in the query, by name — ranked
			// through a subquery instead, SQLite re-runs the whole match once
			// per candidate row.
			'JOIN *PREFIX*fts_sql_fts ON *PREFIX*fts_sql_fts.rowid = d.id',
		);
	}

	/**
	 * Every token goes in as an FTS5 string literal — double quotes around
	 * it, inner double quotes doubled — which is what stops `C++`,
	 * `report.pdf` or `AND` being read as syntax.
	 */
	private function literal(string $value): string {
		return '"' . str_replace('"', '""', $this->normaliseText($value)) . '"';
	}
}
