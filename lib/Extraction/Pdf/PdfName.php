<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Extraction\Pdf;

/**
 * A PDF name (after a slash), kept distinct from a literal string:
 * /FlateDecode is a filter name, not text.
 */
final class PdfName {
	public function __construct(
		public readonly string $name,
	) {
	}
}
