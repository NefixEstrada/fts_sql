<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The shared logic of the vendor scoping pipeline (DESIGN.md,
 * Milestone 3): which Composer packages are runtime dependencies, where
 * Composer put them, and how their scoped copies are laid out under
 * lib/Vendor so that Nextcloud's own autoloader — OCA\FtsSql\ -> lib/ —
 * serves them with no Composer autoloader at runtime.
 *
 * Three files load this one: scoper.inc.php (the finders),
 * tools/scope-vendor.php (the pipeline) and tools/scope-selftest.php
 * (the proof). It must stay loadable without the app's autoloader.
 */

const SCOPING_PREFIX = 'OCA\FtsSql\Vendor';

/** Where the scoped copies land, relative to the project root. */
const SCOPING_TARGET_DIR = 'lib/Vendor';

function scoping_error(string $message): never {
	fwrite(STDERR, 'scoping: ' . $message . PHP_EOL);
	exit(1);
}

/**
 * The runtime package names of the project at $root: composer.json's
 * require section minus the platform packages (php, ext-*, lib-*).
 * These are the roots of the runtime closure that gets scoped — see
 * scoping_runtime_closure().
 *
 * The composer bin plugin is excluded alongside php and the extensions:
 * it carries php-scoper, which is this pipeline's own tooling — the
 * reference pattern excludes it implicitly, by never listing its
 * directory in a hand-written finder.
 *
 * @return list<string> sorted names
 */
function scoping_runtime_packages(string $root): array {
	$raw = file_get_contents($root . '/composer.json');
	if ($raw === false) {
		scoping_error('cannot read ' . $root . '/composer.json');
	}
	$composer = json_decode($raw, true);
	if (!is_array($composer)) {
		scoping_error('cannot parse ' . $root . '/composer.json');
	}
	$require = $composer['require'] ?? [];
	if (!is_array($require)) {
		scoping_error('require is not an object in ' . $root . '/composer.json');
	}

	$tooling = ['bamarni/composer-bin-plugin'];
	$packages = [];
	foreach (array_keys($require) as $name) {
		$name = (string)$name;
		if (scoping_is_platform_require($name) || in_array($name, $tooling, true)) {
			continue;
		}
		$packages[] = $name;
	}
	sort($packages, SORT_STRING);
	return $packages;
}

/**
 * Composer require-link names that are not installable code: platform
 * packages (php and its architecture aliases such as php-64bit, hhvm,
 * extensions, system libraries) and Composer's own pseudo-packages.
 */
function scoping_is_platform_require(string $name): bool {
	return in_array($name, ['php', 'php-64bit', 'php-32bit', 'hhvm'], true)
		|| str_starts_with($name, 'ext-')
		|| str_starts_with($name, 'lib-')
		|| (str_starts_with($name, 'composer-') && str_ends_with($name, '-api'));
}

/**
 * The whole runtime closure: the direct requires plus every package
 * they reach through their own require links — the set of code a
 * `composer install --no-dev` puts in vendor/. Everything in it is
 * scoped together, because php-scoper rewrites every namespaced
 * reference in the files it scopes whether or not the referenced
 * package is in the finders: a transitive runtime dependency left out
 * of the finders does not stay unscoped-and-working, its prefixed name
 * stops existing, and the scoped copy of whatever referenced it cannot
 * load in production — where nothing serves vendor/.
 *
 * Dev-only packages are never reached: the walk starts at the direct
 * runtime requires and follows require links only.
 *
 * @return list<string> sorted names
 */
function scoping_runtime_closure(string $root): array {
	$roots = scoping_runtime_packages($root);
	if ($roots === []) {
		return [];
	}

	$raw = file_get_contents($root . '/vendor/composer/installed.json');
	if ($raw === false) {
		scoping_error('runtime packages are required but vendor/ is not installed; run composer install');
	}
	$installed = json_decode($raw, true);
	if (!is_array($installed)) {
		scoping_error('cannot parse ' . $root . '/vendor/composer/installed.json');
	}
	$entries = $installed['packages'] ?? $installed;
	if (!is_array($entries)) {
		scoping_error('unexpected shape of ' . $root . '/vendor/composer/installed.json');
	}

	$requires = [];
	foreach ($entries as $entry) {
		if (!is_array($entry) || !isset($entry['name']) || !is_string($entry['name'])) {
			continue;
		}
		$links = $entry['require'] ?? [];
		$requires[$entry['name']] = is_array($links) ? array_keys($links) : [];
	}

	$closure = [];
	$queue = $roots;
	while ($queue !== []) {
		$name = array_shift($queue);
		if (isset($closure[$name])) {
			continue;
		}
		if (!isset($requires[$name])) {
			scoping_error('runtime package ' . $name . ' is required but not installed; run composer install');
		}
		$closure[$name] = true;
		foreach ($requires[$name] as $dependency) {
			$dependency = (string)$dependency;
			if (!scoping_is_platform_require($dependency) && !isset($closure[$dependency])) {
				$queue[] = $dependency;
			}
		}
	}

	$names = array_keys($closure);
	sort($names, SORT_STRING);
	return $names;
}

/**
 * The runtime packages whose unscoped copy must STAY in vendor/: the
 * ones the dev-only tooling also loads. Pruning is what keeps the dev
 * tree honest — no test can pass against an unprefixed name that
 * production cannot load — but pruning a package the dev tools
 * themselves depend on breaks the tool: Psalm's composer/pcre is in
 * PhpSpreadsheet's runtime closure too. A kept copy risks nothing the
 * app cares about: no app code names a utility package, so nothing can
 * silently pass in dev and fail in production behind it.
 *
 * A package is shared when it is reachable from any installed package
 * outside the runtime closure — the dev-only roots — through require
 * links.
 *
 * @param list<string> $runtimeNames the runtime closure
 * @return list<string> subset of $runtimeNames, sorted
 */
function scoping_dev_shared_packages(string $root, array $runtimeNames): array {
	if ($runtimeNames === []) {
		return [];
	}
	$raw = file_get_contents($root . '/vendor/composer/installed.json');
	if ($raw === false) {
		scoping_error('cannot read ' . $root . '/vendor/composer/installed.json');
	}
	$installed = json_decode($raw, true);
	if (!is_array($installed)) {
		scoping_error('cannot parse ' . $root . '/vendor/composer/installed.json');
	}
	$entries = $installed['packages'] ?? $installed;
	if (!is_array($entries)) {
		scoping_error('unexpected shape of ' . $root . '/vendor/composer/installed.json');
	}

	$requires = [];
	foreach ($entries as $entry) {
		if (!is_array($entry) || !isset($entry['name']) || !is_string($entry['name'])) {
			continue;
		}
		$links = $entry['require'] ?? [];
		$requires[$entry['name']] = is_array($links) ? array_keys($links) : [];
	}

	$reached = [];
	$queue = array_diff(array_keys($requires), $runtimeNames);
	while ($queue !== []) {
		$name = array_shift($queue);
		if (isset($reached[$name])) {
			continue;
		}
		$reached[$name] = true;
		foreach ($requires[$name] ?? [] as $dependency) {
			$dependency = (string)$dependency;
			if (!scoping_is_platform_require($dependency) && !isset($reached[$dependency])) {
				$queue[] = $dependency;
			}
		}
	}

	$shared = array_intersect($runtimeNames, array_keys($reached));
	sort($shared, SORT_STRING);
	return array_values($shared);
}

/**
 * The installed packages behind those names, with their paths under
 * vendor/, from what Composer itself recorded at install time.
 *
 * @return list<array{name: string, path: string}> path absolute
 */
function scoping_installed_runtime_packages(string $root): array {
	$names = scoping_runtime_closure($root);
	if ($names === []) {
		return [];
	}

	$raw = file_get_contents($root . '/vendor/composer/installed.json');
	if ($raw === false) {
		scoping_error('runtime packages are required but vendor/ is not installed; run composer install');
	}
	$installed = json_decode($raw, true);
	if (!is_array($installed)) {
		scoping_error('cannot parse ' . $root . '/vendor/composer/installed.json');
	}
	$entries = $installed['packages'] ?? $installed;
	if (!is_array($entries)) {
		scoping_error('unexpected shape of ' . $root . '/vendor/composer/installed.json');
	}

	$paths = [];
	foreach ($entries as $entry) {
		if (!is_array($entry) || !isset($entry['name']) || !is_string($entry['name'])) {
			continue;
		}
		$installPath = $entry['install-path'] ?? null;
		$paths[$entry['name']] = is_string($installPath)
			? $root . '/vendor/composer/' . $installPath
			: $root . '/vendor/' . $entry['name'];
	}

	$packages = [];
	foreach ($names as $name) {
		if (!isset($paths[$name])) {
			scoping_error('runtime package ' . $name . ' is in the runtime closure but not installed; run composer install');
		}
		$packages[] = ['name' => $name, 'path' => $paths[$name]];
	}
	return $packages;
}

/**
 * Reshape php-scoper's output into namespace-shaped paths under
 * $target, which is what the PSR-4 rule OCA\FtsSql\ -> lib/ resolves
 * against. The output layout is not an input: php-scoper dumps files
 * relative to the common root of everything it found, so one package
 * lands at the root and two in the same organisation lose that level —
 * packages are located by the name php-scoper keeps in their scoped
 * composer.json instead. Every package must be pure PSR-4: a files or
 * classmap entry has no path shape at all, and shipping it silently
 * would mean a class that works in dev (where vendor/ still serves it)
 * and never loads in production. That, an unexpected package, and any
 * stray file all fail the build instead.
 *
 * @param list<string> $expected the runtime package names
 */
function scoping_organize(string $target, array $expected): void {
	$prefix = SCOPING_PREFIX . '\\';

	// php-scoper dumps its own autoloader next to the scoped code; the
	// app autoloader replaces it, so the files are dead weight.
	scoping_rm($target . '/vendor');
	scoping_rm($target . '/autoload.php');

	$found = [];
	$queue = [$target];
	while ($queue !== []) {
		$dir = array_pop($queue);
		$name = scoping_manifest_name($dir);
		if ($name !== null) {
			// the first manifest met top-down is the package; anything
			// nested deeper is the package's own file to carry along
			if (!in_array($name, $expected, true)) {
				scoping_error($dir . ' is the scoped copy of ' . $name
					. ', which is not a runtime package; the finders selected too much');
			}
			if (isset($found[$name])) {
				scoping_error('two scoped copies of ' . $name . ': ' . $found[$name] . ' and ' . $dir);
			}
			$found[$name] = $dir;
			continue;
		}
		$entries = scandir($dir);
		if ($entries === false) {
			scoping_error('cannot read ' . $dir);
		}
		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$path = $dir . '/' . $entry;
			if (!is_dir($path)) {
				scoping_error('unexpected file in the scoped output: ' . $path);
			}
			$queue[] = $path;
		}
	}

	$missing = array_diff($expected, array_keys($found));
	if ($missing !== []) {
		scoping_error('the scoped output holds no copy of: ' . implode(', ', $missing));
	}

	$carried = [];
	foreach ($found as $name => $path) {
		$carried = array_merge($carried, scoping_organize_package($path, $target, $prefix));
		if ($path === $target) {
			continue;
		}
		// the empty directories php-scoper nested the package under
		$ancestor = dirname($path);
		while ($ancestor !== $target && $ancestor !== '/') {
			if (!@rmdir($ancestor)) {
				break; // holds another package's copy
			}
			$ancestor = dirname($ancestor);
		}
	}

	$leftovers = array_diff(scandir($target) ?: [], ['.', '..']);
	foreach ($leftovers as $entry) {
		// the one legal top-level file is a classmap package's own: its
		// class sits directly under the prefix root, and whatever it
		// carries along lands beside it
		if (!is_dir($target . '/' . $entry) && !in_array($entry, $carried, true)) {
			scoping_error('unexpected file left in ' . $target . ': ' . $entry);
		}
	}
}

/**
 * The package name a scoped composer.json still carries, or null when
 * the file is absent or names nothing.
 */
function scoping_manifest_name(string $dir): ?string {
	$raw = @file_get_contents($dir . '/composer.json');
	$composer = $raw === false ? null : json_decode($raw, true);
	if (!is_array($composer) || !isset($composer['name']) || !is_string($composer['name'])) {
		return null;
	}
	return $composer['name'];
}

/**
 * Lay one scoped package — php-scoper rewrote its composer.json's PSR-4
 * namespaces to the prefixed form — out under its namespace path.
 *
 * A classmap entry rides too, through the one type its file declares:
 * php-scoper has already prefixed the declaration — a global class
 * arrives as a namespaced one — so the file laid at that type's
 * namespace-shaped path loads exactly like a PSR-4 class.
 *
 * @return list<string> files placed directly in $target: a classmap
 *                      class sitting under the prefix root, and
 *                      whatever the package carries along beside it
 */
function scoping_organize_package(string $path, string $target, string $prefix): array {
	$composer = json_decode((string)file_get_contents($path . '/composer.json'), true);
	if (!is_array($composer)) {
		scoping_error('no readable scoped composer.json in ' . $path . '; cannot lay the package out');
	}

	$autoload = $composer['autoload'] ?? [];
	if (!is_array($autoload)) {
		scoping_error('package at ' . $path . ' has no autoload section');
	}
	foreach (['files', 'psr-0'] as $kind) {
		if (isset($autoload[$kind])) {
			scoping_error(
				'package at ' . $path . ' autoloads through ' . $kind
				. '; only PSR-4 and classmap can ride the app autoloader (OCA\FtsSql\ -> lib/),'
				. ' anything else would ship but never load',
			);
		}
	}
	$classmap = $autoload['classmap'] ?? [];
	if (!is_array($classmap)) {
		scoping_error('package at ' . $path . ' has a classmap that is not a list');
	}
	$mappings = $autoload['psr-4'] ?? [];
	if ($classmap === [] && (!is_array($autoload['psr-4'] ?? null) || $mappings === [])) {
		scoping_error('package at ' . $path . ' has no PSR-4 mapping');
	}

	$destinations = [];
	$topLevel = [];
	foreach ($classmap as $file) {
		$file = trim((string)$file, '/');
		$source = $path . '/' . $file;
		if (!is_file($source)) {
			// a classmap entry the package does not ship
			continue;
		}
		$type = scoping_declared_type($source);
		if (!str_starts_with($type, $prefix)) {
			scoping_error('the classmap entry ' . $file . ' of the package at ' . $path
				. ' declares ' . $type . ', which php-scoper did not prefix with ' . $prefix);
		}
		$relative = str_replace('\\', '/', trim(substr($type, strlen($prefix)), '\\'));
		$destination = $target . '/' . $relative . '.php';
		scoping_move_merge($source, $destination);
		if (dirname($destination) === $target) {
			$topLevel[] = basename($destination);
		}
		$destinations[] = dirname($destination);
	}
	foreach (is_array($mappings) ? $mappings : [] as $namespace => $dirs) {
		$namespace = (string)$namespace;
		if (!str_starts_with($namespace, $prefix)) {
			scoping_error('package at ' . $path . ' maps ' . $namespace
				. ', which php-scoper did not prefix with ' . $prefix);
		}
		$relative = substr($namespace, strlen($prefix));
		if (preg_match('#^[\w\\\\]+$#', $relative) !== 1) {
			scoping_error('package at ' . $path . ' maps the unexpected namespace ' . $namespace);
		}
		$destination = $target . '/' . str_replace('\\', '/', trim($relative, '\\'));
		$destinations[] = $destination;
		foreach (is_array($dirs) ? $dirs : [$dirs] as $dir) {
			$dir = trim((string)$dir, '/');
			if ($dir === '') {
				// the package root is the namespace root: everything but
				// the manifest moves as code, nothing else is left to carry
				$entries = scandir($path);
				if ($entries === false) {
					scoping_error('cannot read ' . $path);
				}
				foreach ($entries as $entry) {
					if ($entry !== '.' && $entry !== '..' && $entry !== 'composer.json') {
						scoping_move_merge($path . '/' . $entry, $destination . '/' . $entry);
					}
				}
				return $topLevel;
			}
			scoping_move_merge($path . '/' . $dir, $destination);
		}
	}

	// Whatever is left at the package root — licences, data files —
	// rides along with the code rather than being dropped silently.
	// A directory just built as a destination stays where it is, which
	// happens when the package lands at the output root itself.
	$remaining = scandir($path);
	if ($remaining === false) {
		scoping_error('cannot read ' . $path);
	}
	foreach ($remaining as $file) {
		if ($file === '.' || $file === '..' || $file === 'composer.json') {
			continue;
		}
		$entry = $path . '/' . $file;
		foreach ($destinations as $destination) {
			if ($destination === $entry || str_starts_with($destination, $entry . '/')) {
				continue 2;
			}
		}
		scoping_move_merge($entry, $destinations[0] . '/' . $file);
		if ($destinations[0] === $target) {
			$topLevel[] = $file;
		}
	}
	if ($path !== $target) {
		scoping_rm($path);
	} else {
		// the package landed at the output root: its manifest — the only
		// thing never carried into the layout — goes now that it has
		// done its work
		unlink($path . '/composer.json');
	}
	return $topLevel;
}

/**
 * The fully-qualified name of the one class, interface, enum or trait
 * $file declares, read off the scoped copy so the name is already the
 * prefixed one. Tokens tell declarations apart from comments and
 * ::class constants, which carry the same words.
 */
function scoping_declared_type(string $file): string {
	$source = file_get_contents($file);
	if ($source === false) {
		scoping_error('cannot read ' . $file . ' to find its declared type');
	}

	$namespace = '';
	$types = [];
	$tokens = token_get_all($source);
	$count = count($tokens);
	for ($i = 0; $i < $count; $i++) {
		$token = $tokens[$i];
		if (!is_array($token)) {
			continue;
		}
		if ($token[0] === T_NAMESPACE) {
			for ($j = $i + 1; $j < $count; $j++) {
				if (is_array($tokens[$j])
					&& in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
					continue;
				}
				if (is_array($tokens[$j])
					&& in_array($tokens[$j][0], [T_NAME_QUALIFIED, T_STRING], true)) {
					$namespace = $tokens[$j][1];
				}
				break; // the first non-space token ends the name
			}
		} elseif (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
			// `::class` writes no class; an anonymous `new class` is
			// followed by `(` or `extends`, never by a name
			$previous = $i > 0 ? $tokens[$i - 1] : null;
			if (is_array($previous) && $previous[0] === T_DOUBLE_COLON) {
				continue;
			}
			for ($j = $i + 1; $j < $count; $j++) {
				if (is_array($tokens[$j])
					&& in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
					continue;
				}
				if (is_array($tokens[$j])
					&& in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED], true)) {
					$types[] = ltrim($namespace . '\\' . $tokens[$j][1], '\\');
				}
				break;
			}
		}
	}

	if (count($types) > 1) {
		scoping_error($file . ' declares ' . count($types) . ' types (' . implode(', ', $types)
			. '); a classmap file must declare exactly one to ride the app autoloader');
	}
	if ($types === []) {
		scoping_error($file . ' declares no class, interface, enum or trait; cannot lay it out');
	}
	return $types[0];
}

/**
 * Move $source under $destination, merging when both exist.
 */
function scoping_move_merge(string $source, string $destination): void {
	if (!file_exists($source)) {
		// an optional PSR-4 directory the package does not ship
		return;
	}
	if (file_exists($destination)) {
		if (!is_dir($destination) || !is_dir($source)) {
			scoping_error('conflict between ' . $source . ' and ' . $destination);
		}
		$entries = scandir($source);
		if ($entries === false) {
			scoping_error('cannot read ' . $source);
		}
		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			scoping_move_merge($source . '/' . $entry, $destination . '/' . $entry);
		}
		rmdir($source);
		return;
	}

	if (!is_dir(dirname($destination))) {
		mkdir(dirname($destination), 0755, true);
	}
	if (!rename($source, $destination)) {
		scoping_error('cannot move ' . $source . ' to ' . $destination);
	}
}

/**
 * Run a command and report its exit code; output is only shown on
 * failure, so a successful hook stays quiet.
 *
 * @param list<string> $command raw arguments, escaped here
 */
function scoping_run(array $command, string $cwd): int {
	$line = implode(' ', array_map('escapeshellarg', $command));
	exec('cd ' . escapeshellarg($cwd) . ' && ' . $line . ' 2>&1', $output, $code);
	if ($code !== 0) {
		fwrite(STDERR, 'command failed (exit ' . $code . '): ' . $line . PHP_EOL);
		fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);
	}
	return $code;
}

/**
 * Run composer inside $root; $arguments are this pipeline's own constants.
 */
function scoping_composer(string $root, string $arguments): void {
	exec('cd ' . escapeshellarg($root) . ' && composer ' . $arguments . ' 2>&1', $output, $code);
	if ($code !== 0) {
		fwrite(STDERR, 'composer ' . $arguments . ' failed (exit ' . $code . ')' . PHP_EOL);
		fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);
		scoping_error('composer is needed on PATH for the scoping pipeline');
	}
}

function scoping_rm(string $path): void {
	if (is_link($path) || is_file($path)) {
		unlink($path);
		return;
	}
	if (!is_dir($path)) {
		return;
	}
	$entries = scandir($path);
	if ($entries === false) {
		scoping_error('cannot read ' . $path . ' to remove it');
	}
	foreach ($entries as $entry) {
		if ($entry === '.' || $entry === '..') {
			continue;
		}
		scoping_rm($path . '/' . $entry);
	}
	rmdir($path);
}
