<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Service;

use OCA\FtsSql\Model\DocumentAccess;
use OCA\FtsSql\Model\IndexRow;
use OCP\FullTextSearch\Model\IIndex;
use OCP\FullTextSearch\Model\IIndexDocument;

/**
 * IIndexDocument to IndexRow, pure (DESIGN.md, "Code organisation" and
 * "Worked example: indexing one document"): decode, extract plain text
 * within the budget, map the columns, the access tokens and the tags.
 * Nothing here reads or writes a database, and the outcome of the content
 * extraction travels in the row for the caller to put on the IIndex —
 * INDEX_CONTENT unset and addError() at the row's severity unless
 * contentExtracted is true.
 */
final class IndexMappingService {
	public static function map(IIndexDocument $document, int $budget): IndexRow {
		// First, because an access with no identity refuses the whole
		// document: a document nobody can find is either useless or a
		// permission bug that would otherwise pass silently.
		$access = $document->getAccess();
		$tokens = DocumentAccess::tokens($access);

		$owner = $access->getOwnerId();
		$hash = $document->getHash();

		[$contentExtracted, $content, $contentError, $severity] = self::extractContent($document, $budget);

		return new IndexRow(
			providerId: $document->getProviderId(),
			documentId: $document->getId(),
			owner: $owner !== '' ? $owner : null,
			title: $document->getTitle(),
			content: $content,
			link: $document->getLink(),
			source: $document->getSource(),
			modified: $document->getModifiedTime(),
			hash: $hash !== '' ? $hash : null,
			tokens: $tokens,
			tags: self::tags($document),
			contentExtracted: $contentExtracted,
			contentError: $contentError,
			contentErrorSeverity: $severity,
		);
	}

	/**
	 * Mapping steps 1 and 2 of the worked example: decode, then extract.
	 * Either step failing still yields a full row — indexed on title,
	 * access and tags, without content — with the error and its severity;
	 * the row is never refused over its content.
	 *
	 * @return array{bool, ?string, ?string, int} extracted?, content, error, severity
	 */
	private static function extractContent(IIndexDocument $document, int $budget): array {
		if ($document->isContentEncoded() === IIndexDocument::ENCODED_BASE64) {
			$bytes = base64_decode($document->getContent(), true);
			if ($bytes === false) {
				return [
					false,
					null,
					'the provider flagged the content as base64 but it does not decode — a transport bug on the provider\'s side, not this app\'s',
					IIndex::ERROR_SEV_3,
				];
			}
		} else {
			$bytes = $document->getContent();
		}

		// The files provider sets the title to the path, which is where the
		// extension is read from.
		$extension = pathinfo($document->getTitle(), PATHINFO_EXTENSION);
		$content = ExtractionService::extract($bytes, $extension, $budget);
		if ($content === null) {
			return [
				false,
				null,
				sprintf('the "%s" format is not extracted in this milestone: indexed on title, access and tags only', $extension),
				IIndex::ERROR_SEV_1,
			];
		}

		return [true, $content, null, 0];
	}

	/**
	 * @return list<array{kind: string, value: string}>
	 */
	private static function tags(IIndexDocument $document): array {
		$tags = [];
		foreach ($document->getTags() as $tag) {
			$tags[] = ['kind' => 'meta', 'value' => (string)$tag];
		}
		return $tags;
	}
}
