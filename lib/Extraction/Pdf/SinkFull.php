<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Extraction\Pdf;

/**
 * Internal to the PDF scan: the sink is full, so the walk is over.
 * Thrown past the operand machinery and caught at the page loop —
 * reaching the budget is the normal way a long document ends, not a
 * failure.
 */
final class SinkFull extends \RuntimeException {
}
