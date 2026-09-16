<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Unit\Service;

use OCA\FtsSql\Service\ExtractionService;
use PHPUnit\Framework\TestCase;

/**
 * ExtractionService::extract against DESIGN.md Milestone 1: plain text
 * only, deny-listed by extension, cut to the budget on a character
 * boundary with the trailing partial word dropped.
 */
class ExtractionServiceTest extends TestCase {

	public function testDenyListedExtensionsAreNotPlainText(): void {
		foreach (['docx', 'pdf', 'zip', 'xlsx', 'svg', 'wav'] as $extension) {
			$this->assertNull(ExtractionService::extract('sortida al museu', $extension, 100), $extension);
		}
	}

	public function testTheDenyListIsMatchedLowercased(): void {
		$this->assertNull(ExtractionService::extract('sortida al museu', 'DOCX', 100));
		$this->assertNull(ExtractionService::extract('sortida al museu', 'Pdf', 100));
	}

	public function testUnknownAndEmptyExtensionsAreTextCandidates(): void {
		$this->assertSame('hola', ExtractionService::extract('hola', '', 100));
		$this->assertSame('hola', ExtractionService::extract('hola', 'txt', 100));
		$this->assertSame('hola', ExtractionService::extract('hola', 'md', 100));
		$this->assertSame('hola', ExtractionService::extract('hola', 'a-format-nobody-declared', 100));
	}

	public function testInvalidUtf8IsNotPlainText(): void {
		// \xC3 followed by '(' is not valid UTF-8.
		$this->assertNull(ExtractionService::extract("sortida\xC3\x28", 'txt', 100));
		$this->assertNull(ExtractionService::extract("\x80\x81", '', 100));
	}

	public function testControlCharactersOutsideTabNewlineAndCrAreNotPlainText(): void {
		$this->assertNull(ExtractionService::extract("a\x01b", 'txt', 100));
		$this->assertNull(ExtractionService::extract("a\x00b", 'txt', 100));
		$this->assertNull(ExtractionService::extract("a\x1Fb", 'txt', 100));
		// U+0085 NEXT LINE is a control character too, a multi-byte one.
		$this->assertNull(ExtractionService::extract("a\xC2\x85b", 'txt', 100));
	}

	public function testTabNewlineAndCarriageReturnPass(): void {
		$text = "línia una\tcol\nlínia dos\r\nfi";
		$this->assertSame($text, ExtractionService::extract($text, 'txt', 100));
	}

	public function testEmptyBytesExtractToEmptyText(): void {
		$this->assertSame('', ExtractionService::extract('', 'txt', 100));
	}

	public function testTextWithinTheBudgetComesBackAsIs(): void {
		// 16 bytes exactly, budget 16: no cut, not even the word trim.
		$this->assertSame('sortida al museu', ExtractionService::extract('sortida al museu', 'txt', 16));
	}

	public function testTheCutIsMultibyteSafeAndDropsTheTrailingPartialWord(): void {
		// 'àçò àçò àçò' is 20 bytes. A 10-byte budget cannot take the
		// second word whole: mb_strcut stops at 'àçò à' (9 bytes) rather
		// than split the 'ç' that would not fit, and the trailing partial
		// 'à' is dropped back to the last space.
		$result = ExtractionService::extract('àçò àçò àçò', 'txt', 10);

		$this->assertSame('àçò', $result);
		$this->assertTrue(mb_check_encoding($result, 'UTF-8'));
	}

	public function testACutLandingOnAWordBoundary(): void {
		// 'aa bb cc' (8 bytes), budget 6: the cut is 'aa bb ' and the word
		// trim lands where rtrim would have.
		$this->assertSame('aa bb', ExtractionService::extract('aa bb cc', 'txt', 6));
	}

	public function testACutWithNoSpaceKeepsWholeCharacters(): void {
		// 'ààààà' is 10 bytes; an 8-byte budget keeps four full characters
		// and there is no space to cut back to.
		$this->assertSame('àààà', ExtractionService::extract('ààààà', 'txt', 8));
	}

	public function testASpaceAtPositionZeroIsNotACutPoint(): void {
		// ' abcdef', budget 4, cuts to ' abc'; the only space sits at 0 and
		// cutting back to it would return nothing, so it stays — rtrim only
		// trims the end.
		$this->assertSame(' abc', ExtractionService::extract(' abcdef', 'txt', 4));
	}

	public function testAZeroBudgetExtractsEmptyText(): void {
		$this->assertSame('', ExtractionService::extract('sortida', 'txt', 0));
	}
}
