<!--
SPDX-FileCopyrightText: 2026 Néfix Estrada

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Administration

What the administrator of a live instance needs: removing the app without
leaving data behind, and what an upgrade of the Nextcloud server touches.
The architecture and the decisions are [DESIGN.md](../DESIGN.md)'s.

## Uninstalling

Removing the app (`occ app:remove fts_sql`) leaves its data in place: the
framework's bookkeeping tables and this app's index — which holds a
plaintext copy of every indexed document (DESIGN.md, privacy: a row lives
until the framework deletes the document). While the app is still
enabled, the index itself is emptied by the framework's own command (it
asks for confirmation):

```console
$ ./scripts/occ.sh stable34 -- fulltextsearch:reset
```

The tables and the engine artefacts need the database (replace `oc_` with
the instance's prefix); on SQLite, drop the virtual table and the
triggers before the documents table they read from:

```sql
DROP TABLE oc_fts_sql_access;
DROP TABLE oc_fts_sql_tags;
-- SQLite only, and first:
-- DROP TABLE oc_fts_sql_fts;
-- DROP TRIGGER oc_fts_sql_ai; DROP TRIGGER oc_fts_sql_ad; DROP TRIGGER oc_fts_sql_au;
DROP TABLE oc_fts_sql_documents;
```

On PostgreSQL and MySQL everything else — the `content_tsv` column, the
GIN and FULLTEXT keys, the `_norm` mirrors — belongs to the documents
table and goes with it.

## Failed documents

Indexing is priced per document: a document whose extraction or write
fails keeps its error and is recorded as failed, so one bad file never
costs the indexing run. The cost of that is that the framework also
keeps error-carrying documents out of the queue until the errors are
cleared — after fixing the cause (a corrupt file, a full disk, a
database blip), re-admit them with:

```console
$ occ fulltextsearch:index --errors reset
```

A `fulltextsearch:reset` followed by a full re-index does the same,
less surgically.

## Upgrading Nextcloud

Three deliberate dependencies on the supported server majors are
watched by tests, so an upgrade that breaks any of them fails loudly in
the integration suite instead of silently in production:

- **The streaming fast path** listens on `GenericEvent` delivered by
  class name, because that is how files_fulltextsearch 34 and 35 fire
  their extension events (subject-name listeners never fire; measured
  against 34.0.4, read off 35's ExtensionService). `tests/integration/Listener/FilesIndexingListenerTest.php`
  dispatches the provider's own event, the same way, against a real node.
- **The platform adapter** rebuilds `OC\FullTextSearch\Model\*` objects —
  server-internal classes `OCP` does not carry — the same de-facto route
  `fulltextsearch_elasticsearch` takes. `getDocument()` and the search
  path exercise them end to end.
- **SQLite's FTS5 columns** introspect as an unknown Doctrine type, which
  `Application::boot()` maps once per request while this app is enabled —
  the schema tests catch a Doctrine change breaking that mapping.

On a new server major, run the integration suite against it before
rolling out; the app's manifest pins the majors it has been measured on.
