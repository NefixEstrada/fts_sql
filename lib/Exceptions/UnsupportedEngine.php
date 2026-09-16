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
 * IDBConnection::getDatabaseProvider() named an engine the app has no
 * strategy for — Oracle, which the app manifest's databases enumeration can
 * only declare as sqlite, mysql and pgsql, so it is excluded by construction
 * (DESIGN.md, Non-goals). The factory refuses rather than guessing.
 */
class UnsupportedEngine extends Exception {
}
