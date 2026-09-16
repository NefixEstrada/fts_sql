<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Service;

use OCA\FtsSql\AppInfo\Application;
use OCA\FtsSql\ConfigLexicon;
use OCP\IAppConfig;

/**
 * Typed reads of the two config keys. The language read refuses to return a
 * value outside the closed list: an arbitrary regconfig name would make every
 * PostgreSQL write fail (DESIGN.md, Security).
 */
final class ConfigService {
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function getLanguage(): string {
		$value = $this->appConfig->getValueString(Application::APP_ID, ConfigLexicon::LANGUAGE, ConfigLexicon::DEFAULT_LANGUAGE);
		return in_array($value, ConfigLexicon::availableLanguages(), true) ? $value : ConfigLexicon::DEFAULT_LANGUAGE;
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
