<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Unit\AppInfo;

use OCA\FtsSql\AppInfo\Application;
use PHPUnit\Framework\TestCase;

/**
 * The app id is the identifier everything else hangs off: the table prefixes,
 * the route names, the occ commands. It is asserted on its own so a rename
 * cannot drift quietly between the places that spell it out.
 */
class ApplicationTest extends TestCase {

	public function testAppIdMatchesTheTablePrefixes(): void {
		$this->assertSame('fts_sql', Application::APP_ID);
	}
}
