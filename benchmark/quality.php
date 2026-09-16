<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The benchmark's quality stage (DESIGN.md, "Background" and "Timeline"):
 * index the fixed corpus — 5,000 Wikipedia opening paragraphs, a third each
 * in Catalan, Spanish and English — through the platform's own interface
 * (SqlPlatform::indexDocument / searchRequest, the one interface every
 * engine is reached through), then score query sets whose answers are known
 * by construction: a distinctive word of a document's title must find that
 * document, and a word the corpus does not hold must find nothing.
 *
 * Runs inside a Nextcloud that has fts_sql enabled, against whatever engine
 * that instance runs:
 *
 *   docker exec -u www-data <nextcloud-container> \
 *       php /var/www/html/apps-extra/fts_sql/benchmark/quality.php
 *
 * Leaves nothing behind: everything is indexed under the provider id
 * 'benchmark' and removed at the end. Print the JSON line it ends with into
 * benchmark/results/ to keep a measurement for comparison.
 */

use OC\FullTextSearch\Model\DocumentAccess;
use OC\FullTextSearch\Model\IndexDocument;
use OCA\FtsSql\Model\AccentFold;
use OCA\FtsSql\Platform\SqlPlatform;
use OCA\FullTextSearch\Model\Index;
use OCA\FullTextSearch\Model\SearchRequest;
use OCA\FullTextSearch\Model\SearchResult;
use OCP\FullTextSearch\IFullTextSearchProvider;
use OCP\FullTextSearch\Model\IIndex;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\FullTextSearch\Model\IIndexOptions;
use OCP\FullTextSearch\Model\IRunner;
use OCP\FullTextSearch\Model\ISearchRequest;
use OCP\FullTextSearch\Model\ISearchResult;
use OCP\FullTextSearch\Model\ISearchTemplate;
use OCP\Server;

require __DIR__ . '/../../../lib/base.php';

/**
 * The provider the corpus is indexed and searched under: the platform's
 * searchRequest reads the provider id from the result's provider, so it must
 * be this one — not fulltextsearch's test_provider — for the provider filter
 * to match. Nothing else about it is ever called.
 */
final class BenchmarkProvider implements IFullTextSearchProvider {
	public function getId(): string {
		return 'benchmark';
	}

	public function getName(): string {
		return 'benchmark';
	}

	public function getConfiguration(): array {
		return [];
	}

	public function getSearchTemplate(): ISearchTemplate {
		throw new RuntimeException('not used');
	}

	public function loadProvider() {
	}

	public function setRunner(IRunner $runner) {
	}

	public function setIndexOptions(IIndexOptions $options) {
	}

	public function generateChunks(string $userId): array {
		return [];
	}

	public function generateIndexableDocuments(string $userId, string $chunk): array {
		return [];
	}

	public function isDocumentUpToDate(IIndexDocument $document): bool {
		return false;
	}

	public function fillIndexDocument(IIndexDocument $document) {
	}

	public function updateDocument(IIndex $index): IIndexDocument {
		throw new RuntimeException('not used');
	}

	public function onInitializingIndex(\OCP\FullTextSearch\IFullTextSearchPlatform $platform) {
	}

	public function onResettingIndex(\OCP\FullTextSearch\IFullTextSearchPlatform $platform) {
	}

	public function improveSearchRequest(ISearchRequest $searchRequest) {
	}

	public function improveSearchResult(ISearchResult $searchResult) {
	}

	public function unloadProvider() {
	}
}

const PROVIDER = 'benchmark';
const OWNER = 'benchmark';
const GROUP = 'benchmarkers';
const QUERY_EVERY = 25;    // one scored query per this many documents
const NEGATIVES = 25;

$platform = Server::get(SqlPlatform::class);
$engine = $platform->getConfiguration()['engine'];
$language = $platform->getConfiguration()['language'];

$corpus = gzopen(__DIR__ . '/corpus/wikipedia-opening-text.jsonl.gz', 'r');
if ($corpus === false) {
	fwrite(STDERR, "cannot open the corpus\n");
	exit(1);
}

$platform->resetIndex(PROVIDER);

$start = hrtime(true);
$indexed = 0;
$failed = 0;
$queries = [];

while (($line = gzgets($corpus)) !== false) {
	$entry = json_decode($line, true);
	if (!is_array($entry) || !isset($entry['id'], $entry['title'], $entry['body'])) {
		continue;
	}

	$access = new DocumentAccess(OWNER);
	$access->addGroup(GROUP);

	$document = new IndexDocument(PROVIDER, (string)$entry['id']);
	$document->setIndex(new Index(PROVIDER, (string)$entry['id']));
	$document->setAccess($access);
	$document->setTitle((string)$entry['title']);
	$document->setContent(base64_encode((string)$entry['body']), IIndexDocument::ENCODED_BASE64);
	$document->setModifiedTime(time());

	$index = $platform->indexDocument($document);
	if ($index->isStatus(IIndex::INDEX_FAILED)) {
		$failed++;
	} else {
		$indexed++;
	}

	// The query set is known by construction: the longest word of the title
	// (folded the way the query side folds) belongs to that document and to
	// almost nothing else.
	if ($indexed % QUERY_EVERY === 0) {
		$term = titleTerm((string)$entry['title']);
		if ($term !== null) {
			$queries[$entry['id']] = $term;
		}
	}

	if ($indexed % 500 === 0) {
		echo "indexed $indexed...\n";
	}
}
gzclose($corpus);
$indexMs = (hrtime(true) - $start) / 1_000_000;

$viewer = new DocumentAccess();
$viewer->setViewerId(OWNER);
$viewer->addGroup(GROUP);

$found = 0;
$searchStart = hrtime(true);
foreach ($queries as $documentId => $term) {
	$request = new SearchRequest();
	$request->setSearch($term);
	$request->setSize(10);
	$result = new SearchResult($request);
	$result->setProvider(new BenchmarkProvider());
	$platform->searchRequest($result, $viewer);
	foreach ($result->getDocuments() as $hit) {
		if ($hit->getId() === (string)$documentId) {
			$found++;
			break;
		}
	}
}
$searchMs = (hrtime(true) - $searchStart) / 1_000_000;

$dirty = 0;
for ($i = 0; $i < NEGATIVES; $i++) {
	$request = new SearchRequest();
	$request->setSearch('zzqj' . str_pad((string)$i, 8, '0', STR_PAD_LEFT));
	$result = new SearchResult($request);
	$result->setProvider(new BenchmarkProvider());
	$platform->searchRequest($result, $viewer);
	if ($result->getTotal() > 0) {
		$dirty++;
	}
}

$platform->resetIndex(PROVIDER);

$report = [
	'engine' => $engine,
	'language' => $language,
	'indexed' => $indexed,
	'failed' => $failed,
	'queries' => count($queries),
	'precision_at_10' => count($queries) === 0 ? null : round($found / count($queries), 4),
	'negatives' => NEGATIVES,
	'negatives_clean' => $dirty === 0,
	'index_ms' => (int)$indexMs,
	'search_ms' => (int)$searchMs,
];

echo "\nquality stage\n";
foreach ($report as $key => $value) {
	$pretty = is_bool($value) ? ($value ? 'true' : 'false') : $value;
	echo str_pad((string)$key, 16) . $pretty . "\n";
}
echo "\n" . json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";

/**
 * The longest folded word of the title with at least five letters, or null.
 */
function titleTerm(string $title): ?string {
	$best = '';
	foreach (preg_split('/[^\p{L}\p{N}]+/u', AccentFold::fold($title)) ?: [] as $word) {
		if (mb_strlen($word) >= 5 && mb_strlen($word) > mb_strlen($best)) {
			$best = $word;
		}
	}
	return $best === '' ? null : $best;
}
