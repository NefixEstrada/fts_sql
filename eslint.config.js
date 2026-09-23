/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The unified Nextcloud eslint config (the coding standards every app and
 * library shares), over the sources: src/, the Vite config and the Playwright
 * suite. What the build writes (js/, css/), what it never reads
 * (lib/Vendor's scoped copies, vendor/, build/) and what openapi-typescript
 * generates stay out of it.
 */
import { recommended } from '@nextcloud/eslint-config'

export default [
	...recommended,
	{
		ignores: [
			'js/**',
			'css/**',
			'build/**',
			'lib/**',
			'vendor/**',
			'node_modules/**',
			'playwright-report/**',
			'test-results/**',
			'src/types/openapi/**',
		],
	},
	// Deprecated Nextcloud API is an error in application code, the
	// hardening the config's own README suggests; the spec-file exception
	// below still applies.
	{
		files: ['src/**', 'vite.config.ts'],
		rules: {
			'@nextcloud/no-deprecated-globals': 'error',
		},
	},
	// The Playwright suite drives the page through its own globals on
	// purpose (page.evaluate speaks the page's OC.*), which is exactly what
	// no-deprecated-globals exists to keep out of application code.
	{
		files: ['playwright/**/*.ts'],
		rules: {
			'@nextcloud/no-deprecated-globals': 'off',
		},
	},
]
