<!--
SPDX-FileCopyrightText: 2026 Néfix Estrada

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Releasing

`make appstore` builds the tarball (krankerl, with the same
`before_cmds`, does the same). The app store only accepts signed
archives, and the certificate is the slow part — it is issued after a PR
to [nextcloud/app-certificate-requests][certs] verifies the author's
identity. `make certificate` generates the key pair and the CSR that PR
carries; the key stays in `build/`, which git ignores, and never enters
the repository.

Once the certificate is at hand, sign the staged app and re-pack:

```console
$ make appstore
$ tar -xzf build/artifacts/fts_sql-1.0.0.tar.gz -C build
$ occ integrity:sign-app --privateKey=build/fts_sql.key \
    --certificate=fts_sql.crt --path=build/fts_sql
$ tar -czf build/artifacts/fts_sql-1.0.0.tar.gz -C build fts_sql
```

`occ` is any Nextcloud checkout's — the command only needs its own
implementation. Losing the private key means a new certificate; signing
is also what guards the tarball's integrity afterwards, so every release
after the first signed one must be signed too.

## Publishing to the app store

The store reads the changelog from `CHANGELOG.md` **inside the archive**
(that is why `.nextcloudignore` keeps it in), and a release is the
signed tarball plus a detached signature of the tarball itself:

```console
$ openssl dgst -sha512 -sign build/fts_sql.key \
    build/artifacts/fts_sql-1.0.0.tar.gz | base64 -w0
```

Register the app once at <https://apps.nextcloud.com/developer/apps>:
the certificate, and a signature of the app id made with the same key —
`printf '%s' fts_sql | openssl dgst -sha512 -sign build/fts_sql.key |
base64 -w0`. Then upload the release (the web form or the API) with the
tarball's download URL — conventionally the `v1.0.0` release asset of
this repository, which is why the screenshots and the documentation
links in `appinfo/info.xml` must resolve from `main` — and the detached
signature.

[certs]: https://github.com/nextcloud/app-certificate-requests
