<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Extraction;

use OCA\FtsSql\Extraction\Ppt\OleFile;
use OCA\FtsSql\Vendor\PhpOffice\PhpWord\Element\Section;
use OCA\FtsSql\Vendor\PhpOffice\PhpWord\Element\Text;
use OCA\FtsSql\Vendor\PhpOffice\PhpWord\Element\TextBreak;
use OCA\FtsSql\Vendor\PhpOffice\PhpWord\Element\TextRun;
use OCA\FtsSql\Vendor\PhpOffice\PhpWord\Reader\MsDoc;
use Throwable;

/**
 * Word 97-2003 (.doc): the PhpWord reader of DESIGN.md's Milestone 4,
 * behind the compound-file gate the .ppt and .xls extractors built.
 * Three facts of that reader shape this class. It ignores the FIB's
 * fEncrypted flag entirely — an encrypted document would be parsed as
 * the XOR-noise it is, so the gate reads the flag itself and answers
 * with the Encrypted cause. It treats the paragraph table's FCs as
 * byte offsets into the WordDocument stream, which is what a simple
 * document is and what a piece-table document is not: the design's
 * documented caveat, ".doc piece table is missing from PhpWord" — such
 * a file costs itself, with the parser-gave-up cause and whatever the
 * reader recovered first. And it spills every inline image to a
 * tempnam() pair it never unlinks — the sweep in the finally deletes
 * what this reader created (both the payload file and the empty
 * tempnam base), and nothing else, so a concurrent worker's spool
 * survives.
 */
final class DocExtractor implements IExtractor {
	public const TIME_CAP = 10.0;

	private const SIZE_CAP = 33554432;
	private const WORD_MAGIC = 0xA5EC;
	private const FLAG_ENCRYPTED = 1 << 7;
	private const VENDOR_SPOOL_PREFIX = 'PHPWord_MsDoc';

	/**
	 * The reader the extractor runs, injectable only so a test can
	 * stand in for it and reproduce the vendored spool the finally's
	 * sweep exists to delete.
	 */
	public function __construct(
		private readonly ?MsDoc $reader = null,
	) {
	}

	public function owns(): array {
		return ['doc'];
	}

	public function extract($stream, string $extension, int $budget): ExtractionResult {
		$sink = new TextSink($budget);

		$path = tempnam(sys_get_temp_dir(), 'fts-sql-doc');
		if ($path === false) {
			return new ExtractionResult(null, ExtractionCause::ParserGaveUp, 'no temporary file could be created for the reader');
		}

		// empty until the snapshot just before the reader runs: an
		// abort in the gates below must not leave the finally's sweep
		// reading a variable that was never set
		$spoolBefore = [];

		try {
			$size = $this->spill($stream, $path);
			if ($size > self::SIZE_CAP) {
				throw new ExtractionAbort(
					ExtractionCause::ParserGaveUp,
					sprintf('the document is %s bytes, past this extractor\'s %s-byte wall', number_format($size), number_format(self::SIZE_CAP)),
				);
			}

			$container = OleFile::open($path);
			$wordDocument = $container->stream('WordDocument');
			if ($wordDocument === null) {
				throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'no WordDocument stream in the compound container');
			}
			if ($container->stream('1Table') === null) {
				throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'no 1Table stream in the compound container');
			}

			if (strlen($wordDocument) < 32
				|| (ord($wordDocument[0]) | ord($wordDocument[1]) << 8) !== self::WORD_MAGIC) {
				throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'no Word file information block in the WordDocument stream');
			}
			$flags = ord($wordDocument[0x0A]) | ord($wordDocument[0x0B]) << 8;
			if (($flags & self::FLAG_ENCRYPTED) !== 0) {
				throw new ExtractionAbort(
					ExtractionCause::Encrypted,
					'the document is encrypted: indexed on title, access and tags only',
				);
			}

			$spoolBefore = self::vendorSpool();
			$document = ($this->reader ?? new MsDoc())->load($path);

			$deadline = microtime(true) + self::TIME_CAP;
			foreach ($document->getSections() as $section) {
				if ($sink->full()) {
					break;
				}
				if (microtime(true) > $deadline) {
					throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'extraction ran past its time budget');
				}
				$this->readSection($section, $sink, $deadline);
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
				'the reader gave up on the document: ' . $e->getMessage() . self::recoveredNote($sink),
			);
		} finally {
			foreach (array_diff(self::vendorSpool(), $spoolBefore) as $spilled) {
				@unlink($spilled);
			}
			@unlink($path);
		}
	}

	private function readSection(Section $section, TextSink $sink, float $deadline): void {
		foreach ($section->getElements() as $element) {
			if ($sink->full()) {
				return;
			}
			if ($element instanceof TextBreak) {
				$sink->accept("\n");
				continue;
			}
			// the reader emits one Text per line — the paragraph
			// delimiters it split on — and a TextRun when runs carry
			// their own formatting
			if (!$element instanceof Text && !$element instanceof TextRun) {
				continue;
			}
			if (microtime(true) > $deadline) {
				throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'extraction ran past its time budget');
			}
			// the reader builds text a code point at a time; what
			// arrives here is scrubbed, never stored broken
			$text = $element->getText();
			if ($text !== null && $text !== '') {
				$sink->accept(mb_scrub($text));
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
				if ($total > self::SIZE_CAP) {
					return $total; // the caller refuses it with the cause and the size
				}
				fwrite($out, $chunk);
			}
		} finally {
			fclose($out);
		}
		return $total;
	}

	/**
	 * The spool files the vendored reader may have left behind, by the
	 * prefix its tempnam() calls carry.
	 *
	 * @return list<string>
	 */
	private static function vendorSpool(): array {
		$files = glob(sys_get_temp_dir() . '/' . self::VENDOR_SPOOL_PREFIX . '*');
		return $files === false ? [] : $files;
	}

	private static function recoveredNote(TextSink $sink): string {
		return $sink->text() === '' ? '' : ' — indexed on what was extracted before it gave up';
	}
}
