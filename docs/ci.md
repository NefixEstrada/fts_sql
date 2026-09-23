<!--
SPDX-FileCopyrightText: 2026 Néfix Estrada

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Continuous integration

`.github/workflows/` carries the official Nextcloud template set — the same
files spreed or forms run — copied from
[nextcloud/.github](https://github.com/nextcloud/.github) and adapted the way
every app outside the nextcloud org has to (LibreSign is the reference):
`ubuntu-latest-low` becomes `ubuntu-latest`, and this app's needs live in
`<workflow>.yml.patch` files the weekly sync reapplies. What the patches
carry: the fulltextsearch framework checkouts and enables, `files_external`
(a CLI install leaves it disabled while the test bootstrap loads it), and
the root DSN the catalogue integration test escalates with. The set: the
lint family (`lint-php`, `lint-php-cs`, `lint-eslint`, `lint-stylelint`,
`lint-info-xml`, `lint-typescript`), `npm-build`, `psalm`, `openapi`
(regenerates `openapi.json` from the controllers and fails on drift —
carried unpatched, like `psalm`), the four
`phpunit-*` engine legs, `fixup`, `block-unconventional-commits`, `reuse`,
and two bespoke ones — `e2e-test` (Playwright against a real instance, the
pattern calendar and contacts use for the same need) and `vendor-scoping`
(the Milestone 3 proof plus `composer validate`, which no template covers).

`sync-workflow-templates.yml` keeps them current: every Sunday it compares
each carried template against `.github/actions-lock.txt`, copies what
changed upstream, reapplies the patches and opens a PR. Opening PRs that
modify workflows needs a PAT with `workflow` scope stored as the
`COMMAND_BOT_WORKFLOWS` secret — until it exists, that weekly run fails
harmlessly and the templates update the way they were placed: by hand.

Beside the set, the same automation the official apps run:
`update-nextcloud-ocp` (weekly PR moving the `nextcloud/ocp` dev
dependency — to the branch the *manifest* pins, not master; the
org-locked guard the template ships is stripped, LibreSign-style) and
`npm-audit-fix` (weekly audit PR) — both open their PRs through the
`COMMAND_BOT_PAT` secret, the same caveat as above. Dependabot
(`.github/dependabot.yml`) keeps composer and npm fresh; it
deliberately does not touch `github-actions`, whose pins travel with
the templates the sync manages. The SQLite phpunit leg carries
coverage (pcov + clover, uploaded to Codecov), the forms pattern; the
app's own regression tracking remains the benchmark stages
([benchmark.md](benchmark.md)).
