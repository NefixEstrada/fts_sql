<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Unit\Extraction;

use OCA\FtsSql\Extraction\DocExtractor;
use OCA\FtsSql\Extraction\ExtractionCause;
use OCA\FtsSql\Tests\Fixtures;
use OCA\FtsSql\Vendor\PhpOffice\PhpWord\PhpWord;
use OCA\FtsSql\Vendor\PhpOffice\PhpWord\Reader\MsDoc;
use PHPUnit\Framework\TestCase;

/**
 * The Word 97-2003 extractor against DESIGN.md's Milestone 4, over
 * the simple-layout fixture the tests build byte by byte: the FIB,
 * the UTF-16 text where the paragraph table names it, the PAPX and
 * CHPX pages, the section and font structures the reader walks
 * first. The gate reads what the reader itself ignores — the FIB's
 * fEncrypted flag — and the piece-table documents the design's
 * caveat names are the files this reader was never given: such a
 * file costs itself, with the parser-gave-up cause.
 */
class DocExtractorTest extends TestCase {
	private const BUDGET = 2_097_152;

	private DocExtractor $extractor;

	protected function setUp(): void {
		$this->extractor = new DocExtractor();
	}

	public function testOwnsDoc(): void {
		$this->assertSame(['doc'], $this->extractor->owns());
	}

	public function testParagraphsComeOutOneLineEach(): void {
		$result = $this->extract(Fixtures::doc(['sortida al museu', 'confirmar la data']));

		$this->assertNull($result->cause);
		$this->assertSame("sortida al museu\nconfirmar la data\n", $result->text);
	}

	public function testAccentedTextSurvivesTheRoundTrip(): void {
		$result = $this->extract(Fixtures::doc(['sortida al museu de ciències']));

		$this->assertNull($result->cause);
		$this->assertSame("sortida al museu de ciències\n", $result->text);
	}

	public function testManyParagraphsSpreadOverPages(): void {
		// past 28 paragraphs the fixture's paragraph table takes a
		// second page; the reader has to find both
		$paragraphs = [];
		for ($i = 1; $i <= 65; $i++) {
			$paragraphs[] = "paràgraf número $i del museu";
		}

		$result = $this->extract(Fixtures::doc($paragraphs));

		$this->assertNull($result->cause);
		$this->assertSame('paràgraf número 1 del museu' . "\n" . 'paràgraf número 65 del museu', $this->firstAndLastLine($result->text ?? ''));
	}

	public function testAnEmptyDocumentExtractsToEmptyText(): void {
		$result = $this->extract(Fixtures::doc([]));

		$this->assertNull($result->cause);
		$this->assertSame('', $result->text);
	}

	public function testTheBudgetCutsAtTheNextWordBoundary(): void {
		$result = $this->extractor->extract(
			Fixtures::stream(Fixtures::doc(['museu', 'confirmar la data amb escola'])),
			'doc',
			4,
		);

		$this->assertSame(ExtractionCause::BudgetCut, $result->cause);
		$this->assertSame('muse', $result->text);
	}

	public function testTheFibEncryptedFlagIsTheEncryptedCause(): void {
		$result = $this->extract(Fixtures::docEncryptedFlag(['museu']));

		$this->assertSame(ExtractionCause::Encrypted, $result->cause);
		$this->assertNull($result->text);
		$this->assertStringContainsString('encrypted', $result->message);
	}

	public function testBytesThatAreNotACompoundFileAreRefused(): void {
		$result = $this->extract('no és cap fitxer de Word, és text');

		$this->assertSame(ExtractionCause::ParserGaveUp, $result->cause);
		$this->assertStringContainsString('no compound-file signature', $result->message);
	}

	public function testACompoundFileWithoutAWordDocumentStreamIsRefused(): void {
		// a valid Excel compound container: the gate opens it, and the
		// refusal names what a document needs
		$result = $this->extract(Fixtures::xls(['museu']));

		$this->assertSame(ExtractionCause::ParserGaveUp, $result->cause);
		$this->assertStringContainsString('no WordDocument stream', $result->message);
	}

	public function testAWordDocumentStreamWithoutTheFileInformationBlockIsRefused(): void {
		$result = $this->extract(self::docWithBrokenMagic());

		$this->assertSame(ExtractionCause::ParserGaveUp, $result->cause);
		$this->assertStringContainsString('file information block', $result->message);
	}

	public function testUpperCaseExtensionIsOwned(): void {
		$result = $this->extractor->extract(
			Fixtures::stream(Fixtures::doc(['museu'])),
			'DOC',
			self::BUDGET,
		);

		$this->assertNull($result->cause);
		$this->assertSame("museu\n", $result->text);
	}

	public function testTheVendorImageSpoolIsSweptAfterExtraction(): void {
		// a file predating the extraction stands in for a concurrent
		// worker's spool: the sweep must leave it alone
		$foreign = tempnam(sys_get_temp_dir(), 'PHPWord_MsDoc');

		$extractor = new DocExtractor(
			new class() extends MsDoc {
				// the stand-in reproduces the vendored behaviour the
				// sweep exists for: every inline image is spilled to a
				// tempnam pair the reader never unlinks
				// (MsDoc.php:2195-2197)
				public function load($filename) {
					$base = tempnam(sys_get_temp_dir(), 'PHPWord_MsDoc');
					file_put_contents($base . '.jpg', 'jpeg bytes');

					$phpWord = new PhpWord();
					$phpWord->addSection()->addText('sortida al museu');

					return $phpWord;
				}
			},
		);

		ob_start();
		try {
			$result = $extractor->extract(Fixtures::stream(Fixtures::doc(['sortida al museu'])), 'doc', self::BUDGET);
		} finally {
			ob_end_clean();
		}

		$this->assertNull($result->cause);
		$this->assertSame("sortida al museu\n", $result->text);
		$this->assertSame([$foreign], glob(sys_get_temp_dir() . '/PHPWord_MsDoc*'));

		@unlink($foreign);
	}

	/**
	 * The compound container of a real document with the FIB's magic
	 * broken: a stream the gate can read but no reader should parse.
	 */
	private static function docWithBrokenMagic(): string {
		$bytes = Fixtures::doc(['museu']);
		// the WordDocument stream starts at the second sector of the
		// fixture's deterministic layout: header, then this stream
		return substr_replace($bytes, "\0\0", 512 + 0, 2);
	}

	private function extract(string $bytes) {
		// the reader ships with print_r debug of its paragraph tables;
		// it is the library's noise, not the test's output
		ob_start();
		try {
			return $this->extractor->extract(Fixtures::stream($bytes), 'doc', self::BUDGET);
		} finally {
			ob_end_clean();
		}
	}

	private static function firstAndLastLine(string $text): string {
		$lines = explode("\n", trim($text));
		return $lines[0] . "\n" . $lines[count($lines) - 1];
	}
}
