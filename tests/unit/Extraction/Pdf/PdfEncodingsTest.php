<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Unit\Extraction\Pdf;

use OCA\FtsSql\Extraction\Pdf\PdfEncodings;
use PHPUnit\Framework\TestCase;

/**
 * The predefined encoding tables against the spec's known values, and
 * WinAnsi re-derived from mbstring's cp1252 mapping for every byte —
 * the transcription guard: a mistyped entry cannot survive the whole
 * table being cross-checked against the platform codec.
 */
class PdfEncodingsTest extends TestCase {
	public function testWinAnsiMatchesMbstringSCp1252ByteForByte(): void {
		$table = PdfEncodings::table('WinAnsiEncoding');
		foreach (range(0x20, 0xFF) as $byte) {
			// the five slots cp1252 leaves undefined, and DEL: control
			// characters decode to nothing whatever the codec says
			if (in_array($byte, [0x7F, 0x81, 0x8D, 0x8F, 0x90, 0x9D], true)) {
				$this->assertSame('', PdfEncodings::decode($byte, $table), sprintf('byte 0x%02X', $byte));
				continue;
			}
			$expected = mb_convert_encoding(chr($byte), 'UTF-8', 'Windows-1252');
			$this->assertSame($expected, PdfEncodings::decode($byte, $table), sprintf('byte 0x%02X', $byte));
		}
	}

	public function testTheUndefinedWinAnsiSlotsDecodeToNothing(): void {
		$table = PdfEncodings::table('WinAnsiEncoding');
		foreach ([0x81, 0x8D, 0x8F, 0x90, 0x9D] as $byte) {
			$this->assertSame('', PdfEncodings::decode($byte, $table), sprintf('byte 0x%02X', $byte));
		}
	}

	public function testStandardEncodingSwapsTheTwoQuoteSlots(): void {
		$table = PdfEncodings::table(null);
		$this->assertSame("\u{2019}", PdfEncodings::decode(0x27, $table));
		$this->assertSame("\u{2018}", PdfEncodings::decode(0x60, $table));
		$this->assertSame("\u{00C6}", PdfEncodings::decode(0xE1, $table));
		$this->assertSame("\u{FB01}", PdfEncodings::decode(0xAE, $table));
	}

	public function testMacRomanDiffersFromWinAnsiWhereItMust(): void {
		$table = PdfEncodings::table('MacRomanEncoding');
		$this->assertSame("\u{00C4}", PdfEncodings::decode(0x80, $table));
		$this->assertSame("\u{00E4}", PdfEncodings::decode(0x8A, $table));
		$this->assertSame("\u{20AC}", PdfEncodings::decode(0xDB, $table));
		$this->assertSame("\u{00E9}", PdfEncodings::decode(0x8E, $table));
	}

	public function testGlyphNamesResolveThroughTheReverseTables(): void {
		$this->assertSame("\u{2019}", PdfEncodings::byName('quoteright'));
		$this->assertSame("\u{FB01}", PdfEncodings::byName('fi'));
		$this->assertSame("\u{20AC}", PdfEncodings::byName('Euro'));
		$this->assertSame('', PdfEncodings::byName('notarealglyphname'));
	}

	public function testAnUnknownEncodingNameFallsBackToStandard(): void {
		$this->assertSame(
			PdfEncodings::decode(0x27, PdfEncodings::table(null)),
			PdfEncodings::decode(0x27, PdfEncodings::table('SomethingElseEncoding')),
		);
	}

	public function testControlBytesDecodeToPrintableAsciiOnly(): void {
		foreach ([0x00, 0x0A, 0x1F] as $byte) {
			$this->assertSame('', PdfEncodings::decode($byte, PdfEncodings::table('WinAnsiEncoding')));
		}
		$this->assertSame('A', PdfEncodings::decode(0x41, PdfEncodings::table('WinAnsiEncoding')));
	}
}
