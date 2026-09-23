<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Integration\Listener;

use OC\FullTextSearch\Model\IndexDocument;
use OCA\FtsSql\Listener\FilesIndexingListener;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;
use Test\TestCase;

/**
 * The upgrade sentinel for the streaming fast path. files_fulltextsearch
 * fires Files_FullTextSearch.onFileIndexing as a GenericEvent dispatched BY
 * CLASS NAME through the real IEventDispatcher (its ExtensionService:
 * dispatchTyped(new GenericEvent($subject, $arguments))) — subject-name
 * listeners never fire on Nextcloud 34, which is why the app registers on
 * GenericEvent::class and filters the subject itself. This test dispatches
 * the provider's own event, the same way, against a real file node: the day
 * a server or provider update changes that delivery (a typed event class,
 * a different dispatcher route), it fails here instead of the fast path
 * going quietly silent — indexing would still work through the base64
 * fallback, only slower, and nothing else would notice.
 */
#[Group('DB')]
class FilesIndexingListenerTest extends TestCase {
	private const USER = 'fts-sentinel';

	protected function tearDown(): void {
		$userManager = Server::get(IUserManager::class);
		$user = $userManager->get(self::USER);
		$user?->delete();
		parent::tearDown();
	}

	public function testTheRegistrationCatchesTheProvidersOwnDispatch(): void {
		$userManager = Server::get(IUserManager::class);
		$user = $userManager->get(self::USER) ?? $userManager->createUser(self::USER, self::USER);
		$rootFolder = Server::get(IRootFolder::class);

		$folder = $rootFolder->getUserFolder($user->getUID());
		$file = $folder->newFile('sentinel.txt', 'la sortida al museu de ciències');
		try {
			$document = new IndexDocument('files', (string)$file->getId());
			$document->setTitle($file->getPath());
			$document->setAccess(new \OC\FullTextSearch\Model\DocumentAccess($user->getUID()));

			// Exactly the provider's own ExtensionService::fileIndexing().
			/** @psalm-suppress DeprecatedClass the event this app listens for is a GenericEvent; there is no non-deprecated route to it */
			Server::get(IEventDispatcher::class)->dispatchTyped(
				/** @psalm-suppress DeprecatedClass the same reason as above */
				new GenericEvent('Files_FullTextSearch.onFileIndexing', ['file' => $file, 'document' => $document]),
			);

			$marker = $document->getInfoArray(FilesIndexingListener::INFO_KEY);
			$this->assertTrue((bool)($marker['extracted'] ?? false), 'the class-name registration caught the event and extracted from the node');
			$this->assertSame('la sortida al museu de ciències', $document->getContent());
			$this->assertNull($marker['cause'] ?? null, 'a plain text file extracts whole');
		} finally {
			$file->delete();
		}
	}
}
