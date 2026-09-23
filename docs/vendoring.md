<!--
SPDX-FileCopyrightText: 2026 Néfix Estrada

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Bundled dependencies

Every runtime Composer dependency — `require` minus php and the
extensions — is rewritten under the app's own namespace
(`OCA\FtsSql\Vendor\…`) with [php-scoper][scoper], the pattern
`fulltextsearch_elasticsearch` already uses, and laid out in `lib/Vendor`
with namespace-shaped paths, where Nextcloud's own autoloader
(`OCA\FtsSql\` → `lib/`) serves it. No Composer autoloader loads at
runtime, and the unscoped originals are pruned from `vendor/`, so a
reference to an unprefixed namespace fails in development — where it is
seen — rather than shipping dead to production. Two runtime dependencies
ride today — `phpoffice/phpword` and `phpoffice/phppresentation`, the
`.doc`, `.xls` and `.ppt` readers — and the pipeline carries them scoped
into `lib/Vendor`.

The pipeline (`tools/scope-vendor.php`) runs after every
`composer install` and `update`, so development and release see the same
code shape; with no runtime dependency required it is a no-op that
needs neither php-scoper nor the network. Finders and pruning derive
from what composer.json actually requires, never a hand-kept list; a
package that is not pure PSR-4 fails the build loudly (it would ship but
never load); and php-scoper lives in its own composer bin
(`vendor-bin/php-scoper`, through the bin plugin) so it never mixes with
the app's dependencies.

Its proof needs no runtime dependency either:

```console
$ composer run test:scoping
```

builds a scratch composer project with a path-repository fixture — no
network — scopes it with the real prefix, and checks that the class
exists under `OCA\FtsSql\Vendor`, autoloads out of `lib/Vendor`, and
that the unprefixed original is gone; a fixture with a `files` autoload
must be refused. CI runs it on the static job. `make appstore` stages
composer.json, the tools and php-scoper into the build directory and
runs the pipeline there, so the tarball never depends on the state of
the working tree's `vendor/`; krankerl's `before_cmds` run
`composer install --no-dev` in the checkout for the same reason.

[scoper]: https://github.com/Humbug/php-scoper
