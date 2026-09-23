/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createAppConfig } from '@nextcloud/vite-config'

// One entry per script Nextcloud loads: the admin card. The output is
// js/fts_sql-admin.mjs plus css/fts_sql-admin.css (the app id is read from
// appinfo/info.xml), which is exactly what Util::addScript() and
// Util::addStyle() load — the .mjs is resolved before the legacy .js, so
// Admin.php's names stay as they are. The build empties js/ and css/ first,
// so the plain-JavaScript files the card started with are replaced by the
// built ones: there is no going back except through git.
export default createAppConfig({
	admin: 'src/admin.ts',
}, {
	emptyOutputDirectory: {
		additionalDirectories: ['css'],
	},
})
