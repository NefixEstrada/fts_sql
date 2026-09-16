<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Unit\Extraction\Pdf;

use OCA\FtsSql\Extraction\ExtractionAbort;
use OCA\FtsSql\Extraction\Pdf\PdfFilters;
use PHPUnit\Framework\TestCase;

class PdfFiltersTest extends TestCase {
	public function testFlateRoundTripsAZlibStream(): void {
		$compressed = gzcompress(str_repeat('sortida al museu ', 500), 6);

		$this->assertSame(str_repeat('sortida al museu ', 500), PdfFilters::flate($compressed, 1048576, 'test'));
	}

	public function testFlateAbortsOverItsCapInsteadOfMaterialising(): void {
		$compressed = gzcompress(str_repeat('m', 1048576), 9);

		$this->expectException(ExtractionAbort::class);
		$this->expectExceptionMessage('expands past its 1,024-byte read cap');
		PdfFilters::flate($compressed, 1024, 'test');
	}

	public function testFlateRefusesWhatIsNotDeflate(): void {
		$this->expectException(ExtractionAbort::class);
		PdfFilters::flate('not a deflate stream at all', 1048576, 'test');
	}

	public function testAsciiHexSkipsJunkAndPads(): void {
		$this->assertSame('Hello', PdfFilters::asciiHex('48656C6C6F> trailing junk'));
		$this->assertSame("\x40", PdfFilters::asciiHex('4 0'));
	}

	public function testAscii85Vectors(): void {
		$this->assertSame('Manis.', PdfFilters::ascii85('9jqpRF"R~>'));
		$this->assertSame('Man ', PdfFilters::ascii85('9jqo^'));
		$this->assertSame("\0\0\0\0", PdfFilters::ascii85('z'));
		$this->assertSame('a', PdfFilters::ascii85('@/'));
		$this->assertSame('abc', PdfFilters::ascii85('@:E^'));
	}

	public function testTheAveragePredictorUndoesRowDifferences(): void {
		// one row of four bytes, predictor 3 (Average): each byte was
		// stored as value - floor((0 + left) / 2), left starting at 0
		$expected = "\x10\x20\x30\x40";
		$stored = '';
		$left = 0;
		foreach (str_split($expected) as $byte) {
			$stored .= chr((ord($byte) - (int)(floor($left / 2))) & 0xFF);
			$left = ord($byte);
		}
		$out = PdfFilters::pngPredictor("\x03" . $stored, 1, 8, 4);
		$this->assertSame($expected, $out);
	}

	public function testThePaethPredictorUndoesRowDifferences(): void {
		$previous = "\x05\x05\x05\x05";
		$expected = "\x10\x20\x30\x40";
		$stored = '';
		foreach (str_split($expected) as $i => $byte) {
			$a = $i > 0 ? ord($expected[$i - 1]) : 0;
			$b = ord($previous[$i]);
			$c = $i > 0 ? ord($previous[$i - 1]) : 0;
			$prediction = $a + $b - $c;
			$pa = abs($prediction - $a);
			$pb = abs($prediction - $b);
			$pc = abs($prediction - $c);
			$nearest = ($pa <= $pb && $pa <= $pc) ? $a : (($pb <= $pc) ? $b : $c);
			$stored .= chr((ord($byte) - $nearest) & 0xFF);
		}
		$out = PdfFilters::pngPredictor("\x00" . $previous . "\x04" . $stored, 1, 8, 4);
		$this->assertSame($previous . $expected, $out);
	}

	public function testTheUpPredictorSumsRows(): void {
		$up = PdfFilters::pngPredictor("\x02\x01\x01\x01\x01" . "\x02\x01\x01\x01\x01", 1, 8, 4);
		$this->assertSame("\x01\x01\x01\x01" . "\x02\x02\x02\x02", $up);
	}

	public function testAnUnmarkedRowPassesThrough(): void {
		$this->assertSame('ABCD', PdfFilters::pngPredictor("\x00ABCD", 1, 8, 4));
	}
}
