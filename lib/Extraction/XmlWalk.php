<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Extraction;

use XMLReader;

/**
 * The one XMLReader driver every extractor walks through, carrying the hard
 * caps DESIGN.md's Security section prescribes for hostile documents parsed
 * in-process: a depth cap, a wall-clock cap (the deadline is one per
 * document, started by the extractor, not per entry), malformed-XML
 * detection (read() returns false on a parse error exactly as at the end of
 * a clean document — libxml's error list is what tells them apart, checked
 * whenever the walk ran to its own end and not a full sink), and the XXE
 * posture: LIBXML_NOENT is never passed, so entities surface as
 * entity-reference nodes with an empty value instead of being substituted
 * (measured: neither an external file entity nor an internal billion-laughs
 * chain contributes anything), and the external entity loader is disabled
 * on top as defence in depth.
 *
 * How much of a malformed document is delivered before the error — and so
 * how much a full sink can rescue — is a libxml build property, not a
 * contract: some builds hand out nodes progressively, others validate each
 * chunk before releasing it. Both are safe here; what is guaranteed is the
 * abort, never garbage and never a crash.
 */
final class XmlWalk {
	public const MAX_DEPTH = 64;
	public const TIME_CAP = 10.0;

	private static bool $hardened = false;

	/**
	 * Feed every node to $onNode; returning false stops before the end (the
	 * sink is full) and suppresses the malformed check.
	 *
	 * @param callable(XMLReader $reader): bool $onNode
	 * @throws ExtractionAbort on a cap or malformed XML
	 */
	public static function each(string $xml, callable $onNode, float $deadline): void {
		if ($xml === '') {
			return;
		}
		self::harden();

		$reader = new XMLReader();
		libxml_clear_errors();
		if (!$reader->XML($xml, null, LIBXML_NONET)) {
			throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'the XML could not be opened');
		}

		$stopped = false;
		$nodes = 0;
		try {
			while ($reader->read()) {
				if ($reader->depth > self::MAX_DEPTH) {
					throw new ExtractionAbort(
						ExtractionCause::ParserGaveUp,
						sprintf('the XML nests deeper than %d levels', self::MAX_DEPTH),
					);
				}
				if (++$nodes % 1024 === 0 && microtime(true) > $deadline) {
					throw new ExtractionAbort(
						ExtractionCause::ParserGaveUp,
						sprintf('extraction passed its %.0f-second wall-clock cap', self::TIME_CAP),
					);
				}
				if ($onNode($reader) === false) {
					$stopped = true;
					break;
				}
			}

			if (!$stopped && libxml_get_errors() !== []) {
				$error = libxml_get_errors()[0];
				throw new ExtractionAbort(
					ExtractionCause::ParserGaveUp,
					'malformed XML: ' . trim($error->message),
				);
			}
		} finally {
			libxml_clear_errors();
			$reader->close();
		}
	}

	/**
	 * Walk $xml into $sink under one dialect's rules. Text nodes whose
	 * content is whitespace only become a single space: pretty-printed
	 * markup and an xml:space="preserve" run between two words are
	 * indistinguishable without a schema, and both want exactly one space,
	 * never the indentation run and never nothing.
	 *
	 * @throws ExtractionAbort on a cap or malformed XML
	 */
	public static function text(string $xml, TextSink $sink, XmlTextRules $rules, float $deadline): void {
		$skip = null;
		$inside = [];

		self::each($xml, function (XMLReader $reader) use ($sink, $rules, &$skip, &$inside): bool {
			if ($skip !== null) {
				if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth <= $skip) {
					$skip = null;
				}
				return !$sink->full();
			}

			switch ($reader->nodeType) {
				case XMLReader::ELEMENT:
					$name = $reader->localName;
					if ($rules->skips($name)) {
						// A self-closing element has no subtree to skip and
						// never reaches an end tag.
						$skip = $reader->isEmptyElement ? null : $reader->depth;
						break;
					}
					if (!$reader->isEmptyElement && $rules->isInside($name)) {
						$inside[] = $reader->depth;
					}
					$leaf = $rules->leaf($name);
					if ($leaf !== null) {
						$sink->accept($leaf);
					}
					break;

				case XMLReader::END_ELEMENT:
					if ($inside !== [] && $reader->depth === $inside[count($inside) - 1]) {
						array_pop($inside);
					}
					if ($rules->endsParagraph($reader->localName)) {
						$sink->accept("\n");
					}
					break;

				case XMLReader::TEXT:
				case XMLReader::CDATA:
				case XMLReader::WHITESPACE:
				case XMLReader::SIGNIFICANT_WHITESPACE:
					if (!$rules->wantsAllText() && $inside === []) {
						break;
					}
					$value = $reader->value;
					if ($reader->nodeType !== XMLReader::CDATA && trim($value) === '') {
						$value = ' ';
					}
					$sink->accept($value);
					break;
			}

			return !$sink->full();
		}, $deadline);
	}

	/**
	 * libxml state is process-wide and XMLReader populates the shared error
	 * list, so this is set once and never undone: internal errors are what
	 * keeps parse errors queryable instead of printed, and a null external
	 * entity loader makes every external entity unloadable even for code
	 * elsewhere in the process that does pass LIBXML_NOENT. There is no
	 * getter to save and restore the previous loader; blocking entities
	 * process-wide is the defence in depth, and the blocker is the point.
	 */
	private static function harden(): void {
		if (self::$hardened) {
			return;
		}
		libxml_use_internal_errors(true);
		libxml_set_external_entity_loader(static fn () => null);
		self::$hardened = true;
	}
}
