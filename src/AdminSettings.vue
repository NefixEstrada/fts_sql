<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-FileCopyrightText: 2026 Néfix Estrada
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - The FTS SQL admin card: server-rendered state through InitialState,
  - saved through the two settings endpoints. The ids, classes, accessible
  - labels and roles are the plain script's, kept one to one so the
  - Playwright suite that was written against it ("a Vue rebuild of the same
  - card would pass unchanged") passes without touching a locator.
  -
  - The chrome is the official admin-settings pattern (theming, oauth2,
  - sharebymail, the settings app's own AI page): NcSettingsSection for the
  - titled block, NcNoteCard for the state callout, NcTextField for the
  - labelled input, and grey <em> hints next to each control — the same
  - visual language as the fulltextsearch framework's own form above.
  -->
<template>
	<NcSettingsSection
		:name="t('fts_sql', 'SQL full-text search')"
		:description="t('fts_sql', 'Indexes and searches document content inside the database this instance already runs, with no external engine to set up.')">
		<NcNoteCard :type="stateType" class="fts_sql-admin__state" :class="'fts_sql-admin__state--' + card.state">
			<strong>{{ stateLabel }}.</strong>
			{{ card.message }}
		</NcNoteCard>

		<div v-if="flaggedTotal > 0" class="fts_sql-admin__causes">
			<p class="fts_sql-admin__causes-title">
				{{ t('fts_sql', 'Documents indexed with an extraction flag') }}: {{ flaggedTotal }}
			</p>
			<ul class="fts_sql-admin__causes-list">
				<li v-for="[token, count] of flaggedCauses" :key="token">
					{{ count }} × {{ causeLabel(token) }}
				</li>
			</ul>
			<em class="fts_sql-admin__warning">
				{{ t('fts_sql', 'Each is findable by what was recovered; the per-document message travels on its index entry.') }}
			</em>
		</div>

		<div class="fts_sql-admin__field">
			<NcSelect
				v-model="language"
				:options="languageOptions"
				label="label"
				:inputLabel="t('fts_sql', 'Text search language')"
				class="fts_sql-admin__language" />
			<em class="fts_sql-admin__warning">
				{{ t('fts_sql', 'Changing it later invalidates every indexed document: stemmers differ per language, so a reindex is mandatory.') }}
			</em>
		</div>

		<div class="fts_sql-admin__field">
			<NcTextField
				v-model.number="contentBytes"
				type="number"
				:label="t('fts_sql', 'Stored content per document (bytes)')" />
			<em class="fts_sql-admin__warning">
				{{ t('fts_sql', 'The budget is bytes of extracted text, not file size. Raising it does not retro-fill documents already indexed.') }}
			</em>
		</div>

		<div class="fts_sql-admin__actions">
			<NcButton variant="primary" type="button" @click="save">
				{{ t('fts_sql', 'Save') }}
			</NcButton>
			<span class="fts_sql-admin__status" role="status">
				{{ status }}
			</span>
		</div>
	</NcSettingsSection>
</template>

<script setup lang="ts">
import type { paths } from './types/openapi/openapi.js'

import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'

type CardState = {
	state: string
	message: string
	language: string
	languages: string[]
	contentBytes: number
	/** cause token ('budget cut', …) => flagged documents */
	causes: Record<string, number>
}

// loadState() reads what Admin::getForm() provided through IInitialState:
// no extra GET route, and the value never appears in the HTML.
const card = ref(loadState<CardState>('fts_sql', 'admin'))
const status = ref('')

const stateLabels: Record<string, string> = {
	ready: t('fts_sql', 'Ready'),
	engine: t('fts_sql', 'Engine not supported'),
	unusable: t('fts_sql', 'Engine cannot search'),
	missing: t('fts_sql', 'Search index missing'),
	stale: t('fts_sql', 'Index incomplete'),
}
const stateLabel = computed(() => stateLabels[card.value.state] ?? card.value.state)

// The official callout severities: one healthy state, four that need an
// admin repair. The class twin stays for the Playwright locators.
const stateTypes: Record<string, 'success' | 'error'> = {
	ready: 'success',
	engine: 'error',
	unusable: 'error',
	missing: 'error',
	stale: 'error',
}
const stateType = computed(() => stateTypes[card.value.state] ?? 'error')

// The tokens are ExtractionCause's values, verbatim from the database.
const causeLabels: Record<string, string> = {
	encrypted: t('fts_sql', 'encrypted'),
	unsupported: t('fts_sql', 'unsupported format'),
	'parser gave up': t('fts_sql', 'parser gave up'),
	'budget cut': t('fts_sql', 'cut at the content budget'),
	'engine ceiling': t('fts_sql', "cut at the engine's ceiling"),
}
const causeLabel = (token: string): string => causeLabels[token] ?? token

const flaggedCauses = computed(() => Object.entries(card.value.causes).filter(([, count]) => count > 0))
const flaggedTotal = computed(() => flaggedCauses.value.reduce((sum, [, count]) => sum + count, 0))

type LanguageOption = {
	id: string
	label: string
}

// The options are configuration names the server sent — what the running
// PostgreSQL itself reports — each named by its endonym, Nextcloud's own
// convention for language pickers: invariant of the viewer's locale, so
// the names carry no msgids. simple is not a language and has no endonym:
// it shows as its own name (what it does — no stemming — is the lexicon's
// to document), and a configuration this map does not know yet (estonian
// arrived with PostgreSQL 18) shows as its name too. All the endonyms are
// short enough to stay on one line: NcSelect splits long labels in two at
// their middle, mid-word, and the announced name would grow a space.
const endonyms: Record<string, string> = {
	arabic: 'العربية',
	armenian: 'Հայերեն',
	basque: 'Euskara',
	catalan: 'Català',
	danish: 'Dansk',
	dutch: 'Nederlands',
	english: 'English',
	estonian: 'Eesti',
	finnish: 'Suomi',
	french: 'Français',
	german: 'Deutsch',
	greek: 'Ελληνικά',
	hindi: 'हिन्दी',
	hungarian: 'Magyar',
	indonesian: 'Bahasa Indonesia',
	irish: 'Gaeilge',
	italian: 'Italiano',
	lithuanian: 'Lietuvių',
	nepali: 'नेपाली',
	norwegian: 'Norsk',
	portuguese: 'Português',
	romanian: 'Română',
	russian: 'Русский',
	serbian: 'Српски',
	spanish: 'Español',
	swedish: 'Svenska',
	tamil: 'தமிழ்',
	turkish: 'Türkçe',
	yiddish: 'ייִדיש',
}

/**
 * The endonym of a configuration name, or the name itself when there is
 * none to show.
 *
 * @param code the configuration name the server sent
 */
function languageLabel(code: string): string {
	return endonyms[code] ?? code
}

const languageOptions: LanguageOption[] = card.value.languages.map((code) => ({ id: code, label: languageLabel(code) }))

// NcSelect holds the whole option; what is saved is its id — the
// configuration name the server accepts. The stored language is always
// among the options (the read heals it), so the fallback is just a guard.
const language = ref<LanguageOption>(languageOptions.find((option) => option.id === card.value.language)
	?? { id: card.value.language, label: languageLabel(card.value.language) })
const contentBytes = ref(card.value.contentBytes)

/**
 * Save both settings, one PUT each, and report the outcome on the status line.
 */
async function save(): Promise<void> {
	status.value = ''
	try {
		await put('/ocs/v2.php/apps/fts_sql/settings/language', { language: language.value.id })
		await put('/ocs/v2.php/apps/fts_sql/settings/content-bytes', { contentBytes: contentBytes.value })
		status.value = t('fts_sql', 'Saved. A changed language needs occ fulltextsearch:reset && occ fulltextsearch:index.')
	} catch (error) {
		status.value = error instanceof Error ? error.message : String(error)
	}
}

// The settings endpoints openapi.json carries, as the spec spells them: a
// route that changes in the controller stops the build here, not an
// admin's save in the browser.
type SettingsRoute = Extract<keyof paths, `/ocs/v2.php/apps/fts_sql/settings/${string}`>

/** The JSON body each settings PUT takes, as the spec declares it. */
type SettingsBody<P extends SettingsRoute> = NonNullable<paths[P]['put']['requestBody']>['content']['application/json']

// The envelope every OCS endpoint answers with; a DataResponse error keeps
// its message in the data, the meta carries no message of its own.
type OcsEnvelope = {
	ocs?: { data?: { message?: string } }
}

/**
 * PUT one settings payload over OCS, wording the endpoint's own error
 * message. The spec's paths carry the /ocs/v2.php prefix generateOcsUrl
 * does not want.
 *
 * @param path the endpoint's full spec path
 * @param body the JSON payload the spec declares for it
 */
async function put<P extends SettingsRoute>(path: P, body: SettingsBody<P>): Promise<void> {
	try {
		await axios.put(generateOcsUrl(path.replace(/^\/ocs\/v2\.php/, '')), body, {
			headers: { 'OCS-APIRequest': 'true' },
		})
	} catch (error) {
		const response = (error as { response?: { data?: OcsEnvelope, status?: number } }).response
		throw new Error(response?.data?.ocs?.data?.message ?? `${path} answered ${response?.status ?? 'nothing'}`, { cause: error })
	}
}
</script>

<style scoped>
.fts_sql-admin__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	max-width: 360px;
	margin-block: calc(var(--default-grid-baseline) * 2);
}

.fts_sql-admin__warning {
	color: var(--color-text-maxcontrast);
}

.fts_sql-admin__causes-title {
	font-weight: bold;
}

.fts_sql-admin__causes-list {
	margin: 4px 0;
	padding-inline-start: 20px;
}

.fts_sql-admin__actions {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-block: calc(var(--default-grid-baseline) * 2);
}

.fts_sql-admin__status {
	color: var(--color-text-maxcontrast);
}
</style>
