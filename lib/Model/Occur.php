<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Model;

/**
 * How one query term combines with the rest — the Elasticsearch platform's
 * semantics, because that is the syntax users already have in their fingers:
 * Must terms have to match, MustNot terms must not, and Should terms are
 * optional and ranked. By default a query is an OR over its Should terms.
 */
enum Occur {
	case Should;
	case Must;
	case MustNot;
}
