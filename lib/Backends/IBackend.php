<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Backends;

use OCA\FtsSql\Model\CompiledMatch;
use OCA\FtsSql\Model\SearchQuery;

/**
 * The engine strategy, one class per value of IDBConnection::getDatabaseProvider()
 * (DESIGN.md, "The engine strategy"). Each strategy owns exactly six things:
 * the capability probe, the artefact DDL, "are there rows the artefact cannot
 * find", the write expression, PHP-side normalisation, and match compilation.
 *
 * All but two of those return strings and execute nothing; the two named
 * exceptions that read the engine are the capability probe (SQLite: is FTS5
 * compiled in) and MySQL's catalogue check before its non-idempotent
 * ADD FULLTEXT INDEX. Everything else a strategy wants to know, it is told.
 *
 * Raw SQL uses the *PREFIX* placeholder: IDBConnection::executeQuery and
 * executeStatement substitute the configured table prefix before the engine
 * sees the statement.
 */
interface IBackend {
	/**
	 * The IDBConnection::getDatabaseProvider() value this strategy serves:
	 * postgres, mysql or sqlite.
	 */
	public function name(): string;

	/**
	 * Whether this engine can serve full text search here. SQLite without
	 * FTS5 compiled in cannot, and answers false.
	 */
	public function isUsable(): bool;

	/**
	 * The search artefact's DDL, idempotent: install repair steps run on
	 * every occ app:enable. Ends in an FTS5 rebuild on SQLite, which refills
	 * the index from the documents table.
	 *
	 * @return list<string>
	 */
	public function artefactStatements(): array;

	/**
	 * Are there rows the artefact cannot find — the state creating an
	 * artefact over existing rows leaves behind. Deliberately not "is the
	 * artefact empty": an empty index over an empty table is a healthy
	 * fresh install.
	 */
	public function hasUnindexedDocuments(): bool;

	/**
	 * The SET assignments that fill the artefact from :title and :content,
	 * with the text search configuration as :cfg; '' where the engine
	 * maintains the artefact itself (SQLite's triggers do).
	 */
	public function contentExpression(): string;

	/**
	 * PHP-side normalisation applied to what feeds the artefact and to the
	 * query, never to the stored text an excerpt is cut from.
	 */
	public function normaliseText(string $text): string;

	/**
	 * The predicate, ranking expression, any join and bound parameters for
	 * one parsed query. The query is non-empty: the empty search box never
	 * reaches a strategy (SearchMappingService compiles no predicate for it).
	 */
	public function matchExpression(SearchQuery $query, string $language): CompiledMatch;
}
