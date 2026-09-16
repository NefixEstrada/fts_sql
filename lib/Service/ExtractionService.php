<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Service;

use OCA\FtsSql\Extraction\ExtractionCause;
use OCA\FtsSql\Extraction\ExtractionResult;
use OCA\FtsSql\Extraction\IExtractor;
use OCA\FtsSql\Extraction\OdfExtractor;
use OCA\FtsSql\Extraction\OoxmlExtractor;
use OCA\FtsSql\Extraction\PdfExtractor;
use OCA\FtsSql\Extraction\PptExtractor;
use OCA\FtsSql\Extraction\TextSink;

/**
 * Bytes to a plain-text ExtractionResult within the content budget. The
 * dispatch has three directions: an extension an extractor of the
 * Extraction namespace owns (Milestone 2: OOXML and ODF, over streams —
 * DESIGN.md's "where extraction plugs in" open issue prescribes the stream
 * interface so the files provider's later streaming fast path costs no
 * rewrite); an extension on the not-yet list, which is a cause to report
 * and never plain text; and everything else, which is a plain-text
 * candidate exactly as in Milestone 1 (.txt, .md, .csv, .log and every
 * format nobody thought of), because the binaries, archives and images are
 * the ones that must be refused as text.
 */
final class ExtractionService {
	/**
	 * The formats later milestones own (the legacy binary Office
	 * formats still ahead) plus archives, executables, images and
	 * media: never plain text, not extracted yet.
	 */
	public const NOT_EXTRACTED_EXTENSIONS = [
		'doc', 'xls', 'epub',
		'zip', 'gz', 'tar',
		'exe', 'bin',
		'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp', 'heic',
		'mp3', 'mp4', 'avi', 'mkv', 'mov', 'wav',
	];

	/**
	 * @return ExtractionResult the text within the budget, with a cause when
	 *                          something is missing or was cut
	 */
	public static function extract(string $bytes, string $extension, int $budget): ExtractionResult {
		$stream = fopen('php://temp/maxmemory:1048576', 'w+b');
		if ($stream === false) {
			return new ExtractionResult(null, ExtractionCause::ParserGaveUp, 'the document could not be wrapped in a stream');
		}
		fwrite($stream, $bytes);
		rewind($stream);

		$result = self::extractStream($stream, $extension, $budget);
		fclose($stream);
		return $result;
	}

	/**
	 * The stream entry point the streaming fast path will call directly.
	 *
	 * @param resource $stream
	 */
	public static function extractStream($stream, string $extension, int $budget): ExtractionResult {
		$extension = mb_strtolower($extension, 'UTF-8');

		foreach (self::extractors() as $extractor) {
			if (in_array($extension, $extractor->owns(), true)) {
				return $extractor->extract($stream, $extension, $budget);
			}
		}

		if (in_array($extension, self::NOT_EXTRACTED_EXTENSIONS, true)) {
			return new ExtractionResult(
				null,
				ExtractionCause::Unsupported,
				sprintf('the "%s" format is not extracted yet: indexed on title, access and tags only', $extension),
			);
		}

		return self::plainText($stream, $budget, $extension);
	}

	/**
	 * Milestone 1's path, unchanged in its checks: valid UTF-8, no control
	 * character outside tab, newline and carriage return, then the shared
	 * budget cut. The read is not capped here — the bytes are already in the
	 * process (the framework hands the whole document over), and the cut is
	 * what bounds what is stored.
	 *
	 * @param resource $stream
	 */
	private static function plainText($stream, int $budget, string $extension): ExtractionResult {
		$bytes = stream_get_contents($stream);
		if ($bytes === false) {
			return new ExtractionResult(null, ExtractionCause::ParserGaveUp, 'the document stream could not be read');
		}

		if (!mb_check_encoding($bytes, 'UTF-8')) {
			return new ExtractionResult(
				null,
				ExtractionCause::Unsupported,
				sprintf('the content is not plain text (invalid UTF-8) and no extractor owns "%s": indexed on title, access and tags only', $extension),
			);
		}

		// \P{C} is everything but control and format characters, so the
		// negated class matches any control character other than tab,
		// newline and carriage return. !== 0 (rather than === 1) also
		// refuses on a false, which cannot happen after the encoding check
		// but fails closed if it ever did.
		if (preg_match('/[^\P{C}\t\n\r]/u', $bytes) !== 0) {
			return new ExtractionResult(
				null,
				ExtractionCause::Unsupported,
				sprintf('the content is not plain text (control characters) and no extractor owns "%s": indexed on title, access and tags only', $extension),
			);
		}

		$sink = new TextSink($budget);
		$sink->accept($bytes);

		if ($sink->overflowed()) {
			return new ExtractionResult(
				$sink->text(),
				ExtractionCause::BudgetCut,
				sprintf('the content was cut at the %s-byte budget: the document is findable by what survived', number_format($budget)),
			);
		}
		return ExtractionResult::complete($sink->text());
	}

	/**
	 * @return list<IExtractor>
	 */
	private static function extractors(): array {
		return [
			new OoxmlExtractor(),
			new OdfExtractor(),
			new PdfExtractor(),
			new PptExtractor(),
		];
	}
}
