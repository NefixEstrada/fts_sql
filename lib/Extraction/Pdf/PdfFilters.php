<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Extraction\Pdf;

use OCA\FtsSql\Extraction\ExtractionAbort;
use OCA\FtsSql\Extraction\ExtractionCause;

/**
 * The stream filters a text-bearing PDF needs (ISO 32000-1, 7.4):
 * FlateDecode with an incremental, capped inflate — the same posture
 * as the zip container's, so a lying /Length or a bomb stream is
 * abandoned mid-inflate instead of materialising — and the two
 * trivial ASCII packings. PNG predictors (12 and 13) exist here
 * because cross-reference streams carry them. LZWDecode is refused by
 * name: it is patent-dead, near nothing writes it, and refusing it
 * costs one page, never the run.
 *
 * Every decode is bounded by the caller's cap, and the caller is the
 * page loop — an abort marks the page unread and the walk continues.
 */
final class PdfFilters {
	public const CHUNK = 65536;

	private function __construct() {
	}

	/**
	 * @throws ExtractionAbort over the cap, or on data that is not a
	 *                         deflate stream at all
	 */
	public static function flate(string $data, int $cap, string $what): string {
		$context = inflate_init(ZLIB_ENCODING_DEFLATE);
		if ($context === false) {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'zlib inflate is not available');
		}

		$out = '';
		$len = strlen($data);
		for ($at = 0; $at < $len; $at += self::CHUNK) {
			// a data error is the diagnosis itself; the warning would
			// only be noise beside the abort it precedes
			$piece = @inflate_add($context, substr($data, $at, self::CHUNK));
			if ($piece === false) {
				throw new ExtractionAbort(ExtractionCause::ParserGaveUp, "$what is not a deflate stream");
			}
			$out .= $piece;
			if (strlen($out) > $cap) {
				throw new ExtractionAbort(
					ExtractionCause::ParserGaveUp,
					sprintf('%s expands past its %s-byte read cap', $what, number_format($cap)),
				);
			}
		}

		$tail = @inflate_add($context, '', ZLIB_FINISH);
		if (is_string($tail) && $tail !== '') {
			$out .= $tail;
			if (strlen($out) > $cap) {
				throw new ExtractionAbort(
					ExtractionCause::ParserGaveUp,
					sprintf('%s expands past its %s-byte read cap', $what, number_format($cap)),
				);
			}
		}
		return $out;
	}

	public static function asciiHex(string $data): string {
		$hex = '';
		$len = strlen($data);
		for ($i = 0; $i < $len; $i++) {
			$byte = $data[$i];
			if ($byte === '>') {
				break;
			}
			if (ctype_xdigit($byte)) {
				$hex .= $byte;
			}
		}
		if (strlen($hex) % 2 === 1) {
			$hex .= '0';
		}
		return pack('H*', $hex);
	}

	public static function ascii85(string $data): string {
		$out = '';
		$group = [];
		$len = strlen($data);
		for ($i = 0; $i < $len; $i++) {
			$byte = $data[$i];
			if ($byte === ' ' || $byte === "\0" || $byte === "\t" || $byte === "\n" || $byte === "\f" || $byte === "\r") {
				continue;
			}
			if ($byte === '~') {
				break; // the EOD marker; anything after is junk
			}
			if ($byte === 'z' && $group === []) {
				$out .= "\0\0\0\0";
				continue;
			}
			if ($byte === 'v' && $group === [] && $i + 1 < $len && $data[$i + 1] === '~') {
				$out .= pack('N', 0xFFFFFFFF);
				$i++;
				continue;
			}
			if ($byte < '!' || $byte > 'u') {
				continue;
			}
			$group[] = ord($byte) - 33;
			if (count($group) === 5) {
				$out .= self::ascii85Group($group, 5);
				$group = [];
			}
		}
		if ($group !== []) {
			$out .= self::ascii85Group($group, count($group));
		}
		return $out;
	}

	/**
	 * @param list<int> $group five (or a final partial group of) digits
	 */
	private static function ascii85Group(array $group, int $count): string {
		if ($count < 2) {
			return ''; // a final group of one digit codes nothing
		}
		$value = 0;
		for ($i = 0; $i < 5; $i++) {
			$value = $value * 85 + ($group[$i] ?? 84);
		}
		return substr(pack('N', $value), 0, $count - 1);
	}

	/**
	 * Undo the PNG predictors a cross-reference stream may carry
	 * (ISO 32000-1, 7.4.4.3): rows are Columns*Colors*BitsPerComponent/8
	 * bytes, each preceded by one filter-type byte.
	 */
	public static function pngPredictor(string $data, int $colors, int $bpc, int $columns): string {
		$bpp = max(1, (int)(($colors * $bpc) / 8));
		$rowLength = (int)ceil($colors * $bpc * $columns / 8);
		$len = strlen($data);
		if ($rowLength < 1) {
			return $data;
		}
		// A row consumes 1 + rowLength bytes of stream, so rows longer
		// than the data hold nothing — and a /DecodeParms lie (the
		// three integers are attacker bytes) must be answered before
		// the zero row below repeats itself into a gigabyte allocation.
		if ($rowLength > $len) {
			return '';
		}

		$out = '';
		$previous = str_repeat("\0", $rowLength);
		$at = 0;
		while ($at + 1 + $rowLength <= $len) {
			$type = ord($data[$at]);
			$row = substr($data, $at + 1, $rowLength);
			$at += 1 + $rowLength;

			if ($type === 1) {
				for ($i = $bpp; $i < $rowLength; $i++) {
					$row[$i] = chr((ord($row[$i]) + ord($row[$i - $bpp])) & 0xFF);
				}
			} elseif ($type === 2) {
				for ($i = 0; $i < $rowLength; $i++) {
					$row[$i] = chr((ord($row[$i]) + ord($previous[$i])) & 0xFF);
				}
			} elseif ($type === 3) {
				for ($i = 0; $i < $rowLength; $i++) {
					$left = $i >= $bpp ? ord($row[$i - $bpp]) : 0;
					$row[$i] = chr((ord($row[$i]) + (int)floor(($left + ord($previous[$i])) / 2)) & 0xFF);
				}
			} elseif ($type === 4) {
				for ($i = 0; $i < $rowLength; $i++) {
					$a = $i >= $bpp ? ord($row[$i - $bpp]) : 0;
					$b = ord($previous[$i]);
					$c = $i >= $bpp ? ord($previous[$i - $bpp]) : 0;
					$p = $a + $b - $c;
					$pa = abs($p - $a);
					$pb = abs($p - $b);
					$pc = abs($p - $c);
					$nearest = ($pa <= $pb && $pa <= $pc) ? $a : (($pb <= $pc) ? $b : $c);
					$row[$i] = chr((ord($row[$i]) + $nearest) & 0xFF);
				}
			}
			$out .= $row;
			$previous = $row;
		}
		return $out;
	}
}
