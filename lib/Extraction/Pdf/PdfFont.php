<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Extraction\Pdf;

/**
 * One font resource as a decoder: PDF bytes under that font to UTF-8
 * text. The /ToUnicode CMap wins when the font carries one — it is
 * what every modern generator writes, even over the base fourteen —
 * then the predefined encodings with /Differences applied, and a
 * Type0 font's Identity-H two-byte codes on top of either. A font
 * none of those decode (a named CMap, Symbol without a map) decodes
 * to nothing: its runs are counted, not guessed at.
 */
final class PdfFont {
	private const CMAP_CAP = 1048576;
	private const MAX_MAPPINGS = 65536;

	/**
	 * @param array<int, string>|null $toUnicode code to UTF-8 from /ToUnicode
	 */
	private function __construct(
		private readonly ?array $toUnicode,
		private readonly int $codeBytes,
		private readonly bool $decodable,
		private readonly array $table,
	) {
	}

	/**
	 * @param mixed $resource the font: a reference or an inline dict
	 */
	public static function build(mixed $resource, PdfDocument $doc): self {
		$dict = $resource instanceof PdfRef ? $doc->getDictionary($resource) : $resource;
		if (!is_array($dict)) {
			return new self(null, 1, false, []);
		}

		$subtype = $dict['Subtype'] ?? null;
		$isComposite = $subtype instanceof PdfName && $subtype->name === 'Type0';
		$codeBytes = 1;
		$decodable = true;
		if ($isComposite) {
			$encoding = $dict['Encoding'] ?? null;
			$name = $encoding instanceof PdfName ? $encoding->name : '';
			if ($name === 'Identity-H' || $name === 'Identity-V') {
				$codeBytes = 2;
			} else {
				$decodable = false; // a named CMap we do not carry
			}
		} elseif (!isset($dict['ToUnicode'])) {
			$baseFont = $dict['BaseFont'] ?? null;
			$encoding = $dict['Encoding'] ?? null;
			$baseName = $baseFont instanceof PdfName ? $baseFont->name : '';
			if (($encoding === null || $encoding instanceof PdfName && $encoding->name === 'null')
				&& (str_contains($baseName, 'Symbol') || str_contains($baseName, 'ZapfDingbats'))) {
				$decodable = false; // glyph codes with no textual mapping
			}
		}

		return new self(
			self::toUnicode($dict, $doc),
			$codeBytes,
			$decodable,
			$decodable ? self::table($dict, $doc) : [],
		);
	}

	/**
	 * The UTF-8 text of one string shown in this font; '' when nothing
	 * of it is decodable — the caller counts that as a skipped run.
	 */
	public function decode(string $bytes): string {
		if (!$this->decodable) {
			return '';
		}

		$out = '';
		$len = strlen($bytes);
		for ($at = 0; $at + $this->codeBytes <= $len; $at += $this->codeBytes) {
			$code = $this->codeBytes === 2
				? (ord($bytes[$at]) << 8) | ord($bytes[$at + 1])
				: ord($bytes[$at]);
			if (isset($this->toUnicode[$code])) {
				$out .= $this->toUnicode[$code];
				continue;
			}
			if ($this->codeBytes === 1) {
				$out .= PdfEncodings::decode($code, $this->table);
			}
		}
		return $out;
	}

	/**
	 * @return array<int, string>|null
	 */
	private static function toUnicode(array $dict, PdfDocument $doc): ?array {
		$ref = $dict['ToUnicode'] ?? null;
		if (!$ref instanceof PdfRef) {
			return null;
		}
		$object = $doc->getObject($ref->object);
		if ($object === null || $object->stream === null || !is_array($object->value)) {
			return null;
		}

		$cmap = $doc->decodeStreamData($object->value, $object->stream, self::CMAP_CAP, 'a ToUnicode CMap', $ref);
		$map = [];

		// bfchar sections: <code> <dst> pairs
		if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $sections, PREG_SET_ORDER) > 0) {
			foreach ($sections as $section) {
				preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>/', $section[1], $pairs, PREG_SET_ORDER);
				foreach ($pairs as $pair) {
					if (count($map) >= self::MAX_MAPPINGS) {
						return $map;
					}
					$map[self::hexToInt($pair[1])] = self::utf16Hex($pair[2]);
				}
			}
		}

		// bfrange sections: <lo> <hi> <start dst> or <lo> <hi> [dsts]
		if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $sections, PREG_SET_ORDER) > 0) {
			foreach ($sections as $section) {
				preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<([0-9A-Fa-f]*)>|\[(.*?)\])/s', $section[1], $ranges, PREG_SET_ORDER);
				foreach ($ranges as $range) {
					$lo = self::hexToInt($range[1]);
					$hi = self::hexToInt($range[2]);
					$startHex = isset($range[4]) ? $range[4] : null;
					if ($startHex !== null && $startHex !== '') {
						// one start value; the mapping increments per code
						$base = self::hexToInt($startHex);
						for ($code = $lo; $code <= $hi && count($map) < self::MAX_MAPPINGS; $code++) {
							$map[$code] = self::codepoint($base + ($code - $lo));
						}
					} elseif (isset($range[5]) && $range[5] !== '') {
						preg_match_all('/<([0-9A-Fa-f]*)>/', $range[5], $dsts, PREG_SET_ORDER);
						$i = 0;
						for ($code = $lo; $code <= $hi && $i < count($dsts) && count($map) < self::MAX_MAPPINGS; $code++, $i++) {
							$map[$code] = self::utf16Hex($dsts[$i][1]);
						}
					}
				}
			}
		}

		return $map;
	}

	private static function hexToInt(string $hex): int {
		return (int)hexdec($hex);
	}

	private static function utf16Hex(string $hex): string {
		if ($hex === '') {
			return '';
		}
		if (strlen($hex) % 2 === 1) {
			$hex .= '0';
		}
		$decoded = mb_convert_encoding(pack('H*', $hex), 'UTF-8', 'UTF-16BE');
		return $decoded === false ? '' : $decoded;
	}

	private static function codepoint(int $code): string {
		$decoded = mb_convert_encoding(pack('N', $code), 'UTF-8', 'UTF-32BE');
		return $decoded === false ? '' : $decoded;
	}

	/**
	 * The simple-font table: the named or inherited base encoding with
	 * /Differences applied over it.
	 *
	 * @return array<int, string>
	 */
	private static function table(array $dict, PdfDocument $doc): array {
		$encoding = $doc->resolve($dict['Encoding'] ?? null);
		if ($encoding instanceof PdfName) {
			return PdfEncodings::table($encoding->name);
		}
		if (!is_array($encoding)) {
			return PdfEncodings::table(null);
		}

		$base = $encoding['BaseEncoding'] ?? null;
		$table = PdfEncodings::table($base instanceof PdfName ? $base->name : null);

		$differences = $doc->resolve($encoding['Differences'] ?? null);
		if (is_array($differences)) {
			$code = 0;
			foreach ($differences as $entry) {
				if (is_int($entry)) {
					$code = $entry;
					continue;
				}
				if ($entry instanceof PdfName) {
					$char = PdfEncodings::byName($entry->name);
					if ($char !== '') {
						$table[$code] = $char;
					} else {
						unset($table[$code]);
					}
				}
				$code++;
			}
		}
		return $table;
	}
}
