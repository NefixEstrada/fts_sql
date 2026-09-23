<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Unit\Controller;

use OCA\FtsSql\Backends\BackendFactory;
use OCA\FtsSql\Controller\SettingsController;
use OCA\FtsSql\Service\ConfigService;
use OCP\AppFramework\Http;
use OCP\DB\IResult;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The two settings endpoints are the administrator input into app config:
 * a language the running engine does not itself report, or a non-positive
 * budget, must be refused, because a name the engine lacks would make every
 * PostgreSQL write fail (DESIGN.md, Security). The validation is live, so
 * the tests drive the real factory and PostgreSQL strategy over a mocked
 * connection that answers pg_ts_config.
 */
class SettingsControllerTest extends TestCase {
	private IAppConfig&MockObject $appConfig;
	private IDBConnection&MockObject $db;
	private SettingsController $controller;

	protected function setUp(): void {
		parent::setUp();
		self::stubDoctrineConstants();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_POSTGRES);
		$this->controller = new SettingsController(
			$this->createMock(IRequest::class),
			new ConfigService($this->appConfig, new BackendFactory($this->db)),
		);
	}

	#[DataProvider('providesAcceptedLanguages')]
	public function testAcceptsALanguageTheEngineItselfReports(string $language): void {
		$this->engineReports('simple', 'catalan', 'german');
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('fts_sql', 'language', $language);

		$response = $this->controller->putLanguage($language);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['language' => $language], $response->getData());
	}

	/**
	 * One configuration from the original four, one from the rest of the
	 * built-in set: the endpoint accepts whatever the engine reports.
	 *
	 * @return list<list<string>>
	 */
	public static function providesAcceptedLanguages(): array {
		return [
			['catalan'],
			['german'],
		];
	}

	public function testRefusesAnUnknownLanguage(): void {
		$this->engineReports('simple', 'catalan', 'german');
		$this->appConfig->expects($this->never())->method('setValueString');

		$response = $this->controller->putLanguage('malayalam');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}

	public function testRefusesAConfigurationThisEngineDoesNotShip(): void {
		// estonian is real — PostgreSQL 18 ships it — but the offered set is
		// the running engine's, not the newest engine's.
		$this->engineReports('simple', 'catalan');
		$this->appConfig->expects($this->never())->method('setValueString');

		$response = $this->controller->putLanguage('estonian');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}

	public function testRefusesAnArbitraryRegconfigName(): void {
		$this->engineReports('simple', 'catalan', 'english');

		$response = $this->controller->putLanguage('pg_catalog.english');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}

	public function testAcceptsAPositiveBudget(): void {
		$this->appConfig->expects($this->once())
			->method('setValueInt')
			->with('fts_sql', 'content_bytes', 1048576);

		$response = $this->controller->putContentBytes(1048576);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['contentBytes' => 1048576], $response->getData());
	}

	public function testRefusesANonPositiveBudget(): void {
		$this->appConfig->expects($this->never())->method('setValueInt');

		$this->assertSame(
			Http::STATUS_UNPROCESSABLE_ENTITY,
			$this->controller->putContentBytes(0)->getStatus(),
		);
	}

	private function engineReports(string ...$configurations): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetchFirstColumn')->willReturn($configurations);
		$this->db->method('executeQuery')->willReturn($result);
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
