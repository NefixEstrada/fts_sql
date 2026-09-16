<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Unit\Extraction;

use OCA\FtsSql\Extraction\ExtractionCause;
use OCA\FtsSql\Extraction\PptExtractor;
use OCA\FtsSql\Tests\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * The PowerPoint 97 extractor against DESIGN.md's Milestone 4: .ppt
 * found by its body text through the bundled, scoped PhpOffice reader,
 * with this app's own compound-file gate in front of it — encryption
 * reported as the Encrypted cause rather than the reader's bare
 * "feature not implemented", and the two runaway shapes the reader's
 * length-driven loops permit (an allocation chain in a circle, a
 * container claiming more bytes than the stream holds) each costing
 * only their document.
 */
class PptExtractorTest extends TestCase {
	private const BUDGET = 2_097_152;

	private PptExtractor $extractor;

	protected function setUp(): void {
		$this->extractor = new PptExtractor();
	}

	public function testOwnsPpt(): void {
		$this->assertSame(['ppt'], $this->extractor->owns());
	}

	public function testASlideComesOutParagraphByParagraph(): void {
		$result = $this->extract(Fixtures::ppt([
			['Sortida al Museu de Ciencies', 'Confirmar la data'],
		]));

		$this->assertNull($result->cause);
		$this->assertSame("Sortida al Museu de Ciencies\nConfirmar la data\n", $result->text);
	}

	/**
	 * The reader counts a style run's length in UTF-16 units but cuts
	 * the run out of the decoded UTF-8 by bytes: with accented text the
	 * paragraph boundary drifts into the next run's window and the
	 * paragraphs arrive concatenated. Real files keep their runs on
	 * ASCII boundaries; the fixture states the reader's actual
	 * behaviour with accented text rather than hiding it.
	 */
	public function testAccentedParagraphsArriveConcatenated(): void {
		$result = $this->extract(Fixtures::ppt([
			['Sortida al Museu de Ciències', 'Confirmar la data'],
		]));

		$this->assertNull($result->cause);
		$this->assertSame("Sortida al Museu de CiènciesConfirmar la data\n\n", $result->text);
	}

	public function testSlidesComeOutInOrder(): void {
		$result = $this->extract(Fixtures::ppt([
			['primera diapositiva'],
			['segona diapositiva', 'amb dues línies'],
			['tercera'],
		]));

		$this->assertNull($result->cause);
		$this->assertSame(
			"primera diapositiva\nsegona diapositiva\namb dues línies\ntercera\n",
			$result->text,
		);
	}

	public function testAnEmptyPresentationExtractsToEmptyText(): void {
		$result = $this->extract(Fixtures::ppt([]));

		$this->assertNull($result->cause);
		$this->assertSame('', $result->text);
	}

	public function testTheBudgetCutsAtTheNextWordBoundary(): void {
		$result = $this->extractor->extract(
			Fixtures::stream(Fixtures::ppt([['museu', 'confirmar la data amb escola']])),
			'ppt',
			10,
		);

		$this->assertSame(ExtractionCause::BudgetCut, $result->cause);
		$this->assertSame("museu\nconf", $result->text);
	}

	public function testTheEncryptionTokenIsTheEncryptedCause(): void {
		$result = $this->extract(Fixtures::pptEncryptedToken([['museu']]));

		$this->assertSame(ExtractionCause::Encrypted, $result->cause);
		$this->assertNull($result->text);
		$this->assertStringContainsString('encrypted', $result->message);
	}

	public function testAnOversizeDocumentIsRefusedBeforeTheReader(): void {
		$result = $this->extract(str_repeat(Fixtures::ppt([['museu']]), 6000));

		$this->assertSame(ExtractionCause::ParserGaveUp, $result->cause);
		$this->assertStringContainsString('past this extractor', $result->message);
	}

	public function testBytesThatAreNotACompoundFileAreRefused(): void {
		$result = $this->extract('no és cap fitxer de PowerPoint, és text');

		$this->assertSame(ExtractionCause::ParserGaveUp, $result->cause);
		$this->assertStringContainsString('no compound-file signature', $result->message);
	}

	public function testAnOleFileWithoutThePowerPointStreamsIsRefused(): void {
		// a compound container with the signature and nothing else: the
		// OLE magic a password-protected office wrapper carries
		$result = $this->extract(Fixtures::ole());

		$this->assertSame(ExtractionCause::ParserGaveUp, $result->cause);
	}

	public function testAnAllocationChainInACircleCostsOnlyTheDocument(): void {
		$result = $this->extract(Fixtures::pptFatCycle([['museu']]));

		$this->assertSame(ExtractionCause::ParserGaveUp, $result->cause);
		$this->assertStringContainsString('circle', $result->message);
	}

	public function testAContainerLyingAboutItsLengthIsRefusedBeforeTheReader(): void {
		$result = $this->extract(Fixtures::pptLyingSlideLength([['museu']]));

		$this->assertSame(ExtractionCause::ParserGaveUp, $result->cause);
		$this->assertStringContainsString('claims more bytes', $result->message);
	}

	public function testUpperCaseExtensionIsOwned(): void {
		$result = $this->extractor->extract(
			Fixtures::stream(Fixtures::ppt([['museu']])),
			'PPT',
			self::BUDGET,
		);

		$this->assertNull($result->cause);
		$this->assertSame("museu\n", $result->text);
	}

	private function extract(string $bytes) {
		return $this->extractor->extract(Fixtures::stream($bytes), 'ppt', self::BUDGET);
	}
}
