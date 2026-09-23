<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Migration;

use OCA\FtsSql\Backends\BackendFactory;
use OCA\FtsSql\Exceptions\UnsupportedEngine;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Creates the engine's search artefact, declared under both <install> and
 * <post-migration> in info.xml: the install step reaches a first install and
 * every occ app:enable, the post-migration step reaches an upgrade, and both
 * being this one class means a future artefact change ships with a version
 * bump (DESIGN.md, "Schema"). The statements are the strategy's — idempotent
 * — and this step is one of the places raw SQL is allowed to execute.
 */
final class CreateSearchArtefact implements IRepairStep {
	public function __construct(
		private BackendFactory $factory,
		private IDBConnection $db,
		private IL10N $l10n,
	) {
	}

	public function getName(): string {
		return 'Create the fts_sql search artefact';
	}

	public function run(IOutput $output): void {
		try {
			$backend = $this->factory->getBackend();
		} catch (UnsupportedEngine $e) {
			$output->warning($e->getMessage());
			return;
		}

		if (!$backend->isUsable()) {
			$output->warning(
				$this->l10n->t('this %s build cannot run full text search; FTS SQL will not index or answer searches', [$backend->name()]),
			);
			return;
		}

		foreach ($backend->artefactStatements() as $statement) {
			$this->db->executeStatement($statement);
		}

		// Creating an artefact over existing rows does not refill it; say so
		// where the administrator already looks instead of an empty result
		// page (DESIGN.md, "Monitoring / alerting"). SQLite's statements end
		// in an FTS5 rebuild and never get here.
		if ($backend->hasUnindexedDocuments()) {
			$output->warning(
				$this->l10n->t('the search artefact does not hold every document stored so far; run `occ fulltextsearch:reset && occ fulltextsearch:index`'),
			);
		}
	}
}
