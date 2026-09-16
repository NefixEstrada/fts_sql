<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Unit\Service;

use OCA\FtsSql\Exceptions\AccessIsEmpty;
use OCA\FtsSql\Service\IndexMappingService;
use OCA\FtsSql\Tests\Fixtures;
use OCP\FullTextSearch\Model\IDocumentAccess;
use OCP\FullTextSearch\Model\IIndex;
use OCP\FullTextSearch\Model\IIndexDocument;
use PHPUnit\Framework\TestCase;

/**
 * IndexMappingService::map against DESIGN.md's "Worked example: indexing
 * one document", plus the ways the content can fail to arrive — a base64
 * that does not decode (a provider bug, ERROR_SEV_3) and a format with no
 * extractor yet (expected, ERROR_SEV_1) — and, from Milestone 2, the
 * documents that arrive: a .docx on its body text, a password-protected
 * one without content, and a budget cut that extracts and flags together.
 * All of them still yield a full row.
 */
class IndexMappingServiceTest extends TestCase {

	public function testMapsTheWorkedExampleFromTheDesign(): void {
		$document = self::document(
			title: 'Escola/Sortida al Museu de Ciències.txt',
			content: base64_encode('sortida al museu'),
			encoded: IIndexDocument::ENCODED_BASE64,
			access: self::access(owner: 'biel', groups: ['professorat']),
			tags: ['files_local'],
		);

		$row = IndexMappingService::map($document, 2097152);

		$this->assertSame('files', $row->providerId);
		$this->assertSame('12', $row->documentId);
		$this->assertSame('biel', $row->owner);
		$this->assertSame('Escola/Sortida al Museu de Ciències.txt', $row->title);
		$this->assertSame('sortida al museu', $row->content);
		$this->assertSame('', $row->link);
		$this->assertSame('files', $row->source);
		$this->assertSame(1757984400, $row->modified);
		$this->assertSame('abc123', $row->hash);
		$this->assertSame(['o:biel', 'g:professorat'], $row->tokens);
		$this->assertSame([['kind' => 'meta', 'value' => 'files_local']], $row->tags);
		$this->assertTrue($row->contentExtracted);
		$this->assertNull($row->contentError);
		$this->assertSame(0, $row->contentErrorSeverity);
	}

	public function testBase64ThatDoesNotDecodeStillYieldsARow(): void {
		$document = self::document(
			title: 'Escola/Sortida al Museu de Ciències.txt',
			content: 'sortida al museu!!',
			encoded: IIndexDocument::ENCODED_BASE64,
			access: self::access(owner: 'biel'),
			tags: ['files_local'],
		);

		$row = IndexMappingService::map($document, 2097152);

		$this->assertFalse($row->contentExtracted);
		$this->assertNull($row->content);
		$this->assertNotNull($row->contentError);
		$this->assertStringContainsString('base64', $row->contentError);
		$this->assertSame(IIndex::ERROR_SEV_3, $row->contentErrorSeverity);
		// The row is still whole: title, access and tags travel.
		$this->assertSame('Escola/Sortida al Museu de Ciències.txt', $row->title);
		$this->assertSame(['o:biel'], $row->tokens);
		$this->assertSame([['kind' => 'meta', 'value' => 'files_local']], $row->tags);
	}

	public function testADeniedExtensionIsIndexedWithoutContent(): void {
		$document = self::document(
			title: 'Escola/Sortida al Museu de Ciències.epub',
			content: base64_encode('a zip of XML nobody claims'),
			encoded: IIndexDocument::ENCODED_BASE64,
			access: self::access(owner: 'biel'),
		);

		$row = IndexMappingService::map($document, 2097152);

		$this->assertFalse($row->contentExtracted);
		$this->assertNull($row->content);
		$this->assertNotNull($row->contentError);
		$this->assertStringContainsString('epub', $row->contentError);
		$this->assertSame(IIndex::ERROR_SEV_1, $row->contentErrorSeverity);
		$this->assertSame('Escola/Sortida al Museu de Ciències.epub', $row->title);
	}

	public function testAPdfTheParserGivesUpOnIsIndexedOnWhatItRecovered(): void {
		$document = self::document(
			title: 'Escola/Sortida al Museu de Ciències.pdf',
			content: base64_encode('%PDF-1.7'),
			encoded: IIndexDocument::ENCODED_BASE64,
			access: self::access(owner: 'biel'),
		);

		$row = IndexMappingService::map($document, 2097152);

		$this->assertFalse($row->contentExtracted);
		$this->assertNull($row->content);
		$this->assertNotNull($row->contentError);
		$this->assertStringContainsString('pdf', $row->contentError);
		$this->assertSame(IIndex::ERROR_SEV_1, $row->contentErrorSeverity);
	}

	public function testADocxIsIndexedOnItsBodyText(): void {
		$document = self::document(
			title: 'Escola/Sortida al Museu de Ciències.docx',
			content: base64_encode(Fixtures::docx(
				'<w:p><w:r><w:t>sortida al museu de ciències</w:t></w:r></w:p>',
			)),
			encoded: IIndexDocument::ENCODED_BASE64,
			access: self::access(owner: 'biel'),
		);

		$row = IndexMappingService::map($document, 2097152);

		$this->assertTrue($row->contentExtracted);
		$this->assertSame("sortida al museu de ciències\n", $row->content);
		$this->assertNull($row->contentError);
		$this->assertSame(0, $row->contentErrorSeverity);
	}

	public function testAPasswordProtectedDocxIsIndexedWithoutContent(): void {
		$document = self::document(
			title: 'Escola/Sortida al Museu de Ciències.docx',
			content: base64_encode(Fixtures::ole()),
			encoded: IIndexDocument::ENCODED_BASE64,
			access: self::access(owner: 'biel'),
		);

		$row = IndexMappingService::map($document, 2097152);

		$this->assertFalse($row->contentExtracted);
		$this->assertNull($row->content);
		$this->assertNotNull($row->contentError);
		$this->assertStringContainsString('password-protected', $row->contentError);
		$this->assertSame(IIndex::ERROR_SEV_1, $row->contentErrorSeverity);
	}

	public function testUnencodedContentIsTakenAsTheBytes(): void {
		$row = IndexMappingService::map(self::document(title: 'notes.md', content: 'hola món'), 2097152);

		$this->assertTrue($row->contentExtracted);
		$this->assertSame('hola món', $row->content);
		$this->assertNull($row->contentError);
	}

	public function testTheBudgetCutsTheContentAndFlagsTheDocument(): void {
		$row = IndexMappingService::map(self::document(title: 'notes.txt', content: 'aa bb cc'), 5);

		// The budget cut extracts and flags at the same time: the content
		// is what survived, and the error says it is not the whole document.
		$this->assertTrue($row->contentExtracted);
		$this->assertSame('aa', $row->content);
		$this->assertNotNull($row->contentError);
		$this->assertStringContainsString('budget', $row->contentError);
		$this->assertSame(IIndex::ERROR_SEV_1, $row->contentErrorSeverity);
	}

	public function testAnEmptyOwnerBecomesNullInTheRow(): void {
		$row = IndexMappingService::map(
			self::document(title: 'notes.txt', content: 'hola', access: self::access(users: ['dani'])),
			2097152,
		);

		$this->assertNull($row->owner);
		$this->assertSame(['u:dani'], $row->tokens);
	}

	public function testAnEmptyHashBecomesNullInTheRow(): void {
		$row = IndexMappingService::map(self::document(title: 'notes.txt', content: 'hola', hash: ''), 2097152);

		$this->assertNull($row->hash);
	}

	public function testAnAccessWithNoIdentityRefusesTheDocument(): void {
		$this->expectException(AccessIsEmpty::class);

		IndexMappingService::map(
			self::document(title: 'notes.txt', content: 'hola', access: self::access()),
			2097152,
		);
	}

	/**
	 * IIndexDocument is large; the getters IndexMappingService reads are
	 * stubbed, the rest of the mock stays at PHPUnit's defaults.
	 */
	private function document(
		string $title = '',
		string $content = '',
		int $encoded = IIndexDocument::NOT_ENCODED,
		?IDocumentAccess $access = null,
		array $tags = [],
		string $hash = 'abc123',
	): IIndexDocument {
		$document = self::createMock(IIndexDocument::class);
		$document->method('getProviderId')->willReturn('files');
		$document->method('getId')->willReturn('12');
		$document->method('getTitle')->willReturn($title);
		$document->method('getContent')->willReturn($content);
		$document->method('isContentEncoded')->willReturn($encoded);
		$document->method('getAccess')->willReturn($access ?? self::access(owner: 'biel'));
		$document->method('getTags')->willReturn($tags);
		$document->method('getLink')->willReturn('');
		$document->method('getSource')->willReturn('files');
		$document->method('getModifiedTime')->willReturn(1757984400);
		$document->method('getHash')->willReturn($hash);
		return $document;
	}

	private function access(
		string $owner = '',
		string $viewer = '',
		array $users = [],
		array $groups = [],
		array $circles = [],
	): IDocumentAccess {
		$access = self::createMock(IDocumentAccess::class);
		$access->method('getOwnerId')->willReturn($owner);
		$access->method('getViewerId')->willReturn($viewer);
		$access->method('getUsers')->willReturn($users);
		$access->method('getGroups')->willReturn($groups);
		$access->method('getCircles')->willReturn($circles);
		return $access;
	}
}
