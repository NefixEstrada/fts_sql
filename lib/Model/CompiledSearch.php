<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Model;

/**
 * A search ready to execute: the page statement and the count statement over
 * the identical FROM ... WHERE (so it can never page over a set the page
 * query does not see), and the bound parameters both share. arrayParams
 * names the parameters that bind a list of strings (the token IN list, the
 * metatag IN list): the pure side stays free of Doctrine types, and the
 * executor maps them to the engine's array binding.
 */
final readonly class CompiledSearch {
	/**
	 * @param array<string, mixed> $parameters
	 * @param list<string> $arrayParams parameter names bound as lists of strings
	 */
	public function __construct(
		public string $pageSql,
		public string $countSql,
		public array $parameters,
		public array $arrayParams = [],
	) {
	}
}
