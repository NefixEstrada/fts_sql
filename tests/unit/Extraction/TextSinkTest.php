<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Unit\Extraction;

use OCA\FtsSql\Extraction\TextSink;
use PHPUnit\Framework\TestCase;

/**
 * TextSink against Milestone 1's cut, which it inherits byte for byte: an
 * exact fit is not a cut, an overflow is cut multibyte-safe with the
 * trailing partial word dropped.
 */
class TextSinkTest extends TestCase {

	public function testPiecesWithinTheBudgetComeBackAsAccepted(): void {
		$sink = new TextSink(100);
		$sink->accept('sortida ');
		$sink->accept('al ');
		$sink->accept('museu');

		$this->assertSame('sortida al museu', $sink->text());
		$this->assertFalse($sink->overflowed());
		$this->assertFalse($sink->full(), 'sixteen bytes of a hundred leave room for more');
	}

	public function testAnExactFitIsNotAnOverflowUntilMoreComes(): void {
		$sink = new TextSink(4);
		$sink->accept('aaaa');
		$this->assertFalse($sink->overflowed(), 'a piece that fits exactly is not a cut');

		$sink->accept(' bb');
		$this->assertTrue($sink->overflowed());
		$this->assertSame('aaaa', $sink->text(), 'the cut drops the trailing partial word " bb" whole');
	}

	public function testTheCutIsMultibyteSafe(): void {
		// 'àçò àçò àçò' is 20 bytes; a 10-byte budget keeps 'àçò à' (9 bytes,
		// mb_strcut stops before splitting the 'ç' that would not fit), and
		// the partial 'à' is dropped back to the last space.
		$sink = new TextSink(10);
		$sink->accept('àçò àçò àçò');

		$this->assertTrue($sink->overflowed());
		$this->assertSame('àçò', $sink->text());
		$this->assertTrue(mb_check_encoding($sink->text(), 'UTF-8'));
	}

	public function testAPieceThatDoesNotFitMarksTheOverflowAndCutsToAWord(): void {
		$sink = new TextSink(5);
		$sink->accept('aa bb cc');

		$this->assertTrue($sink->overflowed());
		// mb_strcut keeps 'aa bb' (5 bytes), and the trailing partial word
		// is dropped back to the last space.
		$this->assertSame('aa', $sink->text());
	}

	public function testACutWithNoSpaceKeepsWholeCharacters(): void {
		$sink = new TextSink(4);
		$sink->accept('aabbcc');

		$this->assertSame('aabb', $sink->text());
	}

	public function testAZeroBudgetCutsEverythingToEmptyText(): void {
		$sink = new TextSink(0);
		$sink->accept('sortida');

		$this->assertSame('', $sink->text());
		$this->assertTrue($sink->overflowed());
	}
}
