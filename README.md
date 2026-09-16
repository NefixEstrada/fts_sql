<!--
SPDX-FileCopyrightText: 2026 Néfix Estrada

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# FTS SQL

A full text search platform for the [`fulltextsearch`][fts] framework that
indexes into the database the Nextcloud instance already runs — PostgreSQL,
MySQL/MariaDB or SQLite — so nobody has to operate Elasticsearch to get full
text search. The design lives in [`DESIGN.md`](DESIGN.md); read it first.

[fts]: https://github.com/nextcloud/fulltextsearch

## Tooling

Dependencies live in the Nix flake; work inside it:

```console
$ nix develop
$ composer install          # PHPUnit, Psalm, php-cs-fixer, nextcloud/ocp
$ vendor/bin/phpunit -c tests/phpunit.xml --testsuite unit
$ vendor/bin/psalm --no-cache
$ vendor/bin/php-cs-fixer fix --dry-run --diff
```

The shell ships PHP 8.2 — the manifest floor — with `intl` deliberately
removed (nothing may depend on it) and SQLite with FTS5 for experiments.

## Development instance

The instance runs on [nextcloud-docker-dev][docker-dev] (checkout outside
this repository), one Nextcloud 34 on PostgreSQL 16, with this repository
bind-mounted as `apps-extra/fts_sql`:

```console
$ cd /path/to/nextcloud-docker-dev
$ docker compose up -d stable34
$ ./scripts/occ.sh stable34 -- app:enable fts_sql     # runs pending migrations + the artefact repair step
$ ./scripts/occ.sh stable34 -- db:schema:export       # inspect the tables
```

Select the platform for the framework and run it end to end:

```console
$ ./scripts/occ.sh stable34 -- config:app:set fulltextsearch search_platform \
    --value 'OCA\FtsSql\Platform\SqlPlatform'
$ ./scripts/occ.sh stable34 -- fulltextsearch:index -r
$ ./scripts/occ.sh stable34 -- fulltextsearch:search <user> <needle>
$ ./scripts/occ.sh stable34 -- fulltextsearch:test   # the framework's own smoke test
```

After changing the `language` setting or a failed run, the remedy is always
`fulltextsearch:reset` (it asks for confirmation) followed by another index.

`fulltextsearch` and `files_fulltextsearch` (stable34 branches) sit next to it
in `apps-extra/`. The engine-specific overlay — PostgreSQL 16 instead of
`postgres:latest`, the bind mount — lives in the untracked
`docker-compose.override.yml`.

Run the whole suite, integration included, inside the container:

```console
$ docker exec -u www-data -w /var/www/html/apps-extra/fts_sql \
    master-stable34-1 phpunit -c tests/phpunit.xml
```

The web frontend answers at `http://stable34.local` (add it to `/etc/hosts`,
or curl with `-H 'Host: stable34.local'` against the proxy port). The
MariaDB, MySQL and SQLite legs of the integration matrix are not set up
locally (disk); they run in CI —
[`.github/workflows/tests.yml`](.github/workflows/tests.yml) installs a real
Nextcloud against all four engines from the official `continuous-integration-*`
images and runs both suites on each.

## Benchmark

The quality stage (DESIGN.md, "Background") indexes the fixed corpus —
5,000 Wikipedia opening paragraphs, a third each in Catalan, Spanish and
English, under `benchmark/corpus/` — through the platform's own interface
and scores query sets whose answers are known by construction: a
distinctive word of a document's title has to find that document in the
top ten, and a word the corpus does not hold has to find nothing.

```console
$ docker exec -u www-data <nextcloud-container> \
    php /var/www/html/apps-extra/fts_sql/benchmark/quality.php
```

It runs against whatever engine that instance uses, cleans up after
itself (everything is indexed under the `benchmark` provider and
removed), and ends with one JSON line — keep it under
`benchmark/results/` to compare a later change against today's
measurement (PostgreSQL 16: precision@10 0.9524, index 33 s).

[docker-dev]: https://github.com/nextcloud/nextcloud-docker-dev
