<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Extraction\Pdf;

/**
 * One indirect object as read: its value, and its raw stream bytes
 * when it has any. Stream-bearing objects are never cached, so the
 * memory a document costs is the page being read, not the file.
 */
final class PdfIndirect {
	public function __construct(
		public readonly mixed $value,
		public readonly ?string $stream,
	) {
	}
}
