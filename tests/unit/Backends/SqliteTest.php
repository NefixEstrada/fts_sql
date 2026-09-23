<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Unit\Backends;

use OCA\FtsSql\Backends\Sqlite;
use OCA\FtsSql\Model\SearchQuery;
use OCP\DB\Exception;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The SQLite strategy against DESIGN.md's normative strings: the FTS5 probe,
 * the external-content artefact with its triggers and closing rebuild, and a
 * query where every token is a string literal.
 */
class SqliteTest extends TestCase {
	private IDBConnection&MockObject $db;
	private Sqlite $backend;

	protected function setUp(): void {
		self::stubDoctrineConstants();
		$this->db = $this->createMock(IDBConnection::class);
		$this->backend = new Sqlite($this->db);
	}

	public function testName(): void {
		$this->assertSame('sqlite', $this->backend->name());
	}

	public function testTextSearchConfigurationsOfferOnlySimple(): void {
		// The tokeniser is the configuration here: one name, and it says so.
		$this->assertSame(['simple'], $this->backend->textSearchConfigurations());
	}

	public function testIsUsableProbesFts5ThroughTheConnection(): void {
		$asked = [];
		$this->db->expects($this->exactly(2))
			->method('executeStatement')
			->willReturnCallback(function (string $sql) use (&$asked): int {
				$asked[] = $sql;
				return 0;
			});

		$this->assertTrue($this->backend->isUsable());
		$this->assertSame([
			'CREATE VIRTUAL TABLE temp.fts_sql_probe USING fts5(x)',
			'DROP TABLE temp.fts_sql_probe',
		], $asked);
	}

	public function testIsUsableIsFalseWithoutFts5(): void {
		$this->db->method('executeStatement')->willThrowException(new Exception());

		$this->assertFalse($this->backend->isUsable());
	}

	public function testNormaliseTextIsTheIdentity(): void {
		// The tokeniser folds: unicode61 remove_diacritics 2.
		$this->assertSame(
			'Escola/Sortida al Museu de Ciències.txt',
			$this->backend->normaliseText('Escola/Sortida al Museu de Ciències.txt'),
		);
	}

	public function testContentExpressionIsEmpty(): void {
		$this->assertSame('', $this->backend->contentExpression());
	}

	public function testArtefactStatements(): void {
		$this->assertSame([
			'CREATE VIRTUAL TABLE IF NOT EXISTS *PREFIX*fts_sql_fts USING fts5(title, content, content=\'*PREFIX*fts_sql_documents\', content_rowid=\'id\', tokenize="unicode61 remove_diacritics 2")',
			'CREATE TRIGGER IF NOT EXISTS *PREFIX*fts_sql_ai AFTER INSERT ON *PREFIX*fts_sql_documents BEGIN INSERT INTO *PREFIX*fts_sql_fts(rowid, title, content) VALUES (new.id, new.title, new.content); END',
			'CREATE TRIGGER IF NOT EXISTS *PREFIX*fts_sql_ad AFTER DELETE ON *PREFIX*fts_sql_documents BEGIN INSERT INTO *PREFIX*fts_sql_fts(*PREFIX*fts_sql_fts, rowid, title, content) VALUES (\'delete\', old.id, old.title, old.content); END',
			'CREATE TRIGGER IF NOT EXISTS *PREFIX*fts_sql_au AFTER UPDATE ON *PREFIX*fts_sql_documents BEGIN INSERT INTO *PREFIX*fts_sql_fts(*PREFIX*fts_sql_fts, rowid, title, content) VALUES (\'delete\', old.id, old.title, old.content); INSERT INTO *PREFIX*fts_sql_fts(rowid, title, content) VALUES (new.id, new.title, new.content); END',
			'INSERT INTO *PREFIX*fts_sql_fts(*PREFIX*fts_sql_fts) VALUES (\'rebuild\')',
		], $this->backend->artefactStatements());
	}

	public function testHasUnindexedDocumentsIsAlwaysFalseWithoutTouchingTheDatabase(): void {
		// The closing rebuild refills the index from the documents table
		// (DESIGN.md, Scenario 3): there is nothing to detect.
		$this->db->expects($this->never())->method('executeQuery');
		$this->db->expects($this->never())->method('executeStatement');

		$this->assertFalse($this->backend->hasUnindexedDocuments());
	}

	public function testWorkedExampleFromTheDesign(): void {
		$match = $this->backend->matchExpression(
			SearchQuery::parse('+museu "sortida escolar" -pis mun'),
			'catalan',
		);

		$this->assertSame('*PREFIX*fts_sql_fts MATCH :query', $match->predicate);
		$this->assertSame('-bm25(*PREFIX*fts_sql_fts)', $match->rank);
		$this->assertSame('JOIN *PREFIX*fts_sql_fts ON *PREFIX*fts_sql_fts.rowid = d.id', $match->join);
		$this->assertSame(
			['query' => '("sortida escolar" OR "mun"*) AND "museu" NOT "pis"'],
			$match->parameters,
		);
	}

	public function testAQueryOfNothingButExclusionsSubtractsFromTheEmptyLiteral(): void {
		$match = $this->backend->matchExpression(SearchQuery::parse('-a -b'), 'simple');

		$this->assertSame('"" NOT "a" NOT "b"', $match->parameters['query']);
	}

	public function testRequiredTermsJoinWithAndWithoutAGroup(): void {
		$match = $this->backend->matchExpression(SearchQuery::parse('+museu -pis'), 'simple');

		$this->assertSame('"museu" NOT "pis"', $match->parameters['query']);
	}

	public function testInnerDoubleQuotesAreDoubled(): void {
		$match = $this->backend->matchExpression(SearchQuery::parse('foo"bar'), 'simple');

		$this->assertSame('("foo""bar"*)', $match->parameters['query']);
	}

	public function testSyntaxLookingTokensAreJustStrings(): void {
		// `C++`, `report.pdf` or `AND` being read as syntax is what the
		// quoting stops; AND is the prefix candidate here and still quotes.
		$match = $this->backend->matchExpression(SearchQuery::parse('C++ AND report.pdf'), 'simple');

		$this->assertSame('("C++" OR "AND" OR "report.pdf"*)', $match->parameters['query']);
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
