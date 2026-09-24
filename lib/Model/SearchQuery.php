<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Model;

/**
 * A parsed search string, pure: whitespace-separated terms, "quoted
 * phrases" (inner whitespace collapsed), +required, -excluded — the
 * Elasticsearch platform's syntax (DESIGN.md, "Query syntax").
 *
 * Two invariants the parser owns:
 *
 * - At most 64 terms, truncated from the front: the tail carries what the
 *   user is typing and supplies the prefix candidate, and ranking every
 *   matching row against 1,024 terms measured at 159 s on the corpus where
 *   64 cost 564 ms.
 * - Invalid UTF-8 is substituted, never dropped: preg with /u matches
 *   nothing at all on an invalid byte, which would parse a non-empty query
 *   as empty and send the statement out without its match predicate,
 *   answering with every document the viewer can see (DESIGN.md, Security).
 */
final class SearchQuery {
	public const MAX_TERMS = 64;

	/**
	 * @param list<QueryTerm> $terms
	 */
	private function __construct(
		private readonly array $terms,
	) {
	}

	public static function parse(string $query): self {
		// mb_convert_encoding returns string|false only for the type system's
		// sake: UTF-8 to UTF-8 substitutes invalid sequences (with '?') and
		// never fails on a string input.
		$converted = mb_convert_encoding($query, 'UTF-8', 'UTF-8');
		if (is_string($converted)) {
			$query = $converted;
		}

		$terms = [];
		// A quoted phrase first (optionally signed), then a bare token. An
		// unclosed quote is not a phrase: the bare branch eats it literally.
		// PREG_UNMATCHED_AS_NULL is what tells the two branches apart: without
		// it, groups of an alternative the engine tried and abandoned come
		// back as '' and every bare token would read as an empty phrase.
		$matched = preg_match_all(
			'/([+-]?)"([^"]*)"|([+-]?)(\S+)/u',
			$query,
			$matches,
			PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL,
		);
		if ($matched !== false) {
			foreach ($matches as $match) {
				if (isset($match[4])) {
					$isPhrase = false;
					$value = $match[4];
				} else {
					$isPhrase = true;
					$value = preg_replace('/\s+/u', ' ', $match[2] ?? '') ?? '';
				}

				$operator = $match[1] ?? '';
				if ($operator === '') {
					$operator = $match[3] ?? '';
				}
				$occur = match ($operator) {
					'+' => Occur::Must,
					'-' => Occur::MustNot,
					default => Occur::Should,
				};

				if (trim($value) === '') {
					continue;
				}
				$terms[] = new QueryTerm($value, $isPhrase, $occur);
			}
		}

		if (count($terms) > self::MAX_TERMS) {
			$terms = array_slice($terms, -self::MAX_TERMS);
		}

		return new self($terms);
	}

	/**
	 * @return list<QueryTerm>
	 */
	public function getTerms(): array {
		return $this->terms;
	}

	public function isEmpty(): bool {
		return $this->terms === [];
	}

	/**
	 * The last unquoted optional term, which the engines give a prefix match
	 * (`:*` on PostgreSQL, `token*` on MySQL and FTS5). Quoted terms never
	 * take it: a `*` inside quotes is a literal on MySQL. Null when the
	 * query has no unquoted Should term at all.
	 */
	public function getPrefixCandidate(): ?QueryTerm {
		$candidate = null;
		foreach ($this->terms as $term) {
			if (!$term->phrase && $term->occur === Occur::Should) {
				$candidate = $term;
			}
		}
		return $candidate;
	}
}
