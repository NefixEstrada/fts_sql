<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Unit\Model;

use OCA\FtsSql\Model\Occur;
use OCA\FtsSql\Model\SearchQuery;
use PHPUnit\Framework\TestCase;

/**
 * SearchQuery::parse against the syntax DESIGN.md specifies — including the
 * worked example under "Query syntax", term by term.
 */
class SearchQueryTest extends TestCase {

	public function testParsesWordsAsOptionalTerms(): void {
		$query = SearchQuery::parse('corrents riu');

		$this->assertSame(['corrents', 'riu'], array_map($this->value(...), $query->getTerms()));
		$this->assertFalse($query->isEmpty());
		foreach ($query->getTerms() as $term) {
			$this->assertSame(Occur::Should, $term->occur);
			$this->assertFalse($term->phrase);
		}
	}

	public function testParsesRequiredAndExcludedOperators(): void {
		$query = SearchQuery::parse('+museu -pis');

		$this->assertSame(Occur::Must, $query->getTerms()[0]->occur);
		$this->assertSame('museu', $query->getTerms()[0]->value);
		$this->assertSame(Occur::MustNot, $query->getTerms()[1]->occur);
		$this->assertSame('pis', $query->getTerms()[1]->value);
	}

	public function testParsesQuotedPhrasesWithCollapsedWhitespace(): void {
		$query = SearchQuery::parse('"sortida   escolar"');

		$term = $query->getTerms()[0];
		$this->assertTrue($term->phrase);
		$this->assertSame('sortida escolar', $term->value);
		$this->assertSame(Occur::Should, $term->occur);
	}

	public function testOperatorsApplyToPhrasesToo(): void {
		$query = SearchQuery::parse('-"sortida escolar"');

		$term = $query->getTerms()[0];
		$this->assertTrue($term->phrase);
		$this->assertSame(Occur::MustNot, $term->occur);
	}

	public function testParsesTheWorkedExampleFromTheDesign(): void {
		$query = SearchQuery::parse('+museu "sortida escolar" -pis mun');

		$terms = $query->getTerms();
		$this->assertCount(4, $terms);

		[$museu, $phrase, $pis, $mun] = $terms;
		$this->assertSame('museu', $museu->value);
		$this->assertFalse($museu->phrase);
		$this->assertSame(Occur::Must, $museu->occur);

		$this->assertSame('sortida escolar', $phrase->value);
		$this->assertTrue($phrase->phrase);
		$this->assertSame(Occur::Should, $phrase->occur);

		$this->assertSame('pis', $pis->value);
		$this->assertSame(Occur::MustNot, $pis->occur);

		$this->assertSame('mun', $mun->value);
		$this->assertSame(Occur::Should, $mun->occur);

		$this->assertSame('mun', $query->getPrefixCandidate()?->value);
	}

	public function testPrefixCandidateIsTheLastUnquotedOptionalTerm(): void {
		// A quoted Should term after it does not steal the prefix: phrases
		// never take one.
		$query = SearchQuery::parse('mun "sortida escolar"');

		$this->assertSame('mun', $query->getPrefixCandidate()?->value);
	}

	public function testNoPrefixCandidateWithoutUnquotedOptionalTerms(): void {
		$query = SearchQuery::parse('+museu -pis "sortida escolar"');

		$this->assertNull($query->getPrefixCandidate());
	}

	public function testCapsAt64TermsTruncatingFromTheFront(): void {
		$words = array_map(fn (int $i): string => "w$i", range(0, 69));
		$query = SearchQuery::parse(implode(' ', $words));

		$terms = $query->getTerms();
		$this->assertCount(SearchQuery::MAX_TERMS, $terms);
		// The tail survives: it carries what the user is typing and the term
		// the prefix match is taken from.
		$this->assertSame('w6', $terms[0]->value);
		$this->assertSame('w69', $terms[63]->value);
		$this->assertSame('w69', $query->getPrefixCandidate()?->value);
	}

	public function testInvalidUtf8IsSubstitutedNeverDropped(): void {
		// \xC3 followed by '(' is not valid UTF-8; mb substitutes the bad
		// sequence with '?' and keeps the valid '(' instead of letting preg
		// /u match nothing at all, so the term — and with it the match
		// predicate — survives (DESIGN.md, Security).
		$query = SearchQuery::parse("a\xC3\x28b");

		$terms = $query->getTerms();
		$this->assertCount(1, $terms);
		$this->assertSame('a?(b', $terms[0]->value);
	}

	public function testAQueryOfNothingButInvalidBytesStillHasATerm(): void {
		$query = SearchQuery::parse("\x80");

		$this->assertFalse($query->isEmpty());
		$this->assertSame('?', $query->getTerms()[0]->value);
	}

	public function testEmptyAndWhitespaceOnlyQueriesParseToNothing(): void {
		$this->assertTrue(SearchQuery::parse('')->isEmpty());
		$this->assertTrue(SearchQuery::parse("  \t\n ")->isEmpty());
	}

	public function testAnUnclosedQuoteIsALiteralNotAPhrase(): void {
		$query = SearchQuery::parse('foo"bar');

		$term = $query->getTerms()[0];
		$this->assertFalse($term->phrase);
		$this->assertSame('foo"bar', $term->value);
	}

	private function value(object $term): string {
		return $term->value;
	}
}
