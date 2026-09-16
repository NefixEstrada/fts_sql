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
use OCA\FtsSql\Extraction\OdfExtractor;
use OCA\FtsSql\Tests\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * The ODF extractor against DESIGN.md Milestone 2: one content.xml for all
 * three applications — writer paragraphs and headings, spreadsheet cells,
 * presentation frames — with annotations out, and the manifest checked
 * before anything so an encrypted document says so.
 */
class OdfExtractorTest extends TestCase {
	private const BUDGET = 2_097_152;

	private OdfExtractor $extractor;

	protected function setUp(): void {
		$this->extractor = new OdfExtractor();
	}

	public function testOwnsTheThreeOdfExtensions(): void {
		$this->assertSame(['odt', 'ods', 'odp'], $this->extractor->owns());
	}

	public function testAnOdtComesOutAsParagraphsAndHeadings(): void {
		$odt = Fixtures::odf(
			'<text:h>Sortida al museu</text:h>'
			. '<text:p>primera <text:span>línia</text:span></text:p>'
			. '<text:p>amb<text:tab/>tab i<text:line-break/>salt</text:p>'
			. '<text:p>dos<text:s/>espais</text:p>'
			. '<office:annotation><text:p>nota interna</text:p></office:annotation>'
			. '<text:p>final</text:p>',
		);

		$result = $this->extract($odt, 'odt');

		$this->assertNull($result->cause);
		$this->assertSame(
			"Sortida al museu\nprimera línia\namb\ttab i\nsalt\ndos espais\nfinal\n",
			$result->text,
		);
	}

	public function testAnOdsComesOutAsCells(): void {
		$ods = Fixtures::odf(
			'<table:table table:name="Full1"><table:table-row>'
			. '<table:table-cell><text:p>museu de ciències</text:p></table:table-cell>'
			. '<table:table-cell><text:p>segona cel·la</text:p></table:table-cell>'
			. '</table:table-row></table:table>',
		);

		$result = $this->extract($ods, 'ods');

		$this->assertNull($result->cause);
		$this->assertSame("museu de ciències\nsegona cel·la\n", $result->text);
	}

	public function testAnOdpComesOutAsFrames(): void {
		$odp = Fixtures::odf(
			'<draw:frame><draw:text-box><text:p>portada del museu</text:p></draw:text-box></draw:frame>',
		);

		$result = $this->extract($odp, 'odp');

		$this->assertNull($result->cause);
		$this->assertSame("portada del museu\n", $result->text);
	}

	public function testAManifestDeclaringEncryptionIsEncrypted(): void {
		$result = $this->extract(Fixtures::odfEncrypted(), 'odt');

		$this->assertSame(ExtractionCause::Encrypted, $result->cause);
		$this->assertNull($result->text);
		$this->assertStringContainsString('encrypted', $result->message);
	}

	public function testAContainerWithoutContentXmlGivesUp(): void {
		$odt = Fixtures::zip(['mimetype' => 'application/vnd.oasis.opendocument.text']);

		$result = $this->extract($odt, 'odt');

		$this->assertSame(ExtractionCause::ParserGaveUp, $result->cause);
		$this->assertStringContainsString('no content.xml', $result->message);
	}

	public function testGarbageInsteadOfAContainerGivesUp(): void {
		$result = $this->extract('les dades d-un ODF sense zip', 'odt');

		$this->assertSame(ExtractionCause::ParserGaveUp, $result->cause);
		$this->assertNull($result->text);
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
