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

$packages = scoping_installed_runtime_packages($root);
$byOrganisation = [];
foreach ($packages as $package) {
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
foreach ($byOrganisation as $organisation => $organisationPackages) {
	sort($organisationPackages, SORT_STRING);
	$finders[] = Finder::create()
		->files()
		->in($root . '/vendor/' . $organisation)
		->path(array_map(
			static fn (string $package): string => '~^' . preg_quote($package, '~') . '/~',
			$organisationPackages,
		))
		->exclude(['test', 'tests', 'Tests', 'composer', 'bin'])
		->notName('autoload.php');
}

// php-scoper rewrites every namespaced reference it can see in the code;
// a class name built by string concatenation is invisible to it. PhpWord
// constructs a dozen of them — its collections, its writers, its factory
// — so the string literals naming a scoped package's own namespaces are
// prefixed here, root by root, each root read from the package's own
// composer.json rather than listed by hand. Only literals that begin at
// a quote are touched: in this tree every dynamic construction starts
// its string at the namespace root.
$namespaceRoots = [];
foreach ($packages as $package) {
	$manifest = json_decode((string)file_get_contents($package['path'] . '/composer.json'), true);
	if (!is_array($manifest)) {
		scoping_error('cannot read the composer.json of ' . $package['name'] . ' for its namespace roots');
	}
	foreach (array_keys(is_array($manifest['autoload']['psr-4'] ?? null) ? $manifest['autoload']['psr-4'] : []) as $namespace) {
		$namespace = trim((string)$namespace, '\\') . '\\';
		if ($namespace !== '\\') {
			$namespaceRoots[] = $namespace;
		}
	}
}
$namespaceRoots = array_values(array_unique($namespaceRoots));
sort($namespaceRoots, SORT_STRING);

$stringNamespacePatcher = static function (string $filePath, string $prefix, string $content) use ($namespaceRoots): string {
	foreach ($namespaceRoots as $namespaceRoot) {
		// single-quoted source: one backslash per separator
		$content = str_replace("'" . $namespaceRoot, "'" . $prefix . '\\' . $namespaceRoot, $content);
		// double-quoted source: each separator escaped
		$double = static fn (string $ns): string => str_replace('\\', '\\\\', $ns);
		$content = str_replace('"' . $double($namespaceRoot), '"' . $double($prefix . '\\' . $namespaceRoot), $content);
	}
	return $content;
};

return [
	'prefix' => SCOPING_PREFIX,
	'finders' => $finders,
	'patchers' => [$stringNamespacePatcher],
];
