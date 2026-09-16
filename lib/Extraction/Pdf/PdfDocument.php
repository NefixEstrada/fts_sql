<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Extraction\Pdf;

use OCA\FtsSql\Extraction\ExtractionAbort;
use OCA\FtsSql\Extraction\ExtractionCause;

/**
 * The random-access half of the PDF extractor: the whole file under
 * one input cap, and the cross-reference index that turns an object
 * number into a byte offset — the alternative the Milestone 3 route
 * decision measured (DESIGN.md, "Open issue: the PDF route"): a
 * library that materialises every object of a 21 MiB file costs
 * 697.5 MiB of PHP memory before any text, while an index plus
 * per-page reads cost what the pages hold and no more.
 *
 * The index accepts the three shapes real documents ship: classic
 * tables, PDF 1.5 cross-reference streams (objects compressed into
 * object streams included), and hybrid files that carry both. When
 * the startxref chain is unusable, objects are located by one
 * bounded scan of the file — the fallback every extractor keeps for
 * truncated and rewritten documents — and the catalog is found from
 * the last trailer keyword, or by the scan when there is no trailer
 * at all. The /Prev chain is walked with a visited set and a section
 * cap, so a loop or a fabricated chain costs the walk, not the
 * process.
 */
final class PdfDocument {
	public const INPUT_CAP = 67108864;
	public const TOTAL_DECODE_CAP = 268435456;
	private const MAX_XREF_SECTIONS = 64;
	private const MAX_ENTRIES = 2000000;
	private const MAX_CACHED_OBJECTS = 512;
	private const MAX_CACHED_OBJSTM = 32;
	private const OBJSTM_CAP = 4194304;
	private const DICT_CAP = 1048576;
	private const XREF_CAP = 67108864;
	private const CATALOG_SEARCH = 64;
	private const MAX_CACHED_FONTS = 256;

	public readonly string $data;
	/** @var array<string, mixed> the trailer dict, or [] when there was none to read */
	public readonly array $trailer;
	public readonly bool $encrypted;

	/** @var array<int, array{t: int, offset?: int, stm?: int, idx?: int}> */
	private array $entries = [];
	/** @var array<int, PdfIndirect> objects without streams, resolved once */
	private array $cache = [];
	/** @var array<int, array{string, list<int>, int}> object-stream payloads, their object numbers and /First */
	private array $objstmCache = [];
	/** @var array<string, PdfFont> fonts by resource name or object number */
	private array $fontCache = [];
	private int $totalDecoded = 0;
	private ?int $rootObject = null;
	private readonly ?PdfCrypt $crypt;

	/**
	 * @param resource $stream
	 */
	public static function open($stream): self {
		if (stream_get_meta_data($stream)['seekable']) {
			rewind($stream);
		}

		$data = '';
		while (!feof($stream)) {
			$chunk = fread($stream, 1048576);
			if ($chunk === false || $chunk === '') {
				break;
			}
			$data .= $chunk;
			if (strlen($data) > self::INPUT_CAP) {
				throw new ExtractionAbort(
					ExtractionCause::ParserGaveUp,
					sprintf('the document is over the %d MiB read cap for container formats', self::INPUT_CAP >> 20),
				);
			}
		}

		if (substr($data, 0, 5) !== '%PDF-') {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'not a pdf: no %PDF- header');
		}

		return new self($data);
	}

	private function __construct(string $data) {
		$this->data = $data;

		$trailer = [];
		$encrypted = false;
		$offset = self::findStartxref($data);

		$visited = [];
		$sections = 0;
		while ($offset !== null && $offset > 0 && $offset < strlen($data) && $sections < self::MAX_XREF_SECTIONS) {
			if (isset($visited[$offset])) {
				break;
			}
			$visited[$offset] = true;
			$sections++;

			$cursor = new PdfCursor($data, $offset);
			$cursor->skipSpace();
			$section = $cursor->match('xref') ? $this->classicTable($cursor) : $this->xrefStream($offset);
			if ($section === null) {
				break;
			}

			if ($section['trailer'] !== []) {
				$trailer = $section['trailer'];
			}
			$encryption = $trailer['Encrypt'] ?? null;
			if ($encryption !== null && !($encryption instanceof PdfName && $encryption->name === 'null')) {
				$encrypted = true;
			}

			// a hybrid file's supplemental stream table never overrides
			// the objects the main table already places
			foreach ($section['entries'] as $num => $entry) {
				if (!isset($this->entries[$num])) {
					$this->entries[$num] = $entry;
				}
			}

			$extra = $trailer['XRefStm'] ?? null;
			if (is_int($extra) && $extra > 0 && $extra < strlen($data) && !isset($visited[$extra])) {
				$visited[$extra] = true;
				$supplement = $this->xrefStream($extra);
				if ($supplement !== null) {
					foreach ($supplement['entries'] as $num => $entry) {
						if (!isset($this->entries[$num])) {
							$this->entries[$num] = $entry;
						}
					}
				}
			}

			$prev = $trailer['Prev'] ?? null;
			$offset = is_int($prev) && $prev > 0 ? $prev : null;
		}

		$root = $trailer['Root'] ?? null;
		if (!$root instanceof PdfRef) {
			// an unusable chain: locate objects by scan, and the catalog
			// from the last trailer keyword when the file still has one
			$this->scanObjects();
			$byKeyword = $this->trailerByKeyword();
			$root = ($byKeyword['Root'] ?? null) instanceof PdfRef ? $byKeyword['Root'] : null;
		}

		$this->trailer = $root instanceof PdfRef ? $trailer : ($byKeyword ?? $trailer);
		if ($root instanceof PdfRef) {
			$this->rootObject = $root->object;
		}
		$encryption = $this->trailer['Encrypt'] ?? null;
		$this->encrypted = $encrypted || ($encryption !== null && !($encryption instanceof PdfName && $encryption->name === 'null'));

		// the standard security handler with an empty user password —
		// a permission-restricted document everyone can read — decrypts
		// here; anything else stays the Encrypted cause
		if ($this->encrypted && $encryption instanceof PdfRef) {
			$encryptDict = $this->getDictionary($encryption);
			$this->crypt = $encryptDict !== null ? PdfCrypt::build($encryptDict, $this, $this->trailer) : null;
		} else {
			$this->crypt = null;
		}
	}

	/**
	 * The decryption handler for a document that carries one we can
	 * run; null when the document is not encrypted, or is and we
	 * cannot.
	 */
	public function crypt(): ?PdfCrypt {
		return $this->crypt;
	}

	private static function findStartxref(string $data): ?int {
		$tail = substr($data, -1024);
		$at = strrpos($tail, 'startxref');
		if ($at === false) {
			return null;
		}
		$cursor = new PdfCursor($tail, $at + strlen('startxref'));
		$cursor->skipSpace();
		$number = $cursor->value();
		return is_int($number) ? $number : null;
	}

	/**
	 * @return array{entries: array<int, array{t: int, offset?: int, stm?: int, idx?: int}>, trailer: array<string, mixed>}|null
	 */
	private function classicTable(PdfCursor $cursor): ?array {
		$entries = [];
		while (true) {
			$cursor->skipSpace();
			if ($cursor->match('trailer')) {
				$cursor->skipSpace();
				$trailer = $cursor->value();
				return ['entries' => $entries, 'trailer' => is_array($trailer) ? $trailer : []];
			}

			$at = $cursor->pos;
			$start = $cursor->value();
			$count = $cursor->value();
			if (!is_int($start) || !is_int($count) || $start < 0 || $count < 0 || $start > self::MAX_ENTRIES) {
				$cursor->pos = $at;
				return null;
			}

			$cursor->skipSpace();
			for ($i = 0; $i < $count; $i++) {
				if (count($entries) >= self::MAX_ENTRIES) {
					return null;
				}
				$row = substr($this->data, $cursor->pos, 20);
				if (strlen($row) < 18) {
					return null;
				}
				$cursor->pos += 20;

				$offset = (int)substr($row, 0, 10);
				$type = $row[17] ?? 'f';
				$entries[$start + $i] = ($type === 'n' && $offset > 0 && $offset < strlen($this->data))
					? ['t' => 1, 'offset' => $offset]
					: ['t' => 0];
			}
		}
	}

	/**
	 * @return array{entries: array<int, array{t: int, offset?: int, stm?: int, idx?: int}>, trailer: array<string, mixed>}|null
	 */
	private function xrefStream(int $offset): ?array {
		$object = $this->readIndirectAt($offset, self::XREF_CAP);
		$dict = $object->value;
		$type = is_array($dict) ? ($dict['Type'] ?? null) : null;
		if (!($type instanceof PdfName) || $type->name !== 'XRef' || !is_array($dict) || $object->stream === null) {
			return null;
		}

		$widths = $dict['W'] ?? null;
		if (!is_array($widths) || count($widths) < 3) {
			return null;
		}
		$w = array_map(static fn (mixed $width): int => is_int($width) ? $width : 0, $widths);
		$rowLength = $w[0] + $w[1] + $w[2];
		if ($rowLength < 1) {
			return null;
		}

		$size = is_int($dict['Size'] ?? null) ? $dict['Size'] : 0;
		$pairs = $dict['Index'] ?? null;
		if (!is_array($pairs) || $pairs === []) {
			$pairs = [0, $size];
		}

		$totalRows = 0;
		for ($i = 1; $i < count($pairs); $i += 2) {
			$totalRows += is_int($pairs[$i]) ? $pairs[$i] : 0;
		}
		if ($totalRows < 1 || $totalRows > self::MAX_ENTRIES) {
			return null;
		}

		$cap = min(self::XREF_CAP, $rowLength * $totalRows + 65536);
		$decoded = $this->decodeStreamData($dict, $object->stream, $cap, 'the cross-reference stream');

		$entries = [];
		$at = 0;
		$len = strlen($decoded);
		for ($i = 0; $i + 1 < count($pairs); $i += 2) {
			$first = is_int($pairs[$i]) ? $pairs[$i] : 0;
			$count = is_int($pairs[$i + 1]) ? $pairs[$i + 1] : 0;
			for ($j = 0; $j < $count; $j++) {
				if (count($entries) >= self::MAX_ENTRIES || $at + $rowLength > $len) {
					return ['entries' => $entries, 'trailer' => $dict];
				}
				$fields = [];
				$pos = $at;
				for ($f = 0; $f < 3; $f++) {
					$fields[] = self::bigEndian($decoded, $pos, $w[$f]);
					$pos += $w[$f];
				}
				$at += $rowLength;

				$num = $first + $j;
				if ($fields[0] === 1 && $fields[1] > 0 && $fields[1] < strlen($this->data)) {
					$entries[$num] = ['t' => 1, 'offset' => $fields[1]];
				} elseif ($fields[0] === 2) {
					$entries[$num] = ['t' => 2, 'stm' => $fields[1], 'idx' => $fields[2]];
				} else {
					$entries[$num] = ['t' => 0];
				}
			}
		}
		return ['entries' => $entries, 'trailer' => $dict];
	}

	private static function bigEndian(string $data, int $at, int $length): int {
		$value = 0;
		for ($i = 0; $i < $length; $i++) {
			$value = ($value << 8) | ord($data[$at + $i]);
		}
		return $value;
	}

	/**
	 * The fallback for a broken startxref chain: one bounded scan for
	 * "num gen obj" headers. A number placed twice keeps its first
	 * occurrence, which is the object a well-formed file points at.
	 */
	private function scanObjects(): void {
		$at = 0;
		$found = 0;
		while ($found < self::MAX_ENTRIES) {
			$hit = preg_match('/(\d{1,10})[\x20\t\r\n\f]+(\d{1,5})[\x20\t\r\n\f]+obj\b/', $this->data, $match, 0, $at);
			if ($hit !== 1) {
				return;
			}
			$hit2 = preg_match('/\d{1,10}[\x20\t\r\n\f]+\d{1,5}[\x20\t\r\n\f]+obj\b/', $this->data, $whole, PREG_OFFSET_CAPTURE, $at);
			if ($hit2 !== 1) {
				return;
			}
			$objectStart = $whole[0][1];
			$at = $objectStart + strlen($whole[0][0]);

			$num = (int)$match[1];
			if ($num > 0 && !isset($this->entries[$num])) {
				$this->entries[$num] = ['t' => 1, 'offset' => $objectStart];
				$found++;
			}
		}
	}

	/**
	 * The dict after the file's last trailer keyword — a classic-table
	 * document with a damaged startxref still carries one.
	 *
	 * @return array<string, mixed>
	 */
	private function trailerByKeyword(): array {
		$at = strrpos($this->data, 'trailer');
		if ($at === false) {
			return [];
		}
		$cursor = new PdfCursor($this->data, $at + strlen('trailer'));
		$cursor->skipSpace();
		$dict = $cursor->value();
		return is_array($dict) ? $dict : [];
	}

	/**
	 * The object a reference names, or null when the index does not
	 * place it. Objects without streams are cached — a font dict is
	 * named by every page that uses it — and streams never are.
	 */
	public function getObject(int $num): ?PdfIndirect {
		if (array_key_exists($num, $this->cache)) {
			return $this->cache[$num];
		}

		$entry = $this->entries[$num] ?? null;
		$object = null;
		if ($entry !== null && $entry['t'] === 1) {
			$offset = $entry['offset'] ?? null;
			$object = is_int($offset) ? $this->readIndirectAt($offset, self::DICT_CAP) : null;
		} elseif ($entry !== null && $entry['t'] === 2) {
			$stm = $entry['stm'] ?? null;
			$idx = $entry['idx'] ?? null;
			$object = is_int($stm) && is_int($idx) ? $this->fromObjectStream($stm, $idx, $num) : null;
		}

		if ($object !== null && $object->stream === null) {
			if (count($this->cache) >= self::MAX_CACHED_OBJECTS) {
				$this->cache = [];
			}
			$this->cache[$num] = $object;
		}
		return $object;
	}

	/**
	 * The dict a reference names, or null — streams, scalars and
	 * arrays all mean "not a dictionary here".
	 *
	 * @return array<string, mixed>|null
	 */
	public function getDictionary(PdfRef $ref): ?array {
		$value = $this->getObject($ref->object)?->value;
		return is_array($value) && !array_is_list($value) ? $value : null;
	}

	/**
	 * A value with one level of indirection resolved.
	 */
	public function resolve(mixed $value): mixed {
		if ($value instanceof PdfRef) {
			return $this->getObject($value->object)?->value;
		}
		return $value;
	}

	/**
	 * The catalog: the trailer's /Root when there was one, else the
	 * first object the scan placed that turns out to be one — bounded,
	 * because the catalog of every honest file sits in the first few
	 * objects.
	 *
	 * @return array<string, mixed>|null
	 */
	public function catalog(): ?array {
		if ($this->rootObject === null) {
			$checked = 0;
			foreach (array_keys($this->entries) as $num) {
				if ($checked >= self::CATALOG_SEARCH) {
					break;
				}
				$checked++;
				$value = $this->getObject($num)?->value;
				$type = is_array($value) ? ($value['Type'] ?? null) : null;
				if ($type instanceof PdfName && $type->name === 'Catalog') {
					$this->rootObject = $num;
					break;
				}
			}
		}

		$value = $this->rootObject !== null ? $this->getObject($this->rootObject)?->value : null;
		return is_array($value) ? $value : null;
	}

	/**
	 * The fonts a resources dict names, cached by the object each one
	 * is — a font is named by every page that uses it, and building a
	 * decoder means parsing a CMap.
	 *
	 * @param array<string, mixed>|null $resources
	 * @return array<string, PdfFont>
	 */
	public function fonts(?array $resources): array {
		$fontResources = $this->resolve($resources['Font'] ?? null);
		if (!is_array($fontResources)) {
			return [];
		}

		$fonts = [];
		foreach ($fontResources as $name => $resource) {
			$key = $resource instanceof PdfRef ? 'r' . $resource->object : null;
			if ($key !== null && isset($this->fontCache[$key])) {
				$fonts[$name] = $this->fontCache[$key];
				continue;
			}
			$font = PdfFont::build($resource, $this);
			if ($key !== null) {
				if (count($this->fontCache) >= self::MAX_CACHED_FONTS) {
					$this->fontCache = [];
				}
				$this->fontCache[$key] = $font;
			}
			$fonts[$name] = $font;
		}
		return $fonts;
	}

	/**
	 * An object stream's payload, cached: a compressed file's page
	 * tree points many small objects into the same stream.
	 *
	 * @return array{string, list<int>, int}|null decoded bytes, object numbers, and /First
	 */
	private function objectStream(int $num): ?array {
		if (isset($this->objstmCache[$num])) {
			return $this->objstmCache[$num];
		}

		$object = $this->getObject($num);
		$dict = $object?->value;
		$type = is_array($dict) ? ($dict['Type'] ?? null) : null;
		$stream = $object?->stream;
		if (!is_array($dict) || !($type instanceof PdfName) || $type->name !== 'ObjStm' || $stream === null) {
			return null;
		}

		$count = is_int($dict['N'] ?? null) ? $dict['N'] : -1;
		$first = is_int($dict['First'] ?? null) ? $dict['First'] : -1;
		if ($count < 0 || $count > self::MAX_ENTRIES || $first < 0) {
			return null;
		}

		$decoded = $this->decodeStreamData($dict, $stream, self::OBJSTM_CAP, 'an object stream', new PdfRef($num, 0));
		$cursor = new PdfCursor($decoded);
		$numbers = [];
		for ($i = 0; $i < $count * 2; $i++) {
			$number = $cursor->value();
			if (!is_int($number)) {
				break;
			}
			$numbers[] = $number;
		}

		if (count($this->objstmCache) >= self::MAX_CACHED_OBJSTM) {
			$this->objstmCache = [];
		}
		$this->objstmCache[$num] = [$decoded, $numbers, $first];
		return $this->objstmCache[$num];
	}

	private function fromObjectStream(int $stmNum, int $index, int $num): ?PdfIndirect {
		$stream = $this->objectStream($stmNum);
		if ($stream === null || $index * 2 + 1 >= count($stream[1])) {
			return null;
		}
		[$decoded, $numbers, $first] = $stream;

		// the pair's offsets are relative to the end of the header,
		// which is what /First names
		$objectNum = $numbers[$index * 2];
		$relative = $first + $numbers[$index * 2 + 1];
		$next = ($index + 1) * 2 + 1 < count($numbers)
			? $first + $numbers[($index + 1) * 2 + 1]
			: strlen($decoded);
		if ($objectNum !== $num || $next <= $relative) {
			return null;
		}

		$cursor = new PdfCursor($decoded, $relative);
		return new PdfIndirect($cursor->value(), null);
	}

	/**
	 * Read the indirect object at a byte offset: its value, plus the
	 * raw bytes between stream and endstream when it has one. The
	 * length comes from /Length when it is usable, and from a bounded
	 * forward scan when it is not — a document that lies about its
	 * lengths still yields the bytes its own markers delimit.
	 */
	private function readIndirectAt(int $offset, int $streamCap): PdfIndirect {
		$cursor = new PdfCursor($this->data, $offset);
		$cursor->skipSpace();
		$object = $cursor->value();
		$generation = $cursor->value();
		if (!is_int($object) || !is_int($generation)) {
			return new PdfIndirect(null, null);
		}
		$cursor->skipSpace();
		if ($cursor->keyword() !== 'obj') {
			return new PdfIndirect(null, null);
		}

		$cursor->skipSpace();
		$at = $cursor->pos;
		$value = $cursor->value();
		if ($value === null && $cursor->pos === $at) {
			return new PdfIndirect(null, null);
		}

		$stream = null;
		if (is_array($value)) {
			$dictEnd = $cursor->pos;
			$cursor->skipSpace();
			if ($cursor->keyword() === 'stream') {
				// one EOL follows the keyword; be lenient about which
				if (substr($this->data, $cursor->pos, 2) === "\r\n") {
					$cursor->pos += 2;
				} elseif (substr($this->data, $cursor->pos, 1) === "\n") {
					$cursor->pos += 1;
				}

				$start = $cursor->pos;
				$length = $this->declaredLength($value, $dictEnd);
				if ($length === null || $length < 0 || $start + $length > strlen($this->data)) {
					$length = $this->scanEndstream($start, $streamCap);
				}
				$stream = substr($this->data, $start, $length);
			}
		}

		return new PdfIndirect($value, $stream);
	}

	/**
	 * /Length is usually a direct integer; an indirect one is resolved
	 * against the entries already placed, and only a type-1 offset
	 * placed before the stream's own dict is trusted for it.
	 */
	private function declaredLength(array $dict, int $dictEnd): ?int {
		$length = $dict['Length'] ?? null;
		if (is_int($length)) {
			return $length;
		}
		if ($length instanceof PdfRef) {
			$entry = $this->entries[$length->object] ?? null;
			$offset = $entry !== null && $entry['t'] === 1 ? ($entry['offset'] ?? null) : null;
			if (is_int($offset) && $offset < $dictEnd) {
				$cursor = new PdfCursor($this->data, $offset);
				$cursor->skipSpace();
				$cursor->value();
				$cursor->value();
				$cursor->skipSpace();
				if ($cursor->keyword() === 'obj') {
					$cursor->skipSpace();
					$value = $cursor->value();
					if (is_int($value)) {
						return $value;
					}
				}
			}
		}
		return null;
	}

	private function scanEndstream(int $start, int $cap): int {
		$limit = min(strlen($this->data), $start + $cap);
		$window = substr($this->data, $start, $limit - $start);
		$at = strpos($window, 'endstream');
		return $at === false ? ($limit - $start) : $at;
	}

	/**
	 * A stream's decoded bytes under the filter chain it declares,
	 * against one per-stream cap and the document's total — the total
	 * is what keeps a many-small-bombs document bounded. Cross-
	 * reference streams are never encrypted and pass no $for; every
	 * other caller names the object whose key decrypts it.
	 */
	public function decodeStreamData(array $dict, string $raw, int $cap, string $what, ?PdfRef $for = null): string {
		if ($for !== null && $this->crypt !== null) {
			$raw = $this->crypt->decrypt($raw, $for->object, $for->generation);
		}
		$filter = $this->resolve($dict['Filter'] ?? null);
		if ($filter === null) {
			return $raw;
		}
		$names = $filter instanceof PdfName
			? [$filter->name]
			: array_map(static fn (mixed $f): string => $f instanceof PdfName ? $f->name : 'an unnamed filter', is_array($filter) ? $filter : []);
		$parms = $this->resolve($dict['DecodeParms'] ?? null);
		$parmList = is_array($parms) && array_is_list($parms) ? $parms : [$parms];

		$data = $raw;
		foreach ($names as $i => $name) {
			$parm = $parmList[$i] ?? null;
			$parm = $parm instanceof PdfRef ? $this->resolve($parm) : $parm;
			$parm = is_array($parm) ? $parm : [];

			switch ($name) {
				case 'FlateDecode':
				case 'Fl':
					$data = PdfFilters::flate($data, $cap, $what);
					break;
				case 'ASCIIHexDecode':
				case 'AHx':
					$data = PdfFilters::asciiHex($data);
					break;
				case 'ASCII85Decode':
				case 'A85':
					$data = PdfFilters::ascii85($data);
					break;
				default:
					throw new ExtractionAbort(
						ExtractionCause::ParserGaveUp,
						"$what uses the unsupported filter $name",
					);
			}

			$this->totalDecoded += strlen($data);
			if ($this->totalDecoded > self::TOTAL_DECODE_CAP) {
				throw new ExtractionAbort(
					ExtractionCause::ParserGaveUp,
					sprintf('the document\'s streams expand past the %d MiB total read cap', self::TOTAL_DECODE_CAP >> 20),
				);
			}

			$predictor = is_int($parm['Predictor'] ?? null) ? $parm['Predictor'] : 1;
			if ($predictor >= 10) {
				$data = PdfFilters::pngPredictor(
					$data,
					is_int($parm['Colors'] ?? null) ? $parm['Colors'] : 1,
					is_int($parm['BitsPerComponent'] ?? null) ? $parm['BitsPerComponent'] : 8,
					is_int($parm['Columns'] ?? null) ? $parm['Columns'] : 1,
				);
			}
		}
		return $data;
	}
}
