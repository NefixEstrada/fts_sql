<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Model;

/**
 * What one document becomes after mapping, all pure (DESIGN.md, "Code
 * organisation"): the portable columns of fts_sql_documents, the access
 * tokens and tags that travel in their own tables, and how the content
 * extraction went — INDEX_CONTENT is unset on the IIndex unless
 * contentExtracted is true, and contentError lands on the document through
 * addError() with its severity.
 */
final class IndexRow {
	/**
	 * @param list<string> $tokens the access tokens, from DocumentAccess
	 * @param list<array{kind: string, value: string}> $tags kind 'meta' carries the source checkboxes
	 */
	public function __construct(
		public readonly string $providerId,
		public readonly string $documentId,
		public readonly ?string $owner,
		public readonly ?string $title,
		public readonly ?string $content,
		public readonly ?string $link,
		public readonly ?string $source,
		public readonly int $modified,
		public readonly ?string $hash,
		/** @var list<string> */
		public readonly array $tokens,
		/** @var list<array{kind: string, value: string}> */
		public readonly array $tags,
		public readonly bool $contentExtracted,
		public readonly ?string $contentError,
		public readonly int $contentErrorSeverity = 0,
	) {
	}
}
