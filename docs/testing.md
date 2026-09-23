<!--
SPDX-FileCopyrightText: 2026 Néfix Estrada

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Testing

Three suites. The unit suite runs standalone inside the flake; the
integration suites need a booted Nextcloud — either the development
instance ([development.md](development.md)) or the four-engine matrix
below, the same legs CI runs; the browser suite is Playwright against the
development instance (`npx playwright test` from the repository — the
config maps `stable34.local` itself).

The unit gate, with the toolchain of
[development.md](development.md):

```console
$ nix develop
$ vendor/bin/phpunit -c tests/phpunit.xml --testsuite unit
```

## In the development instance

Run the whole suite, integration included, inside the container:

```console
$ docker exec -u www-data -w /var/www/html/apps-extra/fts_sql \
    master-stable34-1 phpunit -c tests/phpunit.xml
```

A suite run costs the instance its files: the server's
`Test\TestCase::tearDownAfterClass()` wipes `oc_storages` and
`oc_filecache` after every test class and then deletes the data
directory's "stray" files — the ones the emptied cache no longer knows.
So it is not only a container recreation that loses the test files; every
integration run does. The remedy is the same: recreate them under
`data/<user>/files/`, `occ files:scan --quiet <user>`, reset the index and
reindex. A search that comes back empty right after a suite is the
platform tables emptied by a tearDown while the framework's book still
marks everything indexed — `occ fulltextsearch:reset`, then index again.

## The engine matrix, locally

The `phpunit-*` workflow legs also run on this machine, mirrored by
`tests/integration-env.sh`: the same
`continuous-integration-*` images (under a compose project of the script's
own, never the dev instance's databases), a Nextcloud checkout cloned beside
this repository (`nextcloud-server-integration/`, created and marked by
the script, which refuses to touch a checkout it did not make —
`NEXTCLOUD_SERVER_PATH` moves it), the app copied in fresh from the working
tree on every run, and both suites per engine:

```console
$ nix develop
$ tests/integration-env.sh setup        # once: server + framework apps
$ tests/integration-env.sh run pgsql    # sqlite | pgsql | mariadb | mysql
$ tests/integration-env.sh run-all      # the four, with a summary
```

Every run reinstalls from this tree and resets its engine's container, so
nothing carries over between runs; the MySQL and MariaDB legs export the
root DSN (`FTS_SQL_MYSQL_ROOT_DSN`) the catalogue integration test
escalates with to reproduce a shared server. The mirror has already paid
for itself twice: it surfaced that a CLI install leaves `files_external`
unmigrated while the server's test bootstrap loads it — which broke any
test touching a real user folder, in CI just the same — and it is where
the catalogue's shared-server half runs for real.
