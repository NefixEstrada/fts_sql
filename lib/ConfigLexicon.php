<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql;

use OCP\Config\Lexicon\Entry;
use OCP\Config\Lexicon\ILexicon;
use OCP\Config\Lexicon\Strictness;
use OCP\Config\ValueType;

/**
 * The two app config keys, their types and defaults (DESIGN.md,
 * "Configuration"). The languages themselves are no static list: the offered
 * set is what the running engine accepts, read live through
 * ConfigService::availableLanguages(), and the lexicon holds only the
 * default — `simple`, the one configuration every PostgreSQL ships in the
 * bootstrap catalog.
 */
final class ConfigLexicon implements ILexicon {
	public const LANGUAGE = 'language';
	public const CONTENT_BYTES = 'content_bytes';

	public const DEFAULT_LANGUAGE = 'simple';
	/** 2 MiB of extracted plain text per document (one PDF page is ~2.8 KB). */
	public const DEFAULT_CONTENT_BYTES = 2097152;

	public function getStrictness(): Strictness {
		return Strictness::EXCEPTION;
	}

	public function getAppConfigs(): array {
		return [
			new Entry(
				self::LANGUAGE,
				ValueType::STRING,
				self::DEFAULT_LANGUAGE,
				'PostgreSQL text search configuration, one of those the running server itself ships in pg_catalog; simple disables stemming; other engines ignore it. Changing it invalidates every indexed document.',
			),
			new Entry(
				self::CONTENT_BYTES,
				ValueType::INT,
				self::DEFAULT_CONTENT_BYTES,
				'Extracted plain text stored per document, in bytes.',
			),
		];
	}

	public function getUserConfigs(): array {
		return [];
	}
}
