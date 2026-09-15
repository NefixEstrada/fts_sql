<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-FileCopyrightText: 2026 Néfix Estrada
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<form class="fts-sql__form" @submit.prevent="save">
			<NcTextField v-model="greeting" :label="t('fts_sql', 'Greeting')" maxlength="100" />
			<NcButton type="submit" variant="primary">
				{{ t('fts_sql', 'Save') }}
			</NcButton>
		</form>
		<p data-testid="admin-status" aria-live="polite">{{ status }}</p>
	</div>
</template>

<script setup>
import { ref } from 'vue'
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'

// loadState() reads what AdminSettings::getForm() provided through IInitialState:
// no extra GET route, and the value never appears in the HTML.
const greeting = ref(loadState('fts_sql', 'greeting'))
const status = ref('')

async function save() {
	try {
		const response = await axios.put(generateUrl('/apps/fts_sql/api/settings'), { greeting: greeting.value })
		status.value = `Saved: ${response.data.greeting}`
	} catch (error) {
		status.value = `Error: ${error.response?.data?.error ?? error.message}`
	}
}
</script>

<style scoped>
.fts-sql__form {
	display: flex;
	gap: 8px;
	align-items: center;
	max-width: 400px;
}

.fts-sql__form button {
	flex-shrink: 0;
}
</style>
