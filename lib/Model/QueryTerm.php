<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Model;

/**
 * One term of a parsed search query: the value (quotes stripped, inner
 * whitespace collapsed for phrases), whether it was quoted, and how it
 * combines. Engines apply their own prefix operator to the prefix candidate
 * (SearchQuery::getPrefixCandidate()); a phrase never takes one, because a
 * `*` inside quotes is a literal on MySQL.
 */
final readonly class QueryTerm {
	public function __construct(
		public string $value,
		public bool $phrase,
		public Occur $occur,
	) {
	}
}
