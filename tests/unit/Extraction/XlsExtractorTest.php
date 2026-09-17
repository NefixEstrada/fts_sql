<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Unit\Extraction;

use OCA\FtsSql\Extraction\ExtractionCause;
use OCA\FtsSql\Extraction\XlsExtractor;
use OCA\FtsSql\Tests\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * The Excel 97-2003 extractor against DESIGN.md's Milestone 4, over
 * the same measurement that shaped it
 * (benchmark/results/2026-09-16-xls.json): the scoped PhpSpreadsheet
 * reader with the compound-file gate the .ppt extractor built —
 * here exercised on a real mini-stream container, which a small
 * workbook actually uses — and encryption read from the workbook's
 * own FILEPASS record rather than the reader's generic decryption
 * failure.
 */
class XlsExtractorTest extends TestCase {
	private const BUDGET = 2_097_152;

	private XlsExtractor $extractor;

	protected function setUp(): void {
		$this->extractor = new XlsExtractor();
	}

	public function testOwnsXls(): void {
		$this->assertSame(['xls'], $this->extractor->owns());
	}

	public function testStringCellsComeOutOneRowPerLine(): void {
		$result = $this->extract(Fixtures::xls(['sortida al museu', 'confirmar la data']));

		$this->assertNull($result->cause);
		$this->assertSame("sortida al museu\nconfirmar la data\n", $result->text);
	}

	public function testAccentedTextSurvivesTheRoundTrip(): void {
		$result = $this->extract(Fixtures::xls(['sortida al museu de ciències']));

		$this->assertNull($result->cause);
		$this->assertSame("sortida al museu de ciències\n", $result->text);
	}

	public function testAValueThatBeginsLikeAFormulaStaysText(): void {
		$result = $this->extract(Fixtures::xls(['=SUM(1;2) és text, no fórmula']));

		$this->assertNull($result->cause);
		$this->assertSame("=SUM(1;2) és text, no fórmula\n", $result->text);
	}

	public function testAnEmptyWorkbookExtractsToEmptyText(): void {
		$result = $this->extract(Fixtures::xls([]));

		$this->assertNull($result->cause);
		$this->assertSame('', $result->text);
	}

	public function testTheBudgetCutsAtTheNextWordBoundary(): void {
		$result = $this->extractor->extract(
			Fixtures::stream(Fixtures::xls(['museu', 'confirmar la data amb escola'])),
			'xls',
			4,
		);

		$this->assertSame(ExtractionCause::BudgetCut, $result->cause);
		$this->assertSame('muse', $result->text);
	}

	public function testTheFilePassRecordIsTheEncryptedCause(): void {
		$result = $this->extract(Fixtures::xlsEncryptedToken(['museu']));

		$this->assertSame(ExtractionCause::Encrypted, $result->cause);
		$this->assertNull($result->text);
		$this->assertStringContainsString('encrypted', $result->message);
	}

	public function testBytesThatAreNotACompoundFileAreRefused(): void {
		$result = $this->extract('no és cap fitxer de Excel, és text');

		$this->assertSame(ExtractionCause::ParserGaveUp, $result->cause);
		$this->assertStringContainsString('no compound-file signature', $result->message);
	}

	public function testACompoundFileWithoutAWorkbookStreamIsRefused(): void {
		// a valid PowerPoint compound container: the gate opens it, and
		// the refusal names what a workbook needs
		$result = $this->extract(Fixtures::ppt([['museu']]));

		$this->assertSame(ExtractionCause::ParserGaveUp, $result->cause);
		$this->assertStringContainsString('no Workbook stream', $result->message);
	}

	public function testUpperCaseExtensionIsOwned(): void {
		$result = $this->extractor->extract(
			Fixtures::stream(Fixtures::xls(['museu'])),
			'XLS',
			self::BUDGET,
		);

		$this->assertNull($result->cause);
		$this->assertSame("museu\n", $result->text);
	}

	private function extract(string $bytes) {
		return $this->extractor->extract(Fixtures::stream($bytes), 'xls', self::BUDGET);
	}
}
