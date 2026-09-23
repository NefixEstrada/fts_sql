<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Integration\Platform;

use OC\FullTextSearch\Model\DocumentAccess;
use OC\FullTextSearch\Model\IndexDocument;
use OCA\FtsSql\Platform\SqlPlatform;
use OCA\FullTextSearch\Model\Index;
use OCA\FullTextSearch\Model\SearchRequest;
use OCA\FullTextSearch\Model\SearchResult;
use OCA\FullTextSearch\Provider\TestProvider;
use OCP\FullTextSearch\Model\IIndex;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\FullTextSearch\Model\ISearchResult;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;
use Test\TestCase;

/**
 * The interleaved harness of DESIGN.md's open issue "InnoDB FULLTEXT
 * visibility under document-at-a-time writes": the benchmark caught InnoDB
 * serving stale results after a bulk load, and whether the same happens under
 * the platform's one-document-per-write path is what decides if the MySQL
 * write path needs anything beyond the replace it already does.
 *
 * Twenty-five rounds, each one a fresh case — a loop repeating one case runs
 * clean and proves nothing, the same lesson that made the benchmark
 * interleave. Every round indexes a document and immediately searches for
 * it; every fifth round re-indexes an older document under new content (the
 * write path is a replace: delete + insert), and every seventh round deletes
 * one. Staleness in either direction fails the round that caused it: a term
 * that must be visible answers through the same statement shape any search
 * uses, and a term that must be gone has to answer nothing at once, not
 * eventually.
 *
 * The terms are fixed-width on purpose: the last optional term of a query
 * takes a prefix match on every engine, and at one width no term is a
 * proper prefix of another, so a prefix search can only ever find its own
 * document.
 *
 * The question is InnoDB's, but the property — what the write path leaves
 * behind is what the next search sees — holds on every engine, so this runs
 * wherever the integration tier runs, exactly like the CI matrix does.
 */
#[Group('DB')]
class InterleavedIndexSearchTest extends TestCase {
	private const ROUNDS = 25;

	private SqlPlatform $platform;
	private TestProvider $provider;

	protected function setUp(): void {
		parent::setUp();
		$this->platform = Server::get(SqlPlatform::class);
		$this->provider = Server::get(TestProvider::class);
		$this->platform->resetIndex('all');
	}

	protected function tearDown(): void {
		$this->platform->resetIndex('all');
		parent::tearDown();
	}

	public function testTwentyFiveInterleavedRoundsOfIndexThenSearch(): void {
		$owner = new DocumentAccess('anna');
		$owner->addGroup('professorat');

		// document id => the distinctive term its current content carries;
		// insertion order makes array_key_first() the oldest live document.
		$live = [];
		// Terms no live document carries any more: replaced-away and deleted.
		$gone = [];

		for ($round = 1; $round <= self::ROUNDS; $round++) {
			$id = 'vis-' . $round;
			$term = self::term('qjvis', $round);

			$index = $this->platform->indexDocument($this->document($id, $round, $term, $owner));
			$this->assertTrue($index->isStatus(IIndex::INDEX_DONE), "round $round: the fresh document must index");

			// The round's own search, in the same round: this is the sentence
			// pair the staleness would separate.
			$this->assertFindsOne($term, $id, "round $round: a document indexed in this round must be findable in this round");

			$live[$id] = $term;

			// The previous round's document, still live: mutations below only
			// ever take the OLDEST live document, so this needs no exception.
			if ($round > 1) {
				$this->assertFindsOne(
					self::term('qjvis', $round - 1),
					'vis-' . ($round - 1),
					"round $round: the previous round's document must still be findable",
				);
			}

			// A replace of a document the index already holds: same
			// (provider, document) pair, new content. The common case of a
			// reindex and of an ACL change, and the shape that makes a stale
			// index show old text.
			if ($round % 5 === 0 && count($live) > 1) {
				$target = (string)array_key_first($live);
				$oldTerm = $live[$target];
				$newTerm = self::term('qjrep', $round);

				$index = $this->platform->indexDocument($this->document($target, $round, $newTerm, $owner));
				$this->assertTrue($index->isStatus(IIndex::INDEX_DONE), "round $round: the replaced document must re-index");

				$live[$target] = $newTerm;
				$gone[] = $oldTerm;

				$this->assertSame(0, $this->search($oldTerm)->getTotal(), "round $round: the term replaced away must be gone at once, not eventually");
				$this->assertFindsOne($newTerm, $target, "round $round: the replaced document must be findable by its new term at once");
			}

			// A delete, through the same entry point the framework calls when
			// a file goes away: the row and its artefact entry leave together,
			// and the next search has to agree.
			if ($round % 7 === 0 && count($live) > 1) {
				$target = (string)array_key_first($live);
				$deadTerm = $live[$target];
				unset($live[$target]);
				$gone[] = $deadTerm;

				$this->platform->deleteIndexes([new Index('test_provider', $target)]);

				$this->assertSame(0, $this->search($deadTerm)->getTotal(), "round $round: a deleted document must stop being findable at once, not eventually");
			}
		}

		// 25 indexed; the deletes land on rounds 7, 14 and 21, and a replace
		// never removes a document: 22 live. The gone terms are the 5 the
		// replaces retired (rounds 5, 10, 15, 20, 25) plus the 3 the deletes
		// carried away — which, with the deletes taking the oldest live
		// document, are terms an earlier replace had already put there.
		$this->assertCount(22, $live, 'the rounds must leave 22 live documents');
		$this->assertCount(8, $gone, 'the rounds must leave 8 terms no document carries');

		// The final sweep: after all the interleaving, every live term finds
		// exactly its document and every gone term finds nothing — late
		// staleness is staleness too.
		foreach ($live as $id => $term) {
			$this->assertFindsOne($term, (string)$id, 'final sweep: every live document');
		}
		foreach ($gone as $term) {
			$this->assertSame(0, $this->search($term)->getTotal(), "final sweep: $term must stay unfindable");
		}
	}

	/**
	 * qjvis007, qjrep015, …: a fixed-width, per-round-distinctive term. Eight
	 * characters clears innodb_ft_min_token_size (3) and every stopword list,
	 * and the width is what keeps one term from being a prefix of another.
	 */
	private static function term(string $stem, int $round): string {
		return $stem . str_pad((string)$round, 3, '0', STR_PAD_LEFT);
	}

	private function assertFindsOne(string $term, string $documentId, string $message): void {
		$result = $this->search($term);
		$this->assertSame(1, $result->getTotal(), "$message: $term must find exactly one document");
		$documents = $result->getDocuments();
		$this->assertSame($documentId, $documents[0]->getId(), "$message: $term must find $documentId");
	}

	private function document(string $id, int $round, string $term, DocumentAccess $access): IIndexDocument {
		$document = new IndexDocument('test_provider', $id);
		$document->setIndex(new Index('test_provider', $id));
		$document->setAccess($access);
		$document->setTitle("Escola/Sortida al Museu de Ciències $id.txt");
		$document->setContent(
			base64_encode("la sortida de la ronda $round al museu de ciencies naturals duu el codi $term"),
			IIndexDocument::ENCODED_BASE64,
		);
		$document->setModifiedTime(time());
		return $document;
	}

	private function search(string $terms): ISearchResult {
		$viewer = new DocumentAccess();
		$viewer->setViewerId('anna');
		$viewer->addGroup('professorat');

		$request = new SearchRequest();
		$request->setSearch($terms);
		$result = new SearchResult($request);
		$result->setProvider($this->provider);
		$this->platform->searchRequest($result, $viewer);
		return $result;
	}
}
