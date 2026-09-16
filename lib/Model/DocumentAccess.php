<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Model;

use OCA\FtsSql\Exceptions\AccessIsEmpty;
use OCP\FullTextSearch\Model\IDocumentAccess;

/**
 * IDocumentAccess mapped onto flat access tokens, both directions through
 * this one mapper (DESIGN.md, "Access tokens"):
 *
 * - At index time the provider sets ownerId (o:), users (u:), groups (g:)
 *   and circles (c:).
 * - At search time the framework (SearchService::getDocumentAccessFromUser)
 *   sets viewerId, groups and circles. The searching user matches documents
 *   they own (o:) and documents shared directly with them (u:), so the
 *   viewer id emits both tokens.
 *
 * The framework's reserved member `__all` inside getUsers() is read as the
 * identity it spells, never as "everyone": `__all` is also a legal Nextcloud
 * uid, the two arrive as the same string, and only the identity reading
 * cannot leak. Links carry no token kind and no identity; they are dropped,
 * which is the direction that fails closed.
 */
final class DocumentAccess {
	/**
	 * @return list<string> the tokens, deduplicated, in a stable order
	 * @throws AccessIsEmpty when nothing maps: the caller must refuse
	 */
	public static function tokens(IDocumentAccess $access): array {
		$tokens = [];

		$owner = $access->getOwnerId();
		if ($owner !== '') {
			$tokens[] = 'o:' . $owner;
		}

		$viewer = $access->getViewerId();
		if ($viewer !== '') {
			$tokens[] = 'o:' . $viewer;
			$tokens[] = 'u:' . $viewer;
		}

		self::addPrefixed($tokens, 'u:', $access->getUsers());
		self::addPrefixed($tokens, 'g:', $access->getGroups());
		self::addPrefixed($tokens, 'c:', $access->getCircles());

		$tokens = array_values(array_unique($tokens));

		if ($tokens === []) {
			throw new AccessIsEmpty(
				'refusing an access object with no identity: an empty token set '
				. 'can only be a permission bug, and the filter fails closed',
			);
		}

		return $tokens;
	}

	/**
	 * @param list<string> $tokens
	 * @param array<mixed> $identities
	 */
	private static function addPrefixed(array &$tokens, string $prefix, array $identities): void {
		foreach ($identities as $identity) {
			if (is_string($identity) && $identity !== '') {
				$tokens[] = $prefix . $identity;
			}
		}
	}
}
