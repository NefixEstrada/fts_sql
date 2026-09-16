<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Settings;

use OCA\FtsSql\AppInfo\Application;
use OCA\FtsSql\Backends\BackendFactory;
use OCA\FtsSql\ConfigLexicon;
use OCA\FtsSql\Exceptions\UnsupportedEngine;
use OCA\FtsSql\Service\ConfigService;
use OCA\FtsSql\Service\SearchService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * The FTS SQL card inside the framework's own Full text search settings
 * section (getSection() returns its id, so no <admin-section> of our own is
 * declared). Renders the first of three states that fails — the engine
 * cannot search, the artefact is missing, the artefact does not hold every
 * document — or "Ready", and carries the two settings with their warnings
 * next to the control, not after Save (DESIGN.md, "Admin card").
 */
final class Admin implements ISettings {
	public function __construct(
		private BackendFactory $factory,
		private ConfigService $config,
		private SearchService $searchService,
		private IInitialState $initialState,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState('admin', $this->cardState());
		Util::addScript(Application::APP_ID, Application::APP_ID . '-admin');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-admin');
		return new TemplateResponse(Application::APP_ID, 'settings-admin');
	}

	/**
	 * @return array{state: string, message: string, language: string, languages: list<string>, contentBytes: int}
	 */
	private function cardState(): array {
		$language = $this->config->getLanguage();

		try {
			$backend = $this->factory->getBackend();
		} catch (UnsupportedEngine $e) {
			return $this->state('engine', $e->getMessage(), $language);
		}

		if (!$backend->isUsable()) {
			return $this->state(
				'unusable',
				'this ' . $backend->name() . ' build cannot run full text search',
				$language,
			);
		}

		if (!$this->searchService->probe($backend, $language)) {
			return $this->state(
				'missing',
				'the search index artefact is missing in this database; '
				. 're-enable the app or run `occ maintenance:repair`',
				$language,
			);
		}

		if ($backend->hasUnindexedDocuments()) {
			return $this->state(
				'stale',
				'the search index does not hold every document the app has stored; '
				. 'run `occ fulltextsearch:reset && occ fulltextsearch:index`',
				$language,
			);
		}

		return $this->state(
			'ready',
			'Ready: the search index exists in this ' . $backend->name() . ' database',
			$language,
		);
	}

	/**
	 * @param list<string> $languages
	 */
	private function state(string $state, string $message, string $language): array {
		return [
			'state' => $state,
			'message' => $message,
			'language' => $language,
			'languages' => ConfigLexicon::availableLanguages(),
			'contentBytes' => $this->config->getContentBytes(),
		];
	}

	public function getSection(): string {
		return 'fulltextsearch';
	}

	public function getPriority(): int {
		return 31;
	}
}
