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
 * An IDocumentAccess that maps to no access token at all: a document with no
 * owner at index time, or a viewer with no identity at search time. Refused
 * rather than searched with an empty token set, because a document nobody can
 * find is either useless or a permission bug that would otherwise pass
 * silently (DESIGN.md, "Access tokens") — and the filter has to fail closed.
 */
class AccessIsEmpty extends Exception {
}
