<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Unit\Backends;

use OCA\FtsSql\Backends\BackendFactory;
use OCA\FtsSql\Backends\Mysql;
use OCA\FtsSql\Backends\Postgres;
use OCA\FtsSql\Backends\Sqlite;
use OCA\FtsSql\Exceptions\UnsupportedEngine;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The one place the engine is chosen: getDatabaseProvider() → strategy, and
 * a refusal (Oracle is excluded by construction, DESIGN.md Non-goals).
 */
class BackendFactoryTest extends TestCase {
	protected function setUp(): void {
		self::stubDoctrineConstants();
	}

	public function testMapsEachProviderToItsBackend(): void {
		$strategies = [
			'postgres' => Postgres::class,
			'mysql' => Mysql::class,
			'sqlite' => Sqlite::class,
		];
		foreach ($strategies as $provider => $class) {
			$db = $this->createMock(IDBConnection::class);
			$db->method('getDatabaseProvider')->willReturn($provider);

			$backend = (new BackendFactory($db))->getBackend();

			$this->assertInstanceOf($class, $backend);
			$this->assertSame($provider, $backend->name());
		}
	}

	public function testRefusesAnEngineWithNoStrategy(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn('oracle');

		$this->expectException(UnsupportedEngine::class);
		(new BackendFactory($db))->getBackend();
	}

	/**
	 * The nextcloud/ocp dev dependency ships interfaces whose constants
	 * borrow Doctrine's (IQueryBuilder::PARAM_STR is
	 * Doctrine\DBAL\ParameterType::STRING), but Doctrine itself is not a
	 * dependency of this app. PHPUnit's mock generator evaluates default
	 * parameter values, and IDBConnection::quote()'s default is one of
	 * those constants — so the two constant holders must exist for
	 * createMock(IDBConnection) to work. The values are never read.
	 */
	private static function stubDoctrineConstants(): void {
		if (class_exists('Doctrine\DBAL\ParameterType')) {
			return;
		}
		eval(<<<'PHP'
			namespace Doctrine\DBAL;

			final class ParameterType {
				public const NULL = 0;
				public const INTEGER = 1;
				public const STRING = 2;
				public const LARGE_OBJECT = 3;
			}

			final class ArrayParameterType {
				public const INTEGER = 101;
				public const STRING = 102;
			}
			PHP
		);
	}
}
