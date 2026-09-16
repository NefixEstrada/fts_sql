<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Extraction;

/**
 * What one XML dialect's body text looks like, as local names (the office
 * formats reuse the same few names under fixed namespaces, and a local name
 * match is what keeps the walker immune to prefix games).
 *
 *  - skip: subtrees whose text is not body text (OOXML field instructions,
 *    XHTML script/style, ODF annotations and tracked changes);
 *  - paragraphEnd: elements whose end tag ends a paragraph, emitted as "\n"
 *    (the one separator every engine's tokeniser understands);
 *  - leaf: elements that stand for a character (tab, line break, ODF's
 *    text:s space run), emitted at their start tag;
 *  - inside: when set, text nodes count only inside these elements —
 *    spreadsheets' inline strings live in is/t, ODF's body text in p/h —
 *    while leaves and paragraph ends keep working everywhere.
 */
final class XmlTextRules {
	/**
	 * @param list<string> $skip
	 * @param list<string> $paragraphEnd
	 * @param array<string, string> $leaf
	 * @param ?list<string> $inside
	 */
	public function __construct(
		public readonly array $skip,
		public readonly array $paragraphEnd,
		public readonly array $leaf,
		public readonly ?array $inside,
	) {
	}

	public function skips(string $name): bool {
		return in_array($name, $this->skip, true);
	}

	public function endsParagraph(string $name): bool {
		return in_array($name, $this->paragraphEnd, true);
	}

	public function leaf(string $name): ?string {
		return $this->leaf[$name] ?? null;
	}

	public function isInside(string $name): bool {
		return $this->inside !== null && in_array($name, $this->inside, true);
	}

	public function wantsAllText(): bool {
		return $this->inside === null;
	}
}
