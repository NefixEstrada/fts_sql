<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Extraction\Ppt;

use OCA\FtsSql\Extraction\ExtractionAbort;
use OCA\FtsSql\Extraction\ExtractionCause;

/**
 * A strict reader of the compound-file container a legacy Office binary
 * rides in (DESIGN.md, Milestone 4). The PhpOffice readers hand this
 * layer to OLERead, which trusts the file: a FAT or mini-FAT chain that
 * loops appends sectors forever, a directory chain past the end of the
 * file reads zeroes as its own next sector. This reader owns the two
 * streams the PowerPoint route needs and walks every chain with a
 * visited set and a size-derived cap, so a hostile compound file is
 * refused here, before the library ever sees it.
 *
 * Only 512-byte sectors are accepted: that is what OLERead assumes and
 * what every PowerPoint 97 file carries; a v4 4096-byte container is a
 * different shape this app refuses rather than half-reads.
 */
final class OleFile {
	private const SECTOR_SIZE = 512;
	private const MINI_SECTOR_SIZE = 64;
	private const MINI_STREAM_THRESHOLD = 4096;
	private const END_OF_CHAIN = 0xFFFFFFFE;
	private const FREESECT = 0xFFFFFFFF;

	/** A PowerPoint file the framework hands over is gated at 20 MB; this is the wall beyond which the read itself is refused. */
	private const MAX_FILE = 67108864;

	/** A directory of more sectors than this is not a presentation's. */
	private const MAX_DIRECTORY_SECTORS = 1024;

	private const TYPE_STREAM = 2;
	private const TYPE_ROOT = 5;

	/** @var array<int, array{name: string, type: int, start: int, size: int}> */
	private array $entries = [];
	private string $fat = '';
	private string $miniFat = '';
	private string $miniStream = '';

	private function __construct(
		private readonly string $bytes,
		private readonly int $sectorCount,
	) {
		$this->readFat();
		$this->readDirectory();
		$this->readMiniFat();
	}

	public static function open(string $path): self {
		$size = filesize($path);
		if ($size === false) {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'the compound container could not be read');
		}
		$bytes = file_get_contents($path);
		if ($bytes === false) {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'the compound container could not be read');
		}
		if (strncmp($bytes, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) !== 0) {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'not a PowerPoint 97 file: no compound-file signature');
		}
		if ($size < self::SECTOR_SIZE * 2 || $size > self::MAX_FILE) {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'not a PowerPoint 97 file: the compound container is truncated or oversize');
		}
		if (self::u16($bytes, 0x1E) !== 9) {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'not a PowerPoint 97 file: only 512-byte compound sectors are read');
		}
		return new self($bytes, intdiv($size - self::SECTOR_SIZE, self::SECTOR_SIZE));
	}

	/**
	 * The bytes of a named stream, exactly its declared size, or null
	 * when the container holds no stream of that name.
	 */
	public function stream(string $name): ?string {
		foreach ($this->entries as $entry) {
			if ($entry['type'] === self::TYPE_STREAM && strcasecmp($entry['name'], $name) === 0) {
				return $this->readStream($entry['start'], $entry['size']);
			}
		}
		return null;
	}

	private function readStream(int $start, int $size): string {
		if ($size === 0) {
			return '';
		}
		if ($start === self::END_OF_CHAIN || $start === self::FREESECT) {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'the compound container names a stream with no first sector');
		}
		if ($size < self::MINI_STREAM_THRESHOLD) {
			if ($this->miniStream === '' || $this->miniFat === '') {
				throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'the compound container puts a short stream in a mini-stream it does not carry');
			}
			$data = $this->readChain($start, $size, self::MINI_SECTOR_SIZE, $this->miniFat, $this->miniStream, 0);
			return substr($data, 0, $size);
		}
		$data = $this->readChain($start, $size, self::SECTOR_SIZE, $this->fat, $this->bytes, self::SECTOR_SIZE);
		return substr($data, 0, $size);
	}

	/**
	 * One stream's sectors: every hop is checked against the table's
	 * own bounds and no sector is visited twice — a loop costs the
	 * refusal, not the process. The chain is followed to its end
	 * whatever the declared size, the reader's own tolerance: writers
	 * leave stale allocation entries chained past a short stream's
	 * last sector, and the declared size trims the bytes afterwards.
	 */
	private function readChain(int $start, int $size, int $sectorSize, string $fat, string $data, int $base): string {
		$sectorCount = intdiv(strlen($data) - $base, $sectorSize);
		$out = '';
		$visited = [];
		$sector = $start;
		while ($sector !== self::END_OF_CHAIN) {
			if (isset($visited[$sector]) || $sector < 0 || $sector >= $sectorCount) {
				throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'the compound container chains a stream in a circle or past its own end');
			}
			$visited[$sector] = true;
			$out .= substr($data, $base + $sector * $sectorSize, $sectorSize);
			$at = $sector * 4;
			if ($at + 4 > strlen($fat)) {
				throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'the compound container chains a stream past its allocation table');
			}
			$sector = self::u32($fat, $at);
		}
		return $out;
	}

	private function readFat(): void {
		$numFat = self::u32($this->bytes, 0x2C);
		if ($numFat < 1 || $numFat > $this->sectorCount) {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'the compound container declares no sane allocation table');
		}

		// the first 109 table sectors are in the header; any more hang
		// off the extension chain, itself walked with a visited set
		$ids = [];
		for ($i = 0; $i < min($numFat, 109); $i++) {
			$ids[] = self::u32($this->bytes, 0x4C + $i * 4);
		}
		$numExtension = self::u32($this->bytes, 0x48);
		if ($numFat > 109) {
			if ($numExtension < 1 || $numExtension > $this->sectorCount) {
				throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'the compound container declares an allocation table it does not carry');
			}
			$sector = self::u32($this->bytes, 0x44);
			$visited = [];
			while ($sector !== self::END_OF_CHAIN && count($ids) < $numFat) {
				if (isset($visited[$sector]) || $sector < 0 || $sector >= $this->sectorCount) {
					throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'the compound container chains its allocation table in a circle');
				}
				$visited[$sector] = true;
				$base = self::SECTOR_SIZE + $sector * self::SECTOR_SIZE;
				for ($i = 0; $i < 127 && count($ids) < $numFat; $i++) {
					$ids[] = self::u32($this->bytes, $base + $i * 4);
				}
				$sector = self::u32($this->bytes, $base + 127 * 4);
			}
		}

		$this->fat = '';
		foreach ($ids as $id) {
			if ($id < 0 || $id >= $this->sectorCount) {
				throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'the compound container points its allocation table outside the file');
			}
			$this->fat .= $this->sector($id);
		}
	}

	private function readDirectory(): void {
		$rootStart = self::u32($this->bytes, 0x30);
		if ($rootStart === self::END_OF_CHAIN || $rootStart >= $this->sectorCount) {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'the compound container has no directory');
		}
		// the directory's size is not declared before it is read: its
		// own entry is inside it, so the read is capped at what no
		// presentation's directory could exceed
		$directory = $this->readChainCapped($rootStart, self::MAX_DIRECTORY_SECTORS);
		if ($directory === '') {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'the compound container has an empty directory');
		}

		foreach (str_split($directory, 128) as $block) {
			if (strlen($block) < 128) {
				break;
			}
			$nameLength = self::u16($block, 0x40);
			$type = ord($block[0x42]);
			if ($nameLength < 2 || ($type !== self::TYPE_STREAM && $type !== self::TYPE_ROOT)) {
				continue;
			}
			$this->entries[] = [
				'name' => str_replace("\0", '', substr($block, 0, $nameLength)),
				'type' => $type,
				'start' => self::u32($block, 0x74),
				'size' => self::u32($block, 0x78),
			];
		}
	}

	private function readMiniFat(): void {
		$miniFatStart = self::u32($this->bytes, 0x3C);
		$numMiniFat = self::u32($this->bytes, 0x40);
		if ($miniFatStart === self::END_OF_CHAIN || $numMiniFat === 0) {
			return;
		}
		$this->miniFat = $this->readChainCapped($miniFatStart, $numMiniFat);

		// the mini-stream container hangs off the root entry, and its
		// sectors live in the main allocation table however small the
		// container's own declared size: only streams below the
		// threshold take the mini path
		foreach ($this->entries as $entry) {
			if ($entry['type'] === self::TYPE_ROOT) {
				if ($entry['start'] === self::END_OF_CHAIN || $entry['size'] === 0) {
					return;
				}
				$container = $this->readChain($entry['start'], $entry['size'], self::SECTOR_SIZE, $this->fat, $this->bytes, self::SECTOR_SIZE);
				$this->miniStream = substr($container, 0, $entry['size']);
				return;
			}
		}
	}

	/**
	 * A chain read for streams whose declared size lives inside the
	 * stream itself (the allocation table, the directory): capped at
	 * $maxSectors sectors rather than by an outer size.
	 */
	private function readChainCapped(int $start, int $maxSectors): string {
		$out = '';
		$visited = [];
		$sector = $start;
		while ($sector !== self::END_OF_CHAIN) {
			if (isset($visited[$sector]) || count($visited) >= $maxSectors
				|| $sector < 0 || $sector >= $this->sectorCount) {
				throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'the compound container chains a stream in a circle or past its own end');
			}
			$visited[$sector] = true;
			$out .= $this->sector($sector);
			$at = $sector * 4;
			if ($at + 4 > strlen($this->fat)) {
				throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'the compound container chains a stream past its allocation table');
			}
			$sector = self::u32($this->fat, $at);
		}
		return $out;
	}

	private function sector(int $id): string {
		return substr($this->bytes, self::SECTOR_SIZE + $id * self::SECTOR_SIZE, self::SECTOR_SIZE);
	}

	private static function u16(string $data, int $at): int {
		return ord($data[$at]) | ord($data[$at + 1]) << 8;
	}

	private static function u32(string $data, int $at): int {
		return ord($data[$at]) | ord($data[$at + 1]) << 8 | ord($data[$at + 2]) << 16 | ord($data[$at + 3]) << 24;
	}
}
