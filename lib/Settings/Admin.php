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
use OCA\FtsSql\Exceptions\UnsupportedEngine;
use OCA\FtsSql\Service\ConfigService;
use OCA\FtsSql\Service\IndexService;
use OCA\FtsSql\Service\SearchService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * The FTS SQL card inside the framework's own Full text search settings
 * section (getSection() returns its id, so no <admin-section> of our own is
 * declared). Renders the first of three states that fails — the engine
 * cannot search, the artefact is missing, the artefact does not hold every
 * document — or "Ready", and carries the two settings with their warnings
 * next to the control, not after Save (DESIGN.md, "Admin card"). The
 * per-cause extraction counts ride along in every state (decision (c) of
 * the "representing partial extraction" open issue): they describe the
 * documents, not the index's health, so a failing state does not hide them
 * — and a counting failure never blocks the card.
 */
final class Admin implements ISettings {
	public function __construct(
		private BackendFactory $factory,
		private ConfigService $config,
		private SearchService $searchService,
		private IndexService $indexService,
		private IInitialState $initialState,
		private IL10N $l10n,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState('admin', $this->cardState());
		Util::addScript(Application::APP_ID, Application::APP_ID . '-admin');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-admin');
		return new TemplateResponse(Application::APP_ID, 'settings-admin');
	}

	/**
	 * @return array{state: string, message: string, language: string, languages: list<string>, contentBytes: int, causes: array<string, int>}
	 */
	private function cardState(): array {
		$language = $this->config->getLanguage();
		$languages = $this->config->availableLanguages();

		try {
			$backend = $this->factory->getBackend();
		} catch (UnsupportedEngine $e) {
			return $this->state('engine', $e->getMessage(), $language, $languages, $this->causeCounts());
		}

		if (!$backend->isUsable()) {
			return $this->state(
				'unusable',
				$this->l10n->t('this %s build cannot run full text search', [$backend->name()]),
				$language,
				$languages,
				$this->causeCounts(),
			);
		}

		if (!$this->searchService->probe($backend, $language)) {
			return $this->state(
				'missing',
				$this->l10n->t('the search index artefact is missing in this database; re-enable the app or run `occ maintenance:repair`'),
				$language,
				$languages,
				$this->causeCounts(),
			);
		}

		if ($backend->hasUnindexedDocuments()) {
			return $this->state(
				'stale',
				$this->l10n->t('the search index does not hold every document the app has stored; run `occ fulltextsearch:reset && occ fulltextsearch:index`'),
				$language,
				$languages,
				$this->causeCounts(),
			);
		}

		return $this->state(
			'ready',
			$this->l10n->t('Ready: the search index exists in this %s database', [$backend->name()]),
			$language,
			$languages,
			$this->causeCounts(),
		);
	}

	/**
	 * @param list<string> $languages
	 * @param array<string, int> $causes
	 */
	private function state(string $state, string $message, string $language, array $languages, array $causes): array {
		return [
			'state' => $state,
			'message' => $message,
			'language' => $language,
			'languages' => $languages,
			'contentBytes' => $this->config->getContentBytes(),
			'causes' => $causes,
		];
	}

	/**
	 * Counting must never break the card: on a database that refuses the
	 * query (a table the migration has not reached yet, a lost connection)
	 * the card still renders its state, with no counts.
	 *
	 * @return array<string, int>
	 */
	private function causeCounts(): array {
		try {
			return $this->indexService->countCauses();
		} catch (\Throwable) {
			return [];
		}
	}

	public function getSection(): string {
		return 'fulltextsearch';
	}

	public function getPriority(): int {
		return 31;
	}
}
