<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Unit\Extraction;

use OCA\FtsSql\Extraction\ExtractionAbort;
use OCA\FtsSql\Extraction\ExtractionCause;
use OCA\FtsSql\Extraction\ZipContainer;
use OCA\FtsSql\Tests\Fixtures;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * ZipContainer against DESIGN.md's zip-bomb posture: the ratio pre-check
 * over the central directory's declared sizes, the read caps, and the
 * password-protected shapes — an OLE container where the caller's extension
 * says there should be a zip, and an encrypted entry inside an honest zip.
 */
class ZipContainerTest extends TestCase {
	private const CAP = 8_388_608;

	public function testReadsAnEntryBack(): void {
		$zip = ZipContainer::open(Fixtures::stream(Fixtures::zip(['a.xml' => 'hello'])), false);

		try {
			$this->assertSame('hello', $zip->read('a.xml', self::CAP));
			$this->assertNull($zip->read('missing.xml', self::CAP), 'a missing entry is null, not an error');
			$this->assertSame(['a.xml'], $zip->names());
		} finally {
			$zip->close();
		}
	}

	public function testNotAZipAbortsAsParserGaveUp(): void {
		$this->assertAbort(ExtractionCause::ParserGaveUp, 'not a zip container',
			fn () => ZipContainer::open(Fixtures::stream('plainly not a zip'), false));
	}

	public function testAnOleContainerAbortsAsEncryptedForOOXMLExtensions(): void {
		$this->assertAbort(ExtractionCause::Encrypted, 'password-protected',
			fn () => ZipContainer::open(Fixtures::stream(Fixtures::ole()), true));
	}

	public function testAnOleContainerAbortsAsParserGaveUpForOtherExtensions(): void {
		$this->assertAbort(ExtractionCause::ParserGaveUp, 'OLE file',
			fn () => ZipContainer::open(Fixtures::stream(Fixtures::ole()), false));
	}

	public function testADeclaredSizePastTheCapAbortsBeforeInflating(): void {
		$bytes = Fixtures::zip(['big.xml' => str_repeat('a', self::CAP + 1)]);

		$this->assertAbort(ExtractionCause::ParserGaveUp, 'past its', function () use ($bytes): void {
			$zip = ZipContainer::open(Fixtures::stream($bytes), false);
			try {
				$zip->read('big.xml', self::CAP);
			} finally {
				$zip->close();
			}
		});
	}

	public function testAZipBombRatioAborts(): void {
		// Two megabytes of one repeated byte deflate to a couple of
		// kilobytes — a ratio around a thousand, over the cap, with a
		// declared size under the read cap so the ratio check is what fires
		// rather than the size check.
		$bytes = Fixtures::zip(['word/document.xml' => str_repeat('a', 2_000_000)]);

		$this->assertAbort(ExtractionCause::ParserGaveUp, 'ratio cap', function () use ($bytes): void {
			$zip = ZipContainer::open(Fixtures::stream($bytes), true);
			try {
				$zip->read('word/document.xml', self::CAP);
			} finally {
				$zip->close();
			}
		});
	}

	public function testAnEncryptedEntryAbortsAsEncrypted(): void {
		$zip = new ZipArchive();
		$path = tempnam(sys_get_temp_dir(), 'fts-enc-');
		$zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		$zip->addFromString('word/document.xml', '<w:document/>');
		$encrypted = $zip->setPassword('pw')
			&& $zip->setEncryptionName('word/document.xml', ZipArchive::EM_AES_256);
		$zip->close();
		$bytes = (string)file_get_contents($path);
		@unlink($path);

		if (!$encrypted) {
			// This PHP's libzip cannot write encryption (the flake's does
			// not); CI's does, where this runs for real.
			$this->markTestSkipped('libzip without encryption support cannot write the fixture');
		}

		$this->assertAbort(ExtractionCause::Encrypted, 'encrypted', function () use ($bytes): void {
			$zip = ZipContainer::open(Fixtures::stream($bytes), true);
			try {
				$zip->read('word/document.xml', self::CAP);
			} finally {
				$zip->close();
			}
		});
	}

	private function assertAbort(ExtractionCause $cause, string $messagePart, callable $body): void {
		try {
			$body();
			$this->fail('an ExtractionAbort was expected');
		} catch (ExtractionAbort $abort) {
			$this->assertSame($cause, $abort->cause);
			$this->assertStringContainsString($messagePart, $abort->getMessage());
		}
	}
}
