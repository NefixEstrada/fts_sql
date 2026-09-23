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
use OCA\FtsSql\Service\IndexService;
use OCA\FtsSql\Tests\Fixtures;
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

	/**
	 * DESIGN.md Milestone 2's observable state: .docx and .odt are found by
	 * their body text, on whatever engine this instance runs. The
	 * containers are real zips built at run time; what the platform sees is
	 * what any provider hands over — the bytes, base64, and the path as the
	 * title. Milestone 4 adds the three legacy binary formats over the same
	 * assertion — the .ppt, .xls and .doc behind this app's own
	 * compound-file gate and the scoped PhpOffice readers, served by the
	 * app autoloader alone: the production shape, since nothing loads
	 * vendor/ here.
	 */
	public function testOfficeFormatsAreFoundByTheirBodyText(): void {
		$owner = new DocumentAccess('biel');

		$documents = [
			['sortida-museu.docx', Fixtures::docx(
				'<w:p><w:r><w:t xml:space="preserve">sortida al </w:t></w:r><w:r><w:t>museu de ciències</w:t></w:r></w:p>',
			)],
			['sortida-museu.odt', Fixtures::odf(
				'<text:p>una sortida al museu amb tota la classe</text:p>',
			)],
			['sortida-museu.ppt', Fixtures::ppt([
				['sortida al museu de ciències'],
				['confirma el pagament del bus'],
			])],
			['sortida-museu.xls', Fixtures::xls([
				'sortida al museu amb tota la classe',
			])],
			['sortida-museu.doc', Fixtures::doc([
				'la visita al museu amb el pressupost',
			])],
		];

		foreach ($documents as [$name, $bytes]) {
			$index = $this->platform->indexDocument(
				$this->containerDocument($name, $bytes, $owner),
			);
			$this->assertTrue($index->isStatus(IIndex::INDEX_DONE), "$name should be indexed");
			$this->assertTrue($index->isStatus(IIndex::INDEX_CONTENT), "$name body text should be extracted");
		}

		$viewer = new DocumentAccess();
		$viewer->setViewerId('biel');

		$this->assertSame(5, $this->search('museu', $viewer)->getTotal(), 'all five formats found by body text');
		// And by a word only the odt and the xls hold.
		$this->assertSame(2, $this->search('classe', $viewer)->getTotal());
		// And one only the ppt holds.
		$this->assertSame(1, $this->search('pagament', $viewer)->getTotal());
		// And one only the doc holds.
		$this->assertSame(1, $this->search('pressupost', $viewer)->getTotal());
	}

	public function testADocumentWithoutTokensIsRefused(): void {
		$noAccess = new DocumentAccess();

		$index = $this->platform->indexDocument(
			$this->document('orfe-1', 'ningú em trobarà', $noAccess),
		);

		$this->assertTrue($index->isStatus(IIndex::INDEX_FAILED), 'an access with no identity must fail closed');
	}

	/**
	 * Decision (c) of DESIGN.md's "Open issue: representing partial
	 * extraction": what addError() already reports per document becomes
	 * countable per cause, over the column the row now stores — the admin
	 * card's numbers are this query. assertEquals, not assertSame: a GROUP
	 * BY's row order belongs to the engine.
	 */
	public function testExtractionCausesAreCountablePerFlag(): void {
		$owner = new DocumentAccess('biel');

		$this->platform->indexDocument(
			$this->containerDocument('sortida-museu.epub', 'a zip of XML nobody claims', $owner),
		);
		$this->platform->indexDocument(
			$this->containerDocument('sortida-museu.xls', Fixtures::xlsEncryptedToken(['museu']), $owner),
		);
		$this->platform->indexDocument(
			$this->document('giant-2', str_repeat('paraula ', 400_000), $owner), // ~3.2 MB, past the 2 MiB budget
		);
		$this->platform->indexDocument($this->document('nete-1', 'text net', $owner));

		$this->assertEquals(
			['encrypted' => 1, 'unsupported' => 1, 'budget cut' => 1],
			Server::get(IndexService::class)->countCauses(),
			'a flagged document per cause, and the clean one counted nowhere',
		);

		// A re-index that extracts clean takes the flag back out: the cause
		// column travels with the replace-on-write row.
		$this->platform->indexDocument($this->document('giant-2', 'ara sóc petit', $owner));
		$this->assertEquals(
			['encrypted' => 1, 'unsupported' => 1],
			Server::get(IndexService::class)->countCauses(),
		);
	}

	public function testHealthProbe(): void {
		$this->assertTrue($this->platform->testPlatform());
	}

	/**
	 * DESIGN.md Scenario 4: a text where every token is distinct hits the
	 * tsvector's 1,048,575-byte ceiling (SQLSTATE 54000) at roughly 637 KB,
	 * below the 2 MiB budget. The platform halves the content and retries —
	 * up to four times — and the row lands truncated: the document is still
	 * indexed, still findable by what survived, and still reports ok.
	 *
	 * PostgreSQL only: the ceiling is a tsvector property; MySQL, MariaDB
	 * and SQLite have none and store the whole budget-sized content.
	 */
	public function testADocumentTheEngineRefusesLandsTruncated(): void {
		if (\OCP\Server::get(\OCP\IDBConnection::class)->getDatabaseProvider() !== \OCP\IDBConnection::PLATFORM_POSTGRES) {
			$this->markTestSkipped('the tsvector ceiling is a PostgreSQL property');
		}

		$tokens = [];
		for ($i = 0; $i < 80000; $i++) {
			$tokens[] = sprintf('zzqj%08d', $i);
		}
		$content = implode(' ', $tokens); // ~1.04 MB of text, ~1.3 MB of tsvector: past the ceiling

		$owner = new DocumentAccess('biel');
		$index = $this->platform->indexDocument($this->document('giant-1', $content, $owner));

		$this->assertTrue($index->isStatus(IIndex::INDEX_DONE), 'the document must cost itself, never the run');
		$this->assertTrue($index->isStatus(IIndex::INDEX_CONTENT));

		$stored = $this->platform->getDocument('test_provider', 'giant-1');
		$this->assertLessThan(strlen($content), strlen($stored->getContent()), 'the content landed truncated by the halvings');

		// The truncation is a cause like any other gap (decision (c)): the
		// row is flagged for the admin card's ledger, counted per flag.
		$this->assertEquals(
			['engine ceiling' => 1],
			Server::get(IndexService::class)->countCauses(),
			'a document the engine ceiling truncated lands flagged',
		);

		$viewer = new DocumentAccess();
		$viewer->setViewerId('biel');
		$this->assertSame(1, $this->search('zzqj00000001', $viewer)->getTotal(), 'findable by what survived');
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

	/**
	 * The unified search's Date chip arrives as options on the request
	 * (measured in the UI inventory): it narrows over the stored `modified`,
	 * on every engine the integration tier covers.
	 */
	public function testTheUnifiedSearchDateRangeNarrowsResults(): void {
		$owner = new DocumentAccess('biel');
		$this->platform->indexDocument($this->document('vell-1', 'text vell del museu', $owner, modified: 1000));
		$this->platform->indexDocument($this->document('nou-1', 'text nou del museu', $owner, modified: 2000000000));

		$viewer = new DocumentAccess();
		$viewer->setViewerId('biel');

		$since = $this->search('museu', $viewer, ['since' => '1500']);
		$this->assertSame(1, $since->getTotal());
		$this->assertSame('nou-1', $since->getDocuments()[0]->getId());

		$until = $this->search('museu', $viewer, ['until' => '1500']);
		$this->assertSame(1, $until->getTotal());
		$this->assertSame('vell-1', $until->getDocuments()[0]->getId());

		$this->assertSame(2, $this->search('museu', $viewer, ['since' => '500', 'until' => '2500000000'])->getTotal());
	}

	private function document(string $id, string $content, DocumentAccess $access, ?int $modified = null): IIndexDocument {
		$document = new IndexDocument('test_provider', $id);
		$document->setIndex(new Index('test_provider', $id));
		$document->setAccess($access);
		$document->setTitle("Escola/Sortida al Museu de Ciències $id.txt");
		$document->setContent(base64_encode($content), IIndexDocument::ENCODED_BASE64);
		$document->setModifiedTime($modified ?? time());
		return $document;
	}

	/**
	 * Same shape as document(), with the container's bytes and the format's
	 * extension in the title path.
	 */
	private function containerDocument(string $name, string $bytes, DocumentAccess $access): IIndexDocument {
		$document = new IndexDocument('test_provider', $name);
		$document->setIndex(new Index('test_provider', $name));
		$document->setAccess($access);
		$document->setTitle("Escola/Sortida al Museu de Ciències/$name");
		$document->setContent(base64_encode($bytes), IIndexDocument::ENCODED_BASE64);
		$document->setModifiedTime(time());
		return $document;
	}

	private function search(string $terms, DocumentAccess $access, array $options = []): ISearchResult {
		$request = new SearchRequest();
		$request->setSearch($terms);
		foreach ($options as $key => $value) {
			$request->addOption($key, $value);
		}
		$result = new SearchResult($request);
		$result->setProvider($this->provider);
		$this->platform->searchRequest($result, $access);
		return $result;
	}
}
