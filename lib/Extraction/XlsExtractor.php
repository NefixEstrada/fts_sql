<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Extraction;

use OCA\FtsSql\Extraction\Ppt\OleFile;
use OCA\FtsSql\Vendor\PhpOffice\PhpSpreadsheet\Cell\Cell;
use OCA\FtsSql\Vendor\PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use OCA\FtsSql\Vendor\PhpOffice\PhpSpreadsheet\Reader\Xls as XlsReader;
use OCA\FtsSql\Vendor\PhpOffice\PhpSpreadsheet\Worksheet\Row;
use OCA\FtsSql\Vendor\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Excel 97-2003 (.xls): the PhpSpreadsheet reader of DESIGN.md's
 * Milestone 4, behind this app's own compound-file gate — the same
 * posture as the .ppt extractor, and both for the same reasons: the
 * reader hands the container to an OLERead that trusts it, and it
 * answers an encrypted workbook with a generic decryption failure
 * rather than a cause. The FILEPASS record is a record identifier in
 * the workbook stream, read here before the reader is ever invoked.
 *
 * The measurement the design asked for
 * (benchmark/results/2026-09-16-xls.json) settled the extractor's
 * shape: setReadFilter does not bound the Xls reader's memory — the
 * cost is the cell object model, ~22x the text bytes, affordable at
 * the framework's own 20 MB gate — but the reader consults the filter
 * before creating each cell, so the filter carries a deadline: when
 * the clock trips, cells stop loading and the workbook is indexed on
 * what survived, the honest boundary the PDF and PPT extractors
 * document. The walk takes formulas for what they are — the sheet's
 * machinery, not its text.
 */
final class XlsExtractor implements IExtractor {
	public const TIME_CAP = 10.0;

	private const SIZE_CAP = 33554432;
	private const FILEPASS = 0x002F;

	public function owns(): array {
		return ['xls'];
	}

	public function extract($stream, string $extension, int $budget): ExtractionResult {
		$sink = new TextSink($budget);

		$path = tempnam(sys_get_temp_dir(), 'fts-sql-xls');
		if ($path === false) {
			return new ExtractionResult(null, ExtractionCause::ParserGaveUp, 'no temporary file could be created for the reader');
		}

		try {
			$size = $this->spill($stream, $path);
			if ($size > self::SIZE_CAP) {
				throw new ExtractionAbort(
					ExtractionCause::ParserGaveUp,
					sprintf('the workbook is %s bytes, past this extractor\'s %s-byte wall', number_format($size), number_format(self::SIZE_CAP)),
				);
			}

			$container = OleFile::open($path);
			$workbook = $container->stream('Workbook') ?? $container->stream('Book');
			if ($workbook === null) {
				throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'no Workbook stream in the compound container');
			}
			if (self::hasFilePass($workbook)) {
				throw new ExtractionAbort(
					ExtractionCause::Encrypted,
					'the workbook is encrypted: indexed on title, access and tags only',
				);
			}

			$deadline = microtime(true) + self::TIME_CAP;
			$filter = new class($deadline) implements IReadFilter {
				public bool $tripped = false;

				public function __construct(
					private readonly float $deadline,
				) {
				}

				public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool {
					if (microtime(true) > $this->deadline) {
						$this->tripped = true;

						return false;
					}
					return true;
				}
			};

			$reader = new XlsReader();
			$reader->setReadDataOnly(true);
			$reader->setReadFilter($filter);
			$book = $reader->load($path);

			foreach ($book->getAllSheets() as $sheet) {
				if ($sink->full()) {
					break;
				}
				$this->readSheet($sheet, $sink);
			}
			$book->disconnectWorksheets();

			if ($sink->overflowed()) {
				return new ExtractionResult(
					$sink->text(),
					ExtractionCause::BudgetCut,
					sprintf('the content was cut at the %s-byte budget: the document is findable by what survived', number_format($budget)),
				);
			}
			if ($filter->tripped) {
				throw new ExtractionAbort(
					ExtractionCause::ParserGaveUp,
					'extraction ran past its time budget and stopped loading cells',
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
				'the reader gave up on the workbook: ' . $e->getMessage() . self::recoveredNote($sink),
			);
		} finally {
			@unlink($path);
		}
	}

	/**
	 * The File Protection Block's FILEPASS record: everything after it
	 * is encrypted. The walk is over the record headers the stream
	 * itself declares, bounded by the stream's real size — the same
	 * refusal a lying length earns anywhere else.
	 */
	private static function hasFilePass(string $workbook): bool {
		$at = 0;
		$end = strlen($workbook);
		$records = 0;
		while ($at + 4 <= $end) {
			$id = ord($workbook[$at]) | ord($workbook[$at + 1]) << 8;
			if ($id === self::FILEPASS) {
				return true;
			}
			$length = ord($workbook[$at + 2]) | ord($workbook[$at + 3]) << 8;
			$at += 4 + $length;
			if (++$records > intdiv($end, 4) + 2) {
				return false;
			}
		}
		return false;
	}

	private function readSheet(Worksheet $sheet, TextSink $sink): void {
		foreach ($sheet->getRowIterator() as $row) {
			if ($sink->full()) {
				return;
			}
			// the newline goes after a row that said something: an
			// empty row or an all-numeric row is not a line of text
			if ($this->readRow($row, $sink)) {
				$sink->accept("\n");
			}
		}
	}

	private function readRow(Row $row, TextSink $sink): bool {
		$wrote = false;
		foreach ($row->getCellIterator() as $cell) {
			if ($cell === null) {
				continue;
			}
			$value = $cell->getValue();
			// a formula is the sheet's machinery: its text is not
			// content, and its calculated value is not text either
			if (is_string($value) && $value !== '' && !$cell->isFormula()) {
				$sink->accept(mb_scrub($value));
				$wrote = true;
			}
		}
		return $wrote;
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

	private static function recoveredNote(TextSink $sink): string {
		return $sink->text() === '' ? '' : ' — indexed on what was extracted before it gave up';
	}
}
