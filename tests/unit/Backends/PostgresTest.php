<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Unit\Backends;

use OCA\FtsSql\Backends\Postgres;
use OCA\FtsSql\Model\SearchQuery;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The PostgreSQL strategy against DESIGN.md's normative strings: the worked
 * example under "Worked example: one search on each engine", the artefact
 * DDL, and the shapes drivers answer EXISTS in.
 */
class PostgresTest extends TestCase {
	private IDBConnection&MockObject $db;
	private Postgres $backend;

	protected function setUp(): void {
		self::stubDoctrineConstants();
		$this->db = $this->createMock(IDBConnection::class);
		$this->backend = new Postgres($this->db);
	}

	public function testName(): void {
		$this->assertSame('postgres', $this->backend->name());
	}

	public function testIsUsable(): void {
		$this->assertTrue($this->backend->isUsable());
	}

	public function testNormaliseTextFoldsAccentsAndKeepsCase(): void {
		// The design's indexing example: to_tsvector lowercases itself but
		// does not fold accents, so that is all PHP does.
		$this->assertSame(
			'Escola/Sortida al Museu de Ciencies.txt',
			$this->backend->normaliseText('Escola/Sortida al Museu de Ciències.txt'),
		);
	}

	public function testContentExpression(): void {
		$this->assertSame(
			'content_tsv = setweight(to_tsvector(:cfg::regconfig, COALESCE(:title, \'\')), \'A\') || setweight(to_tsvector(:cfg::regconfig, COALESCE(:content, \'\')), \'B\')',
			$this->backend->contentExpression(),
		);
	}

	public function testArtefactStatements(): void {
		$this->assertSame([
			'ALTER TABLE *PREFIX*fts_sql_documents ADD COLUMN IF NOT EXISTS content_tsv tsvector',
			'CREATE INDEX IF NOT EXISTS fts_sql_documents_tsv ON *PREFIX*fts_sql_documents USING GIN (content_tsv) WITH (fastupdate = off)',
		], $this->backend->artefactStatements());
	}

	#[DataProvider('providesExistsValues')]
	public function testHasUnindexedDocumentsReadsEveryDriverShape(mixed $fetchOne, bool $expected): void {
		$this->db->expects($this->once())
			->method('executeQuery')
			->with('SELECT EXISTS (SELECT 1 FROM *PREFIX*fts_sql_documents WHERE content_tsv IS NULL)')
			->willReturn($this->resultGiving($fetchOne));

		$this->assertSame($expected, $this->backend->hasUnindexedDocuments());
	}

	public static function providesExistsValues(): array {
		return [
			'boolean true' => [true, true],
			'string t' => ['t', true],
			'int 1' => [1, true],
			'string 1' => ['1', true],
			'boolean false' => [false, false],
			'string f' => ['f', false],
			'int 0' => [0, false],
			'string 0' => ['0', false],
		];
	}

	public function testWorkedExampleFromTheDesign(): void {
		$match = $this->backend->matchExpression(
			SearchQuery::parse('+museu "sortida escolar" -pis mun'),
			'catalan',
		);

		$this->assertSame('content_tsv @@ to_tsquery(:cfg::regconfig, :query)', $match->predicate);
		$this->assertSame('ts_rank_cd(content_tsv, to_tsquery(:cfg::regconfig, :query))', $match->rank);
		$this->assertSame('', $match->join);
		$this->assertSame(
			['cfg' => 'catalan', 'query' => '(sortida <-> escolar | mun:*) & museu & !(pis)'],
			$match->parameters,
		);
	}

	public function testOnlyMustAndMustNotTermsLeaveNoLeadingAnd(): void {
		$match = $this->backend->matchExpression(SearchQuery::parse('+museu -pis'), 'simple');

		$this->assertSame(['cfg' => 'simple', 'query' => 'museu & !(pis)'], $match->parameters);
	}

	public function testAQueryOfNothingButExclusionsStillComposes(): void {
		$match = $this->backend->matchExpression(SearchQuery::parse('-a -b'), 'simple');

		$this->assertSame('!(a) & !(b)', $match->parameters['query']);
	}

	public function testASingleShouldTermIsStillParenthesised(): void {
		$match = $this->backend->matchExpression(SearchQuery::parse('mun'), 'simple');

		$this->assertSame('(mun:*)', $match->parameters['query']);
	}

	public function testAMustPhraseIsParenthesised(): void {
		$match = $this->backend->matchExpression(SearchQuery::parse('+"sortida escolar"'), 'simple');

		$this->assertSame('(sortida <-> escolar)', $match->parameters['query']);
	}

	public function testEngineOperatorsNeverSurviveIntoTheTsquery(): void {
		// The injection defence (DESIGN.md, Security): everything tsquery
		// would read as syntax is split on, accents fold, and what survives
		// joins with <->.
		$match = $this->backend->matchExpression(SearchQuery::parse("rat&|!(<a>'b):*c\\d"), 'simple');

		$this->assertSame('(rat <-> a <-> b <-> c <-> d:*)', $match->parameters['query']);
	}

	public function testTheQueryIsAccentFoldedToo(): void {
		$match = $this->backend->matchExpression(SearchQuery::parse('+münèu'), 'catalan');

		$this->assertSame('muneu', $match->parameters['query']);
	}

	private function resultGiving(mixed $fetchOne): IResult&MockObject {
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn($fetchOne);
		return $result;
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
