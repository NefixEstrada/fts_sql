/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createApp } from 'vue'
import App from './App.vue'

// The template renders <div id="fts_sql">; the Vue app takes it over.
createApp(App).mount('#fts_sql')
