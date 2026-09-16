<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Extraction;

/**
 * Where an extractor's text accumulates, and the one place the content
 * budget is enforced on the extraction side: pieces are accepted while they
 * fit, and once one does not, the sink is full — the walker stops there, so
 * a document that only needs the first paragraph never inflates the last
 * zip entry (DESIGN.md, Milestone 2: a targeted XMLReader pass, not a
 * library that parses everything).
 *
 * The finalization is Milestone 1's cut, unchanged: mb_strcut() on a byte
 * budget, then the trailing partial word dropped back to the last space —
 * an exact fit is not a cut, and the overflow flag is what turns a cut into
 * the budget-cut cause.
 */
final class TextSink {
	private string $buffer = '';
	private bool $overflowed = false;

	public function __construct(
		private readonly int $budget,
	) {
	}

	public function accept(string $piece): void {
		if ($this->overflowed) {
			return;
		}
		$this->buffer .= $piece;
		if (strlen($this->buffer) > $this->budget) {
			$this->overflowed = true;
		}
	}

	/**
	 * The walker's stop condition: nothing more will be accepted.
	 */
	public function full(): bool {
		return $this->overflowed || strlen($this->buffer) >= $this->budget;
	}

	public function overflowed(): bool {
		return $this->overflowed;
	}

	public function text(): string {
		if (!$this->overflowed) {
			return $this->buffer;
		}

		$cut = mb_strcut($this->buffer, 0, $this->budget, 'UTF-8');

		$lastSpace = strrpos($cut, ' ');
		if ($lastSpace !== false && $lastSpace > 0) {
			$cut = substr($cut, 0, $lastSpace);
		}

		return rtrim($cut);
	}
}
