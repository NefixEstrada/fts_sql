<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Extraction;

/**
 * What one extraction produced. A null cause means the whole document was
 * read — the text may still be empty, which is a valid outcome (an empty
 * file extracts to empty text). A non-null cause means something is missing
 * or was cut, and the message says what; text is then whatever was
 * recovered before the gap, null when nothing was.
 *
 * The caller turns this into the row's content, contentExtracted and
 * contentError: text is stored whenever it is not null (INDEX_CONTENT set),
 * and a cause is always reported alongside it (ERROR_SEV_1).
 */
final class ExtractionResult {
	public function __construct(
		public readonly ?string $text,
		public readonly ?ExtractionCause $cause,
		public readonly string $message,
	) {
	}

	public static function complete(string $text): self {
		return new self($text, null, '');
	}
}
