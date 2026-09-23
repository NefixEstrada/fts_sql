<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Listener;

use OCA\FtsSql\Extraction\ExtractionCause;
use OCA\FtsSql\Extraction\ExtractionResult;
use OCA\FtsSql\Service\ConfigService;
use OCA\FtsSql\Service\ExtractionService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\FullTextSearch\Model\IIndexDocument;

/**
 * The streaming fast path (DESIGN.md, "Open issue: where extraction plugs
 * in", option (b)): while the files provider fills a document it fires
 * Files_FullTextSearch.onFileIndexing with the real Node, and this listener
 * extracts straight from the node's stream — no base64 in memory, no decoded
 * copy — through the extractors' own stream entry point. The outcome is set
 * on the document under self::INFO_KEY, and IndexMappingService honours it
 * instead of extracting again: the platform's indexDocument() then stores
 * what it is handed.
 *
 * The event only fires for the files provider, only with
 * files_fulltextsearch installed, and only inside its own 20 MB gate — every
 * other document still arrives base64 through indexDocument(), which stays
 * correct for every provider (option (a) keeps working underneath).
 *
 * On Nextcloud 34 this event is a GenericEvent delivered by CLASS name, not
 * by its subject: subject-name listeners never fire (measured against
 * 34.0.4), so the registration listens on GenericEvent and the subject
 * filter lives here, first thing.
 */
/**
 * @psalm-suppress MissingTemplateParam bare IEventListener on purpose: the
 *                  event this class exists for is dispatched as a deprecated
 *                  GenericEvent naming it as the template parameter would
 *                  trade one suppression for three
 */
final class FilesIndexingListener implements IEventListener {
	public const INFO_KEY = 'fts_sql';

	public function __construct(
		private ConfigService $config,
	) {
	}

	public function handle(Event $event): void {
		/** @psalm-suppress DeprecatedMethod GenericEvent is what files_fulltextsearch 34 still dispatches; there is no non-deprecated route to this event */
		if (!$event instanceof GenericEvent
			|| $event->getSubject() !== 'Files_FullTextSearch.onFileIndexing') {
			return;
		}

		/** @psalm-suppress DeprecatedMethod the same reason as above */
		$document = $event->getArgument('document');
		/** @psalm-suppress DeprecatedMethod the same reason as above */
		$file = $event->getArgument('file');
		if (!$document instanceof IIndexDocument || !$file instanceof File) {
			return;
		}

		// The provider reads its own plain-text formats into the document
		// before the event: that memory is already spent, and re-reading them
		// from the node would save nothing. getContent() is string-typed, so
		// the empty string is its "never set" — and an empty extraction lands
		// there too, which this path re-derives correctly from the node.
		if ($document->getContent() !== '') {
			return;
		}

		// The extension is read from the title, which the provider has set to
		// the path by now — the same place IndexMappingService reads it.
		$extension = pathinfo($document->getTitle(), PATHINFO_EXTENSION);

		$result = $this->extract($file, $extension);
		if ($result->text !== null) {
			$document->setContent($result->text);
		}
		$document->setInfoArray(self::INFO_KEY, [
			'extracted' => $result->text !== null,
			'cause' => $result->cause?->value,
			'message' => $result->message,
		]);
	}

	/**
	 * One bad document costs that document, never the provider's indexing
	 * run: whatever goes wrong comes back as a cause on the marker, the same
	 * contract the extractors themselves keep.
	 *
	 * @return ExtractionResult
	 */
	private function extract(File $file, string $extension): ExtractionResult {
		$stream = false;
		try {
			$stream = $file->fopen('rb');
			if ($stream === false) {
				return new ExtractionResult(null, ExtractionCause::ParserGaveUp, 'the file could not be opened as a stream');
			}
			return ExtractionService::extractStream($stream, $extension, $this->config->getContentBytes());
		} catch (\Throwable $t) {
			return new ExtractionResult(null, ExtractionCause::ParserGaveUp, 'the file could not be extracted from its stream: ' . $t->getMessage());
		} finally {
			if (is_resource($stream)) {
				fclose($stream);
			}
		}
	}
}
