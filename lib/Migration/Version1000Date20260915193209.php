<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The three portable tables from DESIGN.md ("Schema"). The engines' search
 * artefacts (the PostgreSQL tsvector + GIN index, the MySQL/MariaDB FULLTEXT
 * key, the SQLite FTS5 virtual table and its triggers) are deliberately NOT
 * here: none is expressible through ISchemaWrapper, and they are created by
 * the CreateSearchArtefact repair step instead.
 *
 * `content` carries no length on purpose: a length would make Doctrine emit
 * MySQL TEXT (65,535 bytes) instead of LONGTEXT, below the 2 MiB content
 * budget. Each table is guarded so re-running the step is harmless.
 *
 * `extraction_cause` (DESIGN.md, "Open issue: representing partial
 * extraction", decision (c)): what was already reported per document through
 * addError() becomes countable, so the admin card can show how many documents
 * are indexed with each flag. Nullable by design — NULL is every document
 * whose extraction completed (or whose failure is a provider bug, which is a
 * severity, not a cause) — and wide enough for the longest cause token
 * ('parser gave up').
 */
class Version1000Date20260915193209 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$changed = false;

		if (!$schema->hasTable('fts_sql_documents')) {
			$table = $schema->createTable('fts_sql_documents');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('provider_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('document_id', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('owner', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
			$table->addColumn('title', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('content', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('link', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('source', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
			$table->addColumn('modified', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('hash', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
			$table->addColumn('extraction_cause', Types::STRING, [
				'notnull' => false,
				'length' => 16,
			]);
			$table->setPrimaryKey(['id']);
			// One row per (provider, document): the replace-on-write strategy
			// from DESIGN.md deletes by this pair before inserting.
			$table->addUniqueIndex(['provider_id', 'document_id'], 'fts_sql_doc_provider_document');
			$changed = true;
		}

		if (!$schema->hasTable('fts_sql_access')) {
			$table = $schema->createTable('fts_sql_access');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('doc_id', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->addColumn('token', Types::STRING, [
				'notnull' => true,
				'length' => 96,
			]);
			$table->setPrimaryKey(['id']);
			// The access filter is the EXISTS over (token, doc_id) on every
			// search; the bare doc_id index serves the delete-on-replace.
			$table->addIndex(['token', 'doc_id'], 'fts_sql_access_token_doc');
			$table->addIndex(['doc_id'], 'fts_sql_access_doc');
			$changed = true;
		}

		if (!$schema->hasTable('fts_sql_tags')) {
			$table = $schema->createTable('fts_sql_tags');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('doc_id', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->addColumn('kind', Types::STRING, [
				'notnull' => true,
				'length' => 16,
			]);
			$table->addColumn('value', Types::STRING, [
				'notnull' => true,
				'length' => 128,
			]);
			$table->setPrimaryKey(['id']);
			// Ticked source checkboxes arrive as meta tags: kind + value
			// disjunction over doc_id.
			$table->addIndex(['kind', 'value', 'doc_id'], 'fts_sql_tags_kind_value_doc');
			$table->addIndex(['doc_id'], 'fts_sql_tags_doc');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
