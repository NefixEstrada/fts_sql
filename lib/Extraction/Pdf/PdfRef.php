<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Extraction\Pdf;

/**
 * An indirect reference, "num gen R": resolved against the
 * cross-reference index, never eagerly — the boundedness of the whole
 * extractor is exactly that a reference is followed only when the page
 * being read names it.
 */
final class PdfRef {
	public function __construct(
		public readonly int $object,
		public readonly int $generation,
	) {
	}
}
