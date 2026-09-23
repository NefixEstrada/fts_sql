<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The benchmark's access-filter stage (DESIGN.md, "Not sequenced"): the
 * access filter is the boundary between one user's documents and another's,
 * so its stage measures both sides of it — the cost of the token EXISTS at
 * scale, and its correctness (every viewer finds exactly the documents
 * their tokens admit, and a viewer with no token against anything finds
 * nothing, the fail-closed posture under load).
 *
 * Documents are spread over --owners owners and --groups groups
 * independently (the owner from one modulus, the group from another), so
 * each viewer's expected total is computable by inclusion-exclusion and
 * wrong by construction is impossible to miss. Every document carries the
 * marker word "accessstage", so one search per viewer answers with the
 * viewer's whole subset and the COUNT pays for the full scan.
 *
 *   docker exec -u www-data <nextcloud-container> \
 *       php /var/www/html/apps-extra/fts_sql/benchmark/access.php --count=20000
 *
 * Leaves nothing behind (provider id 'benchmark'); ends with one JSON line
 * for benchmark/results/.
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
 * The provider the stage is indexed and searched under; see scale.php.
 */
final class AccessProvider implements IFullTextSearchProvider {
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
const MARKER = 'accessstage';
const VIEWERS_SAMPLED = 12;
const PROGRESS_EVERY = 5000;

$count = 20000;
$owners = 50;
$groups = 4;
foreach ($_SERVER['argv'] ?? [] as $arg) {
	if (is_string($arg)) {
		if (preg_match('/^--count=(\d+)$/', $arg, $m) === 1) {
			$count = max(VIEWERS_SAMPLED, (int)$m[1]);
		}
		if (preg_match('/^--owners=(\d+)$/', $arg, $m) === 1) {
			$owners = max(1, (int)$m[1]);
		}
		if (preg_match('/^--groups=(\d+)$/', $arg, $m) === 1) {
			$groups = max(1, (int)$m[1]);
		}
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
while ($indexed < $count) {
	$line = gzgets($corpus);
	if ($line === false) {
		rewind($corpus);
		continue;
	}
	$entry = json_decode($line, true);
	if (!is_array($entry) || !isset($entry['body'])) {
		continue;
	}

	// Owner from one modulus, group from another: the two dimensions vary
	// independently, which is what makes each viewer's expectation an
	// inclusion-exclusion rather than a single bucket.
	$owner = 'scaleowner' . ($indexed % $owners);
	$group = 'scaleg' . (intdiv($indexed, $owners) % $groups);

	$access = new DocumentAccess($owner);
	$access->addGroup($group);

	$document = new IndexDocument(PROVIDER, 'access-' . $indexed);
	$document->setIndex(new Index(PROVIDER, 'access-' . $indexed));
	$document->setAccess($access);
	// The marker rides the TITLE, which is always indexed: the corpus
	// carries at least one body with control characters (plain-text corpus
	// entry 152), the Milestone 1 gate refuses it as text, and that document
	// is rightly indexed without content — an expectation that hung the
	// marker on the content would be wrong by exactly that document.
	$document->setTitle(MARKER . " [$indexed]");
	$document->setContent(base64_encode(MARKER . ' ' . (string)$entry['body']), IIndexDocument::ENCODED_BASE64);
	$document->setModifiedTime(time());

	$index = $platform->indexDocument($document);
	if (!$index->isStatus(IIndex::INDEX_FAILED)) {
		$indexed++;
	}

	if ($indexed % PROGRESS_EVERY === 0) {
		echo "indexed $indexed...\n";
	}
}
gzclose($corpus);
$indexMs = (hrtime(true) - $start) / 1_000_000;

// Viewer of owner k carries that owner's tokens; by inclusion-exclusion the
// documents they may see are the owner's own, plus the group's, minus the
// overlap — all three counts are exact over the construction above.
$expectedFor = static fn (int $k): int
	=> (int)floor($count / $owners)
	+ (int)floor($count / $groups)
	- (int)floor($count / ($owners * $groups));

$wrong = 0;
$worst = null;
$latencies = [];
foreach (range(0, VIEWERS_SAMPLED - 1) as $k) {
	$viewer = new DocumentAccess();
	$viewer->setViewerId('scaleowner' . $k);
	$viewer->addGroup('scaleg' . ($k % $groups));

	$at = hrtime(true);
	$request = new SearchRequest();
	$request->setSearch(MARKER);
	$result = new SearchResult($request);
	$result->setProvider(new AccessProvider());
	$platform->searchRequest($result, $viewer);
	$latencies[] = (hrtime(true) - $at) / 1_000_000;

	$expected = $expectedFor($k);
	if ($result->getTotal() !== $expected) {
		$wrong++;
		$worst = ['viewer' => $k, 'expected' => $expected, 'got' => $result->getTotal()];
	}
}

// The fail-closed side: a viewer whose tokens sit against nothing.
$intruder = new DocumentAccess();
$intruder->setViewerId('not-in-any-access-list');
$at = hrtime(true);
$request = new SearchRequest();
$request->setSearch(MARKER);
$result = new SearchResult($request);
$result->setProvider(new AccessProvider());
$platform->searchRequest($result, $intruder);
$intruderMs = (hrtime(true) - $at) / 1_000_000;
$intruderTotal = $result->getTotal();

sort($latencies);
$latencyAt = static fn (float $q): int => (int)($latencies === []
	? 0
	: $latencies[min(count($latencies) - 1, (int)floor($q * count($latencies)))]);

$platform->resetIndex(PROVIDER);

$report = [
	'engine' => $engine,
	'count' => $count,
	'owners' => $owners,
	'groups' => $groups,
	'indexed' => $indexed,
	'index_ms' => (int)$indexMs,
	'docs_per_s' => $indexMs > 0 ? round($indexed / ($indexMs / 1000), 1) : null,
	'viewers_sampled' => VIEWERS_SAMPLED,
	'wrong_viewers' => $wrong,
	'worst_mismatch' => $worst,
	'latency_p50_ms' => $latencyAt(0.50),
	'latency_p95_ms' => $latencyAt(0.95),
	'latency_max_ms' => (int)($latencies === [] ? 0 : $latencies[count($latencies) - 1]),
	'intruder_total' => $intruderTotal,
	'intruder_ms' => (int)$intruderMs,
];

echo "\naccess-filter stage\n";
foreach ($report as $key => $value) {
	$pretty = is_bool($value) ? ($value ? 'true' : 'false') : json_encode($value, JSON_UNESCAPED_SLASHES);
	echo str_pad((string)$key, 16) . $pretty . "\n";
}
echo "\n" . json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";
