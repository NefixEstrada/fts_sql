<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Exceptions;

use Exception;

/**
 * getDocument() over a (provider, document) pair no stored row holds: the
 * answer `occ fulltextsearch:document:platform` surfaces by name, instead
 * of a bare \Exception its caller cannot tell from a bug.
 */
class UnknownDocument extends Exception {
}
