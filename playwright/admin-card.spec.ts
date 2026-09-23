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

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

declare const OC: { generateUrl: (path: string) => string, requestToken: string }

const USER = process.env.NEXTCLOUD_USER ?? 'admin'
const PASSWORD = process.env.NEXTCLOUD_PASSWORD ?? 'admin'

async function login(page: Page) {
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

async function openCard(page: Page) {
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

// NcSelect is not a native select: open the combobox, then pick the option
// from the listbox it drops. Options are named by endonyms — locale
// invariant by design — and simple by its own name.
async function chooseLanguage(page: Page, label: string) {
	await page.getByLabel('Text search language').click()
	await page.getByRole('option', { name: label, exact: true }).click()
}

test('saves the language and the budget, and they survive a reload', async ({ page }) => {
	const budget = page.getByLabel('Stored content per document (bytes)')

	await chooseLanguage(page, 'Català')
	await budget.fill('1048576')
	await page.getByRole('button', { name: 'Save' }).click()

	await expect(page.locator('.fts_sql-admin__status')).toContainText('Saved')

	await page.reload()
	await expect(page.locator('#fts_sql-admin')).toBeVisible()
	// Read back from what the page was served with, not from what we typed:
	// only the stored value explains a select that lands on the same option
	// after a fresh render. NcSelect keeps its search input empty — the
	// selection renders as the chip beside it.
	await expect(page.locator('.fts_sql-admin__language .vs__selected')).toHaveText('Català')
	await expect(budget).toHaveValue('1048576')

	// Leave the instance as it was found.
	await chooseLanguage(page, 'simple')
	await budget.fill('2097152')
	await page.getByRole('button', { name: 'Save' }).click()
	await expect(page.locator('.fts_sql-admin__status')).toContainText('Saved')
})

test('offers and saves a configuration beyond the original four', async ({ page }) => {
	// The select's options come from InitialState as the configurations the
	// running PostgreSQL itself reports (pg_catalog.pg_ts_config, read
	// live), grown from simple/catalan/spanish/english to that set; prove
	// one of the newcomers end to end, named by its endonym.
	await chooseLanguage(page, 'Deutsch')
	await page.getByRole('button', { name: 'Save' }).click()

	await expect(page.locator('.fts_sql-admin__status')).toContainText('Saved')

	await page.reload()
	await expect(page.locator('#fts_sql-admin')).toBeVisible()
	await expect(page.locator('.fts_sql-admin__language .vs__selected')).toHaveText('Deutsch')

	// Leave the instance as it was found.
	await chooseLanguage(page, 'simple')
	await page.getByRole('button', { name: 'Save' }).click()
	await expect(page.locator('.fts_sql-admin__status')).toContainText('Saved')
})

test('shows no extraction flags when every document extracted whole', async ({ page }) => {
	// Decision (c) of DESIGN.md's "representing partial extraction": the
	// per-cause counts only render when a cause exists to name. This
	// instance's documents all extract whole, so the section stays absent —
	// the numbers themselves are the integration tier's to prove.
	await expect(page.locator('#fts_sql-admin .fts_sql-admin__causes')).toHaveCount(0)
})
