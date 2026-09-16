<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Unit\Service;

use OCA\FtsSql\Backends\IBackend;
use OCA\FtsSql\Exceptions\AccessIsEmpty;
use OCA\FtsSql\Exceptions\UnsupportedCapability;
use OCA\FtsSql\Model\CompiledMatch;
use OCA\FtsSql\Model\SearchQuery;
use OCA\FtsSql\Service\SearchMappingService;
use OCP\FullTextSearch\Model\IDocumentAccess;
use OCP\FullTextSearch\Model\ISearchRequest;
use OCP\FullTextSearch\Model\ISearchRequestSimpleQuery;
use PHPUnit\Framework\TestCase;

/**
 * SearchMappingService::compile against DESIGN.md's "Worked example: one
 * search on each engine": the exact page and count statements every engine
 * shares, with the strategy stubbed to a fixed CompiledMatch, plus the
 * direction table — the narrowing capabilities refuse, the widening ones
 * are not even read.
 */
class SearchMappingServiceTest extends TestCase {

	public function testCompilesTheWorkedExampleOnPostgresqlExactly(): void {
		$backend = self::createMock(IBackend::class);
		$backend->expects(self::once())
			->method('matchExpression')
			->with(
				self::callback(static fn (SearchQuery $query): bool => count($query->getTerms()) === 4),
				'catalan',
			)
			->willReturn(new CompiledMatch(
				'content_tsv @@ to_tsquery(:cfg::regconfig, :query)',
				'ts_rank_cd(content_tsv, to_tsquery(:cfg::regconfig, :query))',
				['cfg' => 'catalan', 'query' => '(sortida <-> escolar | mun:*) & museu & !(pis)'],
			));

		$request = self::request('+museu "sortida escolar" -pis mun');
		// The widening capabilities are the platform adapter's to log and
		// skip: the compiler never reads them.
		$request->expects(self::never())->method('getParts');
		$request->expects(self::never())->method('getWildcardFields');
		$request->expects(self::never())->method('getFields');

		$search = SearchMappingService::compile(
			'files',
			$request,
			self::access(viewer: 'carla', groups: ['professorat']),
			$backend,
			'catalan',
		);

		$this->assertSame(
			'SELECT d.id, d.document_id, d.title, d.content, d.link, '
			. 'ts_rank_cd(content_tsv, to_tsquery(:cfg::regconfig, :query)) AS score' . "\n"
			. 'FROM *PREFIX*fts_sql_documents d' . "\n"
			. 'WHERE d.provider_id = :provider' . "\n"
			. '  AND EXISTS (SELECT 1 FROM *PREFIX*fts_sql_access a WHERE a.doc_id = d.id AND a.token IN (:tokens))' . "\n"
			. '  AND content_tsv @@ to_tsquery(:cfg::regconfig, :query)' . "\n"
			. 'ORDER BY score DESC, id ASC' . "\n"
			. 'LIMIT :limit OFFSET :offset',
			$search->pageSql,
		);

		$this->assertSame(
			'SELECT COUNT(d.id)' . "\n"
			. 'FROM *PREFIX*fts_sql_documents d' . "\n"
			. 'WHERE d.provider_id = :provider' . "\n"
			. '  AND EXISTS (SELECT 1 FROM *PREFIX*fts_sql_access a WHERE a.doc_id = d.id AND a.token IN (:tokens))' . "\n"
			. '  AND content_tsv @@ to_tsquery(:cfg::regconfig, :query)',
			$search->countSql,
		);

		$this->assertSame(
			[
				'provider' => 'files',
				'tokens' => ['o:carla', 'u:carla', 'g:professorat'],
				'cfg' => 'catalan',
				'query' => '(sortida <-> escolar | mun:*) & museu & !(pis)',
				'limit' => 20,
				'offset' => 20,
			],
			$search->parameters,
		);
		$this->assertSame(['tokens'], $search->arrayParams);
	}

	public function testAnEmptySearchCompilesABrowseOfWhatTheViewerMaySee(): void {
		$backend = self::createMock(IBackend::class);
		$backend->expects(self::never())->method('matchExpression');

		$search = SearchMappingService::compile(
			'files',
			self::request(''),
			self::access(viewer: 'carla', groups: ['professorat']),
			$backend,
			'catalan',
		);

		$this->assertSame(
			'SELECT d.id, d.document_id, d.title, d.content, d.link, 0 AS score' . "\n"
			. 'FROM *PREFIX*fts_sql_documents d' . "\n"
			. 'WHERE d.provider_id = :provider' . "\n"
			. '  AND EXISTS (SELECT 1 FROM *PREFIX*fts_sql_access a WHERE a.doc_id = d.id AND a.token IN (:tokens))' . "\n"
			. 'ORDER BY score DESC, id ASC' . "\n"
			. 'LIMIT :limit OFFSET :offset',
			$search->pageSql,
		);

		$this->assertSame(
			'SELECT COUNT(d.id)' . "\n"
			. 'FROM *PREFIX*fts_sql_documents d' . "\n"
			. 'WHERE d.provider_id = :provider' . "\n"
			. '  AND EXISTS (SELECT 1 FROM *PREFIX*fts_sql_access a WHERE a.doc_id = d.id AND a.token IN (:tokens))',
			$search->countSql,
		);

		$this->assertSame(
			[
				'provider' => 'files',
				'tokens' => ['o:carla', 'u:carla', 'g:professorat'],
				'limit' => 20,
				'offset' => 20,
			],
			$search->parameters,
		);
		$this->assertSame(['tokens'], $search->arrayParams);
	}

	public function testTheEngineJoinIsAppendedToTheFromClause(): void {
		$backend = self::createMock(IBackend::class);
		$backend->method('matchExpression')->willReturn(new CompiledMatch(
			'fts_sql_fts MATCH :query',
			'-bm25(fts_sql_fts)',
			['query' => '("sortida escolar" OR "mun"*) AND "museu" NOT "pis"'],
			'JOIN fts_sql_fts ON fts_sql_fts.rowid = d.id',
		));

		$search = SearchMappingService::compile(
			'files',
			self::request('museu'),
			self::access(viewer: 'carla'),
			$backend,
			'simple',
		);

		$from = 'FROM *PREFIX*fts_sql_documents d JOIN fts_sql_fts ON fts_sql_fts.rowid = d.id';
		$this->assertStringContainsString($from . "\n" . 'WHERE d.provider_id = :provider', $search->pageSql);
		$this->assertStringContainsString($from . "\n" . 'WHERE d.provider_id = :provider', $search->countSql);
		$this->assertStringContainsString(', -bm25(fts_sql_fts) AS score', $search->pageSql);
	}

	public function testMetaTagsNarrowBothStatements(): void {
		$request = self::request('museu');
		$request->method('getMetaTags')->willReturn(['files_local']);

		$search = SearchMappingService::compile(
			'files',
			$request,
			self::access(viewer: 'carla'),
			self::postgresBackend(),
			'catalan',
		);

		$clause = "  AND EXISTS (SELECT 1 FROM *PREFIX*fts_sql_tags t WHERE t.doc_id = d.id AND t.kind = 'meta' AND t.value IN (:metatags))";
		// Appended after the match predicate, as the last AND.
		$this->assertStringContainsString('AND content_tsv @@ to_tsquery(:cfg::regconfig, :query)' . "\n" . $clause, $search->pageSql);
		$this->assertStringContainsString($clause . "\n" . 'ORDER BY score DESC, id ASC', $search->pageSql);
		$this->assertStringContainsString($clause, $search->countSql);
		$this->assertSame(['files_local'], $search->parameters['metatags']);
		$this->assertSame(['tokens', 'metatags'], $search->arrayParams);
	}

	public function testRegexFiltersRefuseTheSearch(): void {
		$request = self::request('museu');
		$request->method('getRegexFilters')->willReturn([['title' => '^a']]);

		$this->expectException(UnsupportedCapability::class);
		$this->expectExceptionMessage('regex filters');

		SearchMappingService::compile('files', $request, self::access(viewer: 'carla'), self::postgresBackend(), 'catalan');
	}

	public function testWildcardFiltersRefuseTheSearch(): void {
		$request = self::request('museu');
		$request->method('getWildcardFilters')->willReturn([['title' => 'a*']]);

		$this->expectException(UnsupportedCapability::class);
		$this->expectExceptionMessage('wildcard filters');

		SearchMappingService::compile('files', $request, self::access(viewer: 'carla'), self::postgresBackend(), 'catalan');
	}

	public function testSubTagsRefuseTheSearch(): void {
		$request = self::request('museu');
		$request->method('getSubTags')->willReturn([['files' => ['tag']]]);

		$this->expectException(UnsupportedCapability::class);
		$this->expectExceptionMessage('sub tags');

		SearchMappingService::compile('files', $request, self::access(viewer: 'carla'), self::postgresBackend(), 'catalan');
	}

	public function testSimpleQueriesRefuseTheSearch(): void {
		$request = self::request('museu');
		$request->method('getSimpleQueries')->willReturn([self::createMock(ISearchRequestSimpleQuery::class)]);

		$this->expectException(UnsupportedCapability::class);
		$this->expectExceptionMessage('simple queries');

		SearchMappingService::compile('files', $request, self::access(viewer: 'carla'), self::postgresBackend(), 'catalan');
	}

	public function testLimitFieldsAsAProperSubsetRefuseTheSearch(): void {
		$request = self::request('museu');
		$request->method('getLimitFields')->willReturn(['title']);

		$this->expectException(UnsupportedCapability::class);
		$this->expectExceptionMessage('limit fields');

		SearchMappingService::compile('files', $request, self::access(viewer: 'carla'), self::postgresBackend(), 'catalan');
	}

	public function testLimitFieldsNamingBothTitleAndContentIsANoop(): void {
		$request = self::request('museu', size: 25, page: 1);
		$request->method('getLimitFields')->willReturn(['title', 'content']);

		$search = SearchMappingService::compile(
			'files',
			$request,
			self::access(viewer: 'carla'),
			self::postgresBackend(),
			'catalan',
		);

		$this->assertStringContainsString('  AND content_tsv @@ to_tsquery(:cfg::regconfig, :query)', $search->pageSql);
		$this->assertSame(25, $search->parameters['limit']);
		$this->assertSame(0, $search->parameters['offset']);
	}

	public function testAnAccessWithNoIdentityRefusesTheSearch(): void {
		$this->expectException(AccessIsEmpty::class);

		SearchMappingService::compile('files', self::request('museu'), self::access(), self::postgresBackend(), 'catalan');
	}

	/**
	 * The PostgreSQL strategy's answer from the worked example, as a fixed
	 * stub: how the predicate, rank and parameters land in the shared
	 * statement is this class's to test, not the strategy's.
	 */
	private function postgresBackend(): IBackend {
		$backend = self::createMock(IBackend::class);
		$backend->method('matchExpression')->willReturnCallback(
			static fn (SearchQuery $query, string $language): CompiledMatch => new CompiledMatch(
				'content_tsv @@ to_tsquery(:cfg::regconfig, :query)',
				'ts_rank_cd(content_tsv, to_tsquery(:cfg::regconfig, :query))',
				['cfg' => $language, 'query' => '(sortida <-> escolar | mun:*) & museu & !(pis)'],
			),
		);
		return $backend;
	}

	private function request(string $search = '', int $size = 20, int $page = 2): ISearchRequest {
		$request = self::createMock(ISearchRequest::class);
		$request->method('getSearch')->willReturn($search);
		$request->method('getSize')->willReturn($size);
		$request->method('getPage')->willReturn($page);
		return $request;
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
