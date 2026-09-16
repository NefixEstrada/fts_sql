<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Unit\Controller;

use OCA\FtsSql\Controller\SettingsController;
use OCA\FtsSql\Service\ConfigService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The two settings endpoints are the administrator input into app config:
 * a value outside the closed language list or a non-positive budget must be
 * refused, because an arbitrary regconfig name would make every PostgreSQL
 * write fail (DESIGN.md, Security).
 */
class SettingsControllerTest extends TestCase {
	private IAppConfig $appConfig;
	private SettingsController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->controller = new SettingsController(
			$this->createMock(IRequest::class),
			new ConfigService($this->appConfig),
		);
	}

	public function testAcceptsALanguageFromTheClosedList(): void {
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('fts_sql', 'language', 'catalan');

		$response = $this->controller->putLanguage('catalan');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['language' => 'catalan'], $response->getData());
	}

	public function testRefusesAnUnknownLanguage(): void {
		$this->appConfig->expects($this->never())->method('setValueString');

		$response = $this->controller->putLanguage('malayalam');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}

	public function testRefusesAnArbitraryRegconfigName(): void {
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
}
