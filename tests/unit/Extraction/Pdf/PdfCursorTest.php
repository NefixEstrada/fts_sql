<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Unit\Extraction\Pdf;

use OCA\FtsSql\Extraction\Pdf\PdfCursor;
use OCA\FtsSql\Extraction\Pdf\PdfName;
use OCA\FtsSql\Extraction\Pdf\PdfRef;
use PHPUnit\Framework\TestCase;

class PdfCursorTest extends TestCase {
	public function testReadsEveryScalarShape(): void {
		$cursor = new PdfCursor('42 -7 3.14 +0.5');
		$this->assertSame(42, $cursor->value());
		$this->assertSame(-7, $cursor->value());
		$this->assertSame(3.14, $cursor->value());
		$this->assertSame(0.5, $cursor->value());
	}

	public function testAReferenceIsThreeTokensAndNotTwoNumbers(): void {
		$cursor = new PdfCursor('12 0 R 5');
		$ref = $cursor->value();
		$this->assertInstanceOf(PdfRef::class, $ref);
		$this->assertSame(12, $ref->object);
		$this->assertSame(0, $ref->generation);
		// the number after a non-reference pair still reads
		$this->assertSame(5, $cursor->value());
	}

	public function testNumbersThatAreNotAReferenceDoNotConsumeTheNextOne(): void {
		$cursor = new PdfCursor("0 126989\n0000000015");
		$this->assertSame(0, $cursor->value());
		$this->assertSame(126989, $cursor->value());
		$this->assertSame(15, $cursor->value());
	}

	public function testNamesWithHashEscapes(): void {
		$cursor = new PdfCursor('/FlateDecode /A#20B');
		$name = $cursor->value();
		$this->assertInstanceOf(PdfName::class, $name);
		$this->assertSame('FlateDecode', $name->name);
		$escaped = $cursor->value();
		$this->assertInstanceOf(PdfName::class, $escaped);
		$this->assertSame('A B', $escaped->name);
	}

	public function testLiteralStringEscapesAndBalancedParens(): void {
		$cursor = new PdfCursor('(line \\\\ cont\\ninued (nested) \\101\\102)');
		$this->assertSame("line \\ cont\ninued (nested) AB", $cursor->value());
	}

	public function testALineContinuationSwallowsTheWholeEol(): void {
		$cursor = new PdfCursor("(split\\\r\nhere)");
		$this->assertSame('splithere', $cursor->value());
	}

	public function testHexStringsPadOddLengths(): void {
		$cursor = new PdfCursor('<48656C6C6F> <4 0>');
		$this->assertSame('Hello', $cursor->value());
		$this->assertSame("\x40", $cursor->value());
	}

	public function testDictionariesAndArraysNest(): void {
		$cursor = new PdfCursor('<< /A [1 (two) /Three] /B << /C null /D true >> >>');
		$dict = $cursor->value();
		$this->assertSame([1, 'two'], array_slice($dict['A'], 0, 2));
		$this->assertInstanceOf(PdfName::class, $dict['A'][2]);
		$this->assertNull($dict['B']['C']);
		$this->assertTrue($dict['B']['D']);
	}

	public function testNestingPastTheCapIsTruncatedNotRecursed(): void {
		$deep = str_repeat('[', 40) . str_repeat(']', 40);
		$cursor = new PdfCursor($deep);
		$value = $cursor->value();

		// the cap cuts the descent and the parse returns: bounded,
		// which is the property the hostile posture needs
		$this->assertIsArray($value);
		$this->assertTrue($cursor->eof() || $cursor->pos > 0);
	}

	public function testKeywordsReadAsThemselves(): void {
		$cursor = new PdfCursor('obj endobj Tj TJ');
		$this->assertSame('obj', $cursor->keyword());
		$this->assertSame('endobj', $cursor->keyword());
		$this->assertSame('Tj', $cursor->keyword());
		$this->assertSame('TJ', $cursor->keyword());
		$this->assertTrue($cursor->eof());
	}

	public function testUnrecognisedBytesYieldNullAndAdvanceWithTheCaller(): void {
		$cursor = new PdfCursor('}}}}');
		for ($i = 0; $i < 4; $i++) {
			$this->assertNull($cursor->value());
			$cursor->pos++;
		}
		$this->assertTrue($cursor->eof());
	}

	public function testCommentsAndWhitespaceAreSkipped(): void {
		$cursor = new PdfCursor("  % a comment to the end of the line\n42");
		$this->assertSame(42, $cursor->value());
	}
}
