<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The benchmark's extraction stage (DESIGN.md, "Open issue: extraction in
 * the benchmark", sequenced inside Milestone 2): measure every extractor on
 * the same footing as the engines — the same corpus, the platform's own
 * extraction interface — where before there were only one-off scripts. The
 * numbers this stage keeps are the ones the Milestone 3 PDF route decision
 * reads (measured against these, not against hand-built samples).
 *
 * Extraction is pure (no database, no Nextcloud), so unlike quality.php this
 * runs anywhere the app's composer install exists:
 *
 *   nix develop -c php benchmark/extraction.php
 *   docker exec -u www-data <nextcloud-container> \
 *       php /var/www/html/apps-extra/fts_sql/benchmark/extraction.php
 *
 * The fixtures are built at run time from the fixed corpus, as containers
 * shaped like the real applications write them: runs with properties
 * (w:rPr/w:pPr) in the docx, shared strings beside numeric cells in the
 * xlsx, automatic styles and text:span in the odt — the 2–4× XML-over-text
 * ratio of real office files, not the flat markup of the unit fixtures.
 * Scenarios:
 *
 *   docx_corpus_batch   5,000 corpus-sized documents, the shape of a real
 *                       indexing run, comparable to quality.php's index_ms
 *   docx_in_budget      one docx whose text fits the budget
 *   docx_over_budget    1.5× the budget of text: the sink fills and the
 *                       walk stops early — what a large honest document pays
 *   docx_beyond_cap     enough text that its XML passes the entry read cap:
 *                       documents the refusal boundary, not hidden by it
 *   xlsx_in_budget      shared strings beside a numeric sheet, the shape
 *                       DESIGN.md's PhpSpreadsheet comparison measured
 *   odt_in_budget       one content.xml walk
 *   pdf_in_budget       a many-page pdf, flate-compressed, WinAnsi text —
 *                       the route DESIGN.md's Milestone 3 landed on
 *   pdf_encrypted_...   the same shape under the standard security handler
 *                       with an empty user password, the reference file's own
 *   pdf_iso_reference   the 21.45 MiB, 756-page ISO 32000-1 the route
 *                       decision measured, when its path is passed:
 *                       --pdf=/path/to/PDF32000_2008.pdf (the file is not in
 *                       the repository; the numbers are what the open issue
 *                       resolved on)
 *   ppt_in_budget       a many-slide .ppt from the same builder the unit
 *                       fixtures use — the first library-backed extractor,
 *                       so its numbers carry the reader's own memory
 *                       profile, which is what Milestone 4 rides on
 *   ppt_over_budget     1.5× the budget of slides: the sink fills and the
 *                       slide walk stops early
 *
 * Peaks are marginal: memory_reset_peak_usage() before each scenario, the
 * delta of memory_get_peak_usage(true) after. Leaves nothing behind (the
 * fixtures are temp files). Print the JSON line it ends with into
 * benchmark/results/ to keep a measurement for comparison.
 */

use OCA\FtsSql\ConfigLexicon;
use OCA\FtsSql\Service\ExtractionService;
use OCA\FtsSql\Tests\Fixtures;

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../tests/Fixtures.php';

// The ceiling that matters is Nextcloud's documented 512 MB floor — the
// extraction runs inside a Nextcloud process — so the benchmark raises its
// own to that instead of measuring under some CLI default.
ini_set('memory_limit', '512M');

const BUDGET = ConfigLexicon::DEFAULT_CONTENT_BYTES;

/**
 * Every scenario's expected cause: a deviation means an extractor changed
 * behaviour, and a benchmark that quietly reports a different shape than
 * the one it was built to measure is worse than no benchmark.
 */
const EXPECTED = [
	'docx_in_budget' => 'complete',
	'docx_over_budget' => 'budget cut',
	'docx_beyond_cap' => 'parser gave up',
	'xlsx_in_budget' => 'complete',
	'odt_in_budget' => 'complete',
	'pdf_in_budget' => 'complete',
	'pdf_encrypted_in_budget' => 'complete',
	// the reference ISO accepts either designed bound: which of the
	// budget or the clock bites first depends on the host's speed
	'pdf_iso_reference' => ['budget cut', 'parser gave up'],
	'ppt_in_budget' => 'complete',
	'ppt_over_budget' => 'budget cut',
];

/**
 * @param string|list<string> $expected
 */
function causeMatches(string $cause, string|array $expected): bool {
	return is_string($expected) ? $cause === $expected : in_array($cause, $expected, true);
}

$bodies = corpus();
echo count($bodies) . " corpus documents loaded\n";

$scenarios = [];

// --- docx_corpus_batch: 5,000 documents, extraction only --------------
echo "generating the batch fixtures...\n";
$batch = [];
foreach ($bodies as $body) {
	$batch[] = docxBytes([$body]);
}

memory_reset_peak_usage();
$basePeak = memory_get_peak_usage(true);
$start = hrtime(true);
$offShape = 0;
foreach ($batch as $i => $bytes) {
	$result = ExtractionService::extract($bytes, 'docx', BUDGET);
	if ($result->cause !== null) {
		$offShape++;
	}
	if (($i + 1) % 500 === 0) {
		echo 'extracted ' . ($i + 1) . "...\n";
	}
}
$totalMs = (hrtime(true) - $start) / 1_000_000;
$scenarios['docx_corpus_batch'] = [
	'docs' => count($batch),
	'off_shape' => $offShape,
	'total_ms' => (int)$totalMs,
	'per_doc_ms' => round($totalMs / count($batch), 3),
	'peak_bytes' => memory_get_peak_usage(true) - $basePeak,
];

// --- single-file scenarios --------------------------------------------
foreach ([
	'docx_in_budget' => ['docx', 1048576, false],
	'docx_over_budget' => ['docx', BUDGET * 3 / 2, false],
	'docx_beyond_cap' => ['docx', 8388608, false],
	'xlsx_in_budget' => ['xlsx', 1048576, false],
	'odt_in_budget' => ['odt', 1048576, false],
	'pdf_in_budget' => ['pdf', 1048576, false],
	'pdf_encrypted_in_budget' => ['pdf', 1048576, true],
	'ppt_in_budget' => ['ppt', 1048576, false],
	'ppt_over_budget' => ['ppt', BUDGET * 3 / 2, false],
] as $name => [$extension, $textBytes, $encrypted]) {
	$text = textUntil($bodies, (int)$textBytes);
	$bytes = $extension === 'docx' ? docxBytes(paragraphsOf($text))
		: ($extension === 'xlsx' ? xlsxBytes(cellStringsOf($text))
		: ($extension === 'odt' ? odtBytes(paragraphsOf($text))
		: ($extension === 'ppt' ? Fixtures::ppt(slidesOf($text))
		: pdfBytes(paragraphsOf($text), $encrypted))));

	$scenarios[$name] = measure($bytes, $extension);
	$s = $scenarios[$name];
	echo sprintf(
		"%s: %s file, %s text, %s, %d ms, %s peak\n",
		$name,
		kib($s['file_bytes']),
		kib($s['text_bytes']),
		$s['cause'],
		$s['ms'],
		kib($s['peak_bytes']),
	);
	if (!causeMatches($s['cause'], EXPECTED[$name])) {
		fwrite(STDERR, "UNEXPECTED: $name came out '{$s['cause']}', expected '" . json_encode(EXPECTED[$name]) . "'\n");
	}
}

// --- pdf_iso_reference: the file the route decision measured -----------
$isoPath = null;
foreach ($_SERVER['argv'] ?? [] as $arg) {
	if (str_starts_with((string)$arg, '--pdf=')) {
		$isoPath = substr((string)$arg, strlen('--pdf='));
	}
}
if ($isoPath !== null && is_file($isoPath)) {
	$isoBytes = file_get_contents($isoPath) ?: '';
	$scenarios['pdf_iso_reference'] = measure($isoBytes, 'pdf');
	$s = $scenarios['pdf_iso_reference'];
	echo sprintf(
		"%s: %s file, %s text, %s, %d ms, %s peak\n",
		'pdf_iso_reference',
		kib($s['file_bytes']),
		kib($s['text_bytes']),
		$s['cause'],
		$s['ms'],
		kib($s['peak_bytes']),
	);
	if (!causeMatches($s['cause'], EXPECTED['pdf_iso_reference'])) {
		fwrite(STDERR, "UNEXPECTED: pdf_iso_reference came out '{$s['cause']}', expected '" . json_encode(EXPECTED['pdf_iso_reference']) . "'\n");
	}
} else {
	echo "pdf_iso_reference: skipped (pass --pdf=/path/to/PDF32000_2008.pdf for the reference measurement)\n";
}

$report = [
	'stage' => 'extraction',
	'php' => PHP_VERSION,
	'budget_bytes' => BUDGET,
	'scenarios' => $scenarios,
];

echo "\nextraction stage\n";
echo json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";

// --- measurement and fixture builders ---------------------------------

/**
 * One extraction with a fresh peak: the delta of the process peak over the
 * pre-scenario usage, which is the extraction's marginal memory.
 *
 * @return array{file_bytes: int, text_bytes: int, cause: string, ms: int, peak_bytes: int}
 */
function measure(string $bytes, string $extension): array {
	memory_reset_peak_usage();
	$basePeak = memory_get_peak_usage(true);

	$start = hrtime(true);
	$result = ExtractionService::extract($bytes, $extension, BUDGET);
	$ms = (hrtime(true) - $start) / 1_000_000;

	return [
		'file_bytes' => strlen($bytes),
		'text_bytes' => strlen($result->text ?? ''),
		'cause' => $result->cause?->value ?? 'complete',
		'ms' => (int)$ms,
		'peak_bytes' => memory_get_peak_usage(true) - $basePeak,
	];
}

/**
 * @return list<string> the corpus bodies, in corpus order
 */
function corpus(): array {
	$handle = gzopen(__DIR__ . '/corpus/wikipedia-opening-text.jsonl.gz', 'r');
	if ($handle === false) {
		fwrite(STDERR, "cannot open the corpus\n");
		exit(1);
	}
	$bodies = [];
	while (($line = gzgets($handle)) !== false) {
		$entry = json_decode($line, true);
		if (is_array($entry) && isset($entry['body'])) {
			$bodies[] = (string)$entry['body'];
		}
	}
	gzclose($handle);
	return $bodies;
}

/**
 * Corpus text concatenated until it passes $targetBytes (the corpus repeats
 * when it runs out; deterministically, from the start).
 */
function textUntil(array $bodies, int $targetBytes): string {
	$text = '';
	while (strlen($text) < $targetBytes) {
		foreach ($bodies as $body) {
			$text .= $body . "\n\n";
			if (strlen($text) >= $targetBytes) {
				break;
			}
		}
	}
	return $text;
}

/**
 * Each corpus body is one paragraph, as the applications hold them.
 *
 * @return list<string>
 */
function paragraphsOf(string $text): array {
	$paragraphs = [];
	foreach (preg_split('/\n+/', trim($text)) ?: [] as $body) {
		$body = trim($body);
		if ($body !== '') {
			$paragraphs[] = $body;
		}
	}
	return $paragraphs;
}

/**
 * @return list<string> spreadsheet-cell strings of ~9 words each
 */
function cellStringsOf(string $text): array {
	$strings = [];
	foreach (array_chunk(preg_split('/\s+/u', trim($text)) ?: [], 9) as $chunk) {
		$strings[] = implode(' ', $chunk);
	}
	return $strings;
}

/**
 * The same paragraphs as a deck: six bullets per slide, the shape a
 * real presentation holds them in.
 *
 * @return list<list<string>>
 */
function slidesOf(string $text): array {
	return array_chunk(paragraphsOf($text), 6);
}

/**
 * A wordprocessing document: every run carries its properties, every
 * paragraph its paragraph properties — the overhead that makes office XML
 * run 2–4× the size of its text.
 *
 * @param list<string> $paragraphs
 */
function docxBytes(array $paragraphs): string {
	$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>';
	$paragraphIndex = 0;
	foreach ($paragraphs as $paragraph) {
		$paragraphIndex++;
		$xml .= '<w:p><w:pPr><w:spacing w:after="160" w:line="259" w:lineRule="auto"/>'
			. '<w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="22"/></w:rPr></w:pPr>';
		foreach (array_chunk(preg_split('/\s+/u', $paragraph) ?: [], 9) as $run) {
			$runProps = $paragraphIndex % 7 === 0
				? '<w:rPr><w:b/><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="22"/></w:rPr>'
				: '<w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="22"/></w:rPr>';
			$xml .= '<w:r>' . $runProps
				. '<w:t xml:space="preserve">' . esc(implode(' ', $run) . ' ') . '</w:t></w:r>';
		}
		$xml .= '</w:p>';
	}
	$xml .= '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/></w:sectPr></w:body></w:document>';

	return zipBytes([
		'[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
			. '</Types>',
		'_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
			. '</Relationships>',
		'word/document.xml' => $xml,
	]);
}

/**
 * A workbook: the text in shared strings (plain, rich-text runs, and the
 * odd phonetic hint a real file carries), beside a sheet of numeric cells
 * the extractor never reads.
 *
 * @param list<string> $strings
 */
function xlsxBytes(array $strings): string {
	$shared = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
		. ' count="' . count($strings) . '" uniqueCount="' . count($strings) . '">';
	foreach ($strings as $i => $string) {
		if ($i % 13 === 0) {
			$shared .= '<si><r><rPh sb="0" eb="2"><t>ヨミガナ</t></rPh>'
				. '<t xml:space="preserve">' . esc($string) . '</t></r></si>';
		} elseif ($i % 9 === 0) {
			$words = explode(' ', $string, 2);
			$shared .= '<si><r><rPr><b/></rPr><t>' . esc($words[0]) . '</t></r>'
				. '<r><t xml:space="preserve">' . esc(' ' . ($words[1] ?? '')) . '</t></r></si>';
		} else {
			$shared .= '<si><t xml:space="preserve">' . esc($string) . '</t></si>';
		}
	}
	$shared .= '</sst>';

	$sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
	for ($row = 1; $row <= 800; $row++) {
		$sheet .= '<row r="' . $row . '">';
		for ($col = 0; $col < 6; $col++) {
			$sheet .= '<c r="' . chr(65 + $col) . $row . '"><v>' . ($row * 1000 + $col) . '</v></c>';
		}
		$sheet .= '</row>';
	}
	$sheet .= '</sheetData></worksheet>';

	return zipBytes([
		'[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
			. '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
			. '</Types>',
		'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<sheets><sheet name="Full1" sheetId="1" r:id="rId1" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/></sheets></workbook>',
		'xl/worksheets/sheet1.xml' => $sheet,
		'xl/sharedStrings.xml' => $shared,
	]);
}

/**
 * A text document: automatic styles the walk passes through, spans around
 * the odd word, a heading every so often, space runs and tabs.
 *
 * @param list<string> $paragraphs
 */
function odtBytes(array $paragraphs): string {
	$styles = '';
	for ($i = 1; $i <= 20; $i++) {
		$styles .= '<style:style style:name="P' . $i . '" style:family="paragraph" style:parent-style-name="Standard">'
			. '<style:paragraph-properties style:margin-top="0cm"/></style:style>';
	}

	$xml = '<?xml version="1.0" encoding="UTF-8"?>'
		. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
		. ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
		. ' xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0">'
		. '<office:automatic-styles>' . $styles . '</office:automatic-styles>'
		. '<office:body><office:text>';
	$paragraphIndex = 0;
	foreach ($paragraphs as $paragraph) {
		$paragraphIndex++;
		$tag = $paragraphIndex % 17 === 0 ? 'text:h' : 'text:p';
		$xml .= '<' . $tag . ' text:style-name="P' . ($paragraphIndex % 20 + 1) . '">';
		foreach (preg_split('/\s+/u', $paragraph) ?: [] as $j => $word) {
			if ($j > 0) {
				$xml .= ' ';
			}
			if ($j % 12 === 11) {
				$xml .= '<text:span text:style-name="T1">' . esc($word) . '</text:span>';
			} else {
				$xml .= esc($word);
			}
		}
		if ($paragraphIndex % 9 === 0) {
			$xml .= '<text:s/><text:tab/>';
		}
		$xml .= '</' . $tag . '>';
	}
	$xml .= '</office:text></office:body></office:document-content>';

	return zipBytes([
		'META-INF/manifest.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
			. '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0"'
			. ' manifest:version="1.2">'
			. '<manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.text"/>'
			. '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'
			. '</manifest:manifest>',
		'content.xml' => $xml,
	], 'application/vnd.oasis.opendocument.text');
}

/**
 * A many-page pdf: classic cross-reference table, one content stream per
 * page flate-compressed, WinAnsi text in a base-14 font — the shape the
 * Milestone 3 route landed on, measured at the size a real document
 * holds. When $encrypted, the same document under the standard security
 * handler with an empty user password: RC4 revision 3, every content
 * stream under its own object's key.
 *
 * @param list<string> $paragraphs
 */
function pdfBytes(array $paragraphs, bool $encrypted = false): string {
	$linesPerPage = 45;
	$pages = array_chunk($paragraphs, $linesPerPage);
	$pad = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08"
		. "\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";
	$p = -28;
	$id = hex2bin('0123456789abcdef0123456789abcdef');

	$fileKey = null;
	if ($encrypted) {
		$ownerKey = md5($pad, true);
		for ($i = 0; $i < 50; $i++) {
			$ownerKey = md5($ownerKey, true);
		}
		$o = benchRc4(substr($ownerKey, 0, 5), $pad);
		$h = md5($pad . $o . pack('V', $p & 0xFFFFFFFF) . $id, true);
		for ($i = 0; $i < 50; $i++) {
			$h = md5(substr($h, 0, 5), true);
		}
		$fileKey = substr($h, 0, 5);
	}

	// object layout: 1 catalog, 2 pages, then page/content pairs, one
	// font per document, and the /Encrypt dict when encrypted
	$objects = [];
	$fontNum = 3 + count($pages) * 2;
	$encryptNum = $fontNum + 1;

	$kids = [];
	foreach ($pages as $i => $pageLines) {
		$pageNum = 3 + $i * 2;
		$contentNum = $pageNum + 1;
		$kids[] = "$pageNum 0 R";

		$content = "BT\n/F1 10 Tf\n";
		foreach ($pageLines as $line) {
			$winAnsi = mb_convert_encoding($line, 'Windows-1252', 'UTF-8');
			$content .= '(' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $winAnsi) . ") Tj\nT*\n";
		}
		$content .= "ET\n";
		$streamBytes = gzcompress($content, 6);
		if ($fileKey !== null) {
			$objectKey = substr(md5($fileKey . substr(pack('V', $contentNum), 0, 3) . pack('v', 0), true), 0, 10);
			$streamBytes = benchRc4($objectKey, $streamBytes);
		}

		$objects[$pageNum] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842]'
			. " /Resources << /Font << /F1 $fontNum 0 R >> >> /Contents $contentNum 0 R >>";
		$objects[$contentNum] = '<< /Length ' . strlen($streamBytes) . " /Filter /FlateDecode >>\nstream\n$streamBytes\nendstream";
	}

	$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
	$objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($pages) . ' >>';
	$objects[$fontNum] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
	if ($fileKey !== null) {
		$u = md5($pad . $id, true);
		$u = benchRc4($fileKey, $u);
		for ($i = 1; $i <= 19; $i++) {
			$u = benchRc4($fileKey ^ str_repeat(chr($i), 5), $u);
		}
		$u .= str_repeat("\0", 16);
		$objects[$encryptNum] = "<< /Filter /Standard /V 1 /R 3 /Length 40 /P $p"
			. ' /O (' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $o) . ')'
			. ' /U (' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $u) . ') >>';
	}

	$head = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
	$body = '';
	$at = strlen($head);
	$offsets = [];
	for ($num = 1; $num <= count($objects) + 1; $num++) {
		if (!isset($objects[$num])) {
			continue;
		}
		$offsets[$num] = $at;
		$entry = "$num 0 obj\n" . $objects[$num] . "\nendobj\n";
		$body .= $entry;
		$at += strlen($entry);
	}

	$size = max(array_keys($offsets)) + 1;
	$xref = "xref\n0 $size\n0000000000 65535 f \n";
	foreach ($offsets as $offset) {
		$xref .= sprintf('%010d 00000 n ', $offset) . "\n";
	}

	$extra = $fileKey !== null ? " /Encrypt $encryptNum 0 R /ID [<" . bin2hex($id) . '> <' . bin2hex($id) . '>]' : '';
	return $head . $body . $xref . "trailer\n<< /Size $size /Root 1 0 R$extra >>\nstartxref\n$at\n%%EOF\n";
}

function benchRc4(string $key, string $data): string {
	$s = range(0, 255);
	$j = 0;
	$keyLength = strlen($key);
	for ($i = 0; $i < 256; $i++) {
		$j = ($j + $s[$i] + ord($key[$i % $keyLength])) & 0xFF;
		[$s[$i], $s[$j]] = [$s[$j], $s[$i]];
	}
	$keystream = '';
	$i = $j = 0;
	$len = strlen($data);
	for ($at = 0; $at < $len; $at++) {
		$i = ($i + 1) & 0xFF;
		$j = ($j + $s[$i]) & 0xFF;
		[$s[$i], $s[$j]] = [$s[$j], $s[$i]];
		$keystream .= chr($s[($s[$i] + $s[$j]) & 0xFF]);
	}
	return $data ^ $keystream;
}

/**
 * @param array<string, string> $entries
 * @param ?string $storedFirst ODF's mimetype: the first entry, uncompressed
 */
function zipBytes(array $entries, ?string $storedFirst = null): string {
	$path = tempnam(sys_get_temp_dir(), 'fts-bench-');
	$zip = new ZipArchive();
	$zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
	if ($storedFirst !== null) {
		$zip->addFromString('mimetype', $storedFirst);
		$zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
	}
	foreach ($entries as $name => $content) {
		$zip->addFromString($name, $content);
	}
	$zip->close();

	$bytes = file_get_contents($path);
	@unlink($path);
	return $bytes === false ? '' : $bytes;
}

function esc(string $text): string {
	return htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function kib(int $bytes): string {
	return number_format($bytes / 1024) . ' KiB';
}
