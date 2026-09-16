<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Extraction;

use ZipArchive;

/**
 * A zip opened for extraction, with the zip-bomb posture DESIGN.md's
 * Security section prescribes: a ratio pre-check against the central
 * directory's declared sizes before any entry is inflated, then bounded
 * incremental inflation — an entry is read in chunks and the read is
 * abandoned the moment it passes its cap, so neither a lying directory nor
 * an honest one over the cap ever materializes in full. A per-container
 * total bounds the sum across entries.
 *
 * ZipArchive needs a path, not a string or a stream, which is the temp-file
 * cost the design's "where extraction plugs in" open issue already prices
 * into option A; the stream is materialized once here and every extractor
 * shares it.
 */
final class ZipContainer {
	public const INPUT_CAP = 67108864;
	public const TOTAL_READ_CAP = 268435456;
	public const RATIO_CAP = 100;
	public const MAX_SCANNED_ENTRIES = 8192;

	private const OLE_MAGIC = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

	private bool $closed = false;
	private int $totalRead = 0;

	private function __construct(
		private readonly string $path,
		private readonly ZipArchive $zip,
	) {
	}

	/**
	 * @param resource $stream
	 * @throws ExtractionAbort over the input cap; an OLE container (a
	 *                         password-protected office file) when the
	 *                         caller owns one of those extensions; anything
	 *                         else that is not a zip as "parser gave up"
	 */
	public static function open($stream, bool $oleIsEncrypted): self {
		if (!class_exists(ZipArchive::class)) {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'the PHP zip extension is not available');
		}

		$path = (string)tempnam(sys_get_temp_dir(), 'fts_sql_');
		try {
			$written = self::materialize($stream, $path);

			$head = (string)file_get_contents($path, false, null, 0, 8);
			if ($oleIsEncrypted && str_starts_with($head, self::OLE_MAGIC)) {
				throw new ExtractionAbort(
					ExtractionCause::Encrypted,
					'the document is a password-protected office container (an OLE file): indexed on title, access and tags only',
				);
			}

			$zip = new ZipArchive();
			if ($zip->open($path) !== true) {
				if (!$oleIsEncrypted && str_starts_with($head, self::OLE_MAGIC)) {
					throw new ExtractionAbort(
						ExtractionCause::ParserGaveUp,
						'the document is an OLE file, not a zip container of the format its extension claims',
					);
				}
				throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'not a zip container');
			}
		} catch (\Throwable $e) {
			@unlink($path);
			throw $e;
		}

		return new self($path, $zip);
	}

	/**
	 * @param resource $stream
	 * @return int bytes written
	 * @throws ExtractionAbort over the input cap
	 */
	private static function materialize($stream, string $path): int {
		if (stream_get_meta_data($stream)['seekable']) {
			rewind($stream);
		}

		$out = fopen($path, 'wb');
		if ($out === false) {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'the document could not be written to a temporary file');
		}

		$written = 0;
		try {
			while (!feof($stream)) {
				$chunk = fread($stream, 1048576);
				if ($chunk === false || $chunk === '') {
					break;
				}
				$written += strlen($chunk);
				if ($written > self::INPUT_CAP) {
					throw new ExtractionAbort(
						ExtractionCause::ParserGaveUp,
						sprintf('the document is over the %d MiB read cap for container formats', self::INPUT_CAP >> 20),
					);
				}
				fwrite($out, $chunk);
			}
		} finally {
			fclose($out);
		}
		return $written;
	}

	public function exists(string $name): bool {
		return $this->zip->locateName($name) !== false;
	}

	/**
	 * Every entry name, up to the scan cap: the slides of a presentation
	 * and the sheets of a workbook have to be discovered, and a container
	 * with more entries than this can only be indexing filler past a full
	 * sink anyway.
	 *
	 * @return list<string>
	 */
	public function names(): array {
		$count = min($this->zip->numFiles, self::MAX_SCANNED_ENTRIES);
		$names = [];
		for ($i = 0; $i < $count; $i++) {
			$stat = $this->zip->statIndex($i);
			if ($stat !== false) {
				$names[] = (string)$stat['name'];
			}
		}
		return $names;
	}

	/**
	 * One entry's bytes, inflated incrementally under the caps.
	 *
	 * @return ?string null when the entry does not exist
	 * @throws ExtractionAbort encrypted entry, ratio or read cap
	 */
	public function read(string $name, int $cap): ?string {
		$index = $this->zip->locateName($name);
		if ($index === false) {
			return null;
		}

		$stat = $this->zip->statIndex($index);
		$size = (int)($stat['size'] ?? 0);
		$compSize = (int)($stat['comp_size'] ?? 0);
		if (isset($stat['encryption_method']) && (int)$stat['encryption_method'] !== 0) {
			throw new ExtractionAbort(
				ExtractionCause::Encrypted,
				sprintf('the zip entry "%s" is encrypted: indexed on title, access and tags only', $name),
			);
		}
		if ($size > $cap) {
			throw new ExtractionAbort(
				ExtractionCause::ParserGaveUp,
				sprintf('the zip entry "%s" expands past its %s-byte read cap', $name, number_format($cap)),
			);
		}
		if ($compSize > 0 && $size / $compSize > self::RATIO_CAP) {
			throw new ExtractionAbort(
				ExtractionCause::ParserGaveUp,
				sprintf(
					'the zip entry "%s" inflates %d× over its compressed size, past the %d× ratio cap of a zip bomb',
					$name,
					(int)round($size / $compSize),
					self::RATIO_CAP,
				),
			);
		}

		$stream = $this->zip->getStream($name);
		if ($stream === false) {
			throw new ExtractionAbort(
				ExtractionCause::Encrypted,
				sprintf('the zip entry "%s" cannot be read (encrypted, or an unsupported compression method): indexed on title, access and tags only', $name),
			);
		}

		$data = '';
		try {
			while (!feof($stream)) {
				$chunk = fread($stream, 65536);
				if ($chunk === false) {
					break;
				}
				$data .= $chunk;
				if (strlen($data) > $cap) {
					throw new ExtractionAbort(
						ExtractionCause::ParserGaveUp,
						sprintf('the zip entry "%s" expands past its %s-byte read cap', $name, number_format($cap)),
					);
				}
				if ($this->totalRead + strlen($data) > self::TOTAL_READ_CAP) {
					throw new ExtractionAbort(
						ExtractionCause::ParserGaveUp,
						sprintf('the container\'s entries expand past the %d MiB total read cap', self::TOTAL_READ_CAP >> 20),
					);
				}
			}
		} finally {
			fclose($stream);
		}

		$this->totalRead += strlen($data);
		return $data;
	}

	public function close(): void {
		if ($this->closed) {
			return;
		}
		$this->closed = true;
		$this->zip->close();
		@unlink($this->path);
	}

	public function __destruct() {
		$this->close();
	}
}
