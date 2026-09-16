<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Extraction\Pdf;

/**
 * The three predefined simple-font encodings of the PDF specification
 * (ISO 32000-1, annex D): code to UTF-8, with the ASCII range implied
 * and only the deviations carried — StandardEncoding's quoteright and
 * quoteleft over 0x27 and 0x60 among them. The tables were generated
 * from pdf.js's encoding tables (Apache-2.0) cross-checked against the
 * platform codecs; the unit test re-derives WinAnsi from mbstring so a
 * transcription error cannot survive.
 *
 * Differences arrays arrive as glyph names, so the reverse of each
 * table is kept as well: names common to the predefined encodings
 * cover what real documents use; anything else decodes to nothing
 * rather than to a guess.
 */
final class PdfEncodings {
	private const STANDARD = [
		0x27 => "\u{2019}", 0x60 => "\u{2018}", 0xA1 => "\u{00A1}", 0xA2 => "\u{00A2}", 0xA3 => "\u{00A3}",
		0xA4 => "\u{2044}", 0xA5 => "\u{00A5}", 0xA6 => "\u{0192}", 0xA7 => "\u{00A7}", 0xA8 => "\u{00A4}",
		0xA9 => "\u{0027}", 0xAA => "\u{201C}", 0xAB => "\u{00AB}", 0xAC => "\u{2039}", 0xAD => "\u{203A}",
		0xAE => "\u{FB01}", 0xAF => "\u{FB02}", 0xB1 => "\u{2013}", 0xB2 => "\u{2020}", 0xB3 => "\u{2021}",
		0xB4 => "\u{00B7}", 0xB6 => "\u{00B6}", 0xB7 => "\u{2022}", 0xB8 => "\u{201A}", 0xB9 => "\u{201E}",
		0xBA => "\u{201D}", 0xBB => "\u{00BB}", 0xBC => "\u{2026}", 0xBD => "\u{2030}", 0xBF => "\u{00BF}",
		0xC1 => "\u{0060}", 0xC2 => "\u{00B4}", 0xC3 => "\u{02C6}", 0xC4 => "\u{02DC}", 0xC5 => "\u{00AF}",
		0xC6 => "\u{02D8}", 0xC7 => "\u{02D9}", 0xC8 => "\u{00A8}", 0xCA => "\u{02DA}", 0xCB => "\u{00B8}",
		0xCD => "\u{02DD}", 0xCE => "\u{02DB}", 0xCF => "\u{02C7}", 0xD0 => "\u{2014}", 0xE1 => "\u{00C6}",
		0xE3 => "\u{00AA}", 0xE8 => "\u{0141}", 0xE9 => "\u{00D8}", 0xEA => "\u{0152}", 0xEB => "\u{00BA}",
		0xF1 => "\u{00E6}", 0xF5 => "\u{0131}", 0xF8 => "\u{0142}", 0xF9 => "\u{00F8}", 0xFA => "\u{0153}",
		0xFB => "\u{00DF}",
	];

	private const WIN_ANSI = [
		0x80 => "\u{20AC}", 0x82 => "\u{201A}",
		0x83 => "\u{0192}", 0x84 => "\u{201E}", 0x85 => "\u{2026}", 0x86 => "\u{2020}", 0x87 => "\u{2021}",
		0x88 => "\u{02C6}", 0x89 => "\u{2030}", 0x8A => "\u{0160}", 0x8B => "\u{2039}", 0x8C => "\u{0152}",
		0x8E => "\u{017D}", 0x91 => "\u{2018}", 0x92 => "\u{2019}", 0x93 => "\u{201C}", 0x94 => "\u{201D}",
		0x95 => "\u{2022}", 0x96 => "\u{2013}", 0x97 => "\u{2014}", 0x98 => "\u{02DC}", 0x99 => "\u{2122}",
		0x9A => "\u{0161}", 0x9B => "\u{203A}", 0x9C => "\u{0153}", 0x9E => "\u{017E}", 0x9F => "\u{0178}",
		0xA0 => "\u{00A0}", 0xA1 => "\u{00A1}", 0xA2 => "\u{00A2}", 0xA3 => "\u{00A3}", 0xA4 => "\u{00A4}",
		0xA5 => "\u{00A5}", 0xA6 => "\u{00A6}", 0xA7 => "\u{00A7}", 0xA8 => "\u{00A8}", 0xA9 => "\u{00A9}",
		0xAA => "\u{00AA}", 0xAB => "\u{00AB}", 0xAC => "\u{00AC}", 0xAD => "\u{00AD}", 0xAE => "\u{00AE}",
		0xAF => "\u{00AF}", 0xB0 => "\u{00B0}", 0xB1 => "\u{00B1}", 0xB2 => "\u{00B2}", 0xB3 => "\u{00B3}",
		0xB4 => "\u{00B4}", 0xB5 => "\u{00B5}", 0xB6 => "\u{00B6}", 0xB7 => "\u{00B7}", 0xB8 => "\u{00B8}",
		0xB9 => "\u{00B9}", 0xBA => "\u{00BA}", 0xBB => "\u{00BB}", 0xBC => "\u{00BC}", 0xBD => "\u{00BD}",
		0xBE => "\u{00BE}", 0xBF => "\u{00BF}", 0xC0 => "\u{00C0}", 0xC1 => "\u{00C1}", 0xC2 => "\u{00C2}",
		0xC3 => "\u{00C3}", 0xC4 => "\u{00C4}", 0xC5 => "\u{00C5}", 0xC6 => "\u{00C6}", 0xC7 => "\u{00C7}",
		0xC8 => "\u{00C8}", 0xC9 => "\u{00C9}", 0xCA => "\u{00CA}", 0xCB => "\u{00CB}", 0xCC => "\u{00CC}",
		0xCD => "\u{00CD}", 0xCE => "\u{00CE}", 0xCF => "\u{00CF}", 0xD0 => "\u{00D0}", 0xD1 => "\u{00D1}",
		0xD2 => "\u{00D2}", 0xD3 => "\u{00D3}", 0xD4 => "\u{00D4}", 0xD5 => "\u{00D5}", 0xD6 => "\u{00D6}",
		0xD7 => "\u{00D7}", 0xD8 => "\u{00D8}", 0xD9 => "\u{00D9}", 0xDA => "\u{00DA}", 0xDB => "\u{00DB}",
		0xDC => "\u{00DC}", 0xDD => "\u{00DD}", 0xDE => "\u{00DE}", 0xDF => "\u{00DF}", 0xE0 => "\u{00E0}",
		0xE1 => "\u{00E1}", 0xE2 => "\u{00E2}", 0xE3 => "\u{00E3}", 0xE4 => "\u{00E4}", 0xE5 => "\u{00E5}",
		0xE6 => "\u{00E6}", 0xE7 => "\u{00E7}", 0xE8 => "\u{00E8}", 0xE9 => "\u{00E9}", 0xEA => "\u{00EA}",
		0xEB => "\u{00EB}", 0xEC => "\u{00EC}", 0xED => "\u{00ED}", 0xEE => "\u{00EE}", 0xEF => "\u{00EF}",
		0xF0 => "\u{00F0}", 0xF1 => "\u{00F1}", 0xF2 => "\u{00F2}", 0xF3 => "\u{00F3}", 0xF4 => "\u{00F4}",
		0xF5 => "\u{00F5}", 0xF6 => "\u{00F6}", 0xF7 => "\u{00F7}", 0xF8 => "\u{00F8}", 0xF9 => "\u{00F9}",
		0xFA => "\u{00FA}", 0xFB => "\u{00FB}", 0xFC => "\u{00FC}", 0xFD => "\u{00FD}", 0xFE => "\u{00FE}",
		0xFF => "\u{00FF}",
	];

	private const MAC_ROMAN = [
		0x80 => "\u{00C4}", 0x81 => "\u{00C5}",
		0x82 => "\u{00C7}", 0x83 => "\u{00C9}", 0x84 => "\u{00D1}", 0x85 => "\u{00D6}", 0x86 => "\u{00DC}",
		0x87 => "\u{00E1}", 0x88 => "\u{00E0}", 0x89 => "\u{00E2}", 0x8A => "\u{00E4}", 0x8B => "\u{00E3}",
		0x8C => "\u{00E5}", 0x8D => "\u{00E7}", 0x8E => "\u{00E9}", 0x8F => "\u{00E8}", 0x90 => "\u{00EA}",
		0x91 => "\u{00EB}", 0x92 => "\u{00ED}", 0x93 => "\u{00EC}", 0x94 => "\u{00EE}", 0x95 => "\u{00EF}",
		0x96 => "\u{00F1}", 0x97 => "\u{00F3}", 0x98 => "\u{00F2}", 0x99 => "\u{00F4}", 0x9A => "\u{00F6}",
		0x9B => "\u{00F5}", 0x9C => "\u{00FA}", 0x9D => "\u{00F9}", 0x9E => "\u{00FB}", 0x9F => "\u{00FC}",
		0xA0 => "\u{2020}", 0xA1 => "\u{00B0}", 0xA2 => "\u{00A2}", 0xA3 => "\u{00A3}", 0xA4 => "\u{00A7}",
		0xA5 => "\u{2022}", 0xA6 => "\u{00B6}", 0xA7 => "\u{00DF}", 0xA8 => "\u{00AE}", 0xA9 => "\u{00A9}",
		0xAA => "\u{2122}", 0xAB => "\u{00B4}", 0xAC => "\u{00A8}", 0xAD => "\u{2260}", 0xAE => "\u{00C6}",
		0xAF => "\u{00D8}", 0xB0 => "\u{221E}", 0xB1 => "\u{00B1}", 0xB2 => "\u{2264}", 0xB3 => "\u{2265}",
		0xB4 => "\u{00A5}", 0xB5 => "\u{00B5}", 0xB6 => "\u{2202}", 0xB7 => "\u{2211}", 0xB8 => "\u{220F}",
		0xB9 => "\u{03C0}", 0xBA => "\u{222B}", 0xBB => "\u{00AA}", 0xBC => "\u{00BA}", 0xBD => "\u{03A9}",
		0xBE => "\u{00E6}", 0xBF => "\u{00F8}", 0xC0 => "\u{00BF}", 0xC1 => "\u{00A1}", 0xC2 => "\u{00AC}",
		0xC3 => "\u{221A}", 0xC4 => "\u{0192}", 0xC5 => "\u{2248}", 0xC6 => "\u{2206}", 0xC7 => "\u{00AB}",
		0xC8 => "\u{00BB}", 0xC9 => "\u{2026}", 0xCA => "\u{00A0}", 0xCB => "\u{00C0}", 0xCC => "\u{00C3}",
		0xCD => "\u{00D5}", 0xCE => "\u{0152}", 0xCF => "\u{0153}", 0xD0 => "\u{2013}", 0xD1 => "\u{2014}",
		0xD2 => "\u{201C}", 0xD3 => "\u{201D}", 0xD4 => "\u{2018}", 0xD5 => "\u{2019}", 0xD6 => "\u{00F7}",
		0xD7 => "\u{25CA}", 0xD8 => "\u{00FF}", 0xD9 => "\u{0178}", 0xDA => "\u{2044}", 0xDB => "\u{20AC}",
		0xDC => "\u{2039}", 0xDD => "\u{203A}", 0xDE => "\u{FB01}", 0xDF => "\u{FB02}", 0xE0 => "\u{2021}",
		0xE1 => "\u{00B7}", 0xE2 => "\u{201A}", 0xE3 => "\u{201E}", 0xE4 => "\u{2030}", 0xE5 => "\u{00C2}",
		0xE6 => "\u{00CA}", 0xE7 => "\u{00C1}", 0xE8 => "\u{00CB}", 0xE9 => "\u{00C8}", 0xEA => "\u{00CD}",
		0xEB => "\u{00CE}", 0xEC => "\u{00CF}", 0xED => "\u{00CC}", 0xEE => "\u{00D3}", 0xEF => "\u{00D4}",
		0xF0 => "\u{F8FF}", 0xF1 => "\u{00D2}", 0xF2 => "\u{00DA}", 0xF3 => "\u{00DB}", 0xF4 => "\u{00D9}",
		0xF5 => "\u{0131}", 0xF6 => "\u{02C6}", 0xF7 => "\u{02DC}", 0xF8 => "\u{00AF}", 0xF9 => "\u{02D8}",
		0xFA => "\u{02D9}", 0xFB => "\u{02DA}", 0xFC => "\u{00B8}", 0xFD => "\u{02DD}", 0xFE => "\u{02DB}",
		0xFF => "\u{02C7}",
	];

	/**
	 * The table a font's /Encoding names, or StandardEncoding when a
	 * simple font names none.
	 *
	 * @return array<int, string>
	 */
	public static function table(?string $name): array {
		return match ($name) {
			'WinAnsiEncoding' => self::WIN_ANSI,
			'MacRomanEncoding' => self::MAC_ROMAN,
			default => self::STANDARD,
		};
	}

	/**
	 * The UTF-8 text of one byte under an encoding: the table's entry
	 * when it has one — StandardEncoding overrides two ASCII slots —
	 * then identity in the printable ASCII range, and nothing for a
	 * slot the encoding leaves undefined or unprintable.
	 */
	public static function decode(int $byte, array $table): string {
		if (array_key_exists($byte, $table)) {
			return $table[$byte];
		}
		if ($byte >= 0x20 && $byte < 0x7F) {
			return chr($byte);
		}
		return '';
	}

	/**
	 * The UTF-8 text of a glyph name from a Differences array, found in
	 * the reverse of any predefined table; '' when the name is not one
	 * of those glyphs.
	 */
	public static function byName(string $name): string {
		return self::REVERSE[$name] ?? '';
	}

	private const REVERSE = [
		'quoteright' => "\u{2019}", 'quoteleft' => "\u{2018}", 'quotesingle' => "\u{0027}",
		'quotedbl' => "\u{0022}", 'fraction' => "\u{2044}", 'florin' => "\u{0192}",
		'currency' => "\u{00A4}", 'fi' => "\u{FB01}", 'fl' => "\u{FB02}", 'endash' => "\u{2013}",
		'dagger' => "\u{2020}", 'daggerdbl' => "\u{2021}", 'periodcentered' => "\u{00B7}",
		'bullet' => "\u{2022}", 'quotesinglbase' => "\u{201A}", 'quotedblbase' => "\u{201E}",
		'quotedblleft' => "\u{201C}", 'quotedblright' => "\u{201D}", 'guilsinglleft' => "\u{2039}",
		'guilsinglright' => "\u{203A}", 'guillemotleft' => "\u{00AB}", 'guillemotright' => "\u{00BB}",
		'ellipsis' => "\u{2026}", 'perthousand' => "\u{2030}", 'grave' => "\u{0060}",
		'acute' => "\u{00B4}", 'circumflex' => "\u{02C6}", 'tilde' => "\u{02DC}", 'macron' => "\u{00AF}",
		'breve' => "\u{02D8}", 'dotaccent' => "\u{02D9}", 'dieresis' => "\u{00A8}", 'ring' => "\u{02DA}",
		'cedilla' => "\u{00B8}", 'hungarumlaut' => "\u{02DD}", 'ogonek' => "\u{02DB}", 'caron' => "\u{02C7}",
		'emdash' => "\u{2014}", 'ordfeminine' => "\u{00AA}", 'Lslash' => "\u{0141}",
		'ordmasculine' => "\u{00BA}", 'ae' => "\u{00E6}",
		'dotlessi' => "\u{0131}", 'lslash' => "\u{0142}", 'oe' => "\u{0153}",
		'Euro' => "\u{20AC}", 'trademark' => "\u{2122}",

		'Ydieresis' => "\u{0178}", 'mu' => "\u{00B5}", 'nspace' => "\u{202F}",

		// the accented Latin glyph names a /Differences array carries
		'Agrave' => "\u{00C0}", 'Aacute' => "\u{00C1}", 'Acircumflex' => "\u{00C2}",
		'Atilde' => "\u{00C3}", 'Adieresis' => "\u{00C4}", 'Aring' => "\u{00C5}", 'AE' => "\u{00C6}",
		'Ccedilla' => "\u{00C7}", 'Egrave' => "\u{00C8}", 'Eacute' => "\u{00C9}",
		'Ecircumflex' => "\u{00CA}", 'Edieresis' => "\u{00CB}", 'Igrave' => "\u{00CC}",
		'Iacute' => "\u{00CD}", 'Icircumflex' => "\u{00CE}", 'Idieresis' => "\u{00CF}",
		'Eth' => "\u{00D0}", 'Ntilde' => "\u{00D1}", 'Ograve' => "\u{00D2}", 'Oacute' => "\u{00D3}",
		'Ocircumflex' => "\u{00D4}", 'Otilde' => "\u{00D5}", 'Odieresis' => "\u{00D6}",
		'multiply' => "\u{00D7}", 'Oslash' => "\u{00D8}", 'Ugrave' => "\u{00D9}", 'Uacute' => "\u{00DA}",
		'Ucircumflex' => "\u{00DB}", 'Udieresis' => "\u{00DC}", 'Yacute' => "\u{00DD}",
		'Thorn' => "\u{00DE}", 'germandbls' => "\u{00DF}", 'agrave' => "\u{00E0}",
		'aacute' => "\u{00E1}", 'acircumflex' => "\u{00E2}", 'atilde' => "\u{00E3}",
		'adieresis' => "\u{00E4}", 'aring' => "\u{00E5}", 'ccedilla' => "\u{00E7}",
		'egrave' => "\u{00E8}", 'eacute' => "\u{00E9}", 'ecircumflex' => "\u{00EA}",
		'edieresis' => "\u{00EB}", 'igrave' => "\u{00EC}", 'iacute' => "\u{00ED}",
		'icircumflex' => "\u{00EE}", 'idieresis' => "\u{00EF}", 'eth' => "\u{00F0}",
		'ntilde' => "\u{00F1}", 'ograve' => "\u{00F2}", 'oacute' => "\u{00F3}",
		'ocircumflex' => "\u{00F4}", 'otilde' => "\u{00F5}", 'odieresis' => "\u{00F6}",
		'divide' => "\u{00F7}", 'oslash' => "\u{00F8}", 'ugrave' => "\u{00F9}", 'uacute' => "\u{00FA}",
		'ucircumflex' => "\u{00FB}", 'udieresis' => "\u{00FC}", 'yacute' => "\u{00FD}",
		'thorn' => "\u{00FE}", 'ydieresis' => "\u{00FF}", 'Amacron' => "\u{0100}",
		'amacron' => "\u{0101}", 'Ccircumflex' => "\u{0108}", 'ccircumflex' => "\u{0109}",
		'Cacute' => "\u{0106}", 'cacute' => "\u{0107}", 'Ccaron' => "\u{010C}", 'ccaron' => "\u{010D}",
		'dcaron' => "\u{010F}", 'Dcaron' => "\u{010E}", 'Emacron' => "\u{0112}", 'emacron' => "\u{0113}",
		'Ecaron' => "\u{011A}", 'ecaron' => "\u{011B}", 'Gcircumflex' => "\u{011C}",
		'gcircumflex' => "\u{011D}", 'Gbreve' => "\u{011E}", 'gbreve' => "\u{011F}",
		'Imacron' => "\u{012A}", 'imacron' => "\u{012B}", 'Iogonek' => "\u{012E}",
		'iogonek' => "\u{012F}", 'Idotaccent' => "\u{0130}", 'Itilde' => "\u{0128}",
		'itilde' => "\u{0129}", 'Lacute' => "\u{0139}", 'lacute' => "\u{013A}", 'Lcaron' => "\u{013D}",
		'lcaron' => "\u{013E}", 'Nacute' => "\u{0143}", 'nacute' => "\u{0144}", 'Ncaron' => "\u{0147}",
		'ncaron' => "\u{0148}", 'Ohungarumlaut' => "\u{0150}", 'ohungarumlaut' => "\u{0151}",
		'OE' => "\u{0152}", 'Racute' => "\u{0154}", 'racute' => "\u{0155}",
		'Sacute' => "\u{015A}", 'sacute' => "\u{015B}", 'Scircumflex' => "\u{015C}",
		'scircumflex' => "\u{015D}", 'Scaron' => "\u{0160}", 'scaron' => "\u{0161}",
		'Tcaron' => "\u{0164}", 'tcaron' => "\u{0165}", 'Tcedilla' => "\u{0162}",
		'tcedilla' => "\u{0163}", 'Umacron' => "\u{016A}", 'umacron' => "\u{016B}",
		'Uring' => "\u{016E}", 'uring' => "\u{016F}", 'Uhungarumlaut' => "\u{0170}",
		'uhungarumlaut' => "\u{0171}", 'Ycircumflex' => "\u{0176}", 'ycircumflex' => "\u{0177}",
		'Zacute' => "\u{0179}", 'zacute' => "\u{017A}", 'Zcaron' => "\u{017D}", 'zcaron' => "\u{017E}",
		'Zdotaccent' => "\u{017B}", 'zdotaccent' => "\u{017C}",
	];
}
