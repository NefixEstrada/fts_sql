<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The admin form. The current value is NOT printed here: AdminSettings::getForm()
 * provides it as initial state and the form's script (plain or Vue) fills it in, then
 * saves it with a PUT to /api/settings.
 */
?>
<div id="fts_sql_admin" class="section" data-testid="admin-section">
	<h2><?php p($l->t('FTS SQL')); ?></h2>
	<p><?php p($l->t('Admin settings rendered by an ISettings implementation.')); ?></p>
	<!-- The Vue build mounts on this element and replaces its content; the plain script fills it in. -->
	<div id="fts_sql_admin_root">
		<form id="fts_sql_admin_form" class="fts-sql__form">
			<input id="fts_sql_greeting" type="text" maxlength="100"
				aria-label="<?php p($l->t('Greeting')); ?>" placeholder="<?php p($l->t('Greeting')); ?>">
			<button type="submit" class="primary"><?php p($l->t('Save')); ?></button>
		</form>
		<p id="fts_sql_admin_status" data-testid="admin-status" aria-live="polite"></p>
	</div>
</div>
