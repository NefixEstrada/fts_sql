<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Extraction\Pdf;

/**
 * The page walk's tally: pages seen, pages that could not be read,
 * runs shown in fonts no encoding decodes. One instance per
 * extraction, mutated as the walk goes, read once at the end to say
 * what the document is missing — indexed on what was recovered, with
 * the cause (DESIGN.md's representation of partial extraction).
 */
final class PdfWalkStats {
	public int $pages = 0;
	public int $unreadablePages = 0;
	public int $undecodableRuns = 0;
}
