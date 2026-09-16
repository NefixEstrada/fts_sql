<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Extraction\Ppt;

use OCA\FtsSql\Extraction\ExtractionAbort;
use OCA\FtsSql\Extraction\ExtractionCause;

/**
 * The PowerPoint 97 record layer this app owns, ahead of the PhpOffice
 * reader (DESIGN.md, Milestone 4). Two jobs, both about what a hostile
 * file must not cost:
 *
 * The Current User stream is read here because the reader answers
 * "encrypted" with the same bare exception it answers a dozen other
 * unimplemented features with; the token is a four-byte field, and
 * reading it here turns encryption into the Encrypted cause the other
 * formats already report.
 *
 * The document stream's record forest is bounds-checked here because
 * the reader's loops are driven by the record lengths the file itself
 * declares: a container claiming more bytes than the stream holds
 * turns a length-driven skip loop into a run past the end of the
 * string. Every record the reader can reach — from the user edit atom
 * the Current User stream names, through the persist directory, into
 * every container below — is checked against the bytes that actually
 * exist, so every loop the reader runs is bounded by the stream's real
 * size. The walk follows exactly the reader's own reachability, which
 * is what keeps a readable file with a garbage tail from being
 * refused: a record nothing reaches is never judged.
 */
final class PptRecords {
	private const TYPE_USER_EDIT_ATOM = 0x0FF5;
	private const TYPE_CURRENT_USER_ATOM = 0x0FF6;
	private const TYPE_PERSIST_DIRECTORY_ATOM = 0x1772;
	private const TYPE_DOCUMENT_CONTAINER = 0x03E8;
	private const TYPE_SLIDE_CONTAINER = 0x03EE;
	private const TYPE_NOTES_CONTAINER = 0x03F0;

	/** Containers the reader descends into, as record types the version nibble alone cannot name. */
	private const VERSIONED_CONTAINERS = [
		self::TYPE_DOCUMENT_CONTAINER,
		self::TYPE_SLIDE_CONTAINER,
		self::TYPE_NOTES_CONTAINER,
	];

	private const CONTAINER_VERSION = 0xF;
	private const MAX_DEPTH = 64;
	private const MAX_RECORDS = 250000;

	/**
	 * The CurrentUserAtom: whether the file is encrypted, and where the
	 * reader will start in the document stream.
	 *
	 * @return array{encrypted: bool, editOffset: int}
	 */
	public static function currentUserAtom(string $stream): array {
		$header = self::header($stream, 0);
		if ($header === null || $header['version'] !== 0 || $header['instance'] !== 0
			|| $header['type'] !== self::TYPE_CURRENT_USER_ATOM) {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'no CurrentUserAtom in the Current User stream');
		}
		if (strlen($stream) < 8 + 20) {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'the CurrentUserAtom is truncated');
		}
		$size = self::u32($stream, 8);
		if ($size !== 0x14) {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'the CurrentUserAtom has an unexpected size');
		}
		$token = self::u32($stream, 12);
		if ($token === 0xF3D1C4DF) {
			throw new ExtractionAbort(
				ExtractionCause::Encrypted,
				'the presentation is encrypted: indexed on title, access and tags only',
			);
		}
		return ['encrypted' => false, 'editOffset' => self::u32($stream, 16)];
	}

	/**
	 * Bounds-check every record the reader can reach from the user edit
	 * atom the Current User stream names, in the reader's own visiting
	 * order: user edit atom, persist directory, then each persisted
	 * object's record forest.
	 */
	public static function validateDocument(string $stream, int $editOffset): void {
		if ($editOffset < 0 || $editOffset + 8 > strlen($stream)) {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'the user edit atom sits past the end of the document stream');
		}
		$userEdit = self::header($stream, $editOffset);
		if ($userEdit === null || $userEdit['type'] !== self::TYPE_USER_EDIT_ATOM
			|| !in_array($userEdit['length'], [0x1C, 0x20], true)) {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'no user edit atom where the Current User stream points');
		}
		if ($editOffset + 8 + 0x1C > strlen($stream)) {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'the user edit atom is truncated');
		}
		// lastSlideIdRef(4) version(2) minorVersion(1) majorVersion(1)
		// offsetLastEdit(4) then the persist directory's offset
		$persistOffset = self::u32($stream, $editOffset + 8 + 12);

		$objects = self::persistedObjects($stream, $persistOffset);
		$seen = 0;
		foreach ($objects as $offset) {
			self::walk($stream, $offset, 0, $seen);
		}
	}

	/**
	 * The persist directory: the offsets of the persisted objects the
	 * reader dispatches on (document, slides, notes).
	 *
	 * @return list<int>
	 */
	private static function persistedObjects(string $stream, int $offset): array {
		if ($offset < 0 || $offset + 8 > strlen($stream)) {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'the persist directory sits past the end of the document stream');
		}
		$header = self::header($stream, $offset);
		if ($header === null || $header['version'] !== 0 || $header['instance'] !== 0
			|| $header['type'] !== self::TYPE_PERSIST_DIRECTORY_ATOM) {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'no persist directory where the user edit atom points');
		}
		$body = $offset + 8;
		$end = $body + $header['length'];
		if ($end > strlen($stream)) {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'the persist directory claims more bytes than the document stream holds');
		}

		$offsets = [];
		$at = $body;
		while ($at + 4 <= $end) {
			// each entry: a 20-bit persist id, a 12-bit count, then as
			// many 4-byte stream offsets as the count names
			$entry = self::u32($stream, $at);
			$at += 4;
			$count = ($entry >> 20) & 0xFFF;
			for ($i = 0; $i < $count; $i++) {
				if ($at + 4 > $end) {
					throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'the persist directory ends inside its own entry');
				}
				$object = self::u32($stream, $at);
				$at += 4;
				if ($object < 0 || $object + 8 > strlen($stream)) {
					throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'the persist directory names an object past the end of the document stream');
				}
				$offsets[] = $object;
			}
		}
		return $offsets;
	}

	/**
	 * One record: bounds-checked, and descended into when the reader
	 * descends. The budget counter bounds the walk itself on a forest
	 * of deliberately nested one-byte records.
	 */
	private static function walk(string $stream, int $offset, int $depth, int &$seen): void {
		if ($depth > self::MAX_DEPTH) {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'the record forest nests past its depth cap');
		}
		if (++$seen > self::MAX_RECORDS) {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'the record forest is larger than its record cap');
		}

		$header = self::header($stream, $offset);
		if ($header === null) {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'a record header sits past the end of the document stream');
		}
		$end = $offset + 8 + $header['length'];
		if ($end > strlen($stream)) {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'a record claims more bytes than the document stream holds');
		}

		$isContainer = $header['version'] === self::CONTAINER_VERSION
			|| in_array($header['type'], self::VERSIONED_CONTAINERS, true);
		if (!$isContainer) {
			return;
		}

		$at = $offset + 8;
		while ($at < $end) {
			self::walk($stream, $at, $depth + 1, $seen);
			$child = self::header($stream, $at);
			if ($child === null) {
				throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'a record header sits past the end of the document stream');
			}
			$at += 8 + $child['length'];
		}
	}

	/**
	 * @return null|array{version: int, instance: int, type: int, length: int}
	 */
	private static function header(string $stream, int $at): ?array {
		if ($at < 0 || $at + 8 > strlen($stream)) {
			return null;
		}
		$versionAndInstance = self::u16($stream, $at);
		return [
			'version' => $versionAndInstance & 0x0F,
			'instance' => $versionAndInstance >> 4,
			'type' => self::u16($stream, $at + 2),
			'length' => self::u32($stream, $at + 4),
		];
	}

	private static function u16(string $data, int $at): int {
		return ord($data[$at]) | ord($data[$at + 1]) << 8;
	}

	private static function u32(string $data, int $at): int {
		return ord($data[$at]) | ord($data[$at + 1]) << 8 | ord($data[$at + 2]) << 16 | ord($data[$at + 3]) << 24;
	}
}
