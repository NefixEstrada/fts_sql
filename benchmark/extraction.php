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
 *
 * Peaks are marginal: memory_reset_peak_usage() before each scenario, the
 * delta of memory_get_peak_usage(true) after. Leaves nothing behind (the
 * fixtures are temp files). Print the JSON line it ends with into
 * benchmark/results/ to keep a measurement for comparison.
 */

use OCA\FtsSql\ConfigLexicon;
use OCA\FtsSql\Service\ExtractionService;

require __DIR__ . '/../vendor/autoload.php';

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
];

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
	'docx_in_budget' => ['docx', 1048576],
	'docx_over_budget' => ['docx', BUDGET * 3 / 2],
	'docx_beyond_cap' => ['docx', 8388608],
	'xlsx_in_budget' => ['xlsx', 1048576],
	'odt_in_budget' => ['odt', 1048576],
] as $name => [$extension, $textBytes]) {
	$text = textUntil($bodies, (int)$textBytes);
	$bytes = $extension === 'docx' ? docxBytes(paragraphsOf($text))
		: ($extension === 'xlsx' ? xlsxBytes(cellStringsOf($text))
		: odtBytes(paragraphsOf($text)));

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
	if ($s['cause'] !== EXPECTED[$name]) {
		fwrite(STDERR, "UNEXPECTED: $name came out '{$s['cause']}', expected '" . EXPECTED[$name] . "'\n");
	}
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
