<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * php-scoper's configuration, of the bundling tooling DESIGN.md's
 * Milestone 3 prescribes — the pattern fulltextsearch_elasticsearch
 * already uses, because two apps bundling different versions of the
 * same namespace means whichever autoloader registers first wins,
 * silently. Every runtime Composer dependency is rewritten under the
 * app's own namespace and laid out in lib/Vendor with namespace-shaped
 * paths, where Nextcloud's own autoloader (OCA\FtsSql\ -> lib/) serves
 * it; no Composer autoloader loads at runtime.
 *
 * The finders are derived from what composer.json actually requires,
 * not listed by hand: a package added to require but forgotten here
 * would otherwise ship unscoped in vendor/, where nothing ever loads
 * it. The in() base is the vendor organisation — php-scoper keeps the
 * first in() directory as the output root, preserving the
 * {organisation}/{package} layout the organizer walks — and the
 * anchored path() filters select exactly the installed runtime
 * packages, never a dev-only sibling of the same organisation.
 */

use Isolated\Symfony\Component\Finder\Finder;

require_once __DIR__ . '/tools/scoping.php';

$cwd = getcwd();
if (!is_string($cwd)) {
	fwrite(STDERR, 'scoping: cannot determine the working directory' . PHP_EOL);
	exit(1);
}
$root = realpath($cwd) ?: $cwd;

$byOrganisation = [];
foreach (scoping_installed_runtime_packages($root) as $package) {
	$parts = explode('/', $package['name'], 2);
	if (count($parts) !== 2) {
		scoping_error('package name without a vendor organisation: ' . $package['name']);
	}
	$byOrganisation[$parts[0]][] = $parts[1];
}

if ($byOrganisation === []) {
	// With no finders php-scoper would scope the whole tree.
	scoping_error('no runtime packages to scope; refusing to run php-scoper');
}

ksort($byOrganisation);
$finders = [];
foreach ($byOrganisation as $organisation => $packages) {
	sort($packages, SORT_STRING);
	$finders[] = Finder::create()
		->files()
		->in($root . '/vendor/' . $organisation)
		->path(array_map(
			static fn (string $package): string => '~^' . preg_quote($package, '~') . '/~',
			$packages,
		))
		->exclude(['test', 'tests', 'Tests', 'composer', 'bin'])
		->notName('autoload.php');
}

return [
	'prefix' => SCOPING_PREFIX,
	'finders' => $finders,
];
