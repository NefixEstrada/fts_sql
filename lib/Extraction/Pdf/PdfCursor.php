<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Extraction\Pdf;

/**
 * A cursor over PDF bytes: whitespace and comment skipping, literal
 * and hex strings with the specification's escapes, names with their
 * #xx escapes, numbers and indirect references, and the two composite
 * forms — all under one nesting cap, because a hostile document is
 * free to nest deeper than any honest one (DESIGN.md, Security: the
 * prescribed depth caps).
 *
 * Dicts become array<string, mixed> keyed by name; arrays become
 * list<mixed>; numbers int or float; true/false/null as themselves;
 * literal and hex strings raw byte strings; names PdfName and
 * references PdfRef, so a filter name is never confused with text.
 */
final class PdfCursor {
	public const MAX_DEPTH = 24;

	private int $depth = 0;

	public function __construct(
		public readonly string $data,
		public int $pos = 0,
	) {
	}

	public function eof(): bool {
		return $this->pos >= strlen($this->data);
	}

	public function skipSpace(): void {
		while ($this->pos < strlen($this->data)) {
			$byte = $this->data[$this->pos];
			if ($byte === ' ' || $byte === "\0" || $byte === "\t" || $byte === "\n" || $byte === "\f" || $byte === "\r") {
				$this->pos++;
				continue;
			}
			if ($byte === '%') {
				$end = strpos($this->data, "\n", $this->pos);
				$this->pos = ($end === false ? strlen($this->data) : $end + 1);
				continue;
			}
			return;
		}
	}

	/**
	 * A keyword's characters: operators in content streams, the obj,
	 * endobj, trailer and stream markers, true/false/null and R.
	 */
	public function keyword(): string {
		$this->skipSpace();
		$start = $this->pos;
		while ($this->pos < strlen($this->data)) {
			$byte = $this->data[$this->pos];
			if (($byte >= 'a' && $byte <= 'z') || ($byte >= 'A' && $byte <= 'Z') || $byte === '*' || $byte === "'" || $byte === '"') {
				$this->pos++;
				continue;
			}
			break;
		}
		return substr($this->data, $start, $this->pos - $start);
	}

	public function match(string $literal): bool {
		if (substr($this->data, $this->pos, strlen($literal)) !== $literal) {
			return false;
		}
		$this->pos += strlen($literal);
		return true;
	}

	/**
	 * One syntax value at the cursor; null when the bytes there are not
	 * one. The cursor is always advanced past what was read, so a null
	 * never loops.
	 */
	public function value(): mixed {
		$this->skipSpace();
		if ($this->eof() || $this->depth >= self::MAX_DEPTH) {
			return null;
		}

		$byte = $this->data[$this->pos];
		switch (true) {
			case $this->match('<<'):
				return $this->dictionary();
			case $byte === '[':
				$this->pos++;
				return $this->array();
			case $byte === '/':
				$this->pos++;
				return new PdfName($this->name());
			case $byte === '(':
				return $this->literalString();
			case $byte === '<':
				$this->pos++;
				return $this->hexString();
			case ($byte >= '0' && $byte <= '9') || $byte === '+' || $byte === '-' || $byte === '.':
				return $this->numberOrRef();
			default:
				return match ($this->keyword()) {
					'true' => true,
					'false' => false,
					'null' => null,
					default => null,
				};
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function dictionary(): ?array {
		$this->depth++;
		$dict = [];
		try {
			while (true) {
				$this->skipSpace();
				if ($this->match('>>')) {
					return $dict;
				}
				if ($this->eof() || $this->depth >= self::MAX_DEPTH) {
					return null;
				}
				if (!$this->match('/')) {
					// not a name where a key belongs: stop before the junk
					return $dict;
				}
				$key = $this->name();
				$dict[$key] = $this->value();
			}
		} finally {
			$this->depth--;
		}
	}

	/**
	 * @return list<mixed>|null
	 */
	private function array(): ?array {
		$this->depth++;
		$values = [];
		try {
			while (true) {
				$this->skipSpace();
				if ($this->match(']')) {
					return $values;
				}
				if ($this->eof() || $this->depth >= self::MAX_DEPTH) {
					return null;
				}
				$at = $this->pos;
				$value = $this->value();
				if ($value !== null || $this->pos !== $at) {
					if ($value !== null) {
						$values[] = $value;
					}
					continue;
				}
				$this->pos++; // skip the byte nobody recognises
			}
		} finally {
			$this->depth--;
		}
	}

	private function name(): string {
		$name = '';
		while ($this->pos < strlen($this->data)) {
			$byte = $this->data[$this->pos];
			if ($byte === '#') {
				$hex = substr($this->data, $this->pos + 1, 2);
				if (strlen($hex) === 2 && ctype_xdigit($hex)) {
					$name .= chr((int)hexdec($hex));
					$this->pos += 3;
					continue;
				}
			}
			if ($byte === ' ' || $byte === "\0" || $byte === "\t" || $byte === "\n" || $byte === "\f" || $byte === "\r"
				|| $byte === '/' || $byte === '[' || $byte === ']' || $byte === '(' || $byte === '<' || $byte === '>' || $byte === '%') {
				break;
			}
			$name .= $byte;
			$this->pos++;
		}
		return $name;
	}

	/**
	 * A literal string: bulk-copied between the bytes that matter —
	 * backslash, open and close paren — because content streams are
	 * mostly long plain spans and a per-byte walk costs the clock
	 * budget on exactly the documents that carry the most text.
	 */
	private function literalString(): string {
		$this->pos++; // (
		$out = '';
		$parens = 0;
		$len = strlen($this->data);
		while ($this->pos < $len) {
			$span = strcspn($this->data, '\\()', $this->pos);
			if ($span > 0) {
				$out .= substr($this->data, $this->pos, $span);
				$this->pos += $span;
			}
			if ($this->pos >= $len) {
				break;
			}

			$byte = $this->data[$this->pos];
			if ($byte === '\\') {
				$this->pos++;
				if ($this->pos >= $len) {
					break;
				}
				$out .= $this->escape();
				continue;
			}
			if ($byte === '(') {
				$parens++;
			} elseif ($byte === ')') {
				if ($parens === 0) {
					$this->pos++;
					break;
				}
				$parens--;
			}
			$out .= $byte;
			$this->pos++;
		}
		return $out;
	}

	private function escape(): string {
		$byte = $this->data[$this->pos];
		$this->pos++;
		if ($byte === 'n') {
			return "\n";
		}
		if ($byte === 'r') {
			return "\r";
		}
		if ($byte === 't') {
			return "\t";
		}
		if ($byte === 'b') {
			return "\b";
		}
		if ($byte === 'f') {
			return "\f";
		}
		if ($byte === "\r") {
			// a line continuation: swallow the \n of a \r\n pair too
			if (substr($this->data, $this->pos, 1) === "\n") {
				$this->pos++;
			}
			return '';
		}
		if ($byte === "\n") {
			return '';
		}
		if ($byte >= '0' && $byte <= '7') {
			$octal = $byte;
			while (strlen($octal) < 3 && $this->pos < strlen($this->data)
				&& $this->data[$this->pos] >= '0' && $this->data[$this->pos] <= '7') {
				$octal .= $this->data[$this->pos];
				$this->pos++;
			}
			return chr((int)octdec($octal));
		}
		return $byte;
	}

	private function hexString(): string {
		$hex = '';
		while ($this->pos < strlen($this->data)) {
			$byte = $this->data[$this->pos];
			if ($byte === '>') {
				$this->pos++;
				break;
			}
			if (ctype_xdigit($byte)) {
				$hex .= $byte;
			}
			$this->pos++;
		}
		if (strlen($hex) % 2 === 1) {
			$hex .= '0';
		}
		return pack('H*', $hex);
	}

	/**
	 * A number, or the "num gen R" indirect reference when the two
	 * numbers behind the cursor turn out to be one. When they do not,
	 * the cursor stops right after the first number — the second one
	 * belongs to whatever comes next.
	 */
	private function numberOrRef(): mixed {
		$first = $this->scanNumber();
		if (!is_int($first)) {
			return $first;
		}
		$afterNumber = $this->pos;

		$this->skipSpace();
		$second = $this->scanNumber();
		if (!is_int($second)) {
			$this->pos = $afterNumber;
			return $first;
		}

		$this->skipSpace();
		if ($this->keyword() === 'R' && $this->delimiterAhead()) {
			return new PdfRef($first, $second);
		}
		$this->pos = $afterNumber;
		return $first;
	}

	private function delimiterAhead(): bool {
		if ($this->eof()) {
			return true;
		}
		$next = $this->data[$this->pos];
		return $next === ' ' || $next === "\0" || $next === "\t" || $next === "\n" || $next === "\f" || $next === "\r"
			|| $next === '/' || $next === '[' || $next === ']' || $next === '(' || $next === '<' || $next === '>' || $next === '%';
	}

	private function scanNumber(): int|float|null {
		$start = $this->pos;
		while ($this->pos < strlen($this->data)) {
			$byte = $this->data[$this->pos];
			if (($byte >= '0' && $byte <= '9') || $byte === '+' || $byte === '-' || $byte === '.') {
				$this->pos++;
				continue;
			}
			break;
		}
		$text = substr($this->data, $start, $this->pos - $start);
		if ($text === '' || $text === '+' || $text === '-' || $text === '.') {
			return null;
		}
		if (str_contains($text, '.')) {
			return (float)$text;
		}
		return (int)$text;
	}
}
