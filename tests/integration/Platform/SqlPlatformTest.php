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
 * The platform against the instance's real database and search artefact:
 * index a document through SqlPlatform::indexDocument, find it through
 * searchRequest, and prove the access filter fails closed for a viewer
 * without tokens against the document (DESIGN.md, Scenario 2). Runs on
 * every engine the integration tier covers.
 */
#[Group('DB')]
class SqlPlatformTest extends TestCase {
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

	public function testIndexesFindsAndFiltersByAccess(): void {
		$owner = new DocumentAccess('biel');
		$owner->addGroup('professorat');

		$index = $this->platform->indexDocument(
			$this->document('sortida-1', 'sortida al museu de ciències naturals', $owner),
		);
		$this->assertTrue($index->isStatus(IIndex::INDEX_DONE), 'document should be indexed');
		$this->assertTrue($index->isStatus(IIndex::INDEX_CONTENT), 'plain text content should be extracted');

		$carla = new DocumentAccess();
		$carla->setViewerId('carla');
		$carla->addGroup('professorat');

		$found = $this->search('museu', $carla);
		$this->assertSame(1, $found->getTotal());
		$documents = $found->getDocuments();
		$this->assertSame('sortida-1', $documents[0]->getId());
		$this->assertStringContainsString('museu', $documents[0]->getContent());

		// The query folds accents the same way the artefact fill did.
		$this->assertSame(1, $this->search('ciencies', $carla)->getTotal());

		$dani = new DocumentAccess();
		$dani->setViewerId('dani');
		$dani->addGroup('alumnat');
		$this->assertSame(0, $this->search('museu', $dani)->getTotal(), 'a viewer with no token against the document must find nothing');
	}

	public function testPrefixAndPhraseQueries(): void {
		$owner = new DocumentAccess('biel');
		$this->platform->indexDocument(
			$this->document('corrents-1', 'els corrents del riu mitjançant canoes', $owner),
		);

		$viewer = new DocumentAccess();
		$viewer->setViewerId('biel');

		$this->assertSame(1, $this->search('corren', $viewer)->getTotal(), 'the last optional term takes a prefix match');
		$this->assertSame(1, $this->search('"corrents del riu"', $viewer)->getTotal());
		$this->assertSame(0, $this->search('"riu corrents"', $viewer)->getTotal(), 'phrases keep their word order');
	}

	public function testADocumentWithoutTokensIsRefused(): void {
		$noAccess = new DocumentAccess();

		$index = $this->platform->indexDocument(
			$this->document('orfe-1', 'ningú em trobarà', $noAccess),
		);

		$this->assertTrue($index->isStatus(IIndex::INDEX_FAILED), 'an access with no identity must fail closed');
	}

	public function testHealthProbe(): void {
		$this->assertTrue($this->platform->testPlatform());
	}

	public function testGetDocumentRebuildsTheStoredDocument(): void {
		$owner = new DocumentAccess('biel');
		$owner->addGroup('professorat');
		$this->platform->indexDocument(
			$this->document('sortida-1', 'sortida al museu', $owner),
		);

		$stored = $this->platform->getDocument('test_provider', 'sortida-1');
		$this->assertSame('biel', $stored->getAccess()->getOwnerId());
		$this->assertContains('professorat', $stored->getAccess()->getGroups());
		$this->assertSame('sortida al museu', $stored->getContent());
	}

	private function document(string $id, string $content, DocumentAccess $access): IIndexDocument {
		$document = new IndexDocument('test_provider', $id);
		$document->setIndex(new Index('test_provider', $id));
		$document->setAccess($access);
		$document->setTitle("Escola/Sortida al Museu de Ciències $id.txt");
		$document->setContent(base64_encode($content), IIndexDocument::ENCODED_BASE64);
		$document->setModifiedTime(time());
		return $document;
	}

	private function search(string $terms, DocumentAccess $access): ISearchResult {
		$request = new SearchRequest();
		$request->setSearch($terms);
		$result = new SearchResult($request);
		$result->setProvider($this->provider);
		$this->platform->searchRequest($result, $access);
		return $result;
	}
}
