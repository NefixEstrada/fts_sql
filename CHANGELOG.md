<!--
SPDX-FileCopyrightText: 2026 Néfix Estrada

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Changelog

All notable changes to FTS SQL are documented here. The format follows
[Keep a Changelog]; the versions follow [Semantic Versioning].

## [1.0.0] - 2026-09-23

The first public release — milestones 1–4 of DESIGN.md.

### Added

- A full text search platform for the `fulltextsearch` framework that
  indexes into PostgreSQL 14+, MySQL/MariaDB and SQLite — the database
  the instance already runs, with no Elasticsearch to operate.
- Access decided by the share graph, fail-closed, through per-document
  access tokens: the same shares that decide who opens a file decide
  what search finds.
- Pure-PHP extraction over streams under a memory budget: plain text,
  OOXML (`.docx`, `.xlsx`, `.pptx`), ODF, a hand-written PDF stack (a
  ~70 MiB peak where the library route fatally needed 697.5 MiB), and
  the legacy Office formats (`.doc`, `.xls`, `.ppt`) through
  php-scoper-scoped PhpOffice readers.
- An admin card in the Nextcloud design system (Vue + TypeScript), with
  per-cause extraction counts; the unified-search excerpt centres on
  the matched word, and its Date chip narrows over the stored modified.
- The two settings endpoints are OCS (`/ocs/v2.php/apps/fts_sql/…`),
  with their contract in `openapi.json`: generated from the controllers
  by the official extractor, committed, and kept honest by a CI leg
  that regenerates it on every pull request. The same run generates the
  card's TypeScript types from the spec (`openapi-typescript`), so the
  endpoints it calls and the payloads it sends are compiler-checked.
- Catalan and Spanish translations of the admin card — the official
  l10n flow, with the Catalan reviewed by Softcatalà.
- A dark-theme app icon.
- CI on the official Nextcloud template set — patched like every app
  outside the org — with coverage on the SQLite leg and dependency
  automation (dependabot, weekly audit and `nextcloud/ocp` PRs).
- Benchmark stages with kept results: search quality over a fixed
  trilingual corpus, extraction peaks and speeds, scale (100k/1M) and
  access-filter correctness under load.
- The four-engine integration matrix — in CI and mirrored locally — and
  a Playwright suite over the admin card.
- A store manifest that says what ships: the full format list, the
  documentation URLs, the screenshots, the website and the repository.

[Keep a Changelog]: https://keepachangelog.com/en/1.1.0/
[Semantic Versioning]: https://semver.org/
