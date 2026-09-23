#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Néfix Estrada
#
# SPDX-License-Identifier: AGPL-3.0-or-later

# The engines job of .github/workflows/tests.yml, on this machine: a real
# Nextcloud installed from this working tree against SQLite, PostgreSQL 16,
# MariaDB 11.4 or MySQL 8.4 — the same images, ports and install arguments
# the workflow uses — running both suites. Nothing here verifies by pushing:
# the whole point is that the matrix runs before that.
#
#   tests/integration-env.sh setup           clone or refresh the server checkout
#   tests/integration-env.sh run <engine>    install one engine, run both suites
#   tests/integration-env.sh run-all         every engine in turn
#
# Run it inside the flake (`nix develop`): it needs php, composer, git, rsync
# and flock on PATH. NEXTCLOUD_SERVER_PATH moves the checkout (default: a
# sibling of this repository).
#
# The app reaches the checkout as a COPY, refreshed on every run — not a
# symlink — because the workflow's own dependency step mutates the app
# (`composer remove nextcloud/ocp --dev`) and that must not touch this
# working tree. The refresh is rsync --delete against the tree, so the tier
# always runs what is here, never a stale duplicate of it.

set -euo pipefail

APP_ID=fts_sql
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SERVER_PATH="${NEXTCLOUD_SERVER_PATH:-$(dirname "$APP_DIR")/nextcloud-server-integration}"

# Pinned so the container reset below only ever touches containers this
# script created: compose selects by project+service labels, not by which
# file it was pointed at, so an unpinned name could aim `rm --volumes` at an
# unrelated project's postgres.
COMPOSE_PROJECT=fts_sql_ci
COMPOSE="docker compose -p $COMPOSE_PROJECT -f $APP_DIR/tests/compose.yml"

# setup() rewrites the checkout's git state and install_for() deletes its
# config and data outright. A NEXTCLOUD_SERVER_PATH typo that lands on a real
# directory must be refused before any of that: setup() stamps this marker
# only into a directory it cloned itself, and every destructive step checks
# for it first.
MARKER_FILE=.fts_sql-integration-checkout

# Service and published port per engine, from one table so the reset and the
# install cannot agree on the port while disagreeing on the container. The
# ports are tests/compose.yml's, which are .github/workflows/tests.yml's.
engine_facts() {
	case "$1" in
		# sqlite has no container: its whole database is the file under
		# data/, which install_for() already deletes.
		sqlite) echo "- -" ;;
		pgsql) echo "postgres 4446" ;;
		mariadb) echo "mariadb 4444" ;;
		mysql) echo "mysql 4445" ;;
		*) return 1 ;;
	esac
}

# The server branch to test against is the one the manifest declares, the
# same pin the workflow's checkout step hard-codes.
server_branch() {
	local min
	min=$(grep -oP '(?<=<nextcloud min-version=")[0-9]+' "$APP_DIR/appinfo/info.xml")
	echo "stable${min}"
}

path_is_empty_or_missing() {
	[ ! -e "$1" ] && return 0
	[ -d "$1" ] || return 1
	[ -z "$(ls -A "$1" 2>/dev/null)" ]
}

setup() {
	local branch
	branch=$(server_branch)

	if path_is_empty_or_missing "$SERVER_PATH"; then
		git clone --depth 1 --branch "$branch" --recurse-submodules \
			https://github.com/nextcloud/server.git "$SERVER_PATH"
		touch "$SERVER_PATH/$MARKER_FILE"
	elif [ -f "$SERVER_PATH/$MARKER_FILE" ]; then
		git -C "$SERVER_PATH" fetch --depth 1 origin "$branch"
		git -C "$SERVER_PATH" checkout -B "$branch" "origin/$branch"
		git -C "$SERVER_PATH" submodule update --init --depth 1
	else
		echo "refusing to use $SERVER_PATH: it exists, is not empty, and carries" >&2
		echo "no $MARKER_FILE — not a checkout this script made. Point" >&2
		echo "NEXTCLOUD_SERVER_PATH elsewhere, or delete it first if it is" >&2
		echo "really disposable." >&2
		exit 2
	fi

	# The framework this app is a platform of, at the server's branch: the
	# integration suite uses fulltextsearch's own TestProvider, and enabling
	# fts_sql without the framework installed leaves the platform
	# unreachable — the same two clones the workflow makes.
	local app
	for app in fulltextsearch files_fulltextsearch; do
		if [ ! -d "$SERVER_PATH/apps/$app/.git" ]; then
			git clone --depth 1 --branch "$branch" \
				"https://github.com/nextcloud/$app.git" "$SERVER_PATH/apps/$app"
		else
			git -C "$SERVER_PATH/apps/$app" fetch --depth 1 origin "$branch"
			git -C "$SERVER_PATH/apps/$app" checkout -B "$branch" "origin/$branch"
		fi
	done

	mkdir -p "$SERVER_PATH/data"
}

# Refresh the app copy inside the checkout from this working tree. vendor/ and
# node_modules/ are left alone (rsync excludes protect them from --delete):
# prepare_app() owns them there through composer, and pushing a hundred
# megabytes of host vendor/ through rsync would buy nothing.
sync_app() {
	rm -rf "$SERVER_PATH/apps/$APP_ID"
	rsync -a --delete \
		--exclude .git --exclude .zcode --exclude node_modules --exclude vendor \
		--exclude build --exclude test-results --exclude playwright-report \
		--exclude js --exclude css \
		"$APP_DIR/" "$SERVER_PATH/apps/$APP_ID/"
}

# The workflow's dependency step, on the copy: the server checkout provides
# the OCP interfaces, and the standalone nextcloud/ocp dev package — which
# vendor/bin/phpunit's own autoloader would register — shadows them with a
# tree that is not the server's. Removing it in the copy is exactly what the
# workflow does to itself; doing it here is why the app is a copy.
prepare_app() {
	(
		cd "$SERVER_PATH/apps/$APP_ID"
		composer remove nextcloud/ocp --dev --no-scripts
		composer install
	)
}

install_for() {
	local engine="$1"
	local args=(--database-name=nextcloud --database-user=root
		--database-pass=rootpassword --admin-user admin --admin-pass admin --verbose)

	if [ ! -f "$SERVER_PATH/$MARKER_FILE" ]; then
		echo "refusing to modify $SERVER_PATH: no $MARKER_FILE there." >&2
		exit 2
	fi

	# A fresh install per engine: occ refuses to install over an existing
	# config, and a leftover one would silently test the previous engine.
	rm -f "$SERVER_PATH/config/config.php"
	rm -rf "${SERVER_PATH:?}/data"
	mkdir -p "$SERVER_PATH/data"

	local facts port
	facts=$(engine_facts "$engine") || { echo "unknown engine: $engine" >&2; exit 2; }
	read -r _ port <<< "$facts"

	case "$engine" in
		sqlite) args+=(--database=sqlite) ;;
		pgsql) args+=(--database=pgsql --database-host=127.0.0.1 --database-port="$port") ;;
		mariadb|mysql) args+=(--database=mysql --database-host=127.0.0.1 --database-port="$port") ;;
	esac

	(cd "$SERVER_PATH" && php occ maintenance:install "${args[@]}")
	# files_external is not enabled by a CLI maintenance:install, but the
	# server's test bootstrap loads every app under apps/ anyway — and a
	# loaded-but-unmigrated files_external breaks any test that touches a
	# real user folder (its mount provider queries oc_external_mounts
	# before the first run of its migrations). The order the workflow
	# enables the rest in.
	(cd "$SERVER_PATH" && php occ app:enable files_external)
	(cd "$SERVER_PATH" && php occ app:enable fulltextsearch)
	(cd "$SERVER_PATH" && php occ app:enable files_fulltextsearch)
	(cd "$SERVER_PATH" && php occ app:enable "$APP_ID")
}

# One whole invocation — container reset, install, both suites — holds this
# lock, so two runs queue instead of pulling the installation out from under
# each other. Beside the checkout rather than inside it: install_for() deletes
# directories within. flock -w, not a plain block: a stuck holder should
# surface as a legible timeout, not a hang.
LOCK_FILE="${SERVER_PATH}.lock"
LOCK_WAIT_SECONDS=1800

acquire_lock() {
	exec 200>"$LOCK_FILE"
	if ! flock -w "$LOCK_WAIT_SECONDS" 200; then
		echo "gave up waiting ${LOCK_WAIT_SECONDS}s for $LOCK_FILE: another" >&2
		echo "integration run still holds it — check for a stuck process." >&2
		exit 2
	fi
}

reset_engine() {
	local engine="$1"
	[ "$engine" = sqlite ] && return 0

	local facts service port
	facts=$(engine_facts "$engine") || { echo "unknown engine: $engine" >&2; exit 2; }
	read -r service port <<< "$facts"

	# Every run starts from an empty database: `up -d` alone starts what is
	# stopped and keeps what is running, and installing over the previous
	# run's schema fails with errors that say nothing about stale state.
	# Destroying the container re-runs the image's entrypoint, which
	# initialises an empty datadir and creates the database from scratch;
	# --volumes takes the data with it. Only this engine's service, under
	# this script's pinned project.
	$COMPOSE rm --force --stop --volumes "$service"
	$COMPOSE up -d --wait "$service"
}

run() {
	local engine="$1"

	reset_engine "$engine"

	# The catalogue integration test escalates to root to reproduce a shared
	# server (CREATE DATABASE); the credentials are the compose file's, the
	# ones the CI services run with too.
	if [ "$engine" = mysql ] || [ "$engine" = mariadb ]; then
		local _service _port
		read -r _service _port <<< "$(engine_facts "$engine")"
		export FTS_SQL_MYSQL_ROOT_DSN="mysql://root:rootpassword@127.0.0.1:${_port}"
	fi

	sync_app
	prepare_app
	install_for "$engine"

	echo "=== suites on $engine ==="
	local status=0
	(
		cd "$SERVER_PATH/apps/$APP_ID"
		composer run test:unit || exit 1
		composer run test:integration || exit 1
	) || status=$?

	return "$status"
}

case "${1:-}" in
	setup) acquire_lock; setup ;;
	run) acquire_lock; setup; run "${2:?engine required (sqlite|pgsql|mariadb|mysql)}" ;;
	run-all)
		acquire_lock
		setup
		# One engine failing must not hide the ones after it: a matrix exists
		# to learn which engines are broken. `if run …` suspends errexit for
		# the whole call, so a failure anywhere inside run() is recorded as
		# that engine's result instead of aborting the loop.
		results=()
		failed=()
		for engine in sqlite pgsql mariadb mysql; do
			if run "$engine"; then
				results+=("$engine: PASS")
			else
				results+=("$engine: FAIL")
				failed+=("$engine")
			fi
		done

		echo
		echo "=== summary ==="
		printf '%s\n' "${results[@]}"

		if [ "${#failed[@]}" -gt 0 ]; then
			echo "failed: ${failed[*]}" >&2
			exit 1
		fi
		;;
	*) echo "usage: $0 setup|run <engine>|run-all" >&2; exit 2 ;;
esac
