<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Unit\Service;

use OCA\FtsSql\Extraction\ExtractionCause;
use OCA\FtsSql\Service\ExtractionService;
use OCA\FtsSql\Tests\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * ExtractionService's dispatch and its plain-text path against DESIGN.md:
 * an extension an extractor owns goes to the extractor (Milestone 2); an
 * extension on the not-yet list and content that is not plain text are
 * causes to report; everything else is a plain-text candidate, cut to the
 * budget on a character boundary with the trailing partial word dropped.
 */
class ExtractionServiceTest extends TestCase {

	public function testAnOwnedExtensionIsDispatchedToItsExtractor(): void {
		$docx = Fixtures::docx('<w:p><w:r><w:t>sortida al museu</w:t></w:r></w:p>');

		$result = ExtractionService::extract($docx, 'docx', 2097152);

		$this->assertNull($result->cause);
		$this->assertSame("sortida al museu\n", $result->text);
	}

	public function testNotYetExtensionsAreAReportedCause(): void {
		foreach (['epub', 'zip', 'svg', 'wav'] as $extension) {
			$result = ExtractionService::extract('sortida al museu', $extension, 100);

			$this->assertSame(ExtractionCause::Unsupported, $result->cause, $extension);
			$this->assertNull($result->text, $extension);
			$this->assertStringContainsString($extension, $result->message, $extension);
		}
	}

	public function testTheNotYetListIsMatchedLowercased(): void {
		$this->assertSame(ExtractionCause::Unsupported, ExtractionService::extract('sortida al museu', 'EPUB', 100)->cause);
		$this->assertSame(ExtractionCause::Unsupported, ExtractionService::extract('sortida al museu', 'Zip', 100)->cause);
	}

	public function testUnknownAndEmptyExtensionsAreTextCandidates(): void {
		foreach (['', 'txt', 'md', 'a-format-nobody-declared'] as $extension) {
			$result = ExtractionService::extract('hola', $extension, 100);

			$this->assertNull($result->cause, $extension);
			$this->assertSame('hola', $result->text, $extension);
		}
	}

	public function testInvalidUtf8IsAReportedCause(): void {
		// \xC3 followed by '(' is not valid UTF-8.
		$result = ExtractionService::extract("sortida\xC3\x28", 'txt', 100);

		$this->assertSame(ExtractionCause::Unsupported, $result->cause);
		$this->assertNull($result->text);
		$this->assertStringContainsString('not plain text', $result->message);
	}

	public function testControlCharactersOutsideTabNewlineAndCrAreAReportedCause(): void {
		foreach (["a\x01b", "a\x00b", "a\x1Fb", "a\xC2\x85b"] as $bytes) {
			$result = ExtractionService::extract($bytes, 'txt', 100);

			$this->assertSame(ExtractionCause::Unsupported, $result->cause, bin2hex($bytes));
			$this->assertNull($result->text, bin2hex($bytes));
		}
	}

	public function testTabNewlineAndCarriageReturnPass(): void {
		$text = "línia una\tcol\nlínia dos\r\nfi";
		$result = ExtractionService::extract($text, 'txt', 100);

		$this->assertNull($result->cause);
		$this->assertSame($text, $result->text);
	}

	public function testEmptyBytesExtractToEmptyText(): void {
		$result = ExtractionService::extract('', 'txt', 100);

		$this->assertNull($result->cause);
		$this->assertSame('', $result->text);
	}

	public function testTextWithinTheBudgetComesBackAsIs(): void {
		// 16 bytes exactly, budget 16: no cut, not even the word trim.
		$result = ExtractionService::extract('sortida al museu', 'txt', 16);

		$this->assertNull($result->cause);
		$this->assertSame('sortida al museu', $result->text);
	}

	public function testTheCutIsMultibyteSafeAndDropsTheTrailingPartialWord(): void {
		// 'àçò àçò àçò' is 20 bytes. A 10-byte budget cannot take the
		// second word whole: the cut stops at 'àçò à' (9 bytes) rather than
		// split the 'ç' that would not fit, and the trailing partial 'à' is
		// dropped back to the last space.
		$result = ExtractionService::extract('àçò àçò àçò', 'txt', 10);

		$this->assertSame(ExtractionCause::BudgetCut, $result->cause);
		$this->assertSame('àçò', $result->text);
		$this->assertTrue(mb_check_encoding($result->text, 'UTF-8'));
	}

	public function testACutLandingOnAWordBoundary(): void {
		// 'aa bb cc' (8 bytes), budget 6: the cut is 'aa bb ' and the word
		// trim lands where rtrim would have.
		$result = ExtractionService::extract('aa bb cc', 'txt', 6);

		$this->assertSame(ExtractionCause::BudgetCut, $result->cause);
		$this->assertSame('aa bb', $result->text);
	}

	public function testACutWithNoSpaceKeepsWholeCharacters(): void {
		// 'ààààà' is 10 bytes; an 8-byte budget keeps four full characters
		// and there is no space to cut back to.
		$result = ExtractionService::extract('ààààà', 'txt', 8);

		$this->assertSame(ExtractionCause::BudgetCut, $result->cause);
		$this->assertSame('àààà', $result->text);
	}

	public function testASpaceAtPositionZeroIsNotACutPoint(): void {
		// ' abcdef', budget 4, cuts to ' abc'; the only space sits at 0 and
		// cutting back to it would return nothing, so it stays.
		$result = ExtractionService::extract(' abcdef', 'txt', 4);

		$this->assertSame(ExtractionCause::BudgetCut, $result->cause);
		$this->assertSame(' abc', $result->text);
	}

	public function testAZeroBudgetCutsToEmptyTextAndReportsIt(): void {
		$result = ExtractionService::extract('sortida', 'txt', 0);

		$this->assertSame(ExtractionCause::BudgetCut, $result->cause);
		$this->assertSame('', $result->text);
	}
}
