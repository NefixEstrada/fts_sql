<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Model;

/**
 * One engine's answer for one query: the WHERE fragment over the alias d, the
 * ranking expression (higher is better; SQLite negates bm25() to comply), the
 * parameters both bind, and any join the rank or predicate need appended to
 * FROM (only SQLite needs one, and by table name: bm25() resolves only against
 * a table already in the query).
 */
final readonly class CompiledMatch {
	/**
	 * @param array<string, mixed> $parameters what predicate and rank bind
	 */
	public function __construct(
		public string $predicate,
		public string $rank,
		public array $parameters,
		public string $join = '',
	) {
	}
}
