<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Extraction;

use OCA\FtsSql\Extraction\Pdf\PdfContent;
use OCA\FtsSql\Extraction\Pdf\PdfDocument;
use OCA\FtsSql\Extraction\Pdf\PdfName;
use OCA\FtsSql\Extraction\Pdf\PdfRef;
use OCA\FtsSql\Extraction\Pdf\PdfWalkStats;
use OCA\FtsSql\Extraction\Pdf\SinkFull;
use Throwable;

/**
 * PDF: the page-bounded text extractor of DESIGN.md's Milestone 3.
 * The route the design's own measurement picked: a library that
 * parses the whole object graph costs 697.5 MiB on the 21 MiB
 * reference file — fatal at Nextcloud's 512 MB floor before any text
 * — so this one reads the cross-reference index, then per page only
 * what the page names: its content streams, its fonts, its forms.
 * A page is read, its text goes to the sink, and when the sink is
 * full the walk stops: a document is findable by its first pages the
 * same way a long text is findable by its first bytes.
 *
 * The hostile posture is the one the design prescribes for PDFs:
 * input, per-stream and total read caps, an incremental bounded
 * inflate, depth caps on the syntax, the page tree and form
 * recursion, a visited set wherever a graph is walked, a wall-clock
 * budget, and never a \Throwable past the extract call. A page that
 * fails costs that page: it is counted, the walk continues, and the
 * document carries the cause with what was recovered.
 */
final class PdfExtractor implements IExtractor {
	public const TIME_CAP = 10.0;

	private const ENTRY_FLOOR = 8388608;
	private const ENTRY_MULTIPLE = 8;
	private const MAX_PAGES = 100000;
	private const MAX_TREE_DEPTH = 64;

	public function owns(): array {
		return ['pdf'];
	}

	public function extract($stream, string $extension, int $budget): ExtractionResult {
		$sink = new TextSink($budget);
		$stats = new PdfWalkStats();

		try {
			$document = PdfDocument::open($stream);

			if ($document->encrypted && $document->crypt() === null) {
				throw new ExtractionAbort(
					ExtractionCause::Encrypted,
					'the pdf is encrypted: indexed on title, access and tags only',
				);
			}

			$catalog = $document->catalog();
			if ($catalog === null) {
				throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'no catalog in the pdf');
			}
			$pages = $catalog['Pages'] ?? null;
			if (!$pages instanceof PdfRef) {
				throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'no page tree in the pdf');
			}

			$deadline = microtime(true) + self::TIME_CAP;
			try {
				$this->readNode($document, $pages, $sink, $deadline, null, 0, [], self::entryCap($budget), $stats);
			} catch (SinkFull) {
				// the budget was reached: the normal end of a long document
			}

			if ($sink->overflowed()) {
				return new ExtractionResult(
					$sink->text(),
					ExtractionCause::BudgetCut,
					sprintf('the content was cut at the %s-byte budget: the document is findable by what survived', number_format($budget)),
				);
			}

			$gaps = [];
			if ($stats->unreadablePages > 0) {
				$gaps[] = sprintf('%d of %d page(s) could not be read', $stats->unreadablePages, $stats->pages);
			}
			if ($stats->undecodableRuns > 0) {
				$gaps[] = 'some text runs used fonts with no usable encoding';
			}
			if ($gaps !== []) {
				return new ExtractionResult(
					$sink->text() === '' ? null : $sink->text(),
					ExtractionCause::ParserGaveUp,
					implode(' and ', $gaps) . self::recoveredNote($sink),
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
		}
	}

	/**
	 * One node of the page tree: a page, or an intermediate whose kids
	 * are walked with the resources it leaves them. The visited set is
	 * what a tree that points at itself costs: one lookup.
	 *
	 * @param array<int, true> $visited
	 */
	private function readNode(PdfDocument $document, PdfRef $node, TextSink $sink, float $deadline, ?array $inherited, int $depth, array $visited, int $streamCap, PdfWalkStats $stats): void {
		if ($depth > self::MAX_TREE_DEPTH || isset($visited[$node->object]) || $stats->pages >= self::MAX_PAGES || $sink->full()) {
			return;
		}
		$visited[$node->object] = true;

		$dict = $document->getDictionary($node);
		if ($dict === null) {
			$stats->unreadablePages++;
			return;
		}

		$type = $dict['Type'] ?? null;
		$type = $type instanceof PdfName ? $type->name : '';

		$resources = $document->resolve($dict['Resources'] ?? null);
		$resources = is_array($resources) ? $resources : $inherited;

		if ($type === 'Page' || isset($dict['Kids']) === false) {
			$this->readPage($document, $dict, $resources, $sink, $deadline, $streamCap, $stats);
			return;
		}

		if (microtime(true) > $deadline) {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'extraction ran past its time budget');
		}

		$kids = $document->resolve($dict['Kids'] ?? null);
		if (!is_array($kids)) {
			$stats->unreadablePages++;
			return;
		}
		foreach ($kids as $kid) {
			if ($kid instanceof PdfRef) {
				$this->readNode($document, $kid, $sink, $deadline, $resources, $depth + 1, $visited, $streamCap, $stats);
			}
		}
	}

	/**
	 * @param array<string, mixed>|null $resources the page's effective resources
	 */
	private function readPage(PdfDocument $document, array $dict, ?array $resources, TextSink $sink, float $deadline, int $streamCap, PdfWalkStats $stats): void {
		$stats->pages++;
		$fonts = $document->fonts($resources);

		$contents = [];
		$raw = $dict['Contents'] ?? null;
		if ($raw instanceof PdfRef) {
			$contents = [$raw];
		} elseif (is_array($raw)) {
			$contents = array_values(array_filter($raw, static fn (mixed $part): bool => $part instanceof PdfRef));
		}
		if ($contents === []) {
			// a page with no content stream: a blank page, not a failure
			return;
		}

		$scanner = new PdfContent($document, $sink, $deadline, $streamCap);
		foreach ($contents as $ref) {
			if ($sink->full()) {
				return;
			}
			$object = $document->getObject($ref->object);
			$value = $object?->value;
			$stream = $object?->stream;
			if (!is_array($value) || $stream === null) {
				$stats->unreadablePages++;
				continue;
			}

			try {
				$bytes = $document->decodeStreamData($value, $stream, $streamCap, 'a page content stream', $ref);
			} catch (ExtractionAbort) {
				$stats->unreadablePages++;
				continue;
			}

			$scanner->scan($bytes, $fonts, $resources);
		}
		$stats->undecodableRuns += $scanner->undecodableRuns;
	}

	private static function entryCap(int $budget): int {
		return max(self::ENTRY_MULTIPLE * $budget, self::ENTRY_FLOOR);
	}

	private static function recoveredNote(TextSink $sink): string {
		return $sink->text() === '' ? '' : ' — indexed on what was extracted before it gave up';
	}
}
