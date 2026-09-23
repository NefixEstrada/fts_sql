<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Model;

/**
 * The accent folding PostgreSQL and MySQL need applied in PHP, over Latin-1
 * Supplement and Latin Extended-A: `to_tsvector` does not fold accents, and
 * an InnoDB FULLTEXT index over utf8mb4_bin folds neither accents nor case.
 * Applied to what feeds the search artefact and to the query — never to the
 * stored text an excerpt is cut from. Case is kept here on purpose: the
 * MySQL backend lowercases in its own normaliseText(), PostgreSQL's
 * tokeniser lowercases itself, and neither may touch the stored copy.
 */
final class AccentFold {
	/**
	 * Curated, not exhaustive: the Latin letters around Catalan, Spanish and
	 * English, plus a few one-to-many folds (Æ, ß, Œ, Þ, Ĳ) folding is safe
	 * on as long as it is applied to both sides of every match.
	 */
	private const MAP = [
		// Latin-1 Supplement
		'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Æ' => 'AE',
		'Ç' => 'C',
		'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
		'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
		'Ð' => 'D', 'Ñ' => 'N',
		'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O', 'Œ' => 'OE',
		'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
		'Ý' => 'Y', 'Þ' => 'TH', 'ß' => 'ss',
		'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae',
		'ç' => 'c',
		'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
		'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
		'ð' => 'd', 'ñ' => 'n',
		'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'œ' => 'oe',
		'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
		'ý' => 'y', 'ÿ' => 'y', 'þ' => 'th',
		// Latin Extended-A
		'Ā' => 'A', 'ā' => 'a', 'Ă' => 'A', 'ă' => 'a', 'Ą' => 'A', 'ą' => 'a',
		'Ć' => 'C', 'ć' => 'c', 'Ĉ' => 'C', 'ĉ' => 'c', 'Ċ' => 'C', 'ċ' => 'c', 'Č' => 'C', 'č' => 'c',
		'Ď' => 'D', 'ď' => 'd', 'Đ' => 'D', 'đ' => 'd',
		'Ē' => 'E', 'ē' => 'e', 'Ĕ' => 'E', 'ĕ' => 'e', 'Ė' => 'E', 'ė' => 'e',
		'Ę' => 'E', 'ę' => 'e', 'Ě' => 'E', 'ě' => 'e',
		'Ĝ' => 'G', 'ĝ' => 'g', 'Ğ' => 'G', 'ğ' => 'g', 'Ġ' => 'G', 'ġ' => 'g', 'Ģ' => 'G', 'ģ' => 'g',
		'Ĥ' => 'H', 'ĥ' => 'h', 'Ħ' => 'H', 'ħ' => 'h',
		'Ĩ' => 'I', 'ĩ' => 'i', 'Ī' => 'I', 'ī' => 'i', 'Ĭ' => 'I', 'ĭ' => 'i',
		'Į' => 'I', 'į' => 'i', 'İ' => 'I', 'ı' => 'i', 'Ĳ' => 'IJ', 'ĳ' => 'ij',
		'Ĵ' => 'J', 'ĵ' => 'j', 'Ķ' => 'K', 'ķ' => 'k',
		'Ĺ' => 'L', 'ĺ' => 'l', 'Ļ' => 'L', 'ļ' => 'l', 'Ľ' => 'L', 'ľ' => 'l',
		'Ŀ' => 'L', 'ŀ' => 'l', 'Ł' => 'L', 'ł' => 'l',
		'Ń' => 'N', 'ń' => 'n', 'Ņ' => 'N', 'ņ' => 'n', 'Ň' => 'N', 'ň' => 'n', 'ŉ' => 'n',
		'Ō' => 'O', 'ō' => 'o', 'Ŏ' => 'O', 'ŏ' => 'o', 'Ő' => 'O', 'ő' => 'o',
		'Ŕ' => 'R', 'ŕ' => 'r', 'Ŗ' => 'R', 'ŗ' => 'r', 'Ř' => 'R', 'ř' => 'r',
		'Ś' => 'S', 'ś' => 's', 'Ŝ' => 'S', 'ŝ' => 's', 'Ş' => 'S', 'ş' => 's', 'Š' => 'S', 'š' => 's',
		'Ţ' => 'T', 'ţ' => 't', 'Ť' => 'T', 'ť' => 't', 'Ŧ' => 'T', 'ŧ' => 't',
		'Ŭ' => 'U', 'ŭ' => 'u', 'Ů' => 'U', 'ů' => 'u', 'Ű' => 'U', 'ű' => 'u', 'Ų' => 'U', 'ų' => 'u',
		'Ŵ' => 'W', 'ŵ' => 'w', 'Ŷ' => 'Y', 'ŷ' => 'y', 'Ÿ' => 'Y',
		'Ź' => 'Z', 'ź' => 'z', 'Ż' => 'Z', 'ż' => 'z', 'Ž' => 'Z', 'ž' => 'z',
	];

	public static function fold(string $text): string {
		return strtr($text, self::MAP);
	}

	/**
	 * A case- and accent-insensitive match body for the folded form of
	 * $text, anchored nowhere: every letter becomes a character class of
	 * itself and of everything the MAP folds onto it, so the pattern finds
	 * `ciències` from `ciencies` — against the stored text, whose accents
	 * survive. Intended under the `ui` flags. The one-to-many folds (Æ, ß,
	 * Œ, Þ, Ĳ) are not reversed: their folded output is longer than one
	 * character, so no single-letter class can stand for them, and `ss`
	 * simply will not match `ß`.
	 */
	public static function insensitivePattern(string $text): string {
		$pattern = '';
		foreach (mb_str_split(self::fold($text)) as $char) {
			$sources = self::sourcesFoldingTo($char);
			$pattern .= $sources === ''
				? preg_quote($char, '/')
				: '[' . preg_quote($char, '/') . $sources . ']';
		}
		return $pattern;
	}

	/**
	 * The accented characters folding onto one folded character, escaped
	 * for the inside of a character class; '' when nothing does.
	 */
	private static function sourcesFoldingTo(string $folded): string {
		static $reversed = null;
		if ($reversed === null) {
			$reversed = [];
			foreach (self::MAP as $source => $output) {
				if (strlen($output) !== 1) {
					continue;
				}
				$reversed[$output] = ($reversed[$output] ?? '') . preg_quote($source, '/');
			}
		}
		return $reversed[$folded] ?? '';
	}
}
