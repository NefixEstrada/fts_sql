<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Model;

use OCA\FtsSql\Extraction\ExtractionCause;

/**
 * What one document becomes after mapping, all pure (DESIGN.md, "Code
 * organisation"): the portable columns of fts_sql_documents, the access
 * tokens and tags that travel in their own tables, and how the content
 * extraction went — INDEX_CONTENT is unset on the IIndex unless
 * contentExtracted is true, and contentError lands on the document through
 * addError() with its severity. The cause is the countable half of the same
 * outcome: it is what the admin card counts per flag, null when extraction
 * completed (a provider bug is a severity, not a cause).
 */
final readonly class IndexRow {
	/**
	 * @param list<string> $tokens the access tokens, from DocumentAccess
	 * @param list<array{kind: string, value: string}> $tags kind 'meta' carries the source checkboxes
	 */
	public function __construct(
		public string $providerId,
		public string $documentId,
		public ?string $owner,
		public ?string $title,
		public ?string $content,
		public ?string $link,
		public ?string $source,
		public int $modified,
		public ?string $hash,
		/** @var list<string> */
		public array $tokens,
		/** @var list<array{kind: string, value: string}> */
		public array $tags,
		public bool $contentExtracted,
		public ?string $contentError,
		public ?ExtractionCause $cause = null,
		public int $contentErrorSeverity = 0,
	) {
	}
}
