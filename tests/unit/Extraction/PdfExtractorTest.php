<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Unit\Extraction;

use OCA\FtsSql\Extraction\ExtractionCause;
use OCA\FtsSql\Extraction\PdfExtractor;
use OCA\FtsSql\Tests\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * The PDF extractor against DESIGN.md's Milestone 3: .pdf found by its
 * body text, page-bounded under the budget, with the reference
 * document's own shapes — classic and compressed cross-references,
 * ToUnicode CMaps over two-byte codes, and the standard security
 * handler with an empty user password — and the hostile posture the
 * design's Security section prescribes for PDFs: bombs, loops and
 * undecodable fonts each cost their piece, never the run.
 */
class PdfExtractorTest extends TestCase {
	private const BUDGET = 2_097_152;

	private PdfExtractor $extractor;

	protected function setUp(): void {
		$this->extractor = new PdfExtractor();
	}

	public function testOwnsPdf(): void {
		$this->assertSame(['pdf'], $this->extractor->owns());
	}

	public function testAClassicDocumentComesOutLineByLine(): void {
		$result = $this->extract(Fixtures::pdfText(['sortida al museu', 'segona línia amb é']));

		$this->assertNull($result->cause);
		$this->assertSame("sortida al museu\nsegona línia amb é\n", $result->text);
	}

	public function testFlateCompressedContentIsRead(): void {
		$content = "BT\n/F1 12 Tf\n(sortida comprimida) Tj\nET\n";
		$pdf = Fixtures::pdf([
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			'<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
			Fixtures::pdfStream('/Filter /FlateDecode', gzcompress($content, 6)),
			'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
		]);

		$result = $this->extract($pdf);

		$this->assertNull($result->cause);
		$this->assertSame('sortida comprimida', $result->text);
	}

	public function testAXrefStreamWithObjectStreamsIsRead(): void {
		$result = $this->extract(Fixtures::pdfCompressed(['museu comprimit', 'en un object stream']));

		$this->assertNull($result->cause);
		$this->assertSame("museu comprimit\nen un object stream\n", $result->text);
	}

	public function testToUnicodeCodesOverIdentityH(): void {
		$result = $this->extract(Fixtures::pdfToUnicodeText());

		$this->assertNull($result->cause);
		$this->assertSame("\u{00C0}\u{00E9}\nabc", $result->text);
	}

	public function testADifferencesArrayRemapsGlyphs(): void {
		$e = chr(0xE9);
		$content = "BT\n/F1 12 Tf\n($e) Tj\n(A) Tj\nET\n";
		$pdf = Fixtures::pdf([
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			'<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
			Fixtures::pdfStream('', $content),
			'<< /Type /Font /Subtype /Type1 /BaseFont /Custom'
			. ' /Encoding << /BaseEncoding /WinAnsiEncoding /Differences [65 /eacute] >> >>',
		]);

		$result = $this->extract($pdf);

		$this->assertNull($result->cause);
		// 0xE9 keeps its WinAnsi é; 0x41 is remapped to eacute by Differences
		$this->assertSame("\u{00E9}\u{00E9}", $result->text);
	}

	public function testTheStandardHandlerWithAnEmptyUserPasswordDecrypts(): void {
		$result = $this->extract(Fixtures::pdfEncryptedText(['xifrat amb contrasenya buida']));

		$this->assertNull($result->cause);
		$this->assertSame("xifrat amb contrasenya buida\n", $result->text);
	}

	public function testADocumentThatAsksForARealPasswordStaysEncrypted(): void {
		// the same shape, but /U built for a password this file does not say
		$result = $this->extract(Fixtures::pdfEncryptedText(['no'], 'contrasenya real'));

		$this->assertSame(ExtractionCause::Encrypted, $result->cause);
		$this->assertNull($result->text);
		$this->assertStringContainsString('encrypted', $result->message);
	}

	public function testTheBudgetCutsMidDocument(): void {
		$lines = [];
		for ($i = 1; $i <= 60; $i++) {
			$lines[] = "línia $i del museu";
		}
		$result = $this->extract(Fixtures::pdfText($lines), 40);

		$this->assertSame(ExtractionCause::BudgetCut, $result->cause);
		$this->assertLessThanOrEqual(40, strlen($result->text));
		$this->assertStringContainsString('línia 1', $result->text);
		$this->assertStringNotContainsString('línia 60', $result->text);
	}

	public function testAFormXObjectSTextIsFollowed(): void {
		$form = "BT\n/F1 12 Tf\n(text del form) Tj\nET\n";
		$content = "/Fx Do\nBT\n/F1 12 Tf\n(text de la pagina) Tj\nET\n";
		$pdf = Fixtures::pdf([
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			'<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 5 0 R >> /XObject << /Fx 6 0 R >> >> /Contents 4 0 R >>',
			Fixtures::pdfStream('', $content),
			'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
			Fixtures::pdfStream('/Type /XObject /Subtype /Form /BBox [0 0 100 100]', $form),
		]);

		$result = $this->extract($pdf);

		$this->assertNull($result->cause);
		$this->assertStringContainsString('text del form', $result->text);
		$this->assertStringContainsString('text de la pagina', $result->text);
	}

	public function testABombStreamCostsItsPageNotTheRun(): void {
		$bomb = gzcompress(str_repeat('A', 20971520), 9);
		$pages = [
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R 6 0 R] /Count 2 >>',
			'<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
			Fixtures::pdfStream('/Filter /FlateDecode', $bomb),
			'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
			'<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 5 0 R >> >> /Contents 7 0 R >>',
		];
		$pages[] = Fixtures::pdfStream('', "BT\n/F1 12 Tf\n(la pagina honesta) Tj\nET\n");
		$result = $this->extract(Fixtures::pdf($pages));

		$this->assertSame(ExtractionCause::ParserGaveUp, $result->cause);
		$this->assertStringContainsString('1 of 2 page(s) could not be read', $result->message);
		$this->assertSame('la pagina honesta', $result->text);
	}

	public function testAPageTreeThatPointsAtItselfCostsTheLookup(): void {
		$pdf = Fixtures::pdf([
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [2 0 R 3 0 R] /Count 2 >>',
			'<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
			Fixtures::pdfStream('', "BT\n/F1 12 Tf\n(museu) Tj\nET\n"),
			'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
		]);

		$result = $this->extract($pdf);

		// the loop is cut by the visited set; the real page still reads
		$this->assertSame('museu', $result->text);
	}

	public function testASymbolFontWithoutAMapReportsItsUndecodableRuns(): void {
		$pdf = Fixtures::pdf([
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			'<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
			Fixtures::pdfStream('', "BT\n/F1 12 Tf\n(ABC) Tj\nET\n"),
			'<< /Type /Font /Subtype /Type1 /BaseFont /Symbol >>',
		]);

		$result = $this->extract($pdf);

		$this->assertSame(ExtractionCause::ParserGaveUp, $result->cause);
		$this->assertNull($result->text);
		$this->assertStringContainsString('fonts with no usable encoding', $result->message);
	}

	public function testABlankPageIsAnEmptyExtractionNotAFailure(): void {
		$pdf = Fixtures::pdf([
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			'<< /Type /Page /Parent 2 0 R >>',
		]);

		$result = $this->extract($pdf);

		$this->assertNull($result->cause);
		$this->assertSame('', $result->text);
	}

	public function testNotAPdfIsRefusedByName(): void {
		$result = $this->extract('a zip or an odt with a .pdf name');

		$this->assertSame(ExtractionCause::ParserGaveUp, $result->cause);
		$this->assertNull($result->text);
		$this->assertStringContainsString('not a pdf', $result->message);
	}

	public function testACataloglessStubReportsIt(): void {
		$result = $this->extract("%PDF-1.7\njunk\n%%EOF\n");

		$this->assertSame(ExtractionCause::ParserGaveUp, $result->cause);
		$this->assertStringContainsString('no catalog', $result->message);
	}

	public function testABrokenStartxrefStillReadsByScan(): void {
		$lines = ['recuperat per escaneig'];
		$pdf = substr(Fixtures::pdfText($lines), 0, -30) . "\n%%EOF\n"; // startxref cut off

		$result = $this->extract($pdf);

		$this->assertNull($result->cause);
		$this->assertSame("recuperat per escaneig\n", $result->text);
	}

	public function testTjRunsAreConcatenatedWithoutInventedSpaces(): void {
		$content = "BT\n/F1 12 Tf\n[(sor)5(tida)5( al m)5(useu)] TJ\nET\n";
		$pdf = Fixtures::pdf([
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			'<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
			Fixtures::pdfStream('', $content),
			'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
		]);

		$result = $this->extract($pdf);

		$this->assertSame('sortida al museu', $result->text);
	}

	private function extract(string $bytes, ?int $budget = null): \OCA\FtsSql\Extraction\ExtractionResult {
		$stream = fopen('php://temp', 'w+b');
		fwrite($stream, $bytes);
		rewind($stream);

		$result = $this->extractor->extract($stream, 'pdf', $budget ?? self::BUDGET);
		fclose($stream);
		return $result;
	}
}
