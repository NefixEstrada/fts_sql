/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The FTS SQL admin card: server-rendered state through InitialState, saved
 * through the two settings endpoints. Plain script on purpose — the card is
 * the app's only frontend and stays build-free until it earns a build.
 */
window.addEventListener('DOMContentLoaded', () => {
	const root = document.getElementById('fts_sql-admin')
	if (!root) {
		return
	}

	const state = OCP.InitialState.loadState('fts_sql', 'admin')
	if (!state) {
		return
	}

	root.replaceChildren(
		stateLine(state.state, state.message),
		languageField(state.language, state.languages),
		budgetField(state.contentBytes),
		saveButton(),
		statusLine(),
	)

	function el(tag, attributes, ...children) {
		const node = document.createElement(tag)
		for (const [name, value] of Object.entries(attributes || {})) {
			if (name === 'text') {
				node.textContent = value
			} else {
				node.setAttribute(name, value)
			}
		}
		for (const child of children) {
			node.append(child)
		}
		return node
	}

	function stateLine(state, message) {
		const label = { ready: 'Ready', engine: 'Engine not supported', unusable: 'Engine cannot search', missing: 'Search index missing', stale: 'Index incomplete' }[state] || state
		return el('p', { class: 'fts_sql-admin__state fts_sql-admin__state--' + state },
			el('strong', { text: label + '. ' }),
			el('span', { text: message }),
		)
	}

	function languageField(language, languages) {
		const select = el('select', { id: 'fts_sql-language', class: 'fts_sql-admin__select' })
		for (const code of languages) {
			const option = el('option', { value: code, text: code })
			if (code === language) {
				option.selected = true
			}
			select.append(option)
		}
		return el('p', { class: 'fts_sql-admin__field' },
			el('label', { for: 'fts_sql-language', text: 'Text search language' }),
			select,
			el('span', { class: 'fts_sql-admin__warning', text: 'Changing it later invalidates every indexed document: stemmers differ per language, so a reindex is mandatory.' }),
		)
	}

	function budgetField(bytes) {
		return el('p', { class: 'fts_sql-admin__field' },
			el('label', { for: 'fts_sql-content-bytes', text: 'Stored content per document (bytes)' }),
			el('input', { id: 'fts_sql-content-bytes', class: 'fts_sql-admin__input', type: 'number', min: '1', value: String(bytes) }),
			el('span', { class: 'fts_sql-admin__warning', text: 'The budget is bytes of extracted text, not file size. Raising it does not retro-fill documents already indexed.' }),
		)
	}

	function saveButton() {
		const button = el('button', { class: 'fts_sql-admin__save button', type: 'button', text: 'Save' })
		button.addEventListener('click', save)
		return el('p', {}, button)
	}

	function statusLine() {
		return el('p', { class: 'fts_sql-admin__status', role: 'status' })
	}

	async function put(path, body) {
		const response = await fetch(OC.generateUrl(path), {
			method: 'PUT',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: OC.requestToken,
			},
			body: JSON.stringify(body),
		})
		if (!response.ok) {
			const data = await response.json().catch(() => ({}))
			throw new Error(data.message || (path + ' answered ' + response.status))
		}
		return response.json()
	}

	async function save() {
		const status = root.querySelector('.fts_sql-admin__status')
		const language = document.getElementById('fts_sql-language').value
		const contentBytes = Number(document.getElementById('fts_sql-content-bytes').value)
		status.textContent = ''
		try {
			await put('/apps/fts_sql/settings/language', { language })
			await put('/apps/fts_sql/settings/content-bytes', { contentBytes })
			status.textContent = 'Saved. A changed language needs occ fulltextsearch:reset && occ fulltextsearch:index.'
		} catch (error) {
			status.textContent = error.message
		}
	}
})
