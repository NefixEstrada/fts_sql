<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\AppInfo;

use OCA\FtsSql\ConfigLexicon;
use OCA\FtsSql\Listener\FilesIndexingListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\GenericEvent;
use OCP\IDBConnection;

/**
 * The app's entry point. Nextcloud instantiates this class for every request that
 * touches the app, so keep it cheap: register things here, do not do work here.
 * The platform itself is declared in info.xml, not here — the listener is the
 * one registration beyond the lexicon: the streaming fast path (DESIGN.md,
 * "Open issue: where extraction plugs in", option (b)) plugs in at the files
 * provider's indexing event, and on Nextcloud 34 that event is a GenericEvent
 * delivered by class name, so that is what the registration listens on.
 */
class Application extends App implements IBootstrap {
	public const APP_ID = 'fts_sql';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	/**
	 * Register services, listeners, middlewares. Runs on every request; must not
	 * query the database or use \OC::$server.
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerConfigLexicon(ConfigLexicon::class);
		/**
		 * @psalm-suppress DeprecatedClass GenericEvent is what
		 *                files_fulltextsearch 34 still dispatches its
		 *                extension events as, delivered by class name —
		 *                subject-name listeners never fire (measured against
		 *                34.0.4); the listener filters the subject itself
		 */
		$context->registerEventListener(GenericEvent::class, FilesIndexingListener::class);
	}

	/**
	 * FTS5 virtual tables declare their columns without a type, which
	 * Doctrine's SQLite introspection refuses ("Unknown database type"),
	 * taking every later schema introspection with it: any app's next
	 * migration, occ db:schema:export, our own tests. Map the empty type to
	 * text while this app is enabled — idempotent, once per request.
	 */
	public function boot(IBootContext $context): void {
		$context->injectFn(function (IDBConnection $db): void {
			if ($db->getDatabaseProvider() !== IDBConnection::PLATFORM_SQLITE) {
				return;
			}
			/** @psalm-suppress DeprecatedMethod the recommended replacement covers platform detection, not type-mapping registration */
			$db->getDatabasePlatform()->registerDoctrineTypeMapping('', 'text');
		});
	}
}
