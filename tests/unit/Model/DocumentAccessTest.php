<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Unit\Model;

use OCA\FtsSql\Exceptions\AccessIsEmpty;
use OCA\FtsSql\Model\DocumentAccess;
use OCP\FullTextSearch\Model\IDocumentAccess;
use PHPUnit\Framework\TestCase;

/**
 * DocumentAccess::tokens over both shapes the framework really sends: the
 * provider's ACL at index time (ownerId, users, groups, circles) and the
 * viewer's memberships at search time (viewerId, groups, circles — see
 * fulltextsearch's SearchService::getDocumentAccessFromUser).
 */
class DocumentAccessTest extends TestCase {

	public function testMapsTheIndexSide(): void {
		$access = self::access(owner: 'biel', groups: ['professorat']);

		$this->assertSame(['o:biel', 'g:professorat'], DocumentAccess::tokens($access));
	}

	public function testEveryIdentityKindCarriesItsPrefix(): void {
		$access = self::access(
			owner: 'biel',
			users: ['dani', 'elis'],
			groups: ['professorat'],
			circles: ['circle-id-1'],
		);

		$this->assertSame(
			['o:biel', 'u:dani', 'u:elis', 'g:professorat', 'c:circle-id-1'],
			DocumentAccess::tokens($access),
		);
	}

	public function testTheViewerMatchesOwnAndDirectlySharedDocuments(): void {
		// The worked example: Carla, in professorat, searches.
		$access = self::access(viewer: 'carla', groups: ['professorat']);

		$this->assertSame(
			['o:carla', 'u:carla', 'g:professorat'],
			DocumentAccess::tokens($access),
		);
	}

	public function testTheReservedAllMemberIsAnIdentityNotEveryone(): void {
		// A uid actually called __all arrives in getUsers() the same way the
		// framework's reserved "everyone" marker does, and nothing on the
		// interface tells them apart; only the identity reading cannot leak
		// (DESIGN.md, Security).
		$access = self::access(owner: 'anna', users: ['__all']);

		$this->assertSame(['o:anna', 'u:__all'], DocumentAccess::tokens($access));
	}

	public function testAnAccessWithNoIdentityIsRefused(): void {
		$this->expectException(AccessIsEmpty::class);

		DocumentAccess::tokens(self::access());
	}

	public function testDuplicateTokensCollapse(): void {
		$access = self::access(viewer: 'carla', users: ['carla'], groups: ['a', 'a']);

		$this->assertSame(['o:carla', 'u:carla', 'g:a'], DocumentAccess::tokens($access));
	}

	public function testEmptyAndNonStringIdentitiesAreIgnored(): void {
		$access = self::access(owner: 'biel', users: ['', 3, 'dani', null]);

		$this->assertSame(['o:biel', 'u:dani'], DocumentAccess::tokens($access));
	}

	/**
	 * A stand-in for OCP\FullTextSearch\Model\DocumentAccess with the fields
	 * DocumentAccess reads; the setters exist because the interface demands
	 * them, not because the mapper uses them.
	 */
	private static function access(
		string $owner = '',
		string $viewer = '',
		array $users = [],
		array $groups = [],
		array $circles = [],
	): IDocumentAccess {
		return new class($owner, $viewer, $users, $groups, $circles) implements IDocumentAccess {
			private string $ownerId;
			private string $viewerId;
			private array $users;
			private array $groups;
			private array $circles;

			public function __construct(
				string $ownerId = '',
				string $viewerId = '',
				array $users = [],
				array $groups = [],
				array $circles = [],
			) {
				$this->ownerId = $ownerId;
				$this->viewerId = $viewerId;
				$this->users = $users;
				$this->groups = $groups;
				$this->circles = $circles;
			}

			public function setOwnerId(string $ownerId): IDocumentAccess {
				$this->ownerId = $ownerId;
				return $this;
			}

			public function getOwnerId(): string {
				return $this->ownerId;
			}

			public function setViewerId(string $viewerId): IDocumentAccess {
				$this->viewerId = $viewerId;
				return $this;
			}

			public function getViewerId(): string {
				return $this->viewerId;
			}

			public function setUsers(array $users): IDocumentAccess {
				$this->users = $users;
				return $this;
			}

			public function addUser(string $user): IDocumentAccess {
				$this->users[] = $user;
				return $this;
			}

			public function addUsers($users): IDocumentAccess {
				$this->users = array_merge($this->users, $users);
				return $this;
			}

			public function getUsers(): array {
				return $this->users;
			}

			public function setGroups(array $groups): IDocumentAccess {
				$this->groups = $groups;
				return $this;
			}

			public function addGroup(string $group): IDocumentAccess {
				$this->groups[] = $group;
				return $this;
			}

			public function addGroups(array $groups): IDocumentAccess {
				$this->groups = array_merge($this->groups, $groups);
				return $this;
			}

			public function getGroups(): array {
				return $this->groups;
			}

			public function setCircles(array $circles): IDocumentAccess {
				$this->circles = $circles;
				return $this;
			}

			public function addCircle(string $circle): IDocumentAccess {
				$this->circles[] = $circle;
				return $this;
			}

			public function addCircles(array $circles): IDocumentAccess {
				$this->circles = array_merge($this->circles, $circles);
				return $this;
			}

			public function getCircles(): array {
				return $this->circles;
			}

			public function setLinks(array $links): IDocumentAccess {
				return $this;
			}

			public function getLinks(): array {
				return [];
			}
		};
	}
}
