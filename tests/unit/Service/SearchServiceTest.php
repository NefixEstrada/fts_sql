<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Unit\Service;

use OCA\FtsSql\Service\SearchService;
use PHPUnit\Framework\TestCase;

/**
 * The excerpt cut, identical on all engines by construction: 200 characters
 * of the stored content, starting 40 characters before the first
 * case-insensitive occurrence of the search text with the operators
 * stripped (DESIGN.md, "Worked example").
 */
class SearchServiceTest extends TestCase {

	public function testStartsFortyCharactersBeforeTheFirstOccurrence(): void {
		$content = str_repeat('a', 60) . 'museu' . str_repeat('b', 300);

		$excerpt = SearchService::excerpt($content, 'museu');

		$this->assertSame(mb_substr($content, 20, 200), $excerpt);
		$this->assertSame(200, mb_strlen($excerpt));
		$this->assertStringStartsWith(str_repeat('a', 40), $excerpt);
	}

	public function testTheOccurrenceIsCaseInsensitive(): void {
		$content = 'El Museu de Ciències té corrents';

		$this->assertSame(mb_substr($content, 0, 200), SearchService::excerpt($content, 'MUSEU'));
	}

	public function testOperatorsAreStrippedBeforeSearchingTheContent(): void {
		$content = str_repeat('x', 10) . 'sortida escolar' . str_repeat('y', 200);

		$excerpt = SearchService::excerpt($content, '+"sortida escolar"');

		$this->assertStringContainsString('sortida escolar', $excerpt);
	}

	public function testStartsAtTheBeginningWhenThereIsNoOccurrence(): void {
		$content = 'un contingut que no conté el terme';

		$this->assertSame($content, SearchService::excerpt($content, 'museu'));
	}

	public function testShortContentIsReturnedWhole(): void {
		$this->assertSame('curt', SearchService::excerpt('curt', 'curt'));
		$this->assertSame('', SearchService::excerpt('', 'museu'));
	}

	public function testTheOccurrenceIsAccentInsensitiveAndKeepsTheStoredAccents(): void {
		// The match itself folds: `ciencies` finds `ciències` in the index,
		// so the excerpt centres on the accented word — from the stored
		// text, whose accents survive the cut.
		$content = str_repeat('a', 60) . 'les ciències naturals' . str_repeat('b', 300);

		$excerpt = SearchService::excerpt($content, 'ciencies');

		$this->assertStringContainsString('ciències', $excerpt);
		$this->assertSame(mb_substr($content, 24, 200), $excerpt, 'forty characters before the accented occurrence, as with an exact one');
	}

	public function testAnAccentedQueryFindsThePlainOccurrence(): void {
		$content = str_repeat('a', 60) . 'les ciencies naturals' . str_repeat('b', 300);

		$this->assertStringContainsString('ciencies', SearchService::excerpt($content, 'ciències'));
	}

	public function testTheAccentInsensitivePatternLeavesRegexSyntaxOutOfTheNeedle(): void {
		$content = 'preu (final) del menú . extraordinarily . z';

		// ( ) and . are regex syntax; the needle must match them literally.
		$this->assertStringContainsString('(final) del menú', SearchService::excerpt($content, 'final del menu'));
		$this->assertStringContainsString('. extraordinarily .', SearchService::excerpt($content, 'extraordinarily'));
	}

	public function testAMultibyteOccurrenceBeforeTheWindowKeepsCharacterOffsets(): void {
		// à is two bytes: a byte-offset slip would cut mid-character or
		// shift the window.
		$content = str_repeat('à', 60) . 'museu' . str_repeat('é', 300);

		$excerpt = SearchService::excerpt($content, 'museu');

		$this->assertSame(mb_substr($content, 20, 200), $excerpt);
		$this->assertSame(200, mb_strlen($excerpt));
	}
}
