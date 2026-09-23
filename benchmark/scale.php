<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The benchmark's scale stage (DESIGN.md, "Not sequenced"): the quality
 * stage's shape — the fixed corpus through the platform's own interface,
 * query sets whose answers are known by construction — at a document count
 * the quality stage never reaches, because what stays fast at 5,000 and
 * what stays fast at 1,000,000 are different questions. Rows are streamed
 * from the corpus and cycled with distinct ids and titles, so the query set
 * (the longest title word of every 500th document) stays answerable.
 *
 * Runs inside a Nextcloud that has fts_sql enabled, against whatever engine
 * that instance runs; the count is a flag:
 *
 *   docker exec -u www-data <nextcloud-container> \
 *       php /var/www/html/apps-extra/fts_sql/benchmark/scale.php --count=100000
 *
 * Leaves nothing behind: everything is indexed under the provider id
 * 'benchmark' and removed at the end — whose own cost is part of the
 * report, because a reset that takes an hour is an operational fact. Print
 * the JSON line it ends with into benchmark/results/ to keep a measurement
 * for comparison.
 */

use OC\FullTextSearch\Model\DocumentAccess;
use OC\FullTextSearch\Model\IndexDocument;
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
final class ScaleProvider implements IFullTextSearchProvider {
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
const QUERY_EVERY = 500;     // one scored query per this many documents
const NEGATIVES = 10;
const PROGRESS_EVERY = 10000;

$count = 100000;
foreach ($_SERVER['argv'] ?? [] as $arg) {
	if (is_string($arg) && preg_match('/^--count=(\d+)$/', $arg, $m) === 1) {
		$count = max(1, (int)$m[1]);
	}
}

$platform = Server::get(SqlPlatform::class);
$engine = $platform->getConfiguration()['engine'];

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
$access = new DocumentAccess(OWNER);
$access->addGroup(GROUP);

while ($indexed < $count) {
	$line = gzgets($corpus);
	if ($line === false) {
		rewind($corpus);
		continue;
	}
	$entry = json_decode($line, true);
	if (!is_array($entry) || !isset($entry['title'], $entry['body'])) {
		continue;
	}

	// Distinct id and title per row, corpus body cycled. The title carries a
	// zero-padded 7-digit document code: one query per sampled row searches
	// its own code, and the answer is exact at any scale — the corpus is
	// cycled, so a title WORD would tie across every copy of its document
	// (20 of each at 100,000) and precision@10 would measure the tie-break,
	// not the engine. Relevance stays the quality stage's question; scale
	// asks whether the query still comes back.
	$n = $indexed;
	$id = 'scale-' . $n;
	$code = str_pad((string)$n, 7, '0', STR_PAD_LEFT);
	$title = (string)$entry['title'] . ' [' . $code . ']';

	$document = new IndexDocument(PROVIDER, $id);
	$document->setIndex(new Index(PROVIDER, $id));
	$document->setAccess($access);
	$document->setTitle($title);
	$document->setContent(base64_encode((string)$entry['body']), IIndexDocument::ENCODED_BASE64);
	$document->setModifiedTime(time());

	$index = $platform->indexDocument($document);
	if ($index->isStatus(IIndex::INDEX_FAILED)) {
		$failed++;
	} else {
		$indexed++;
	}

	// Keyed by this document's own id, sampled every QUERY_EVERY-th row: the
	// term must describe the document it will be looked for in.
	if ($n % QUERY_EVERY === 0) {
		$queries[$id] = $code;
	}

	if ($indexed % PROGRESS_EVERY === 0) {
		echo "indexed $indexed...\n";
	}
}
gzclose($corpus);
$indexMs = (hrtime(true) - $start) / 1_000_000;

$viewer = new DocumentAccess();
$viewer->setViewerId(OWNER);
$viewer->addGroup(GROUP);

$found = 0;
$latencies = [];
$searchStart = hrtime(true);
foreach ($queries as $documentId => $term) {
	$at = hrtime(true);
	$request = new SearchRequest();
	$request->setSearch($term);
	$request->setSize(10);
	$result = new SearchResult($request);
	$result->setProvider(new ScaleProvider());
	$platform->searchRequest($result, $viewer);
	$latencies[] = (hrtime(true) - $at) / 1_000_000;
	foreach ($result->getDocuments() as $hit) {
		if ($hit->getId() === (string)$documentId) {
			$found++;
			break;
		}
	}
}
$searchMs = (hrtime(true) - $searchStart) / 1_000_000;
sort($latencies);
$latencyAt = static fn (float $q): int => (int)($latencies === []
	? 0
	: $latencies[min(count($latencies) - 1, (int)floor($q * count($latencies)))]);

$dirty = 0;
for ($i = 0; $i < NEGATIVES; $i++) {
	$request = new SearchRequest();
	$request->setSearch('zzqj' . str_pad((string)$i, 8, '0', STR_PAD_LEFT));
	$result = new SearchResult($request);
	$result->setProvider(new ScaleProvider());
	$platform->searchRequest($result, $viewer);
	if ($result->getTotal() > 0) {
		$dirty++;
	}
}

$resetStart = hrtime(true);
$platform->resetIndex(PROVIDER);
$resetMs = (hrtime(true) - $resetStart) / 1_000_000;

$report = [
	'engine' => $engine,
	'count' => $count,
	'indexed' => $indexed,
	'failed' => $failed,
	'index_ms' => (int)$indexMs,
	'docs_per_s' => $indexMs > 0 ? round($indexed / ($indexMs / 1000), 1) : null,
	'reset_ms' => (int)$resetMs,
	'queries' => count($queries),
	'precision_at_10' => count($queries) === 0 ? null : round($found / count($queries), 4),
	'search_ms' => (int)$searchMs,
	'latency_p50_ms' => $latencyAt(0.50),
	'latency_p95_ms' => $latencyAt(0.95),
	'latency_max_ms' => (int)($latencies === [] ? 0 : $latencies[count($latencies) - 1]),
	'negatives' => NEGATIVES,
	'negatives_clean' => $dirty === 0,
];

echo "\nscale stage\n";
foreach ($report as $key => $value) {
	$pretty = is_bool($value) ? ($value ? 'true' : 'false') : $value;
	echo str_pad((string)$key, 16) . $pretty . "\n";
}
echo "\n" . json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";
