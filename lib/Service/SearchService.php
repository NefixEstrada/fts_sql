<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Service;

use OCA\FtsSql\Backends\IBackend;
use OCA\FtsSql\Model\CompiledSearch;
use OCA\FtsSql\Model\SearchQuery;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Runs a CompiledSearch, impure: paging, counting, and the health probe.
 * Array parameters bind through the public IQueryBuilder::PARAM_STR_ARRAY
 * type, which is what an IN list needs on every engine.
 */
final class SearchService {
	private const EXCERPT_LENGTH = 200;
	private const EXCERPT_BEFORE = 40;

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @return array{rows: list<array<string, mixed>>, total: int}
	 */
	public function run(CompiledSearch $search): array {
		$types = [];
		foreach ($search->arrayParams as $name) {
			$types[$name] = IQueryBuilder::PARAM_STR_ARRAY;
		}
		// LIMIT and OFFSET are bound as integers: as strings PostgreSQL
		// coerces them, but MySQL and MariaDB answer SQLSTATE 42000 with
		// LIMIT '10'.
		$types['limit'] = IQueryBuilder::PARAM_INT;
		$types['offset'] = IQueryBuilder::PARAM_INT;

		$rows = $this->db->executeQuery($search->pageSql, $search->parameters, $types)->fetchAll();
		$total = (int)$this->db->executeQuery($search->countSql, $search->parameters, $types)->fetchOne();

		return ['rows' => $rows, 'total' => $total];
	}

	/**
	 * The health probe (DESIGN.md, "Worked example"): compile the platform's
	 * own predicate and make the engine run it, for a word no document
	 * holds. A working artefact answers "no rows"; a missing column, index
	 * or table raises — which is the point: a broken artefact must not look
	 * like an empty index.
	 */
	public function probe(IBackend $backend, string $language): bool {
		$match = $backend->matchExpression(
			SearchQuery::parse('fts_sql_health_probe_zzqjkvyw'),
			$language,
		);
		$sql = 'SELECT 1 FROM *PREFIX*fts_sql_documents d ' . $match->join
			. ' WHERE ' . $match->predicate . ' LIMIT 1';

		try {
			$this->db->executeQuery($sql, $match->parameters)->fetchOne();
			return true;
		} catch (DbException) {
			return false;
		}
	}

	/**
	 * 200 characters of the stored content, starting 40 characters before the
	 * first case-insensitive occurrence of the search text with the operators
	 * stripped, or from the start when there is none. Cut in PHP identically
	 * on all engines: MySQL has no native snippet, so there are not going to
	 * be three notions of one.
	 */
	public static function excerpt(string $content, string $search): string {
		if ($content === '') {
			return '';
		}

		$needle = trim((string)preg_replace('/[+\-"]/', ' ', $search));
		$position = $needle === '' ? false : mb_stripos($content, $needle);
		$start = $position === false ? 0 : max(0, $position - self::EXCERPT_BEFORE);

		return mb_substr($content, $start, self::EXCERPT_LENGTH);
	}
}
