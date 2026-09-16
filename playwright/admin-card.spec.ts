/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The FTS SQL admin card in the browser: the visual layer no server-side
 * check can see — the script loading under the CSP, InitialState reaching
 * the page, the card's state line, and the settings round trip through the
 * two endpoints. The card is plain script on purpose; the locators use the
 * accessible labels the markup carries, so a Vue rebuild of the same card
 * would pass unchanged.
 */

import { expect, test } from '@playwright/test'

declare const OC: { generateUrl: (path: string) => string, requestToken: string }

const USER = process.env.NEXTCLOUD_USER ?? 'admin'
const PASSWORD = process.env.NEXTCLOUD_PASSWORD ?? 'admin'

async function login(page: import('@playwright/test').Page) {
	await page.goto('login')
	await page.locator('#user').fill(USER)
	await page.locator('#password').fill(PASSWORD)
	await page.locator('button[type="submit"]').click()
	await page.waitForURL(/apps|dashboard|files/)
	// The first-run wizard's overlay intercepts pointer events for a user
	// that never dismissed it; remove it once, harmlessly, the way its own
	// close button does.
	await page.evaluate(() => fetch(OC.generateUrl('/apps/firstrunwizard/wizard'), {
		method: 'DELETE',
		headers: { requesttoken: OC.requestToken },
	}))
}

async function openCard(page: import('@playwright/test').Page) {
	await login(page)
	await page.goto('settings/admin/fulltextsearch')
	const card = page.locator('#fts_sql-admin')
	await expect(card).toBeVisible()
	return card
}

test.beforeEach(async ({ page }) => {
	// Nothing the card does may fail on its own: no uncaught exception, no
	// failing request that belongs to this app.
	const errors: string[] = []
	page.on('pageerror', (error) => errors.push(`uncaught: ${error.message}`))
	page.on('response', (response) => {
		if (response.status() >= 400 && response.url().includes('fts_sql')) {
			errors.push(`${response.status()} ${response.url()}`)
		}
	})
	await openCard(page)
	await expect(page.locator('#fts_sql-admin .fts_sql-admin__state')).toBeVisible()
	expect(errors).toEqual([])
})

test('renders the Ready state naming the engine', async ({ page }) => {
	await expect(page.locator('.fts_sql-admin__state--ready')).toContainText('Ready')
	await expect(page.locator('.fts_sql-admin__state--ready')).toContainText('postgres')
})

test('carries both settings with their warnings next to the control', async ({ page }) => {
	await expect(page.getByLabel('Text search language')).toBeVisible()
	await expect(page.getByLabel('Stored content per document (bytes)')).toHaveValue('2097152')

	await expect(page.locator('.fts_sql-admin__warning').first())
		.toContainText('invalidates every indexed document')
})

test('saves the language and the budget, and they survive a reload', async ({ page }) => {
	const language = page.getByLabel('Text search language')
	const budget = page.getByLabel('Stored content per document (bytes)')

	await language.selectOption('catalan')
	await budget.fill('1048576')
	await page.getByRole('button', { name: 'Save' }).click()

	await expect(page.locator('.fts_sql-admin__status')).toContainText('Saved')

	await page.reload()
	await expect(page.locator('#fts_sql-admin')).toBeVisible()
	// Read back from what the page was served with, not from what we typed:
	// only the stored value explains a select that lands on the same option
	// after a fresh render.
	await expect(page.getByLabel('Text search language')).toHaveValue('catalan')
	await expect(page.getByLabel('Stored content per document (bytes)')).toHaveValue('1048576')

	// Leave the instance as it was found.
	await page.getByLabel('Text search language').selectOption('simple')
	await page.getByLabel('Stored content per document (bytes)').fill('2097152')
	await page.getByRole('button', { name: 'Save' }).click()
	await expect(page.locator('.fts_sql-admin__status')).toContainText('Saved')
})
