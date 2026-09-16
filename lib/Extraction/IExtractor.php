<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Extraction;

/**
 * One format family's extraction. The input is a stream, not bytes, from
 * the first extractor on (DESIGN.md, "Open issue: where extraction plugs
 * in", option (b)): today the only caller wraps decoded bytes in a
 * php://temp stream, and when the streaming fast path arrives — a listener
 * on the files provider's indexing event, handing over a Node to read from
 * — it passes its stream straight here and no extractor is rewritten.
 *
 * Implementations never throw over their content: every failure, including
 * a \Throwable from the XML layer, comes back as an ExtractionResult with a
 * cause. One bad document costs that document, never the indexing run.
 */
interface IExtractor {
	/**
	 * @return list<string> lowercase extensions, without the dot
	 */
	public function owns(): array;

	/**
	 * @param resource $stream
	 */
	public function extract($stream, string $extension, int $budget): ExtractionResult;
}
