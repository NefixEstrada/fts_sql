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

	// ---- PDF fixtures -------------------------------------------------------

	/**
	 * A classic-table PDF: the object sources in order from object 1,
	 * the cross-reference table computed over the assembled bytes, and
	 * whatever the trailer needs beyond /Size and /Root.
	 *
	 * @param list<string> $objectSources each between "N 0 obj" and "endobj"
	 * @param array<string, string> $trailerExtra e.g. ['Encrypt' => '7 0 R', 'ID' => '[<...> <...>]']
	 */
	public static function pdf(array $objectSources, array $trailerExtra = []): string {
		$head = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$body = '';
		$at = strlen($head);
		$offsets = [];
		foreach ($objectSources as $index => $source) {
			$num = $index + 1;
			$offsets[$num] = $at;
			$entry = "$num 0 obj\n$source\nendobj\n";
			$body .= $entry;
			$at += strlen($entry);
		}

		$count = count($objectSources) + 1;
		$xref = "xref\n0 $count\n0000000000 65535 f \n";
		foreach ($offsets as $offset) {
			$xref .= sprintf('%010d 00000 n ', $offset) . "\n";
		}

		$extra = '';
		foreach ($trailerExtra as $key => $value) {
			$extra .= " /$key $value";
		}
		return $head . $body . $xref . "trailer\n<< /Size $count /Root 1 0 R$extra >>\n"
			. "startxref\n$at\n%%EOF\n";
	}

	/**
	 * One stream object's source, its /Length computed from the bytes.
	 */
	public static function pdfStream(string $dictEntries, string $bytes): string {
		$length = strlen($bytes);
		return "<< /Length $length $dictEntries >>\nstream\n$bytes\nendstream";
	}

	/**
	 * A single-page text document: one line per entry, WinAnsi text in
	 * a base-14 font, the classic shape every generator can write.
	 *
	 * @param list<string> $lines
	 */
	public static function pdfText(array $lines): string {
		$content = "BT\n/F1 12 Tf\n";
		foreach ($lines as $line) {
			$content .= self::pdfLine($line);
		}
		$content .= "ET\n";

		return self::pdf([
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792]'
			. ' /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
			self::pdfStream('', $content),
			'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
		]);
	}

	/**
	 * The single-page document with its content stream, and only its
	 * content stream, encrypted under the standard security handler:
	 * RC4, revision 3, and the user password of the caller's choosing —
	 * the empty one is the shape of the 21 MiB reference file, built
	 * by deriving O, U and the file key exactly as the specification's
	 * algorithms 3.2 to 3.5 do.
	 *
	 * @param list<string> $lines
	 */
	public static function pdfEncryptedText(array $lines, string $userPassword = ''): string {
		$p = -28;
		$id = hex2bin('00112233445566778899aabbccddeeff');

		$passwordPad = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08"
			. "\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";
		$userPad = substr($userPassword . $passwordPad, 0, 32);
		$ownerKey = md5($passwordPad, true);
		for ($i = 0; $i < 50; $i++) {
			$ownerKey = md5($ownerKey, true);
		}
		$ownerKey = substr($ownerKey, 0, 5);
		$o = self::pdfRc4($ownerKey, $passwordPad);

		$fileKey = md5($passwordPad . $o . pack('V', $p & 0xFFFFFFFF) . $id, true);
		for ($i = 0; $i < 50; $i++) {
			$fileKey = md5(substr($fileKey, 0, 5), true);
		}
		$fileKey = substr($fileKey, 0, 5);

		$u = md5($userPad . $id, true);
		$u = self::pdfRc4($fileKey, $u);
		for ($i = 1; $i <= 19; $i++) {
			$u = self::pdfRc4($fileKey ^ str_repeat(chr($i), 5), $u);
		}
		$u .= str_repeat("\0", 16);

		// the content object is the fourth; its key is its own
		$objectKey = substr(md5($fileKey . substr(pack('V', 4), 0, 3) . pack('v', 0), true), 0, 10);

		$content = "BT\n/F1 12 Tf\n";
		foreach ($lines as $line) {
			$content .= self::pdfLine($line);
		}
		$content .= "ET\n";
		$encryptedContent = self::pdfRc4($objectKey, $content);

		return self::pdf([
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792]'
			. ' /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
			self::pdfStream('', $encryptedContent),
			'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
			"<< /Filter /Standard /V 1 /R 3 /Length 40 /P $p"
			. ' /O (' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $o) . ')'
			. ' /U (' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $u) . ') >>',
		], [
			'Encrypt' => '6 0 R',
			'ID' => '[<' . bin2hex($id) . '> <' . bin2hex($id) . '>]',
		]);
	}

	/**
	 * PDF strings carry single-byte encodings, not UTF-8: the fixtures'
	 * Catalan text is written as the WinAnsi bytes a reader would find.
	 */
	private static function pdfWinAnsi(string $text): string {
		return mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
	}

	private static function pdfLine(string $line): string {
		return '(' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], self::pdfWinAnsi($line)) . ") Tj\nT*\n";
	}

	private static function pdfRc4(string $key, string $data): string {
		$s = range(0, 255);
		$j = 0;
		$keyLength = strlen($key);
		for ($i = 0; $i < 256; $i++) {
			$j = ($j + $s[$i] + ord($key[$i % $keyLength])) & 0xFF;
			[$s[$i], $s[$j]] = [$s[$j], $s[$i]];
		}
		$out = '';
		$i = $j = 0;
		$len = strlen($data);
		for ($at = 0; $at < $len; $at++) {
			$i = ($i + 1) & 0xFF;
			$j = ($j + $s[$i]) & 0xFF;
			[$s[$i], $s[$j]] = [$s[$j], $s[$i]];
			$out .= $data[$at] ^ chr($s[($s[$i] + $s[$j]) & 0xFF]);
		}
		return $out;
	}

	/**
	 * A PDF 1.5 shape: the catalog and the page live inside an object
	 * stream, and the cross-reference is itself a flate-compressed
	 * stream with the PNG predictor the format prescribes for it —
	 * the structure every modern generator writes.
	 *
	 * @param list<string> $lines
	 */
	public static function pdfCompressed(array $lines): string {
		$content = "BT\n/F1 12 Tf\n";
		foreach ($lines as $line) {
			$content .= self::pdfLine($line);
		}
		$content .= "ET\n";

		// objects 1 (catalog) and 3 (page) travel inside object 6
		$objstmBodies = [
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792]'
			. ' /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
		];
		$header = '1 0 3 ' . strlen($objstmBodies[0]);
		$first = strlen($header);
		$objstmPayload = $header . implode('', $objstmBodies);
		$objstmCompressed = gzcompress($objstmPayload, 6);

		$objects = [
			null, // 1: inside the object stream
			'<< /Type /Pages /Kids [3 0 R] /Count 1 >>', // 2
			null, // 3: inside the object stream
			self::pdfStream('', $content), // 4
			'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>', // 5
			self::pdfStream("/Type /ObjStm /N 2 /First $first /Filter /FlateDecode", $objstmCompressed), // 6
		];

		$head = "%PDF-1.5\n%\xE2\xE3\xCF\xD3\n";
		$body = '';
		$at = strlen($head);
		$offsets = [];
		foreach ($objects as $index => $source) {
			$num = $index + 1;
			if ($source === null) {
				continue;
			}
			$offsets[$num] = $at;
			$entry = "$num 0 obj\n$source\nendobj\n";
			$body .= $entry;
			$at += strlen($entry);
		}

		// object 7: the cross-reference stream, over 8 objects
		$size = 8;
		$rows = [
			[0, 0, 0],
			[2, 6, 0], // object 1: inside object stream 6, first
			[1, $offsets[2], 0],
			[2, 6, 1], // object 3: inside object stream 6, second
			[1, $offsets[4], 0],
			[1, $offsets[5], 0],
			[1, $offsets[6], 0],
			[0, 0, 0], // object 7 places itself through startxref
		];
		$raw = '';
		foreach ($rows as $row) {
			$raw .= pack('CnN', $row[0], $row[1], $row[2]);
		}

		// PNG predictor 12 stores each row as its difference from the
		// row above; the decoder's Up filter undoes exactly that
		$columns = 7;
		$predicted = '';
		$previous = str_repeat("\0", $columns);
		foreach (str_split($raw, $columns) as $row) {
			$difference = '';
			for ($i = 0; $i < $columns; $i++) {
				$difference .= chr((ord($row[$i]) - ord($previous[$i])) & 0xFF);
			}
			// the /Predictor says 12 (PNG); the row byte itself is the
			// Up filter, type 2
			$predicted .= "\x02" . $difference;
			$previous = $row;
		}
		$compressed = gzcompress($predicted, 6);
		$xrefStream = self::pdfStream(
			"/Type /XRef /Size $size /W [1 2 4] /Index [0 $size] /Root 1 0 R"
			. " /Filter /FlateDecode /DecodeParms << /Predictor 12 /Columns $columns >>",
			$compressed,
		);
		$xrefOffset = $at;
		$body .= "7 0 obj\n$xrefStream\nendobj\n";

		return $head . $body . "startxref\n$xrefOffset\n%%EOF\n";
	}

	/**
	 * A Type0 Identity-H font with a ToUnicode CMap: bfchar, bfrange
	 * and a two-codepage range, over hex-string operands.
	 */
	public static function pdfToUnicodeText(): string {
		$cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
			. "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
			. "/CMapName /Adobe-Identity-UCS def\n"
			. "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
			. "2 beginbfchar\n<0041> <00C0>\n<00E9> <00E9>\nendbfchar\n"
			. "1 beginbfrange\n<0100> <0102> <0061>\nendbfrange\n"
			. "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";

		$content = "BT\n/F1 12 Tf\n[<004100E9>] TJ\nT*\n[<010001010102>] TJ\nET\n";

		return self::pdf([
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792]'
			. ' /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
			self::pdfStream('', $content),
			'<< /Type /Font /Subtype /Type0 /BaseFont /Test-UCS2 /Encoding /Identity-H'
			. ' /DescendantFonts [<< /Type /Font /Subtype /CIDFontType2 /BaseFont /Test-UCS2 >>] /ToUnicode 6 0 R >>',
			self::pdfStream('', $cmap),
		]);
	}
}
