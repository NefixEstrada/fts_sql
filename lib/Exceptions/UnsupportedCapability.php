<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Exceptions;

use Exception;

/**
 * An ISearchRequest capability this platform cannot serve the narrowing way
 * (DESIGN.md, "What the search honours, by direction"): regex filters,
 * wildcard filters, sub tags and simple queries narrow the result set, and
 * ignoring them would return documents the user asked to exclude — so the
 * search is refused, naming the capability. Limit fields are the same
 * direction, served only over both title and content; a proper subset
 * refuses. The widening capabilities (parts, wildcard fields, fields) are
 * the ones skipped instead.
 */
class UnsupportedCapability extends Exception {
}
