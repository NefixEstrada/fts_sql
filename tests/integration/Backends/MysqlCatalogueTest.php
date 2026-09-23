<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Integration\Backends;

use OCA\FtsSql\Backends\Mysql;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Server;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Test\TestCase;

/**
 * The information_schema catalogue the MySQL strategy stands in for the
 * IF NOT EXISTS its DDL lacks, against the real server. Two regressions
 * assert the same way — over an existing artefact, the strategy stays
 * quiet — and only the second needs privileges the installed connection
 * does not have:
 *
 * STATISTICS holds one row per index COLUMN, so the composite FULLTEXT key
 * counted 2 and the old exactly-once check read it as absent: every
 * occ app:enable after the first retried ADD FULLTEXT INDEX and died on
 * the duplicate name. Provable with the instance's own schema — the
 * instance's own user may read information_schema.
 *
 * A shared MySQL hosting two Nextclouds with the same table prefix doubles
 * an unqualified TABLE_NAME count, failing the check or satisfying it from
 * the other schema alone. Reproducing it means creating a second database,
 * which only root may — FTS_SQL_MYSQL_ROOT_DSN (mysql://user:pass@host:port)
 * supplies that connection when the runner has one; the CI matrix and
 * tests/integration-env.sh export it. Without it the half skips, loudly
 * enough to be noticed, rather than passing as if covered.
 */
#[Group('DB')]
class MysqlCatalogueTest extends TestCase {
	private const SHADOW = 'fts_sql_shadow_catalogue_test';

	public function testTheCatalogueIgnoresASamePrefixedTableInAnotherSchema(): void {
		$db = Server::get(IDBConnection::class);
		if ($db->getDatabaseProvider() !== IDBConnection::PLATFORM_MYSQL) {
			$this->markTestSkipped('the information_schema catalogue is the MySQL strategy\'s');
		}

		$backend = new Mysql($db);
		$this->assertSame(
			[],
			$backend->artefactStatements(),
			'the artefact this instance enabled already exists, composite FULLTEXT index and all; the catalogue must stay quiet over it',
		);

		$rootDsn = getenv('FTS_SQL_MYSQL_ROOT_DSN');
		if ($rootDsn === false || $rootDsn === '') {
			self::markTestSkipped(
				'the shared-server half needs CREATE DATABASE: set FTS_SQL_MYSQL_ROOT_DSN'
				. ' (mysql://user:pass@host:port) — the CI matrix and tests/integration-env.sh do',
			);
		}
		$root = self::rootConnection((string)$rootDsn);

		$prefix = (string)Server::get(IConfig::class)->getSystemValue('dbtableprefix', 'oc_');
		// The app's connection user — the installer's own, not root — is the
		// one the catalogue runs as; SHOW it the shadow schema, the way a
		// hosting panel's wildcard grants would. CURRENT_USER() comes back
		// unquoted; GRANT wants 'user'@'host'.
		[$appUser, $appHost] = explode('@', (string)$db->executeQuery('SELECT CURRENT_USER()')->fetchOne(), 2);

		$root->exec('CREATE DATABASE IF NOT EXISTS ' . self::SHADOW);
		try {
			$root->exec(
				'CREATE TABLE ' . self::SHADOW . '.' . $prefix . 'fts_sql_documents ('
				. 'id INTEGER PRIMARY KEY, '
				. 'title_norm LONGTEXT, '
				. 'content_norm LONGTEXT, '
				. 'FULLTEXT INDEX fts_sql_documents_fulltext (title_norm, content_norm), '
				. 'INDEX fts_sql_documents_stale (title_norm(8))'
				. ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
			);
			$root->exec('GRANT SELECT ON ' . self::SHADOW . ".* TO '$appUser'@'$appHost'");

			$this->assertSame(
				[],
				$backend->artefactStatements(),
				'a same-named, same-prefixed table in another schema must not turn the catalogue against this one',
			);
		} finally {
			$root->exec('DROP DATABASE IF EXISTS ' . self::SHADOW);
		}
	}

	private static function rootConnection(string $dsn): PDO {
		$parts = parse_url($dsn);
		if ($parts === false || !isset($parts['host'], $parts['user'])) {
			self::fail("FTS_SQL_MYSQL_ROOT_DSN is not a mysql://user:pass@host:port DSN: $dsn");
		}
		return new PDO(
			'mysql:host=' . $parts['host'] . ';port=' . ($parts['port'] ?? 3306),
			$parts['user'],
			$parts['pass'] ?? '',
			[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
		);
	}
}
