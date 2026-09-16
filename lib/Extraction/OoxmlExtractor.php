<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Extraction;

/**
 * OOXML: .docx, .xlsx, .pptx, each with its own XMLReader pass over the
 * entries that hold body text (DESIGN.md, Milestone 2: PhpSpreadsheet
 * measured at +595.9 MiB and 31.8 s on a 1.09 MiB .xlsx where a targeted
 * XMLReader pass costs +0.3 MiB and 1.0 s).
 *
 * What counts as body text, per format:
 *  - docx: word/document.xml — runs (w:t) concatenated as Word lays them
 *    out, tabs and breaks as characters, one newline per paragraph; field
 *    instructions (w:instrText, PAGE and friends) and text deleted under
 *    track changes (w:delText) are not body text. Headers, footers and
 *    footnotes are not read.
 *  - xlsx: xl/sharedStrings.xml — every string cell's text once, which is
 *    what a search looks for; the worksheets are read only when a writer
 *    that does not use shared strings put the text inline (is/t), because
 *    a worksheet's own nodes are numbers and formulas.
 *  - pptx: ppt/slides/slide1..N.xml in their numeric order — runs (a:t)
 *    with one newline per paragraph (a:p).
 */
final class OoxmlExtractor extends ContainerExtractor {
	public function owns(): array {
		return ['docx', 'xlsx', 'pptx'];
	}

	protected function passwordProtectedOle(): bool {
		return true;
	}

	protected function readEntries(ZipContainer $zip, string $extension, TextSink $sink, float $deadline, int $entryCap): void {
		switch ($extension) {
			case 'docx':
				$this->document($zip, $sink, $deadline, $entryCap);
				return;
			case 'xlsx':
				$this->workbook($zip, $sink, $deadline, $entryCap);
				return;
			case 'pptx':
				$this->slides($zip, $sink, $deadline, $entryCap);
				return;
		}
	}

	private function document(ZipContainer $zip, TextSink $sink, float $deadline, int $entryCap): void {
		$xml = $zip->read('word/document.xml', $entryCap)
			?? throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'no word/document.xml in the container');

		XmlWalk::text($xml, $sink, new XmlTextRules(
			skip: ['instrText', 'delText'],
			paragraphEnd: ['p'],
			leaf: ['tab' => "\t", 'br' => "\n"],
			inside: null,
		), $deadline);
	}

	private function workbook(ZipContainer $zip, TextSink $sink, float $deadline, int $entryCap): void {
		$shared = $zip->read('xl/sharedStrings.xml', $entryCap);
		if ($shared !== null) {
			XmlWalk::text($shared, $sink, new XmlTextRules(
				skip: ['rPh'],
				paragraphEnd: ['si'],
				leaf: [],
				inside: null,
			), $deadline);
			return;
		}

		foreach (self::numbered($zip->names(), '~^xl/worksheets/sheet(\d+)\.xml$~') as $name) {
			if ($sink->full()) {
				return;
			}
			$xml = $zip->read($name, $entryCap);
			if ($xml !== null) {
				XmlWalk::text($xml, $sink, new XmlTextRules(
					skip: [],
					paragraphEnd: ['row'],
					leaf: [],
					inside: ['is'],
				), $deadline);
			}
		}
	}

	private function slides(ZipContainer $zip, TextSink $sink, float $deadline, int $entryCap): void {
		$slides = self::numbered($zip->names(), '~^ppt/slides/slide(\d+)\.xml$~');
		if ($slides === []) {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'no ppt/slides/slideN.xml in the container');
		}

		foreach ($slides as $name) {
			if ($sink->full()) {
				return;
			}
			$xml = $zip->read($name, $entryCap);
			if ($xml !== null) {
				XmlWalk::text($xml, $sink, new XmlTextRules(
					skip: [],
					paragraphEnd: ['p'],
					leaf: ['br' => "\n"],
					inside: null,
				), $deadline);
			}
		}
	}

	/**
	 * Entry names matching $pattern, ordered by the number the pattern
	 * captured — slide2 before slide10, which zip directory order is not.
	 *
	 * @param list<string> $names
	 * @param non-empty-string $pattern
	 * @return list<string>
	 */
	private static function numbered(array $names, string $pattern): array {
		$found = [];
		foreach ($names as $name) {
			if (preg_match($pattern, $name, $match) === 1) {
				$found[] = [(int)$match[1], $name];
			}
		}
		usort($found, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
		return array_column($found, 1);
	}
}
