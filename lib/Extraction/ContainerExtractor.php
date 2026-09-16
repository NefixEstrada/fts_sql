<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Extraction;

use Throwable;

/**
 * The shape every container format shares: open the stream as a zip, read
 * the entries that hold body text, walk them into one sink under one
 * wall-clock deadline, and never let a failure escape — an abort keeps what
 * the sink already holds and carries the cause, and the same goes for any
 * \Throwable, because a parser's TypeError is a Throwable too
 * (DESIGN.md, "The framework contract").
 *
 * The entry read cap scales with the budget rather than sitting at a fixed
 * size: office XML runs 2–4× the size of the text it carries (every run is
 * a w:t/a:t element with its properties), so eight budgets of XML headroom
 * let a budget-sized extraction always finish and reach the sink's early
 * stop instead of the cap; the read cap stays what a zip bomb hits.
 */
abstract class ContainerExtractor implements IExtractor {
	private const ENTRY_FLOOR = 8388608;
	private const ENTRY_MULTIPLE = 8;

	public function extract($stream, string $extension, int $budget): ExtractionResult {
		$sink = new TextSink($budget);
		$zip = null;
		try {
			$zip = ZipContainer::open($stream, $this->passwordProtectedOle());
			$this->readEntries($zip, $extension, $sink, microtime(true) + XmlWalk::TIME_CAP, self::entryCap($budget));

			if ($sink->overflowed()) {
				return new ExtractionResult(
					$sink->text(),
					ExtractionCause::BudgetCut,
					sprintf('the content was cut at the %s-byte budget: the document is findable by what survived', number_format($budget)),
				);
			}
			return ExtractionResult::complete($sink->text());
		} catch (ExtractionAbort $abort) {
			return new ExtractionResult(
				$sink->text() === '' ? null : $sink->text(),
				$abort->cause,
				$abort->getMessage() . self::recoveredNote($sink),
			);
		} catch (Throwable $e) {
			return new ExtractionResult(
				$sink->text() === '' ? null : $sink->text(),
				ExtractionCause::ParserGaveUp,
				'extraction failed: ' . $e->getMessage() . self::recoveredNote($sink),
			);
		} finally {
			$zip?->close();
		}
	}

	/**
	 * Read the entries this format keeps its body text in, into the sink.
	 * Throwing ExtractionAbort is the way to say "no more can be read".
	 */
	abstract protected function readEntries(ZipContainer $zip, string $extension, TextSink $sink, float $deadline, int $entryCap): void;

	/**
	 * An OOXML file saved with a password is not a zip at all but an OLE
	 * compound file; its extensions report that container as encrypted.
	 */
	protected function passwordProtectedOle(): bool {
		return false;
	}

	final protected static function entryCap(int $budget): int {
		return max(self::ENTRY_MULTIPLE * $budget, self::ENTRY_FLOOR);
	}

	private static function recoveredNote(TextSink $sink): string {
		return $sink->text() === '' ? '' : ' — indexed on what was extracted before it gave up';
	}
}
