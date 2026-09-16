<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Extraction;

/**
 * The closed set of reasons an extraction is flagged, fixed now that
 * Milestone 2 is designed in detail (DESIGN.md, "Open issue: representing
 * partial extraction"): every gap is a cause to report on the document, not
 * a documented omission. From Milestone 2 on, what was recovered is indexed
 * and the per-cause message travels to the IIndex through addError().
 */
enum ExtractionCause: string {
	case Encrypted = 'encrypted';
	case Unsupported = 'unsupported';
	case ParserGaveUp = 'parser gave up';
	case BudgetCut = 'budget cut';
}
