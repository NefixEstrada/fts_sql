<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Service;

use OCA\FtsSql\Backends\IBackend;
use OCA\FtsSql\Exceptions\UnsupportedCapability;
use OCA\FtsSql\Model\CompiledSearch;
use OCA\FtsSql\Model\DocumentAccess;
use OCA\FtsSql\Model\SearchQuery;
use OCP\FullTextSearch\Model\IDocumentAccess;
use OCP\FullTextSearch\Model\ISearchRequest;

/**
 * ISearchRequest plus IDocumentAccess to CompiledSearch, pure (DESIGN.md,
 * "Code organisation"): one statement shell every engine shares, with the
 * strategy supplying only the predicate, the rank, any join and their
 * parameters ("Worked example: one search on each engine"). The count
 * statement runs over the identical FROM ... WHERE, so it can never page
 * over a set the page query does not see, and the ORDER BY is always
 * score DESC, id ASC: with LIMIT/OFFSET an unstable sort puts a document
 * on two pages or on neither.
 */
final class SearchMappingService {
	private const ACCESS_EXISTS = 'EXISTS (SELECT 1 FROM *PREFIX*fts_sql_access a WHERE a.doc_id = d.id AND a.token IN (:tokens))';
	private const TAG_EXISTS = "EXISTS (SELECT 1 FROM *PREFIX*fts_sql_tags t WHERE t.doc_id = d.id AND t.kind = 'meta' AND t.value IN (:metatags))";

	/**
	 * How deep any search may page: beyond it the engine would burn rows
	 * walking the OFFSET for a page no user reaches — the UI inventories at
	 * most a handful — and a hand-built API request could ask for millions.
	 */
	private const MAX_OFFSET = 100_000;

	public static function compile(
		string $providerId,
		ISearchRequest $request,
		IDocumentAccess $access,
		IBackend $backend,
		string $language,
	): CompiledSearch {
		// The narrowing capabilities first: ignoring any of them would
		// return documents the user excluded, so the search is refused
		// ("What the search honours, by direction"). The widening ones
		// (parts, wildcard fields, fields) are not read here at all — the
		// platform adapter logs them and skips them, and reading them would
		// make this pure class log or refuse where the design says neither.
		self::refuseNarrowing($request);

		$tokens = DocumentAccess::tokens($access);
		$metaTags = self::metaTags($request);

		// The unified search's Date chip arrives as options — the one
		// user-visible filter the platform used to ignore, a widening by
		// omission a user could see in the results (the UI inventory,
		// DESIGN.md's Resolved issues). It narrows over the stored
		// `modified`, a portable column on every engine, so it belongs to
		// the shared shell rather than any strategy.
		$since = self::timestampOption($request, 'since');
		$until = self::timestampOption($request, 'until');

		// The empty search box never reaches a strategy: it compiles to a
		// browse of what the viewer may see — no predicate, 0 as rank, no
		// join — ordered by id.
		$query = SearchQuery::parse($request->getSearch());
		$match = $query->isEmpty() ? null : $backend->matchExpression($query, $language);

		$from = 'FROM *PREFIX*fts_sql_documents d';
		if ($match !== null && $match->join !== '') {
			$from .= ' ' . $match->join;
		}

		$where = "WHERE d.provider_id = :provider\n"
			. '  AND ' . self::ACCESS_EXISTS;
		if ($match !== null) {
			$where .= "\n  AND " . $match->predicate;
		}
		if ($metaTags !== []) {
			$where .= "\n  AND " . self::TAG_EXISTS;
		}
		if ($since !== null) {
			$where .= "\n  AND d.modified >= :since";
		}
		if ($until !== null) {
			$where .= "\n  AND d.modified <= :until";
		}

		$rank = $match?->rank ?? '0';

		// The framework paginates 1-based: page 2 at 20 per page is limit
		// 20, offset 20. The clamps keep a malformed request from compiling
		// a negative OFFSET or an empty page. A page past the ceiling
		// answers empty — the always-false predicate rides both statements,
		// so a pager that walks by "fewer rows than asked, or a total that
		// ran out" terminates instead of looping on a clamped repeat.
		$limit = max(1, $request->getSize());
		$offset = max(0, ($request->getPage() - 1) * $limit);
		if ($offset > self::MAX_OFFSET) {
			$where .= "\n  AND 1 = 0";
		}

		$pageSql = 'SELECT d.id, d.document_id, d.title, d.content, d.link, ' . $rank . " AS score\n"
			. $from . "\n" . $where . "\n"
			. "ORDER BY score DESC, id ASC\n"
			. 'LIMIT :limit OFFSET :offset';

		$countSql = "SELECT COUNT(d.id)\n" . $from . "\n" . $where;

		$parameters = [
			'provider' => $providerId,
			'tokens' => $tokens,
		];
		$arrayParams = ['tokens'];
		if ($metaTags !== []) {
			$parameters['metatags'] = $metaTags;
			$arrayParams[] = 'metatags';
		}
		if ($match !== null) {
			$parameters = array_merge($parameters, $match->parameters);
		}
		if ($since !== null) {
			$parameters['since'] = $since;
		}
		if ($until !== null) {
			$parameters['until'] = $until;
		}
		$parameters['limit'] = $limit;
		$parameters['offset'] = $offset;

		return new CompiledSearch($pageSql, $countSql, $parameters, $arrayParams);
	}

	/**
	 * @throws UnsupportedCapability naming what cannot be served
	 */
	private static function refuseNarrowing(ISearchRequest $request): void {
		if ($request->getRegexFilters() !== []) {
			throw new UnsupportedCapability(
				'regex filters narrow the search and no engine here serves them; ignoring them would return documents the user excluded',
			);
		}
		if ($request->getWildcardFilters() !== []) {
			throw new UnsupportedCapability(
				'wildcard filters narrow the search and no engine here serves them; ignoring them would return documents the user excluded',
			);
		}
		if ($request->getSubTags(false) !== []) {
			throw new UnsupportedCapability(
				'sub tags narrow the search and no engine here serves them; ignoring them would return documents the user excluded',
			);
		}
		if ($request->getSimpleQueries() !== []) {
			throw new UnsupportedCapability(
				'simple queries narrow the search and no engine here serves them; ignoring them would return documents the user excluded',
			);
		}

		// Limit fields narrow too: honoured when they name both title and
		// content — a no-op, both are always matched — and a proper subset
		// refuses, because MySQL answers MATCH(title) against the composite
		// index with ERROR 1191.
		$limitFields = $request->getLimitFields();
		if ($limitFields === []) {
			return;
		}
		if (!in_array('title', $limitFields, true) || !in_array('content', $limitFields, true)) {
			throw new UnsupportedCapability(
				'limit fields are honoured only when they name both title and content; refusing over: '
				. implode(', ', array_map(static fn (mixed $field): string => (string)$field, $limitFields)),
			);
		}
	}

	/**
	 * @return list<string>
	 */
	private static function metaTags(ISearchRequest $request): array {
		$tags = [];
		foreach ($request->getMetaTags() as $tag) {
			if (is_string($tag)) {
				$tags[] = $tag;
			}
		}
		return $tags;
	}

	/**
	 * The option as a unix timestamp, or null when it is absent or not a
	 * number: a non-numeric option keeps today's behaviour — ignored —
	 * rather than refusing a search over metadata the request's own sender
	 * got wrong.
	 */
	private static function timestampOption(ISearchRequest $request, string $key): ?int {
		$value = $request->getOption($key, '');
		return is_numeric($value) ? (int)$value : null;
	}
}
