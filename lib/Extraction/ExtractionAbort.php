<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Extraction;

/**
 * Internal to the Extraction namespace: an extractor stops here and the
 * cause plus message become the ExtractionResult — the row is never
 * refused over its content (DESIGN.md, "Worked example: indexing one
 * document"). The text already in the caller's TextSink is what was
 * recovered before the abort, and is kept.
 */
final class ExtractionAbort extends \RuntimeException {
	public function __construct(
		public readonly ExtractionCause $cause,
		string $message,
	) {
		parent::__construct($message);
	}
}
