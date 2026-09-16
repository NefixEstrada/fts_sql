<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Unit\Backends;

use OCA\FtsSql\Backends\Mysql;
use OCA\FtsSql\Model\SearchQuery;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The MySQL/MariaDB strategy against DESIGN.md's normative strings, and the
 * information_schema catalogue checks that stand in for the IF NOT EXISTS
 * its FULLTEXT DDL lacks.
 */
class MysqlTest extends TestCase {
	private IDBConnection&MockObject $db;
	private Mysql $backend;

	protected function setUp(): void {
		self::stubDoctrineConstants();
		$this->db = $this->createMock(IDBConnection::class);
		$this->backend = new Mysql($this->db);
	}

	public function testName(): void {
		$this->assertSame('mysql', $this->backend->name());
	}

	public function testIsUsable(): void {
		$this->assertTrue($this->backend->isUsable());
	}

	public function testNormaliseTextFoldsAccentsAndLowercases(): void {
		// The _norm columns hold this form: an InnoDB FULLTEXT index over
		// utf8mb4_bin folds neither accents nor case on the query side.
		$this->assertSame(
			'escola/sortida al museu de ciencies.txt',
			$this->backend->normaliseText('Escola/Sortida al Museu de Ciències.txt'),
		);
	}

	public function testContentExpression(): void {
		$this->assertSame(
			'title_norm = :title, content_norm = :content',
			$this->backend->contentExpression(),
		);
	}

	public function testArtefactStatementsAsksTheCatalogueAndStaysQuietOverAnExistingArtefact(): void {
		$asked = [];
		$this->db->expects($this->exactly(3))
			->method('executeQuery')
			->willReturnCallback(function (string $sql) use (&$asked): IResult {
				$asked[] = $sql;
				return $this->resultGiving(1);
			});

		$this->assertSame([], $this->backend->artefactStatements());
		$this->assertSame([
			"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_NAME = '*PREFIX*fts_sql_documents' AND COLUMN_NAME = 'title_norm'",
			"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_NAME = '*PREFIX*fts_sql_documents' AND COLUMN_NAME = 'content_norm'",
			"SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_NAME = '*PREFIX*fts_sql_documents' AND INDEX_NAME = 'fts_sql_documents_fulltext'",
		], $asked);
	}

	public function testArtefactStatementsEmitsEveryMissingPieceOnAFreshInstall(): void {
		$this->db->method('executeQuery')->willReturnCallback(fn (string $sql): IResult => $this->resultGiving(0));

		$this->assertSame([
			'ALTER TABLE *PREFIX*fts_sql_documents ADD COLUMN title_norm LONGTEXT',
			'ALTER TABLE *PREFIX*fts_sql_documents ADD COLUMN content_norm LONGTEXT',
			'ALTER TABLE *PREFIX*fts_sql_documents ADD FULLTEXT INDEX fts_sql_documents_fulltext (title_norm, content_norm)',
		], $this->backend->artefactStatements());
	}

	public function testArtefactStatementsEmitsOnlyWhatTheCatalogueSaysIsMissing(): void {
		$catalogue = [
			"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_NAME = '*PREFIX*fts_sql_documents' AND COLUMN_NAME = 'title_norm'" => 1,
			"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_NAME = '*PREFIX*fts_sql_documents' AND COLUMN_NAME = 'content_norm'" => 0,
			"SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_NAME = '*PREFIX*fts_sql_documents' AND INDEX_NAME = 'fts_sql_documents_fulltext'" => 0,
		];
		$this->db->method('executeQuery')->willReturnCallback(
			fn (string $sql): IResult => $this->resultGiving($catalogue[$sql] ?? 0),
		);

		$this->assertSame([
			'ALTER TABLE *PREFIX*fts_sql_documents ADD COLUMN content_norm LONGTEXT',
			'ALTER TABLE *PREFIX*fts_sql_documents ADD FULLTEXT INDEX fts_sql_documents_fulltext (title_norm, content_norm)',
		], $this->backend->artefactStatements());
	}

	public function testHasUnindexedDocumentsReadsTrueAndFalse(): void {
		$this->db->expects($this->exactly(2))
			->method('executeQuery')
			->with('SELECT EXISTS (SELECT 1 FROM *PREFIX*fts_sql_documents WHERE title_norm IS NULL)')
			->willReturnOnConsecutiveCalls(
				$this->resultGiving(1),
				$this->resultGiving(0),
			);

		$this->assertTrue($this->backend->hasUnindexedDocuments());
		$this->assertFalse($this->backend->hasUnindexedDocuments());
	}

	public function testWorkedExampleFromTheDesign(): void {
		$match = $this->backend->matchExpression(
			SearchQuery::parse('+museu "sortida escolar" -pis mun'),
			'catalan',
		);

		$this->assertSame('MATCH(title_norm, content_norm) AGAINST(:query IN BOOLEAN MODE)', $match->predicate);
		$this->assertSame('MATCH(title_norm, content_norm) AGAINST(:query IN BOOLEAN MODE)', $match->rank);
		$this->assertSame('', $match->join);
		$this->assertSame(
			['query' => '+museu "sortida escolar" -pis mun*'],
			$match->parameters,
		);
	}

	public function testAQueryOfNothingButExclusionsStillComposes(): void {
		$match = $this->backend->matchExpression(SearchQuery::parse('-a -b'), 'simple');

		$this->assertSame('-a -b', $match->parameters['query']);
	}

	public function testBooleanModeOperatorsAreStrippedBeforeThisAppsOwnAreAttached(): void {
		// A malformed boolean query is ERROR 1064, the same code as broken
		// SQL, so nothing the user typed survives as syntax.
		$match = $this->backend->matchExpression(SearchQuery::parse('(a+b)*c @distància -"sort~ida es*colar"'), 'simple');

		$this->assertSame('abc distancia* -"sortida escolar"', $match->parameters['query']);
	}

	public function testARequiredTermSanitisesUnderItsOwnOperator(): void {
		$match = $this->backend->matchExpression(SearchQuery::parse('+(a+b)*c'), 'simple');

		$this->assertSame('+abc', $match->parameters['query']);
	}

	public function testAPhraseNeverTakesThePrefixOperator(): void {
		$match = $this->backend->matchExpression(SearchQuery::parse('"sortida escolar" mun'), 'simple');

		$this->assertSame('"sortida escolar" mun*', $match->parameters['query']);
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
