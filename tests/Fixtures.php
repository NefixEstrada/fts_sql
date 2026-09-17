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

	// ---- Excel 97-2003 fixtures ---------------------------------------------

	/**
	 * A readable .xls: string cells in column A beside numeric ones,
	 * written by the scoped PhpSpreadsheet writer — the same library
	 * the extractor reads with, so the fixture is exactly what that
	 * writer produces. Strings are set explicitly: a cell value that
	 * begins with "=" would otherwise be written as a formula.
	 *
	 * @param list<string> $strings one string cell per row
	 */
	public static function xls(array $strings): string {
		$book = new \OCA\FtsSql\Vendor\PhpOffice\PhpSpreadsheet\Spreadsheet();
		$sheet = $book->getActiveSheet();
		foreach ($strings as $i => $string) {
			$row = $i + 1;
			$sheet->setCellValueExplicit('A' . $row, $string, \OCA\FtsSql\Vendor\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
			$sheet->setCellValue('B' . $row, $row * 100 + 1);
			$sheet->setCellValue('C' . $row, $row * 100 + 2);
		}
		$path = tempnam(sys_get_temp_dir(), 'fts-xls-') . '.xls';
		(new \OCA\FtsSql\Vendor\PhpOffice\PhpSpreadsheet\Writer\Xls($book))->save($path);
		$book->disconnectWorksheets();

		$bytes = file_get_contents($path);
		@unlink($path);
		return $bytes === false ? '' : $bytes;
	}

	/**
	 * The same workbook with a FILEPASS record spliced in after the
	 * first (BOF) record: the File Protection Block's marker for an
	 * encrypted workbook, the shape the extractor's own gate reads.
	 *
	 * @param list<string> $strings
	 */
	public static function xlsEncryptedToken(array $strings): string {
		$path = tempnam(sys_get_temp_dir(), 'fts-xls-') . '.xls';
		file_put_contents($path, self::xls($strings));
		$ole = \OCA\FtsSql\Extraction\Ppt\OleFile::open($path);
		$workbook = $ole->stream('Workbook') ?? $ole->stream('Book');
		@unlink($path);
		if ($workbook === null) {
			throw new RuntimeException('the xls fixture carries no Workbook stream');
		}

		$bofLength = 4 + (ord($workbook[2]) | ord($workbook[3]) << 8);
		$spliced = substr($workbook, 0, $bofLength) . pack('vv', 0x002F, 0) . substr($workbook, $bofLength);

		return self::oleContainer([
			['name' => 'Workbook', 'data' => $spliced],
		])['bytes'];
	}

	// ---- Word 97-2003 fixtures ------------------------------------------------

	/**
	 * A readable .doc: a simple-layout document, the shape the PhpWord
	 * reader actually parses — the text as UTF-16LE in the WordDocument
	 * stream where the paragraph table's FCs name it, one PAPX page per
	 * batch of paragraphs, one CHPX run over the lot, and the section,
	 * font and table structures the reader walks first. Word itself
	 * writes more; nothing here is anything the reader refuses.
	 *
	 * @param list<string> $paragraphs
	 */
	public static function doc(array $paragraphs): string {
		return self::docBuild($paragraphs, false);
	}

	/**
	 * The same document with the FIB's fEncrypted flag set — the flag
	 * the reader itself ignores and this app's gate reads.
	 *
	 * @param list<string> $paragraphs
	 */
	public static function docEncryptedFlag(array $paragraphs): string {
		return self::docBuild($paragraphs, true);
	}

	/**
	 * @param list<string> $paragraphs
	 */
	private static function docBuild(array $paragraphs, bool $encrypted): string {
		$fibMin = 1024;

		// the text: every paragraph, its \r, and a NUL unit to stop the
		// reader's read-through at the paragraph's end
		$text = '';
		$fcs = [];
		foreach ($paragraphs as $paragraph) {
			$fcs[] = $fibMin + strlen($text);
			$text .= self::pptUtf16($paragraph) . "\r\x00" . "\0\x00";
		}
		$textEnd = $fibMin + strlen($text);
		$ccpText = array_sum(array_map(static fn (string $p): int => self::pptUtf16Units($p) + 1, $paragraphs));

		// the PAPX pages: up to 28 paragraphs per page
		$pages = array_chunk($fcs, 28);
		// the section properties: one sprm, sprmSXaPage — enough for the
		// reader to build the section style it reads back
		$sepxAt = $textEnd;
		$sepx = pack('v', 6) . pack('v', 0x701F) . pack('V', 12240);
		$fkpStart = (int)ceil(($sepxAt + strlen($sepx)) / 512) * 512;
		$chpxPage = $fkpStart + count($pages) * 512;
		$length = $chpxPage + 512;

		$wordDocument = str_repeat("\0", $length);
		$wordDocument = substr_replace($wordDocument, pack('v', 0xA5EC), 0x00, 2); // wIdent
		$wordDocument = substr_replace($wordDocument, pack('v', 0x00C1), 0x02, 2); // nFib
		$flags = (1 << 3) | (1 << 6); // fExtChar: 16-bit text; fWhichTblStm: 1Table
		if ($encrypted) {
			$flags |= 1 << 7; // fEncrypted
		}
		$wordDocument = substr_replace($wordDocument, pack('v', $flags), 0x0A, 2);
		$wordDocument = substr_replace($wordDocument, pack('v', 0x00C1), 0x0C, 2); // nFibBack
		$wordDocument = substr_replace($wordDocument, pack('V', $length), 0x40, 4); // cbMac
		$wordDocument = substr_replace($wordDocument, pack('V', $ccpText), 0x4C, 4); // ccpText
		$wordDocument = substr_replace($wordDocument, pack('v', 0x5D), 0x98, 2); // cbRgFcLcb: the Word 97 block
		$wordDocument = substr_replace($wordDocument, $text, $fibMin, strlen($text));
		$wordDocument = substr_replace($wordDocument, $sepx, $sepxAt, strlen($sepx));

		// the FIB's fc/lcb pairs at their documented places: PlcfSed
		// (pair 7), PlcfBteChpx (13), PlcfBtePapx (14), SttbfFfn (16)
		$papxPlcfLength = (count($pages) + 1) * 4 + count($pages) * 4;
		$wordDocument = substr_replace($wordDocument, pack('VV', 0, 20), 0xCA, 8);
		$wordDocument = substr_replace($wordDocument, pack('VV', 24, 12), 0xFA, 8);
		$wordDocument = substr_replace($wordDocument, pack('VV', 36, $papxPlcfLength), 0x102, 8);
		$wordDocument = substr_replace($wordDocument, pack('VV', 20, 4), 0x112, 8);

		// the PAPX pages: rgfc, then one rgb byte per paragraph (with its
		// twelve pad bytes), then the zero PAPX they point at; the count
		// sits in the page's last byte
		foreach ($pages as $i => $pageFcs) {
			$page = str_repeat("\0", 512);
			$at = 0;
			foreach ($pageFcs as $fc) {
				$page = substr_replace($page, pack('V', $fc), $at, 4);
				$at += 4;
			}
			$page = substr_replace($page, pack('V', $textEnd), $at, 4);
			$at += 4;
			$papxAt = $at + count($pageFcs) * 13;
			$papxAt += $papxAt % 2; // word aligned, as the rgb offset halves
			foreach ($pageFcs as $ignored) {
				$page = substr_replace($page, chr(intdiv($papxAt, 2)), $at, 1);
				$at += 13;
			}
			$page = substr_replace($page, "\0\0\0\0", $papxAt, 4); // the PAPX itself: cb 0, istd 0
			$page = substr_replace($page, chr(count($pageFcs)), 511, 1);
			$wordDocument = substr_replace($wordDocument, $page, $fkpStart + $i * 512, 512);
		}

		// the CHPX page: one run over all the text, no style entry
		$chpx = str_repeat("\0", 512);
		$chpx = substr_replace($chpx, pack('V', $fibMin), 0, 4);
		$chpx = substr_replace($chpx, pack('V', $textEnd), 4, 4);
		$chpx = substr_replace($chpx, "\x01", 511, 1); // one rgfc pair, rgb 0: no style
		$wordDocument = substr_replace($wordDocument, $chpx, $chpxPage, 512);

		// the 1Table stream: PlcfSed, SttbfFfn, PlcfBteChpx, PlcfBtePapx,
		// at the offsets the FIB names (0, 20, 24, 36)
		$table = str_repeat("\0", 4096);
		$table = substr_replace($table, pack('VV', 0, $ccpText) . pack('vVvV', 0, $sepxAt, 0, 0), 0, 20);
		$table = substr_replace($table, pack('vv', 0, 0), 20, 4); // no fonts
		$table = substr_replace($table, pack('VVV', $fibMin, $textEnd, intdiv($chpxPage, 512)), 24, 12);
		// the plcf's CPs are one per page — its first paragraph's FC —
		// with the text's end closing the range: the reader counts the
		// pages from the length and skips exactly that many CPs
		$plcf = '';
		foreach ($pages as $pageFcs) {
			$plcf .= pack('V', $pageFcs[0]);
		}
		$plcf .= pack('V', $textEnd);
		foreach ($pages as $i => $ignored) {
			$plcf .= pack('V', intdiv($fkpStart + $i * 512, 512));
		}
		$table = substr_replace($table, $plcf, 36, strlen($plcf));

		return self::oleContainer([
			['name' => 'WordDocument', 'data' => $wordDocument],
			['name' => '1Table', 'data' => $table],
		])['bytes'];
	}

	private static function pptUtf16Units(string $text): int {
		return intdiv(strlen(self::pptUtf16($text)), 2);
	}

	// ---- PowerPoint 97 fixtures ----------------------------------------------

	/**
	 * A readable .ppt: one text shape per slide, every paragraph its own
	 * styled run, exactly the records the PowerPoint97 reader demands
	 * and nothing it merely tolerates. The paragraph delimiters are the
	 * format's own (\r inside the text atoms), so the reader builds one
	 * paragraph per string, the shape the .pptx fixtures build from
	 * their slide XML.
	 *
	 * @param list<list<string>> $slides paragraphs per slide
	 */
	public static function ppt(array $slides): string {
		return self::pptContainer($slides)['bytes'];
	}

	/**
	 * The same file with the encryption token: the compound container is
	 * untouched, so what this exercises is the CurrentUserAtom read,
	 * not the reader's own refusal.
	 *
	 * @param list<list<string>> $slides
	 */
	public static function pptEncryptedToken(array $slides): string {
		$built = self::pptContainer($slides);
		$tokenAt = $built['currentUserOffset'] + 12;
		return substr_replace($built['bytes'], pack('V', 0xF3D1C4DF), $tokenAt, 4);
	}

	/**
	 * The same file with the slide container claiming far more bytes
	 * than the document stream holds: the shape the reader's skip loops
	 * would walk past the end of the string.
	 *
	 * @param list<list<string>> $slides
	 */
	public static function pptLyingSlideLength(array $slides): string {
		$built = self::pptContainer($slides);
		return substr_replace($built['bytes'], pack('V', 0x00FFFF00), $built['firstSlideOffset'] + 4, 4);
	}

	/**
	 * The same file with the document stream's last sector pointing
	 * back at its first: the allocation chain is a loop.
	 *
	 * @param list<list<string>> $slides
	 */
	public static function pptFatCycle(array $slides): string {
		$built = self::pptContainer($slides);
		$document = $built['layout']['streams']['PowerPoint Document'];
		$lastSector = $document['start'] + $document['sectors'] - 1;
		$entryAt = 512 + $built['layout']['fatStart'] * 512 + $lastSector * 4;
		return substr_replace($built['bytes'], pack('V', $document['start']), $entryAt, 4);
	}

	/**
	 * @param list<list<string>> $slides
	 * @return array{bytes: string, currentUserOffset: int, firstSlideOffset: int, layout: array{fatStart: int, streams: array<string, array{start: int, sectors: int}>}}
	 */
	private static function pptContainer(array $slides): array {
		$document = self::pptDocumentStream($slides);

		$userEditAt = strlen($document) - (8 + 0x1C) - self::pptPersistDirectoryLength(count($slides));
		$currentUser = substr_replace(self::pptCurrentUserStream(), pack('V', $userEditAt), 16, 4);

		$built = self::oleContainer([
			['name' => 'Current User', 'data' => $currentUser],
			['name' => 'PowerPoint Document', 'data' => $document],
			['name' => 'Pictures', 'data' => ''],
		]);

		$currentUserOffset = 512 + $built['layout']['streams']['Current User']['start'] * 512;
		$documentOffset = 512 + $built['layout']['streams']['PowerPoint Document']['start'] * 512;
		// the document container and its one atom precede the first slide
		$firstSlideOffset = $documentOffset + (8 + 8 + 0x28);

		return [
			'bytes' => $built['bytes'],
			'currentUserOffset' => $currentUserOffset,
			'firstSlideOffset' => $firstSlideOffset,
			'layout' => $built['layout'],
		];
	}

	/**
	 * The document stream: document container, slide containers, the
	 * user edit atom, the persist directory — in that order, which is
	 * what the offsets above rely on.
	 *
	 * @param list<list<string>> $slides
	 */
	private static function pptDocumentStream(array $slides): string {
		$documentContainer = self::pptRecord(0xF, 0, 0x3E8,
			self::pptRecord(1, 0, 0x3E9, str_repeat("\0", 0x28)));

		$stream = $documentContainer;
		$slideOffsets = [];
		foreach ($slides as $paragraphs) {
			$slideOffsets[] = strlen($stream);
			$stream .= self::pptSlideContainer($paragraphs);
		}

		$userEditAt = strlen($stream);
		$persistDirectoryAt = $userEditAt + 8 + 0x1C;

		$userEdit = pack('V', 256)          // lastSlideIdRef
			. pack('v', 0x000F)             // version
			. "\x00"                        // minorVersion: 0
			. "\x03"                        // majorVersion: 3
			. pack('V', 0)                  // offsetLastEdit
			. pack('V', $persistDirectoryAt)
			. pack('V', 1)                  // docPersistIdRef: 1
			. pack('V', count($slides) + 2) // persistIdSeed
			. pack('v', 1)                  // lastView
			. pack('v', 0);                 // unused
		$stream .= self::pptRecord(0, 0, 0x0FF5, $userEdit);

		$offsets = pack('V', 0);
		foreach ($slideOffsets as $offset) {
			$offsets .= pack('V', $offset);
		}
		$entry = pack('V', 1 | ((count($slideOffsets) + 1) << 20)) . $offsets;
		$stream .= self::pptRecord(0, 0, 0x1772, $entry);

		return $stream;
	}

	private static function pptPersistDirectoryLength(int $slides): int {
		return 8 + 4 + 4 * ($slides + 1);
	}

	/**
	 * @param list<string> $paragraphs
	 */
	private static function pptSlideContainer(array $paragraphs): string {
		$slideAtom = self::pptRecord(2, 0, 0x3EF,
			pack('V', 0)              // geom
			. str_repeat("\0", 8)     // rgPlaceholderTypes
			. pack('V', 0)            // masterIdRef
			. pack('V', 0)            // notesIdRef
			. pack('vv', 0, 0));      // slideFlags, unused

		// one text shape: FSP, an anchor, and a textbox whose styled
		// runs are one per paragraph
		$textBox = self::pptRecord(0xF, 0, 0xF00D,
			self::pptRecord(0, 0, 0x0F9F, "\0\0\0\0")
			. self::pptRecord(0, 0, 0x0FA0, self::pptUtf16(implode("\r", $paragraphs)))
			. self::pptStyleTextProp(array_map(
				static fn (string $p): int => intdiv(strlen(mb_convert_encoding($p, 'UTF-16LE', 'UTF-8')), 2),
				$paragraphs,
			)));
		$shape = self::pptRecord(0xF, 0, 0xF004,
			self::pptRecord(2, 0, 0xF00A, pack('VV', 0x400, 0))
			. self::pptRecord(0, 0, 0xF010, pack('vvvv', 100, 100, 600, 400))
			. $textBox);

		$drawing = self::pptRecord(0xF, 0, 0x040C,
			self::pptRecord(0xF, 0, 0xF002,
				self::pptRecord(0, 0, 0xF008, pack('VV', 0x400, 1))
				. self::pptRecord(0xF, 0, 0xF003, $shape)));

		$colorScheme = self::pptRecord(0, 1, 0x07F0, str_repeat("\0", 0x20));

		return self::pptRecord(0xF, 0, 0x3EE, $slideAtom . $drawing . $colorScheme);
	}

	/**
	 * StyleTextPropAtom: one paragraph-level run and one character-level
	 * run per paragraph, each covering the paragraph's characters plus
	 * its delimiter — the shape that makes the reader build one
	 * paragraph per string. The counts are UTF-16 code units, the unit
	 * the reader tallies the text in; all masks are zero, so no styling
	 * fields ride along.
	 *
	 * @param list<int> $units UTF-16 code units per paragraph
	 */
	private static function pptStyleTextProp(array $units): string {
		$body = '';
		$runs = '';
		foreach ($units as $count) {
			$count = $count + 1;
			$body .= pack('V', $count) . pack('v', 0) . pack('V', 0); // count, indent, masks
			$runs .= pack('VV', $count, 0); // count, masks
		}
		return self::pptRecord(0, 0, 0x0FA1, $body . $runs);
	}

	private static function pptCurrentUserStream(): string {
		// relVersion's own first byte (0x08) is a control character, and
		// the ANSI user-name scan stops on the first of those without
		// consuming it: an empty user name needs no terminator of its own
		return self::pptRecord(0, 0, 0x0FF6,
			pack('V', 0x14)           // size
			. pack('V', 0xE391C80F)   // headerToken: not encrypted
			. pack('V', 0)            // offsetToCurrentEdit, patched by the caller
			. pack('v', 0)            // lenUserName
			. pack('v', 0x03F4)       // docFileVersion
			. "\x03"                  // majorVersion
			. "\x00"                  // minorVersion
			. pack('v', 0)            // unused
			. pack('V', 8));          // relVersion
	}

	private static function pptUtf16(string $text): string {
		return mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
	}

	private static function pptRecord(int $version, int $instance, int $type, string $body): string {
		return pack('vvV', $version | ($instance << 4), $type, strlen($body)) . $body;
	}

	/**
	 * A compound-file container with no mini-stream: every stream is
	 * padded past the 4096-byte threshold so its sectors hang off the
	 * main allocation table, the layout both OLE readers this app has
	 * dispatch on by the stream's declared size. Sector order: the
	 * streams in the order given, then the directory, then the
	 * allocation table itself.
	 *
	 * @param list<array{name: string, data: string}> $streams
	 * @return array{bytes: string, layout: array{fatStart: int, streams: array<string, array{start: int, sectors: int}>}}
	 */
	private static function oleContainer(array $streams): array {
		$end = 0xFFFFFFFE;
		$fatSector = 0xFFFFFFFD;
		$unused = 0xFFFFFFFF;

		$dataSectors = [];
		$layout = ['streams' => []];
		$nextSector = 0;
		foreach ($streams as $stream) {
			$payload = $stream['data'];
			if (strlen($payload) < 4096) {
				$payload = str_pad($payload, 4096, "\0");
			}
			$sectors = intdiv(strlen($payload) - 1, 512) + 1;
			$payload = str_pad($payload, $sectors * 512, "\0");

			$layout['streams'][$stream['name']] = ['start' => $nextSector, 'sectors' => $sectors];
			foreach (str_split($payload, 512) as $sector) {
				$dataSectors[] = $sector;
				$nextSector++;
			}
		}

		$directorySector = $nextSector++;
		$dataSectorCount = count($dataSectors) + 1; // + the directory

		$fatSectors = 1;
		while (128 * $fatSectors < $dataSectorCount + $fatSectors) {
			$fatSectors++;
		}
		$layout['fatStart'] = $nextSector;

		$total = $dataSectorCount + $fatSectors;
		$fat = '';
		for ($sector = 0; $sector < $total; $sector++) {
			if ($sector >= $dataSectorCount) {
				$next = $fatSector; // the table's own sectors
			} else {
				$next = $end;
				foreach ($layout['streams'] as $info) {
					if ($sector >= $info['start'] && $sector < $info['start'] + $info['sectors'] - 1) {
						$next = $sector + 1;
						break;
					}
				}
			}
			$fat .= pack('V', $next);
		}
		$fat = str_pad($fat, $fatSectors * 512, pack('V', $unused));

		// directory: the root, then one entry per stream
		$entries = self::oleDirectoryEntry('Root Entry', 5, $end, 0);
		foreach ($streams as $stream) {
			$info = $layout['streams'][$stream['name']];
			$entries .= self::oleDirectoryEntry($stream['name'], 2, $info['start'], $info['sectors'] * 512);
		}
		$directory = str_pad($entries, 512, "\0");

		$header = str_repeat("\0", 512);
		$header = substr_replace($header, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 0, 8);
		$header = substr_replace($header, pack('vvvv', 0x3E, 3, 0xFFFE, 9), 0x18, 8); // versions, byte order, sector shift
		$header = substr_replace($header, pack('v', 6), 0x20, 2); // mini sector shift
		$header = substr_replace($header, pack('V', $fatSectors), 0x2C, 4);
		$header = substr_replace($header, pack('V', $directorySector), 0x30, 4);
		$header = substr_replace($header, pack('V', $end), 0x3C, 4); // no mini allocation table
		$difat = '';
		for ($i = 0; $i < 109; $i++) {
			$difat .= pack('V', $i < $fatSectors ? $layout['fatStart'] + $i : $unused);
		}
		$header = substr_replace($header, $difat, 0x4C, 436);

		$bytes = $header . implode('', $dataSectors) . $directory;
		for ($i = 0; $i < $fatSectors; $i++) {
			$bytes .= substr($fat, $i * 512, 512);
		}
		return ['bytes' => $bytes, 'layout' => $layout];
	}

	private static function oleDirectoryEntry(string $name, int $type, int $start, int $size): string {
		$entry = str_repeat("\0", 128);
		$nameBytes = mb_convert_encoding($name, 'UTF-16LE', 'UTF-8') . "\0\0";
		$entry = substr_replace($entry, $nameBytes, 0, strlen($nameBytes));
		$entry = substr_replace($entry, pack('v', strlen($nameBytes)), 0x40, 2);
		$entry = substr_replace($entry, chr($type), 0x42, 1);
		$entry = substr_replace($entry, chr(1), 0x43, 1); // colour: black
		$entry = substr_replace($entry, str_repeat(pack('V', 0xFFFFFFFF), 3), 0x44, 12); // no siblings, no children: the readers scan flat
		$entry = substr_replace($entry, pack('V', $start), 0x74, 4);
		$entry = substr_replace($entry, pack('VV', $size, 0), 0x78, 8);
		return $entry;
	}
}
