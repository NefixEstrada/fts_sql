<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests;

use ZipArchive;

/**
 * Office container fixtures, built in place with ZipArchive: the tests need
 * real zips holding real XML — namespaces, runs, empty elements — and
 * building them per run keeps binary blobs out of the repository and out of
 * every CI leg's checkout. The XML is written without indentation between
 * elements on purpose: inter-element whitespace is a single space in the
 * extractor's rules, and fixtures that carry none make the expected
 * extraction strings exact.
 */
final class Fixtures {
	/**
	 * @param resource $stream is returned, positioned at the start
	 */
	public static function stream(string $bytes) {
		$stream = fopen('php://temp', 'w+b');
		fwrite($stream, $bytes);
		rewind($stream);
		return $stream;
	}

	/**
	 * @param array<string, string> $entries name => content
	 */
	public static function zip(array $entries): string {
		$path = tempnam(sys_get_temp_dir(), 'fts-zip-');
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		foreach ($entries as $name => $content) {
			$zip->addFromString($name, $content);
		}
		$zip->close();

		$bytes = file_get_contents($path);
		@unlink($path);
		return $bytes === false ? '' : $bytes;
	}

	/**
	 * The compound-file magic an office application writes when the document
	 * is saved with a password (the OOXML zip is wrapped in an OLE
	 * container).
	 */
	public static function ole(): string {
		return "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 64);
	}

	public static function docx(string $bodyXml): string {
		return self::zip([
			'word/document.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
				. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
				. "<w:body>$bodyXml</w:body></w:document>",
		]);
	}

	/**
	 * @param list<string> $strings each becomes one si (shared string) item;
	 *                              runs inside one string stay one item
	 */
	public static function xlsxSharedStrings(array $strings, string $extraBody = ''): string {
		$items = '';
		foreach ($strings as $string) {
			$items .= $string;
		}
		return self::zip([
			'xl/sharedStrings.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
				. '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($strings) . '" uniqueCount="' . count($strings) . '">'
				. $items . $extraBody . '</sst>',
		]);
	}

	/**
	 * A workbook without sharedStrings: its text lives in the sheets' inline
	 * strings (is/t), beside numeric cells (c/v).
	 *
	 * @param array<string, string> $sheets entry name => sheetData XML
	 */
	public static function xlsxInline(array $sheets): string {
		$entries = [];
		foreach ($sheets as $name => $sheetData) {
			$entries[$name] = '<?xml version="1.0" encoding="UTF-8"?>'
				. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
				. "<sheetData>$sheetData</sheetData></worksheet>";
		}
		return self::zip($entries);
	}

	/**
	 * @param array<string, string> $slides entry name => slide XML body
	 */
	public static function pptx(array $slides): string {
		$entries = [];
		foreach ($slides as $name => $body) {
			$entries[$name] = '<?xml version="1.0" encoding="UTF-8"?>'
				. '<p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"'
				. ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
				. "<p:cSld><p:sp><p:txBody>$body</p:txBody></p:sp></p:cSld></p:sld>";
		}
		return self::zip($entries);
	}

	public static function odf(string $bodyXml): string {
		return self::zip([
			'content.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
				. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
				. ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
				. ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
				. ' xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0">'
				. "<office:body>$bodyXml</office:body></office:document-content>",
		]);
	}

	public static function odfEncrypted(): string {
		return self::zip([
			'content.xml' => '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"/>',
			'META-INF/manifest.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
				. '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0">'
				. '<manifest:file-entry manifest:full-path="/">'
				. '<manifest:encryption-data manifest:checksum-type="SHA1/1K" manifest:checksum="...">'
				. '</manifest:encryption-data></manifest:file-entry></manifest:manifest>',
		]);
	}
}
