<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Psalm-only stubs for the server's internal full text search models: the
 * nextcloud/ocp package ships the OCP interfaces but not the OC
 * implementations. Loaded through psalm.xml <extraFiles> — parsed for
 * symbols, never executed — and inside a server checkout the real classes
 * exist and these declarations are shadowed.
 */

namespace OC\FullTextSearch\Model {

	use OCP\FullTextSearch\Model\IDocumentAccess;
	use OCP\FullTextSearch\Model\IIndexDocument;

	class DocumentAccess implements IDocumentAccess {
		public function __construct(string $ownerId = '') {
		}

		public function setOwnerId(string $ownerId): IDocumentAccess {
			return $this;
		}

		public function getOwnerId(): string {
			return '';
		}

		public function setViewerId(string $viewerId): IDocumentAccess {
			return $this;
		}

		public function getViewerId(): string {
			return '';
		}

		public function setUsers(array $users): IDocumentAccess {
			return $this;
		}

		public function addUser(string $user): IDocumentAccess {
			return $this;
		}

		public function addUsers($users): IDocumentAccess {
			return $this;
		}

		public function getUsers(): array {
			return [];
		}

		public function setGroups(array $groups): IDocumentAccess {
			return $this;
		}

		public function addGroup(string $group): IDocumentAccess {
			return $this;
		}

		public function addGroups(array $groups): IDocumentAccess {
			return $this;
		}

		public function getGroups(): array {
			return [];
		}

		public function setCircles(array $circles): IDocumentAccess {
			return $this;
		}

		public function addCircle(string $circle): IDocumentAccess {
			return $this;
		}

		public function addCircles(array $circles): IDocumentAccess {
			return $this;
		}

		public function getCircles(): array {
			return [];
		}

		public function setLinks(array $links): IDocumentAccess {
			return $this;
		}

		public function getLinks(): array {
			return [];
		}
	}

	class IndexDocument implements IIndexDocument {
		public function __construct(
			protected string $providerId,
			protected string $id,
		) {
		}

		public function setId(string $id): IIndexDocument {
			return $this;
		}

		public function getId(): string {
			return '';
		}

		public function getProviderId(): string {
			return '';
		}

		public function setIndex($index): IIndexDocument {
			return $this;
		}

		public function getIndex() {
		}

		public function setAccess(IDocumentAccess $access): IIndexDocument {
			return $this;
		}

		public function getAccess(): IDocumentAccess {
		}

		public function setModifiedTime(int $modifiedTime): IIndexDocument {
			return $this;
		}

		public function getModifiedTime(): int {
			return 0;
		}

		public function setTitle(string $title): IIndexDocument {
			return $this;
		}

		public function getTitle(): string {
			return '';
		}

		public function setLink(string $link): IIndexDocument {
			return $this;
		}

		public function getLink(): string {
			return '';
		}

		public function setSource(string $source): IIndexDocument {
			return $this;
		}

		public function getSource(): string {
			return '';
		}

		public function setContent(string $content, int $encoded = 0): IIndexDocument {
			return $this;
		}

		public function getContent(): string {
			return '';
		}

		public function isContentEncoded(): int {
			return 0;
		}

		public function getContentSize(): int {
			return 0;
		}

		public function setHash(string $hash): IIndexDocument {
			return $this;
		}

		public function getHash(): string {
			return '';
		}

		public function setScore(string $score): IIndexDocument {
			return $this;
		}

		public function getScore(): string {
			return '0';
		}

		public function setParts(array $parts): IIndexDocument {
			return $this;
		}

		public function getParts(): array {
			return [];
		}

		public function addExcerpt(string $keyword, string $excerpt = ''): IIndexDocument {
			return $this;
		}
	}
}
