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
 * Schema migration. The class name encodes the app version it belongs to and a
 * timestamp: Version<version-without-dots>Date<YmdHis>. Nextcloud runs pending
 * migrations when the app is enabled or upgraded, and remembers each executed step in
 * oc_migrations, so editing an executed step does nothing: add a new file instead.
 *
 * Migrations must be idempotent: always guard with hasTable()/hasColumn(), because
 * the step can run again on a database that already has the change.
 */
class Version1000Date20260915193209 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('fts_sql_items')) {
			return null;
		}

		$table = $schema->createTable('fts_sql_items');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
		]);
		$table->addColumn('user_id', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('title', Types::STRING, [
			'notnull' => true,
			'length' => 255,
		]);
		$table->addColumn('created_at', Types::BIGINT, [
			'notnull' => true,
			'default' => 0,
		]);

		$table->setPrimaryKey(['id']);
		// Index the column you filter on. Without it every list query is a full scan.
		$table->addIndex(['user_id'], 'fts_sql_items_uid');

		return $schema;
	}
}
