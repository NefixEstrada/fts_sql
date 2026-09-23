<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Service;

use OCA\FtsSql\AppInfo\Application;
use OCA\FtsSql\Backends\BackendFactory;
use OCA\FtsSql\ConfigLexicon;
use OCA\FtsSql\Exceptions\UnsupportedEngine;
use OCP\IAppConfig;

/**
 * Typed reads of the two config keys. The offered languages are not a static
 * list but what the running engine itself accepts, read once per request
 * through the strategy (ConfigLexicon holds only the default); the language
 * read refuses to return a value outside that list, because a name the
 * engine lacks would make every PostgreSQL write fail (DESIGN.md, Security).
 */
final class ConfigService {
	/** @var list<string>|null */
	private ?array $languages = null;

	public function __construct(
		private IAppConfig $appConfig,
		private BackendFactory $factory,
	) {
	}

	/**
	 * The catalogue table is tiny, but getLanguage() is called once per
	 * document while indexing, so the list is fetched once per request. On
	 * an engine this app cannot serve at all there is nothing better to
	 * offer than the default.
	 *
	 * @return list<string>
	 */
	public function availableLanguages(): array {
		if ($this->languages === null) {
			try {
				$this->languages = $this->factory->getBackend()->textSearchConfigurations();
			} catch (UnsupportedEngine) {
				$this->languages = [ConfigLexicon::DEFAULT_LANGUAGE];
			}
		}
		return $this->languages;
	}

	public function getLanguage(): string {
		$value = $this->appConfig->getValueString(Application::APP_ID, ConfigLexicon::LANGUAGE, ConfigLexicon::DEFAULT_LANGUAGE);
		return in_array($value, $this->availableLanguages(), true) ? $value : ConfigLexicon::DEFAULT_LANGUAGE;
	}

	public function setLanguage(string $language): void {
		$this->appConfig->setValueString(Application::APP_ID, ConfigLexicon::LANGUAGE, $language);
	}

	public function getContentBytes(): int {
		$value = $this->appConfig->getValueInt(Application::APP_ID, ConfigLexicon::CONTENT_BYTES, ConfigLexicon::DEFAULT_CONTENT_BYTES);
		return max(1, $value);
	}

	public function setContentBytes(int $bytes): void {
		$this->appConfig->setValueInt(Application::APP_ID, ConfigLexicon::CONTENT_BYTES, $bytes);
	}
}
