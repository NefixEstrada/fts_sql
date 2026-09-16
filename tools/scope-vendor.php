<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The bundling pipeline of DESIGN.md's Milestone 3, in one entry point:
 * rewrite every runtime Composer dependency under the app's own
 * namespace with php-scoper, lay the copies out in lib/Vendor where
 * Nextcloud's own autoloader serves them, and take the unscoped
 * originals out of vendor/ so that a reference to an unprefixed
 * namespace cannot work in development and silently break in
 * production, where nothing loads vendor/.
 *
 * Composer runs it after every install and update — development and
 * release then see the same code shape. With no runtime dependencies
 * (Milestones 1 and 2 ship none) it removes any stale scoped copy and
 * exits, without needing php-scoper or the network at all.
 *
 * Usage: php tools/scope-vendor.php [project-root] [php-scoper-binary]
 * Composer hooks pass no arguments; the self-test and the release
 * build pass both, so the pipeline always runs against a known root.
 */

require_once __DIR__ . '/scoping.php';

$root = isset($argv[1]) && $argv[1] !== '' ? $argv[1] : getcwd();
$root = realpath((string)$root);
if ($root === false || !is_dir($root)) {
	scoping_error('project root does not exist: ' . (string)($argv[1] ?? getcwd()));
}
$scoperBinary = isset($argv[2]) && $argv[2] !== '' ? (string)$argv[2] : $root . '/vendor/bin/php-scoper';

// scoper.inc.php and php-scoper itself resolve paths from the cwd.
if (!chdir($root)) {
	scoping_error('cannot enter ' . $root);
}

$packages = scoping_installed_runtime_packages($root);
$target = $root . '/' . SCOPING_TARGET_DIR;

if ($packages === []) {
	if (is_dir($target)) {
		scoping_rm($target);
		echo 'scoping: no runtime packages; removed the stale ' . SCOPING_TARGET_DIR . PHP_EOL;
	}
	exit(0);
}

// php-scoper lives in its own composer bin (vendor-bin/php-scoper),
// installed by the bin plugin, so it never mixes with the app's
// dependencies. It is only fetched when there is something to scope.
if (!file_exists($scoperBinary)) {
	scoping_composer($root, 'bin all install --ignore-platform-reqs');
	if (!file_exists($scoperBinary)) {
		scoping_error($scoperBinary . ' is still missing after composer bin all install');
	}
}

$names = array_map(static fn (array $package): string => $package['name'], $packages);

// 1. rewrite every runtime package under SCOPING_PREFIX
$output = $root . '/build/vendor-scoped';
scoping_rm($output);
scoping_rm($target);
$code = scoping_run(
	[PHP_BINARY, $scoperBinary, 'add-prefix', '--force', '--no-ansi', '--no-interaction',
		'--output-dir=build/vendor-scoped', '--config=' . $root . '/scoper.inc.php'],
	$root,
);
if ($code !== 0) {
	scoping_error('php-scoper failed (exit ' . $code . ')');
}
if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true)) {
	scoping_error('cannot create ' . dirname($target));
}
if (!is_dir($output) || !rename($output, $target)) {
	scoping_error('cannot move ' . $output . ' to ' . $target);
}

// 2. reshape the vendor layout into namespace-shaped paths
scoping_organize($target, $names);

// 3. the unscoped copies have to go: while they remain in vendor/, the
// Composer autoloader keeps serving the unprefixed namespaces to every
// test and tool, and production — which loads only lib/ — diverges.
// The one exception is a package the dev-only tooling also depends on
// (Psalm loads composer/pcre, which is in PhpSpreadsheet's closure):
// pruning it would break the tool, and keeping it risks nothing, since
// no app code ever names a utility package.
$shared = scoping_dev_shared_packages($root, $names);
foreach ($packages as $package) {
	if (in_array($package['name'], $shared, true)) {
		continue;
	}
	scoping_rm($package['path']);
	$organisation = dirname($package['path']);
	@rmdir($organisation); // stays when dev-only siblings of the same organisation remain
}

// 4. ... and the autoloader has to forget them
scoping_composer($root, 'dump-autoload -o');

echo 'scoping: rewrote ' . count($packages) . ' package(s) under ' . SCOPING_PREFIX
	. ' into ' . SCOPING_TARGET_DIR . ': ' . implode(', ', $names) . PHP_EOL;
if ($shared !== []) {
	echo 'scoping: kept the unscoped copy of ' . implode(', ', $shared)
		. ' in vendor/ — the dev tooling loads them too' . PHP_EOL;
}
