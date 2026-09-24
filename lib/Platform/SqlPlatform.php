<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Platform;

use OC\FullTextSearch\Model\DocumentAccess;
use OC\FullTextSearch\Model\IndexDocument;
use OCA\FtsSql\Backends\BackendFactory;
use OCA\FtsSql\Exceptions\UnknownDocument;
use OCA\FtsSql\Exceptions\UnsupportedEngine;
use OCA\FtsSql\Service\ConfigService;
use OCA\FtsSql\Service\IndexMappingService;
use OCA\FtsSql\Service\IndexService;
use OCA\FtsSql\Service\SearchMappingService;
use OCA\FtsSql\Service\SearchService;
use OCP\FullTextSearch\IFullTextSearchPlatform;
use OCP\FullTextSearch\Model\IDocumentAccess;
use OCP\FullTextSearch\Model\IIndex;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\FullTextSearch\Model\IRunner;
use OCP\FullTextSearch\Model\ISearchResult;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The platform adapter: the only thing the framework calls, deliberately
 * thin — one hand-off per method (DESIGN.md, "The framework contract").
 * Deciding is pure and lives in the mapping services and the engine
 * strategy; executing is impure and lives in IndexService, SearchService
 * and the repair step.
 */
class SqlPlatform implements IFullTextSearchPlatform {
	private ?IRunner $runner = null;

	public function __construct(
		private readonly ConfigService $config,
		private readonly BackendFactory $factory,
		private readonly IndexService $indexService,
		private readonly SearchService $searchService,
		private readonly LoggerInterface $logger,
	) {
	}

	public function getId(): string {
		return 'fts_sql';
	}

	/**
	 * The name an administrator picks from the platform list.
	 */
	public function getName(): string {
		return 'SQL';
	}

	/**
	 * Engine, language and budget, for the framework's own panel.
	 */
	public function getConfiguration(): array {
		try {
			$engine = $this->factory->getBackend()->name();
		} catch (UnsupportedEngine) {
			$engine = 'unsupported';
		}
		return [
			'engine' => $engine,
			'language' => $this->config->getLanguage(),
			'content_bytes' => $this->config->getContentBytes(),
		];
	}

	public function setRunner(IRunner $runner): void {
		$this->runner = $runner;
	}

	/**
	 * Nothing to load: the database is already connected.
	 */
	public function loadPlatform(): void {
	}

	/**
	 * Nothing to initialize: the artefacts are created by the
	 * CreateSearchArtefact repair step, which reaches installs and upgrades
	 * alike.
	 */
	public function initializeIndex(): void {
	}

	/**
	 * Compiles the platform's own search predicate and makes the engine run
	 * it: a working artefact answers no rows, a missing one raises.
	 */
	public function testPlatform(): bool {
		try {
			$backend = $this->factory->getBackend();
		} catch (UnsupportedEngine) {
			return false;
		}
		if (!$backend->isUsable()) {
			return false;
		}
		return $this->searchService->probe($backend, $this->config->getLanguage());
	}

	public function resetIndex(string $providerId): void {
		if ($providerId === 'all') {
			$this->indexService->deleteAll();
		} else {
			$this->indexService->deleteProvider($providerId);
		}
	}

	/**
	 * @param IIndex[] $indexes
	 */
	public function deleteIndexes(array $indexes): void {
		foreach ($indexes as $index) {
			try {
				$this->indexService->delete($index->getProviderId(), $index->getDocumentId());
				$this->runnerResult($index, 'index deleted', 'success', IRunner::RESULT_TYPE_SUCCESS);
			} catch (Throwable) {
				$this->runnerResult($index, 'index not deleted', 'issue while deleting index', IRunner::RESULT_TYPE_WARNING);
			}
		}
	}

	/**
	 * Map, then write. \Throwable, never \Exception: a parser's TypeError is
	 * a Throwable, and one bad document must cost that document, never the
	 * indexing run.
	 */
	public function indexDocument(IIndexDocument $document): IIndex {
		$index = $document->getIndex();

		try {
			if ($index->isStatus(IIndex::INDEX_REMOVE)) {
				$this->indexService->delete($document->getProviderId(), $document->getId());
			} else {
				$backend = $this->factory->getBackend();
				$row = IndexMappingService::map($document, $this->config->getContentBytes());
				$this->indexService->write($row, $backend, $this->config->getLanguage());

				if ($row->contentExtracted) {
					$index->setStatus(IIndex::INDEX_CONTENT);
				}
				// Not an else: a budget cut extracts content and flags the
				// document at the same time (DESIGN.md, "Open issue:
				// representing partial extraction").
				if ($row->contentError !== null) {
					$index->addError(
						$row->contentError,
						'',
						$row->contentErrorSeverity ?: IIndex::ERROR_SEV_1,
					);
				}
			}

			$index->setLastIndex();
			$index->setStatus(IIndex::INDEX_DONE);
			$this->runnerResult($index, 'true', 'ok', IRunner::RESULT_TYPE_SUCCESS);
		} catch (Throwable $e) {
			$index->setStatus(IIndex::INDEX_FAILED);
			$index->addError($e->getMessage(), $e::class, IIndex::ERROR_SEV_3);
			$this->runnerResult($index, $e->getMessage(), 'fail', IRunner::RESULT_TYPE_FAIL);
			$this->logger->warning('fts_sql: document not indexed', [
				'exception' => $e,
				'provider' => $document->getProviderId(),
				'document' => $document->getId(),
			]);
		}

		return $index;
	}

	/**
	 * Compile, execute, page, count, excerpt. The narrowing capabilities of
	 * ISearchRequest refuse the search (UnsupportedCapability from the
	 * mapping service): ignoring them would return documents the user
	 * excluded. The widening ones are logged once per capability per search
	 * and skipped: files_fulltextsearch sends the first two on every search.
	 */
	public function searchRequest(ISearchResult $result, IDocumentAccess $access): void {
		$start = hrtime(true);
		$request = $result->getRequest();
		$providerId = $result->getProvider()->getId();

		$this->logWideningIgnored('parts', $request->getParts());
		$this->logWideningIgnored('wildcard fields', $request->getWildcardFields());
		$this->logWideningIgnored('fields', $request->getFields());

		$backend = $this->factory->getBackend();
		$search = SearchMappingService::compile(
			$providerId,
			$request,
			$access,
			$backend,
			$this->config->getLanguage(),
		);
		$found = $this->searchService->run($search);

		$documents = [];
		foreach ($found['rows'] as $row) {
			$hit = new IndexDocument($providerId, (string)$row['document_id']);
			// The search result is what the viewer is allowed to see; the
			// access travels with the hit because the framework serializes it.
			$hit->setAccess($access);
			$hit->setTitle((string)($row['title'] ?? ''));
			$hit->setContent((string)($row['content'] ?? ''), IIndexDocument::NOT_ENCODED);
			$hit->setLink((string)($row['link'] ?? ''));
			$hit->setScore((string)$row['score']);
			$hit->addExcerpt(
				$this->excerptTerm($request->getSearch()),
				SearchService::excerpt((string)($row['content'] ?? ''), $request->getSearch()),
			);
			$documents[] = $hit;
		}

		$result->setDocuments($documents);
		$result->setTotal($found['total']);
		$result->setTime((int)((hrtime(true) - $start) / 1_000_000));
	}

	/**
	 * Rebuilds an IIndexDocument from the stored row, for
	 * occ fulltextsearch:document:platform — the administrator's console,
	 * not a user-facing route, hence no access filter here. The access
	 * object is rebuilt from the token rows, undoing
	 * DocumentAccess::tokens()' prefixes.
	 */
	public function getDocument(string $providerId, string $documentId): IIndexDocument {
		$row = $this->indexService->findRow($providerId, $documentId);
		if ($row === null) {
			throw new UnknownDocument("unknown document $providerId/$documentId");
		}

		$access = new DocumentAccess((string)($row['owner'] ?? ''));
		foreach ($this->indexService->findTokens((int)$row['id']) as $token) {
			match (substr($token, 0, 2)) {
				'u:' => $access->addUser(substr($token, 2)),
				'g:' => $access->addGroup(substr($token, 2)),
				'c:' => $access->addCircle(substr($token, 2)),
				default => null,
			};
		}

		$document = new IndexDocument($providerId, $documentId);
		$document->setAccess($access);
		$document->setTitle((string)($row['title'] ?? ''));
		$document->setContent((string)($row['content'] ?? ''), IIndexDocument::NOT_ENCODED);
		$document->setLink((string)($row['link'] ?? ''));
		$document->setSource((string)($row['source'] ?? ''));
		$document->setModifiedTime((int)($row['modified'] ?? 0));
		$document->setScore('0');
		return $document;
	}

	private function runnerResult(IIndex $index, string $message, string $status, int $type): void {
		if ($this->runner === null) {
			return;
		}
		$this->runner->newIndexResult($index, $message, $status, $type);
	}

	private function logWideningIgnored(string $capability, array $value): void {
		if ($value !== [] && $value !== ['']) {
			$this->logger->debug("fts_sql: widening capability ignored: $capability", ['capability' => $capability, 'value' => $value]);
		}
	}

	private function excerptTerm(string $search): string {
		return trim((string)preg_replace('/[+\-"]/', ' ', $search));
	}
}
