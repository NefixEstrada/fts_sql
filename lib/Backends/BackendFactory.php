<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Backends;

use OCA\FtsSql\Exceptions\UnsupportedEngine;
use OCP\IDBConnection;

/**
 * IDBConnection::getDatabaseProvider() → one IBackend, selected once per
 * request (DESIGN.md, "The engine strategy"). MariaDB answers 'mysql' from
 * the non-strict call the factory makes, so one strategy serves both.
 */
final class BackendFactory {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function getBackend(): IBackend {
		$provider = $this->db->getDatabaseProvider();
		return match ($provider) {
			IDBConnection::PLATFORM_POSTGRES => new Postgres($this->db),
			IDBConnection::PLATFORM_MYSQL => new Mysql($this->db),
			IDBConnection::PLATFORM_SQLITE => new Sqlite($this->db),
			default => throw new UnsupportedEngine("The database provider '$provider' has no full text search backend"),
		};
	}
}
