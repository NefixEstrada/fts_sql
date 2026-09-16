<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Extraction;

use OCA\FtsSql\Extraction\Ppt\OleFile;
use OCA\FtsSql\Extraction\Ppt\PptRecords;
use OCA\FtsSql\Vendor\PhpOffice\PhpPresentation\Reader\PowerPoint97;
use OCA\FtsSql\Vendor\PhpOffice\PhpPresentation\Reader\ReaderInterface;
use OCA\FtsSql\Vendor\PhpOffice\PhpPresentation\Shape\Group;
use OCA\FtsSql\Vendor\PhpOffice\PhpPresentation\Shape\RichText;
use Throwable;

/**
 * PowerPoint 97 (.ppt): the PhpOffice reader of DESIGN.md's Milestone
 * 4, the first format the app reads through a bundled, scoped library
 * rather than its own parser. The reader takes a path, so the stream
 * is spilled to a temporary file — bounded by the same copy cap the
 * other binary formats pay — and the compound container is opened
 * first by this app's own OleFile and PptRecords: encryption becomes
 * the Encrypted cause instead of the reader's bare "feature not
 * implemented", and every record length the reader's loops are driven
 * by is bounds-checked against the stream that actually exists.
 *
 * Images are skipped at the reader's own flag (SKIP_IMAGES): they cost
 * memory and a search never finds them. The wall clock is checked
 * between slides and shapes, cooperatively, the way the PDF extractor
 * checks between pages; the record bounds are what keep the reader's
 * own loops linear in the stream's real size. The scoped reader is
 * called through the app's namespace only — in production nothing
 * serves the unprefixed one.
 */
final class PptExtractor implements IExtractor {
	public const TIME_CAP = 10.0;

	private const MAX_DEPTH = 64;

	public function owns(): array {
		return ['ppt'];
	}

	public function extract($stream, string $extension, int $budget): ExtractionResult {
		$sink = new TextSink($budget);

		$path = tempnam(sys_get_temp_dir(), 'fts-sql-ppt');
		if ($path === false) {
			return new ExtractionResult(null, ExtractionCause::ParserGaveUp, 'no temporary file could be created for the reader');
		}

		try {
			$size = $this->spill($stream, $path);
			if ($size > $this->sizeCap()) {
				throw new ExtractionAbort(
					ExtractionCause::ParserGaveUp,
					sprintf('the presentation is %s bytes, past this extractor\'s %s-byte wall', number_format($size), number_format($this->sizeCap())),
				);
			}

			$container = OleFile::open($path);
			$currentUser = $container->stream('Current User');
			if ($currentUser === null) {
				throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'no Current User stream in the compound container');
			}
			$atom = PptRecords::currentUserAtom($currentUser);

			$document = $container->stream('PowerPoint Document');
			if ($document === null) {
				throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'no PowerPoint Document stream in the compound container');
			}
			PptRecords::validateDocument($document, $atom['editOffset']);

			$presentation = (new PowerPoint97())->load($path, ReaderInterface::SKIP_IMAGES);

			$deadline = microtime(true) + self::TIME_CAP;
			foreach ($presentation->getAllSlides() as $slide) {
				if ($sink->full()) {
					break;
				}
				if (microtime(true) > $deadline) {
					throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'extraction ran past its time budget');
				}
				$this->readShapes($slide->getShapeCollection(), 0, $sink, $deadline);
			}

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
				'the reader gave up on the presentation: ' . $e->getMessage() . self::recoveredNote($sink),
			);
		} finally {
			@unlink($path);
		}
	}

	/**
	 * One slide's shapes: a text shape's paragraphs one newline each —
	 * the same shape the .pptx extractor reads from its slide XML —
	 * and a group's own shapes, nested, to the same depth cap.
	 *
	 * @param iterable<\OCA\FtsSql\Vendor\PhpOffice\PhpPresentation\AbstractShape> $shapes
	 */
	private function readShapes(iterable $shapes, int $depth, TextSink $sink, float $deadline): void {
		if ($depth > self::MAX_DEPTH) {
			return;
		}
		foreach ($shapes as $shape) {
			if ($sink->full()) {
				return;
			}
			if ($shape instanceof Group) {
				$this->readShapes($shape->getShapeCollection(), $depth + 1, $sink, $deadline);
				continue;
			}
			if (!$shape instanceof RichText) {
				continue;
			}
			if (microtime(true) > $deadline) {
				throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'extraction ran past its time budget');
			}
			foreach ($shape->getParagraphs() as $paragraph) {
				if ($sink->full()) {
					return;
				}
				foreach ($paragraph->getRichTextElements() as $element) {
					if ($element instanceof RichText\BreakElement) {
						$sink->accept("\n");
						continue;
					}
					// the reader builds text a code point at a time;
					// unpaired surrogates in the file would have been
					// encoded into invalid UTF-8, which never reaches
					// the database
					$sink->accept(mb_scrub($element->getText()));
				}
				$sink->accept("\n");
			}
		}
	}

	/**
	 * The reader reads from a path: the stream is copied there in
	 * chunks, so the spill costs a buffer, not the document again.
	 *
	 * @param resource $stream
	 */
	private function spill($stream, string $path): int {
		$out = fopen($path, 'wb');
		if ($out === false) {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'the temporary file for the reader could not be written');
		}
		$total = 0;
		try {
			while (!feof($stream)) {
				$chunk = fread($stream, 1048576);
				if ($chunk === false) {
					throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'the document stream could not be read');
				}
				$total += strlen($chunk);
				if ($total > $this->sizeCap()) {
					return $total; // the caller refuses it with the cause and the size
				}
				fwrite($out, $chunk);
			}
		} finally {
			fclose($out);
		}
		return $total;
	}

	private function sizeCap(): int {
		// the framework hands documents over gated at 20 MB; this wall
		// is the extractor's own, for the provider that does not gate
		return 33554432;
	}

	private static function recoveredNote(TextSink $sink): string {
		return $sink->text() === '' ? '' : ' — indexed on what was extracted before it gave up';
	}
}
