# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-FileCopyrightText: 2026 Néfix Estrada
# SPDX-License-Identifier: AGPL-3.0-or-later

app_name := $(shell sed -n 's:.*<id>\(.*\)</id>.*:\1:p' appinfo/info.xml | head -n 1)
version := $(shell sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)
build_dir := build
artifact := $(build_dir)/artifacts/$(app_name)-$(version).tar.gz

.PHONY: all build check-manifest scope-selftest appstore certificate l10n ci-setup ci-engine ci-all clean

all: build

# The admin card's Vue frontend: built into js/ and css/ before anything is
# packaged or served, because the build empties both directories first and
# `make appstore` rsyncs from the working tree (the built files are not all
# tracked; see .gitignore).
build:
	npm ci
	npm run build

# Validate appinfo/info.xml against the app store schema (needs xmllint: libxml2-utils on
# Debian/Ubuntu, preinstalled on macOS). Also catches TODO placeholders left by rename.sh.
check-manifest:
	mkdir -p $(build_dir)
	curl -sSfo $(build_dir)/info.xsd https://apps.nextcloud.com/schema/apps/info.xsd
	xmllint --noout --schema $(build_dir)/info.xsd appinfo/info.xml

# The bundling pipeline's own proof (DESIGN.md, Milestone 3): a scratch
# composer project with a path-repository fixture is scoped with the app's
# real prefix, and the classes have to land where Nextcloud's autoloader
# serves them. Run it after `composer install` (it needs php-scoper).
scope-selftest:
	php tools/scope-selftest.php

# Release tarball: the app directory minus everything in .nextcloudignore, with the
# top-level directory named after the app id (what the app store and occ expect).
# Runtime dependencies are scoped inside the staged copy — the same post-install
# hook the dev tree runs — so the tarball never depends on the state of the
# working tree's vendor/: composer.json, the scoping tools and the php-scoper
# bin are staged in, `composer install --no-dev` runs the pipeline there, and
# the staging goes out again before the tar.
appstore: check-manifest build
	rm -rf $(build_dir)/$(app_name)
	mkdir -p $(build_dir)/artifacts $(build_dir)/$(app_name)
	rsync -a --exclude-from=.nextcloudignore ./ $(build_dir)/$(app_name)/
	rsync -a composer.json vendor-bin tools scoper.inc.php $(build_dir)/$(app_name)/
	composer install --no-dev -o --no-interaction --working-dir=$(build_dir)/$(app_name)
	rm -rf $(build_dir)/$(app_name)/composer.json $(build_dir)/$(app_name)/composer.lock \
		$(build_dir)/$(app_name)/vendor $(build_dir)/$(app_name)/vendor-bin \
		$(build_dir)/$(app_name)/tools $(build_dir)/$(app_name)/scoper.inc.php
	tar -czf $(artifact) -C $(build_dir) $(app_name)
	@echo "$(artifact)"
	@tar -tzf $(artifact) | grep -cE '\.(php|mjs|css|xml)$$' | xargs printf '%s runtime files packaged\n'

# The app store accepts only signed archives. The certificate comes from a PR
# to nextcloud/app-certificate-requests; this generates the key pair and the
# CSR that PR carries. The key lands in build/ — ignored by git — and never
# in the repository; see docs/releasing.md for the signing steps.
certificate:
	mkdir -p $(build_dir)
	openssl req -new -newkey rsa:4096 -nodes \
		-keyout $(build_dir)/$(app_name).key -out $(build_dir)/$(app_name).csr \
		-subj "/CN=$(app_name)"
	@echo "PR $(build_dir)/$(app_name).csr to nextcloud/app-certificate-requests; keep $(build_dir)/$(app_name).key secret"

# The official translation flow: the pot regenerated from the sources with
# the server's own tool (downloaded on first use — it lives in
# nextcloud/docker-ci now, not the server checkout), then the .po files of
# translationfiles/ converted into the l10n/ the tarball ships. Needs GNU
# gettext (xgettext) on PATH — the flake provides it.
L10N_TOOL ?= $(build_dir)/translationtool.phar
L10N_TOOL_URL ?= https://raw.githubusercontent.com/nextcloud/docker-ci/master/translations/translationtool/translationtool.phar
l10n:
	mkdir -p $(build_dir)
	[ -f $(L10N_TOOL) ] || curl -sfL $(L10N_TOOL_URL) -o $(L10N_TOOL)
	php $(L10N_TOOL) create-pot-files
	php $(L10N_TOOL) convert-po-files

# The engines matrix of .github/workflows/tests.yml, on this machine — the
# verbs of tests/integration-env.sh (run inside `nix develop`). setup once,
# then one engine (ENGINE=sqlite|pgsql|mariadb|mysql) or all four.
ci-setup:
	tests/integration-env.sh setup

ci-engine:
	tests/integration-env.sh run $(ENGINE)

ci-all:
	tests/integration-env.sh run-all

clean:
	rm -rf $(build_dir) node_modules vendor
