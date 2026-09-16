/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineConfig, devices } from '@playwright/test'

// Point the tests at an existing Nextcloud that has this app installed:
//   PLAYWRIGHT_BASE_URL=http://stable34.local npx playwright test
// The default matches the dev environment from the nextcloud-dev-setup skill.
const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://stable34.local'

export default defineConfig({
	testDir: './playwright',
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 2 : 0,
	reporter: process.env.CI ? [['dot'], ['github']] : [['list']],
	// The admin-card tests read and write shared admin settings; parallel
	// workers would race each other.
	workers: 1,
	use: {
		// Nextcloud serves its routes under /index.php/, so keeping it in the base URL
		// lets specs use relative paths like 'apps/fts_sql/'.
		baseURL: baseURL + '/index.php/',
		trace: 'on-first-retry',
		video: 'on-first-retry',
		ignoreHTTPSErrors: true,
		// The dev instance's hostname resolves through the proxy on 127.0.0.1;
		// /etc/hosts is not editable in every environment this suite runs in,
		// and Chromium can map it itself.
		launchOptions: {
			args: [`--host-resolver-rules=MAP ${new URL(baseURL).hostname} 127.0.0.1`],
		},
	},
	projects: [
		{ name: 'chromium', use: { ...devices['Desktop Chrome'] } },
	],
})
