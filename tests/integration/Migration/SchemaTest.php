<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Integration\Migration;

use OC\DB\Connection;
use OC\DB\SchemaWrapper;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;
use Test\TestCase;

/**
 * Proves the migration and the design's schema agree, on the real database:
 * the three portable tables, the indexes the access filter and the replace
 * strategy depend on, and the content column with no length — a length would
 * make Doctrine emit MySQL TEXT (65,535 bytes) instead of LONGTEXT, below the
 * 2 MiB content budget.
 *
 * SchemaWrapper over the internal connection is how the server's own repair
 * steps introspect the schema (see OC\Repair\Owncloud\MigratePropertiesTable):
 * it applies the table prefix and the engine's namespace handling, which the
 * raw Doctrine schema from IDBConnection::createSchema() does not.
 */
#[Group('DB')]
class SchemaTest extends TestCase {
	private SchemaWrapper $schema;

	protected function setUp(): void {
		parent::setUp();
		$this->schema = new SchemaWrapper(Server::get(Connection::class));
	}

	public function testDocumentsTable(): void {
		$table = $this->schema->getTable('fts_sql_documents');

		$unique = $table->getIndex('fts_sql_doc_provider_document');
		$this->assertTrue($unique->isUnique());
		$this->assertSame(['provider_id', 'document_id'], $unique->getColumns());

		// null on PostgreSQL and SQLite; MySQL introspection reports LONGTEXT
		// as 0. Both mean "no length declared": a length would introspect as
		// 65,535 — the TEXT regression that would sit below the budget.
		$this->assertContains($table->getColumn('content')->getLength(), [null, 0]);
	}

	public function testAccessTable(): void {
		$table = $this->schema->getTable('fts_sql_access');

		$this->assertSame(
			['token', 'doc_id'],
			$table->getIndex('fts_sql_access_token_doc')->getColumns(),
		);
		$this->assertSame(
			['doc_id'],
			$table->getIndex('fts_sql_access_doc')->getColumns(),
		);
	}

	public function testTagsTable(): void {
		$table = $this->schema->getTable('fts_sql_tags');

		$this->assertSame(
			['kind', 'value', 'doc_id'],
			$table->getIndex('fts_sql_tags_kind_value_doc')->getColumns(),
		);
		$this->assertSame(
			['doc_id'],
			$table->getIndex('fts_sql_tags_doc')->getColumns(),
		);
	}
}
