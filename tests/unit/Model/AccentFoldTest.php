<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Unit\Model;

use OCA\FtsSql\Model\AccentFold;
use PHPUnit\Framework\TestCase;

/**
 * AccentFold against the design's worked examples: `Ciències` folds to
 * `Ciencies` with the case kept, for what feeds the search artefact and the
 * query — never the stored text an excerpt is cut from.
 */
class AccentFoldTest extends TestCase {

	public function testFoldsTheDesignExampleKeepingCase(): void {
		$this->assertSame('Ciencies', AccentFold::fold('Ciències'));
	}

	public function testFoldsBothCases(): void {
		$this->assertSame('AEcUn', AccentFold::fold('ÀÉçÜñ'));
		$this->assertSame('museu de ciencies', AccentFold::fold('museu de ciències'));
	}

	public function testFoldsTheOneToManyCases(): void {
		$this->assertSame('Strasse', AccentFold::fold('Straße'));
		$this->assertSame('AEro', AccentFold::fold('Ærø'));
		$this->assertSame('THor', AccentFold::fold('Þor'));
	}

	public function testFoldsLatinExtendedA(): void {
		$this->assertSame('Cim', AccentFold::fold('Čím'));
		$this->assertSame('Zdena', AccentFold::fold('Žděná'));
	}

	public function testLeavesEverythingElseAlone(): void {
		$this->assertSame('plain text 123', AccentFold::fold('plain text 123'));
		$this->assertSame('', AccentFold::fold(''));
		// Outside the folding table: untouched, not mangled.
		$this->assertSame('Живот', AccentFold::fold('Живот'));
	}
}
