/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The one Vite entry (see vite.config.ts): js/fts_sql-admin.mjs, loaded by
 * Admin::getForm() through Util::addScript(). It takes over the div the
 * server-rendered template leaves inside the framework's own Full text
 * search settings section.
 */

import { createApp } from 'vue'
import AdminSettings from './AdminSettings.vue'

createApp(AdminSettings).mount('#fts_sql-admin')
