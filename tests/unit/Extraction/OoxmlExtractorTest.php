<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Unit\Extraction;

use OCA\FtsSql\Extraction\ExtractionCause;
use OCA\FtsSql\Extraction\ExtractionResult;
use OCA\FtsSql\Extraction\OoxmlExtractor;
use OCA\FtsSql\Tests\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * The OOXML extractor against DESIGN.md Milestone 2: .docx, .xlsx and .pptx
 * found by their body text — runs concatenated as the application laid them
 * out, one newline per paragraph, field instructions and deleted text
 * skipped, spreadsheets read through the shared strings and presentations
 * in numeric slide order.
 */
class OoxmlExtractorTest extends TestCase {
	private const BUDGET = 2_097_152;

	private OoxmlExtractor $extractor;

	protected function setUp(): void {
		$this->extractor = new OoxmlExtractor();
	}

	public function testOwnsTheThreeOoxmlExtensions(): void {
		$this->assertSame(['docx', 'xlsx', 'pptx'], $this->extractor->owns());
	}

	public function testADocxComesOutAsParagraphsOfConcatenatedRuns(): void {
		$docx = Fixtures::docx(
			'<w:p><w:r><w:t xml:space="preserve">sortida al </w:t></w:r><w:r><w:t>museu</w:t></w:r></w:p>'
			. '<w:p><w:r><w:tab/><w:t>amb tab</w:t><w:br/><w:t>i salt</w:t></w:r></w:p>'
			. '<w:p><w:r><w:instrText> PAGE </w:instrText></w:r></w:p>',
		);

		$result = $this->extract($docx, 'docx');

		$this->assertNull($result->cause);
		// The field-instruction paragraph contributes nothing but its own
		// paragraph break.
		$this->assertSame("sortida al museu\n\tamb tab\ni salt\n\n", $result->text);
	}

	public function testAXlsxReadsTheSharedStringsOnceEach(): void {
		$xlsx = Fixtures::xlsxSharedStrings([
			'<si><t>sortida al museu</t></si>',
			'<si><r><t xml:space="preserve">corrents i </t></r><r><t>canoes</t></r></si>',
			'<si><r><rPh><t>フリガナ</t></rPh><t>fonètica fora</t></r></si>',
		]);

		$result = $this->extract($xlsx, 'xlsx');

		$this->assertNull($result->cause);
		$this->assertSame("sortida al museu\ncorrents i canoes\nfonètica fora\n", $result->text);
	}

	public function testAXlsxWithoutSharedStringsFallsBackToInlineStrings(): void {
		$xlsx = Fixtures::xlsxInline([
			'xl/worksheets/sheet1.xml' => '<row><c t="inlineStr"><is><t>museu</t></is></c><c><v>42</v></c><c t="inlineStr"><is><t>final</t></is></c></row>',
			'xl/worksheets/sheet10.xml' => '<row><c t="inlineStr"><is><t>deu</t></is></c></row>',
			'xl/worksheets/sheet2.xml' => '<row><c t="inlineStr"><is><t>dos</t></is></c></row>',
		]);

		$result = $this->extract($xlsx, 'xlsx');

		$this->assertNull($result->cause);
		// Sheet order is numeric (sheet2 before sheet10) and the numeric
		// cells contribute nothing.
		$this->assertSame("museufinal\ndos\ndeu\n", $result->text);
	}

	public function testAPptxFollowsNumericSlideOrder(): void {
		$pptx = Fixtures::pptx([
			// Added out of order on purpose: zip directory order is not slide
			// order, and slide10 must not sort before slide2.
			'ppt/slides/slide10.xml' => '<a:p><a:r><a:t>última</a:t></a:r></a:p>',
			'ppt/slides/slide2.xml' => '<a:p><a:r><a:t>segona</a:t></a:r></a:p>',
			'ppt/slides/slide1.xml' => '<a:p><a:r><a:t>portada</a:t></a:r></a:p>',
		]);

		$result = $this->extract($pptx, 'pptx');

		$this->assertNull($result->cause);
		$this->assertSame("portada\nsegona\núltima\n", $result->text);
	}

	public function testTheBudgetCutsOnAWordBoundary(): void {
		$docx = Fixtures::docx('<w:p><w:r><w:t>aa bb cc dd</w:t></w:r></w:p>');

		$result = $this->extract($docx, 'docx', 6);

		$this->assertSame(ExtractionCause::BudgetCut, $result->cause);
		$this->assertSame('aa bb', $result->text);
		$this->assertStringContainsString('budget', $result->message);
	}

	public function testGarbageInsteadOfAContainerGivesUp(): void {
		$result = $this->extract('sortida al museu sense zip', 'docx');

		$this->assertSame(ExtractionCause::ParserGaveUp, $result->cause);
		$this->assertNull($result->text);
		$this->assertStringContainsString('not a zip container', $result->message);
	}

	public function testAPasswordProtectedDocumentIsEncrypted(): void {
		$result = $this->extract(Fixtures::ole(), 'docx');

		$this->assertSame(ExtractionCause::Encrypted, $result->cause);
		$this->assertNull($result->text);
	}

	public function testAContainerWithoutTheExpectedEntryGivesUp(): void {
		$docx = Fixtures::zip(['xl/workbook.xml' => '<workbook/>']);

		$result = $this->extract($docx, 'docx');

		$this->assertSame(ExtractionCause::ParserGaveUp, $result->cause);
		$this->assertStringContainsString('no word/document.xml', $result->message);
	}

	public function testMalformedBodyXmlGivesUp(): void {
		$docx = Fixtures::zip(['word/document.xml' => '<w:document><w:body><w:p>sense tancar</w:p>']);

		$result = $this->extract($docx, 'docx');

		$this->assertSame(ExtractionCause::ParserGaveUp, $result->cause);
		$this->assertStringContainsString('malformed XML', $result->message);
	}

	public function testAZipBombInTheBodyEntryGivesUp(): void {
		$docx = Fixtures::zip(['word/document.xml' => str_repeat('a', 2_000_000)]);

		$result = $this->extract($docx, 'docx');

		$this->assertSame(ExtractionCause::ParserGaveUp, $result->cause);
		$this->assertStringContainsString('ratio cap', $result->message);
	}

	public function testAnEmptyParagraphDocumentExtractsToEmptyText(): void {
		$result = $this->extract(Fixtures::docx('<w:p><w:r><w:t/></w:r></w:p>'), 'docx');

		$this->assertNull($result->cause);
		$this->assertSame("\n", $result->text);
	}

	private function extract(string $bytes, string $extension, int $budget = self::BUDGET): ExtractionResult {
		$stream = Fixtures::stream($bytes);
		try {
			return $this->extractor->extract($stream, $extension, $budget);
		} finally {
			fclose($stream);
		}
	}
}
