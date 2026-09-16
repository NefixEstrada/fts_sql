<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Extraction\Pdf;

use OCA\FtsSql\Extraction\ExtractionAbort;
use OCA\FtsSql\Extraction\ExtractionCause;
use OCA\FtsSql\Extraction\TextSink;

/**
 * One page's content stream walked for the text it shows: the string
 * operands of Tj, TJ, ' and ", decoded through the font Tf selected,
 * with a newline where the positioning operators say a new line
 * begins. Everything else in the language — graphics state, paths,
 * images, marked content — is skipped by the same token walk that
 * reads the operands, so none of it is parsed into being.
 *
 * Form XObjects are followed, because plenty of real documents draw
 * their text through them: the recursion carries a visited set and a
 * depth cap, the same posture the design prescribes for object
 * graphs.
 */
final class PdfContent {
	public const MAX_FORM_DEPTH = 8;

	public bool $showedText = false;
	public int $undecodableRuns = 0;

	private string $lastEmit = '';
	/** @var list<mixed> */
	private array $operands = [];

	public function __construct(
		private readonly PdfDocument $document,
		private readonly TextSink $sink,
		private readonly float $deadline,
		private readonly int $streamCap,
	) {
	}

	/**
	 * @param array<string, PdfFont> $fonts the page's resources' fonts, by name
	 * @param array<string, mixed>|null $resources the page's effective resources
	 * @param array<int, true> $visited
	 */
	public function scan(string $bytes, array $fonts, ?array $resources, int $depth = 0, array $visited = []): void {
		if ($depth > self::MAX_FORM_DEPTH) {
			return;
		}

		$cursor = new PdfCursor($bytes);
		$currentFont = null;
		$checks = 0;

		while (!$cursor->eof()) {
			$cursor->skipSpace();
			if ($cursor->eof()) {
				break;
			}

			if ((++$checks & 0x3FF) === 0 && microtime(true) > $this->deadline) {
				throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'extraction ran past its time budget');
			}

			$byte = $bytes[$cursor->pos];
			if (($byte >= 'a' && $byte <= 'z') || ($byte >= 'A' && $byte <= 'Z') || $byte === '*' || $byte === "'" || $byte === '"') {
				$operator = $cursor->keyword();
				if ($operator === '') {
					$cursor->pos++;
					continue;
				}
				$this->operator($operator, $currentFont, $fonts, $resources, $depth, $visited);
				continue;
			}

			$at = $cursor->pos;
			$value = $cursor->value();
			if ($value === null && $cursor->pos === $at) {
				$cursor->pos++;
				continue;
			}
			if ($value !== null) {
				$this->operands[] = $value;
				if (count($this->operands) > 256) {
					array_shift($this->operands); // a hostile operand pile, not a real one
				}
			}
		}
	}

	/**
	 * @param array<string, PdfFont> $fonts
	 * @param array<string, mixed>|null $resources
	 * @param array<int, true> $visited
	 */
	private function operator(string $operator, ?PdfFont &$currentFont, array $fonts, ?array $resources, int $depth, array $visited): void {
		switch ($operator) {
			case 'Tf':
				$name = $this->operand(-2);
				$currentFont = $name instanceof PdfName ? ($fonts[$name->name] ?? null) : null;
				break;

			case 'Tj':
				$this->show($this->operand(-1), $currentFont);
				break;

			case "'":
				$this->newline();
				$this->show($this->operand(-1), $currentFont);
				break;

			case '"':
				$this->newline();
				$this->show($this->operand(-1), $currentFont);
				break;

			case 'TJ':
				$array = $this->operand(-1);
				if (is_array($array)) {
					foreach ($array as $part) {
						$this->show($part, $currentFont);
					}
				}
				break;

			case 'T*':
			case 'Td':
			case 'TD':
				$this->newline();
				break;

			case 'Do':
				$name = $this->operand(-1);
				if ($name instanceof PdfName) {
					$this->form($name->name, $resources, $fonts, $depth, $visited);
				}
				break;
		}

		$this->operands = [];
	}

	private function operand(int $fromEnd): mixed {
		$count = count($this->operands);
		$index = $count + $fromEnd;
		return $index >= 0 && $index < $count ? $this->operands[$index] : null;
	}

	private function show(mixed $value, ?PdfFont $font): void {
		if (!is_string($value)) {
			return;
		}

		// a content stream's strings are payload of the stream, not PDF
		// syntax strings: the stream's own decryption has already covered
		// them, and decrypting again would only corrupt them
		$decoded = $font?->decode($value) ?? '';
		if ($decoded === '') {
			if ($value !== '') {
				$this->undecodableRuns++;
			}
			return;
		}

		// control characters are never indexable text; one space each.
		// A map that produced invalid UTF-8 is substituted, never kept:
		// the stored text has to be valid whatever the font said.
		$replaced = preg_replace('/[^\P{C}\t\n\r]/u', ' ', $decoded);
		if ($replaced === null) {
			$replaced = mb_convert_encoding($decoded, 'UTF-8', 'UTF-8');
		}
		if ($replaced === false || $replaced === '') {
			return;
		}
		$decoded = $replaced;

		$this->sink->accept($decoded);
		$this->lastEmit = substr($decoded, -1);
		$this->showedText = true;
		if ($this->sink->full()) {
			throw new SinkFull();
		}
	}

	private function newline(): void {
		if ($this->lastEmit === "\n" || $this->lastEmit === '') {
			return;
		}
		$this->sink->accept("\n");
		$this->lastEmit = "\n";
	}

	/**
	 * A Form XObject's content, drawn with its own (or the page's)
	 * resources.
	 *
	 * @param array<string, PdfFont> $fonts
	 * @param array<string, mixed>|null $resources
	 * @param array<int, true> $visited
	 */
	private function form(string $name, ?array $resources, array $fonts, int $depth, array $visited): void {
		$xobjects = $this->document->resolve(is_array($resources) ? ($resources['XObject'] ?? null) : null);
		if (!is_array($xobjects)) {
			return;
		}
		$ref = $xobjects[$name] ?? null;
		if (!$ref instanceof PdfRef || isset($visited[$ref->object])) {
			return;
		}
		$visited[$ref->object] = true;

		$object = $this->document->getObject($ref->object);
		$dict = $object?->value;
		$subtype = is_array($dict) ? ($dict['Subtype'] ?? null) : null;
		$stream = $object?->stream;
		if (!is_array($dict) || !($subtype instanceof PdfName) || $subtype->name !== 'Form' || $stream === null) {
			return;
		}

		$innerResources = $this->document->resolve($dict['Resources'] ?? null);
		$innerResources = is_array($innerResources) ? $innerResources : $resources;
		$innerFonts = is_array($innerResources) ? $this->document->fonts($innerResources) : $fonts;

		$content = $this->document->decodeStreamData($dict, $stream, $this->streamCap, 'a form XObject', $ref);
		$this->scan($content, $innerFonts, $innerResources, $depth + 1, $visited);
	}
}
