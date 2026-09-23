<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Unit\Listener;

use OCA\FtsSql\Backends\BackendFactory;
use OCA\FtsSql\Extraction\ExtractionCause;
use OCA\FtsSql\Listener\FilesIndexingListener;
use OCA\FtsSql\Service\ConfigService;
use OCA\FtsSql\Tests\Fixtures;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\GenericEvent;
use OCP\Files\File;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The streaming fast path in isolation: the subject filter (on Nextcloud 34
 * the event arrives by class name, so a same-class event with another
 * subject must pass through untouched), the "the provider already read it"
 * skip, and the marker that carries the extraction's outcome to
 * IndexMappingService — text set as plain content, cause and message set
 * even when there is no text, and nothing the listener does ever throwing
 * over the provider's indexing run.
 */
class FilesIndexingListenerTest extends TestCase {
	private IIndexDocument&MockObject $document;
	private File&MockObject $file;
	private FilesIndexingListener $listener;

	protected function setUp(): void {
		self::stubDoctrineConstants();
		$appConfig = self::createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(2097152);
		// The factory is never read here — the listener only spends the
		// content budget — but ConfigService's constructor takes it.
		$db = self::createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_POSTGRES);
		$config = new ConfigService($appConfig, new BackendFactory($db));
		$this->listener = new FilesIndexingListener($config);
		$this->document = self::createMock(IIndexDocument::class);
		$this->file = self::createMock(File::class);
	}

	public function testIgnoresEventsOfAnotherSubject(): void {
		$this->document->expects(self::never())->method('setInfoArray');

		$this->listener->handle(new GenericEvent('Files_FullTextSearch.onSearchRequest', [
			'document' => $this->document, 'file' => $this->file,
		]));
	}

	public function testIgnoresEventsThatAreNotGenericEvents(): void {
		$this->document->expects(self::never())->method('setInfoArray');

		$this->listener->handle(new Event());
	}

	public function testSkipsDocumentsTheProviderAlreadyFilled(): void {
		$this->document->method('getContent')->willReturn('c29ydGlkYQ==');
		$this->file->expects(self::never())->method('fopen');
		$this->document->expects(self::never())->method('setInfoArray');

		$this->listener->handle($this->event());
	}

	public function testStreamsADocxAndMarksTheOutcome(): void {
		$this->fileReads(Fixtures::docx('<w:p><w:r><w:t>sortida al museu de ciències</w:t></w:r></w:p>'));
		$this->document->method('getTitle')->willReturn('Escola/sortida-museu.docx');

		$set = [];
		$this->document->method('setContent')->willReturnCallback(
			function (string $content) use (&$set): IIndexDocument {
				$set['content'] = $content;
				return $this->document;
			},
		);
		$this->document->method('setInfoArray')->willReturnCallback(
			function (string $key, array $value) use (&$set): IIndexDocument {
				$set[$key] = $value;
				return $this->document;
			},
		);

		$this->listener->handle($this->event());

		$this->assertSame("sortida al museu de ciències\n", $set['content'] ?? null);
		$this->assertSame([
			'extracted' => true,
			'cause' => null,
			'message' => '',
		], $set[FilesIndexingListener::INFO_KEY] ?? null);
	}

	public function testAnEncryptedContainerCarriesItsCauseWithoutContent(): void {
		$this->fileReads(Fixtures::ole());
		$this->document->method('getTitle')->willReturn('Escola/sortida-museu.docx');

		$set = [];
		$this->document->method('setInfoArray')->willReturnCallback(
			function (string $key, array $value) use (&$set): IIndexDocument {
				$set[$key] = $value;
				return $this->document;
			},
		);
		$this->document->expects(self::never())->method('setContent');

		$this->listener->handle($this->event());

		$this->assertSame(ExtractionCause::Encrypted->value, $set[FilesIndexingListener::INFO_KEY]['cause'] ?? null);
		$this->assertFalse($set[FilesIndexingListener::INFO_KEY]['extracted']);
	}

	public function testAFileThatCannotBeOpenedIsAParserGaveUpMarker(): void {
		$this->file->method('fopen')->willReturn(false);
		$this->document->method('getTitle')->willReturn('Escola/sortida-museu.docx');

		$set = [];
		$this->document->method('setInfoArray')->willReturnCallback(
			function (string $key, array $value) use (&$set): IIndexDocument {
				$set[$key] = $value;
				return $this->document;
			},
		);

		$this->listener->handle($this->event());

		$this->assertSame(ExtractionCause::ParserGaveUp->value, $set[FilesIndexingListener::INFO_KEY]['cause'] ?? null);
	}

	private function event(): GenericEvent {
		return new GenericEvent('Files_FullTextSearch.onFileIndexing', [
			'document' => $this->document,
			'file' => $this->file,
		]);
	}

	/**
	 * The node hands over a stream the extractors can read — the one thing
	 * path B exists for. The budget is the config's, so the tiny cut test
	 * works through the same entry point.
	 */
	private function fileReads(string $bytes): void {
		$stream = fopen('php://temp', 'w+b');
		fwrite($stream, $bytes);
		rewind($stream);
		$this->file->method('fopen')->willReturn($stream);
	}

	/**
	 * The nextcloud/ocp dev dependency ships interfaces whose constants
	 * borrow Doctrine's (IQueryBuilder::PARAM_STR is
	 * Doctrine\DBAL\ParameterType::STRING), but Doctrine itself is not a
	 * dependency of this app. PHPUnit's mock generator evaluates default
	 * parameter values, and IDBConnection::quote()'s default is one of
	 * those constants — so the two constant holders must exist for
	 * createMock(IDBConnection) to work. The values are never read.
	 */
	private static function stubDoctrineConstants(): void {
		if (class_exists('Doctrine\DBAL\ParameterType')) {
			return;
		}
		eval(<<<'PHP'
			namespace Doctrine\DBAL;

			final class ParameterType {
				public const NULL = 0;
				public const INTEGER = 1;
				public const STRING = 2;
				public const LARGE_OBJECT = 3;
			}

			final class ArrayParameterType {
				public const INTEGER = 101;
				public const STRING = 102;
			}
			PHP
		);
	}
}
