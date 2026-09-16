<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Tests\Unit\Extraction;

use OCA\FtsSql\Extraction\ExtractionAbort;
use OCA\FtsSql\Extraction\ExtractionCause;
use OCA\FtsSql\Extraction\TextSink;
use OCA\FtsSql\Extraction\XmlTextRules;
use OCA\FtsSql\Extraction\XmlWalk;
use PHPUnit\Framework\TestCase;

/**
 * XmlWalk's rules and hard caps against DESIGN.md's Security section: the
 * XXE posture (entities are never substituted — an external entity reads
 * nothing, an internal billion-laughs chain contributes nothing), the
 * nesting and malformed caps, and the whitespace rule (a whitespace-only
 * text node becomes exactly one space, indentation and xml:space preserve
 * runs alike).
 */
class XmlWalkTest extends TestCase {
	private const DEADLINE = 9e9;

	public function testParagraphEndsLeavesAndTextComeOutAsText(): void {
		$xml = '<d xmlns:w="urn:w"><w:p><w:r><w:t xml:space="preserve">foo </w:t></w:r><w:r><w:t>bar</w:t></w:r></w:p>'
			. '<w:p><w:tab/><w:t>amb tab</w:t><w:br/><w:t>i salt</w:t></w:p></d>';

		$this->assertSame("foo bar\n\tamb tab\ni salt\n", $this->walk($xml));
	}

	public function testWhitespaceOnlyTextNodesBecomeOneSpace(): void {
		// Inter-element indentation and a preserved run of spaces between
		// two words are indistinguishable without a schema, and both want
		// exactly one space: 'foo<ws>bar' and 'foo<ws-run>bar' alike.
		$indented = "<d xmlns:w=\"urn:w\"><w:p><w:t>foo</w:t>\n    <w:t>bar</w:t></w:p></d>";
		$preserved = '<d xmlns:w="urn:w"><w:p><w:t>foo</w:t><w:t xml:space="preserve"> </w:t><w:t>bar</w:t></w:p></d>';

		$this->assertSame("foo bar\n", $this->walk($indented));
		$this->assertSame("foo bar\n", $this->walk($preserved));
	}

	public function testSkippedSubtreesContributeNothing(): void {
		$xml = '<d xmlns:w="urn:w"><w:p><w:t>abans</w:t></w:p><w:skip><w:t>secret</w:t><w:p><w:t>més</w:t></w:p></w:skip>'
			. '<w:skip/><w:p><w:t>després</w:t></w:p></d>';

		$this->assertSame("abans\ndesprés\n", $this->walk($xml));
	}

	public function testInsideRestrictsTextNodesButNotLeaves(): void {
		$xml = '<d xmlns:w="urn:w"><w:out>sense</w:out><w:in><w:t>dins</w:t><w:tab/></w:in></d>';

		$this->assertSame("dins\t", $this->walk($xml, new XmlTextRules(
			skip: [],
			paragraphEnd: [],
			leaf: ['tab' => "\t"],
			inside: ['in'],
		)));
	}

	public function testAnExternalEntityReadsNothing(): void {
		$secret = tempnam(sys_get_temp_dir(), 'fts-secret-');
		file_put_contents($secret, 'SECRET-CONTENT-42');
		$xml = '<!DOCTYPE w:document [<!ENTITY xxe SYSTEM "file://' . $secret . '">]>'
			. '<w:document xmlns:w="urn:w"><w:p>abans &xxe; després</w:p></w:document>';

		try {
			$text = $this->walk($xml);
		} finally {
			@unlink($secret);
		}

		$this->assertStringNotContainsString('SECRET-CONTENT-42', $text);
		$this->assertStringContainsString('abans', $text);
	}

	public function testAnInternalEntityChainContributesNothing(): void {
		$xml = '<!DOCTYPE d [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">]>'
			. '<d>&b;final</d>';

		$this->assertSame('final', $this->walk($xml, self::plainRules()));
	}

	public function testCdataComesOutVerbatim(): void {
		$xml = '<d><![CDATA[ <b>&amp;</b>  ]]></d>';

		$this->assertSame(' <b>&amp;</b>  ', $this->walk($xml, self::plainRules()));
	}

	public function testNestingPastTheCapAborts(): void {
		$xml = str_repeat('<d>', XmlWalk::MAX_DEPTH + 2) . 'x' . str_repeat('</d>', XmlWalk::MAX_DEPTH + 2);

		$this->expectException(ExtractionAbort::class);
		$this->expectExceptionMessage('nests deeper than');
		$this->walk($xml, self::plainRules());
	}

	public function testMalformedXmlAbortsInsteadOfEndingQuietly(): void {
		// read() returns false on a parse error exactly as at a clean end of
		// document; libxml's error list is what tells them apart.
		$xml = '<d><p>text sensetancar</p>';

		try {
			$this->walk($xml, self::plainRules());
			$this->fail('malformed XML must abort');
		} catch (ExtractionAbort $abort) {
			$this->assertSame(ExtractionCause::ParserGaveUp, $abort->cause);
			$this->assertStringContainsString('malformed XML', $abort->getMessage());
		}
	}

	public function testAGarbageDocumentAborts(): void {
		$this->expectException(ExtractionAbort::class);
		$this->walk("\x01\x02not xml at all", self::plainRules());
	}

	public function testAnEmptyDocumentIsNothing(): void {
		$this->assertSame('', $this->walk('', self::plainRules()));
	}

	private function walk(string $xml, ?XmlTextRules $rules = null): string {
		$sink = new TextSink(1_000_000);
		XmlWalk::text($xml, $sink, $rules ?? self::paragraphRules(), self::DEADLINE);
		return $sink->text();
	}

	private static function paragraphRules(): XmlTextRules {
		return new XmlTextRules(
			skip: ['skip'],
			paragraphEnd: ['p'],
			leaf: ['tab' => "\t", 'br' => "\n"],
			inside: null,
		);
	}

	private static function plainRules(): XmlTextRules {
		return new XmlTextRules(skip: [], paragraphEnd: [], leaf: [], inside: null);
	}
}
