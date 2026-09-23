<!--
SPDX-FileCopyrightText: 2026 Néfix Estrada

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Translations

User-visible strings go through `t('fts_sql', …)` on the admin card and
`IL10N` behind the card's state messages and the repair step's warnings.
The flow is the official apps': the pot in `translationfiles/templates/`
is regenerated from the sources with the server's own
`translationtool.phar` (via `.l10nignore`, which keeps it out of
`vendor/` and the other non-app trees), the working `.po` files live in
`translationfiles/<lang>/` — **ca** and **es** are maintained here, the
app's own languages — and `l10n/<lang>.{js,json}`, what the tarball
ships and what both the PHP and the card read, is generated from them:

```console
$ nix develop
$ make l10n     # pot + l10n, in one go (downloads the tool on first use)
```

At runtime nothing else is needed: `Util::addScript` injects
`l10n/<lang>.js` for the app, and `IL10N` reads the same file — a user
with their language set to Catalan sees the whole card in Catalan
(`occ user:setting <user> core lang ca`, the key the language factory
reads). [Transifex][tx] (`.tx/config`) can take the `.po` files over
whenever a project exists there; until then they travel in this
repository.

[tx]: https://app.transifex.com/nextcloud/nextcloud/
