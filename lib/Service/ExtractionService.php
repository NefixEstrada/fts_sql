<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Service;

/**
 * Bytes to plain text within the content budget, pure (DESIGN.md, "Code
 * organisation" and Milestone 1): Milestone 1 indexes plain text only, and
 * a null return is the one signal "this is not plain text" — the row is
 * still written, on its title, access and tags, without content (an empty
 * string is a valid outcome: an empty file extracted to empty text).
 *
 * The deny-list semantics are deliberate: an extension NOT on the list —
 * including an empty or unknown one — is a plain-text candidate (.txt,
 * .md, .csv, .log and every format nobody thought of), because the formats
 * later milestones own, plus binaries and images, are the ones this
 * milestone must refuse to index as text.
 */
final class ExtractionService {
	/**
	 * The formats later milestones own (office containers, archives,
	 * executables) plus binaries and images: never plain text here.
	 */
	public const DENIED_EXTENSIONS = [
		'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', 'epub',
		'pdf', 'doc', 'xls', 'ppt',
		'zip', 'gz', 'tar',
		'exe', 'bin',
		'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp', 'heic',
		'mp3', 'mp4', 'avi', 'mkv', 'mov', 'wav',
	];

	/**
	 * @return ?string the plain text, cut to $budget bytes; null when the
	 *                 bytes are not plain text this milestone extracts
	 */
	public static function extract(string $bytes, string $extension, int $budget): ?string {
		if (in_array(mb_strtolower($extension, 'UTF-8'), self::DENIED_EXTENSIONS, true)) {
			return null;
		}

		if (!mb_check_encoding($bytes, 'UTF-8')) {
			return null;
		}

		// \P{C} is everything but control and format characters, so the
		// negated class matches any control character other than tab,
		// newline and carriage return. !== 0 (rather than === 1) also
		// refuses on a false, which cannot happen after the encoding check
		// but fails closed if it ever did.
		if (preg_match('/[^\P{C}\t\n\r]/u', $bytes) !== 0) {
			return null;
		}

		if (strlen($bytes) <= $budget) {
			return $bytes;
		}

		// Byte-denominated and sequence-safe: mb_strcut stops at the last
		// complete character that fits, where substr() would split a
		// multi-byte sequence and mb_substr() would count characters
		// (DESIGN.md, "Configuration").
		$cut = mb_strcut($bytes, 0, $budget, 'UTF-8');

		// Drop the trailing partial word: cut back to the last space when
		// one exists at a positive position (a space at 0 would empty the
		// cut), then trim whatever whitespace is left at the end.
		$lastSpace = strrpos($cut, ' ');
		if ($lastSpace !== false && $lastSpace > 0) {
			$cut = substr($cut, 0, $lastSpace);
		}

		return rtrim($cut);
	}
}
