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
 * ODF: .odt, .ods, .odp — one entry, content.xml, and the same walk for all
 * three, because ODF keeps body text in text:p and text:h whatever the
 * application: paragraphs of a writer document, cells of a spreadsheet,
 * frames of a presentation. Tabs, line breaks and space runs (text:s) are
 * characters; annotations and tracked changes are not body text.
 *
 * An ODF file saved with a password stays a zip, but its content.xml is
 * ciphertext declared in META-INF/manifest.xml — the manifest is checked
 * first so an encrypted document says so instead of dying on XML that will
 * not parse.
 */
final class OdfExtractor extends ContainerExtractor {
	private const MANIFEST_CAP = 1048576;

	public function owns(): array {
		return ['odt', 'ods', 'odp'];
	}

	protected function readEntries(ZipContainer $zip, string $extension, TextSink $sink, float $deadline, int $entryCap): void {
		if ($this->declaresEncryptedContent($zip, $deadline)) {
			throw new ExtractionAbort(
				ExtractionCause::Encrypted,
				'the document\'s manifest declares encrypted content: indexed on title, access and tags only',
			);
		}

		$xml = $zip->read('content.xml', $entryCap)
			?? throw new ExtractionAbort(ExtractionCause::ParserGaveUp, 'no content.xml in the container');

		XmlWalk::text($xml, $sink, new XmlTextRules(
			skip: ['annotation', 'tracked-changes'],
			paragraphEnd: ['p', 'h'],
			leaf: ['tab' => "\t", 'line-break' => "\n", 's' => ' '],
			inside: ['p', 'h'],
		), $deadline);
	}

	/**
	 * A manifest that cannot be parsed proves nothing either way: the
	 * answer is "not proven encrypted", and content.xml decides what the
	 * document is worth — the row keeps whatever its parse recovers.
	 */
	private function declaresEncryptedContent(ZipContainer $zip, float $deadline): bool {
		$manifest = $zip->read('META-INF/manifest.xml', self::MANIFEST_CAP);
		if ($manifest === null) {
			return false;
		}

		$encrypted = false;
		try {
			XmlWalk::each($manifest, static function (XMLReader $reader) use (&$encrypted): bool {
				if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'encryption-data') {
					$encrypted = true;
					return false;
				}
				return true;
			}, $deadline);
		} catch (ExtractionAbort) {
			return false;
		}
		return $encrypted;
	}
}
