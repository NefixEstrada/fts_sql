<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The bundling pipeline's own proof (DESIGN.md, Milestone 3: the tool
 * exists and works before the first library arrives). It builds a
 * scratch Composer project that requires a fixture package through a
 * path repository — no network — runs the real pipeline with the app's
 * real prefix, and checks what only matters in production: the class
 * exists under OCA\FtsSql\Vendor, Nextcloud's PSR-4 rule resolves it
 * inside lib/Vendor, and the unprefixed original is gone from vendor/.
 * One fixture is a transitive dependency — reached only through another
 * fixture's require — because php-scoper rewrites cross-package
 * references whether or not the referenced package is in the finders,
 * so a closure miss is a class that cannot load. One is a classmap of
 * a global class, the pclzip shape: it must land at the prefix root
 * and carry its licence beside it. Another fixture with a files
 * autoload must fail the pipeline loudly, because such a package would
 * ship but never load.
 */

require_once __DIR__ . '/scoping.php';

$repo = dirname(__DIR__);
$work = $repo . '/build/scope-selftest';
$failures = [];

/**
 * @param list<string> $command
 * @return array{int, string} exit code and combined output
 */
function selftest_run(array $command, string $cwd): array {
	$line = implode(' ', array_map('escapeshellarg', $command));
	exec('cd ' . escapeshellarg($cwd) . ' && ' . $line . ' 2>&1', $output, $code);
	return [$code, implode(PHP_EOL, $output)];
}

/**
 * @param callable(string):void $report
 */
function selftest_check(callable $report, bool $condition, string $what): bool {
	if (!$condition) {
		$report($what);
	}
	return $condition;
}

$report = static function (string $what) use (&$failures): void {
	$failures[] = $what;
	fwrite(STDERR, 'FAIL: ' . $what . PHP_EOL);
};

try {
	if (!file_exists($repo . '/vendor/bin/php-scoper')) {
		scoping_composer($repo, 'bin all install --ignore-platform-reqs');
	}
	if (!selftest_check($report, file_exists($repo . '/vendor/bin/php-scoper'),
		'php-scoper is not at ' . $repo . '/vendor/bin/php-scoper after composer bin all install')) {
		throw new RuntimeException('setup failed');
	}

	scoping_rm($work);
	foreach (['project', 'fixture/src', 'shapefixture/src', 'legacyfixture', 'devtoolfixture/src', 'badfixture'] as $dir) {
		if (!mkdir($work . '/' . $dir, 0755, true)) {
			throw new RuntimeException('cannot create ' . $dir);
		}
	}

	$encode = static fn (array $data): string => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

	file_put_contents($work . '/fixture/composer.json', $encode([
		'name' => 'fts-sql/fixture-scoped-math',
		'description' => 'a PSR-4 fixture for the scoping self-test',
		'type' => 'library',
		'license' => 'MIT',
		'autoload' => ['psr-4' => ['FtsSqlFixture\\ScopedMath\\' => 'src/']],
	]));
	file_put_contents($work . '/fixture/src/Math.php', <<<'PHP'
		<?php

		declare(strict_types=1);

		namespace FtsSqlFixture\ScopedMath;

		final class Math {
			public static function add(int $a, int $b): int {
				return $a + $b;
			}
		}
		PHP);

	file_put_contents($work . '/badfixture/composer.json', $encode([
		'name' => 'fts-sql/fixture-files-autoload',
		'description' => 'a files-autoload fixture the pipeline must refuse',
		'type' => 'library',
		'license' => 'MIT',
		'autoload' => ['files' => ['functions.php']],
	]));
	file_put_contents($work . '/badfixture/functions.php', <<<'PHP'
	<?php

	declare(strict_types=1);

	function ftsSqlFixtureFilesAutoload(): bool {
		return true;
	}
	PHP);

	// The transitive fixture: its require is the only path to ScopedMath,
	// so a pipeline that scopes direct requires alone leaves Math's
	// prefixed name existing nowhere and Shape unable to load.
	file_put_contents($work . '/shapefixture/composer.json', $encode([
		'name' => 'fts-sql/fixture-scoped-shape',
		'description' => 'a PSR-4 fixture that requires another fixture',
		'type' => 'library',
		'license' => 'MIT',
		'require' => ['fts-sql/fixture-scoped-math' => '^1.0'],
		'autoload' => ['psr-4' => ['FtsSqlFixture\\ScopedShape\\' => 'src/']],
	]));
	file_put_contents($work . '/shapefixture/src/Shape.php', <<<'PHP'
	<?php

	declare(strict_types=1);

	namespace FtsSqlFixture\ScopedShape;

	use FtsSqlFixture\ScopedMath\Math;

	final class Shape {
		public static function addViaMath(int $a, int $b): int {
			return Math::add($a, $b);
		}

		/**
		 * The idiom php-scoper cannot see: a class name built from a
		 * string. The pipeline's string-namespace patcher has to
		 * prefix the literal, or the scoped copy asks for a class
		 * that exists nowhere — loadable in development through
		 * vendor/, dead in production.
		 */
		public static function dynamicallyBuilt(): string {
			$suffix = 'Part';
			$class = 'FtsSqlFixture\ScopedShape\\' . $suffix;
			return $class::name();
		}
	}
	PHP);
	file_put_contents($work . '/shapefixture/src/Part.php', <<<'PHP'
	<?php

	declare(strict_types=1);

	namespace FtsSqlFixture\ScopedShape;

	final class Part {
		public static function name(): string {
			return 'the dynamically built class answered';
		}
	}
	PHP);

	// The classmap fixture: a global class, the pclzip shape, plus a
	// licence-like file at the package root that must ride along.
	file_put_contents($work . '/legacyfixture/composer.json', $encode([
		'name' => 'fts-sql/fixture-classmap-legacy',
		'description' => 'a classmap fixture with one global class',
		'type' => 'library',
		'license' => 'LGPL-2.1',
		'autoload' => ['classmap' => ['legacy.php']],
	]));
	file_put_contents($work . '/legacyfixture/legacy.php', <<<'PHP'
	<?php

	declare(strict_types=1);

	class FtsSqlLegacyZip {
		public function __construct(private string $path) {
		}

		public function open(): string {
			return 'opened:' . $this->path;
		}
	}
	PHP);
	file_put_contents($work . '/legacyfixture/COPYING.txt', "a licence the pipeline must carry along\n");

	// The dev-tool fixture: a require-dev package that loads a runtime
	// package — Psalm and composer/pcre, the shape of it. The pipeline
	// must keep that one's unscoped copy in vendor/ and prune the rest.
	file_put_contents($work . '/devtoolfixture/composer.json', $encode([
		'name' => 'fts-sql/fixture-dev-tool',
		'description' => 'a dev-only fixture that requires a runtime fixture',
		'type' => 'library',
		'license' => 'MIT',
		'require' => ['fts-sql/fixture-scoped-math' => '^1.0'],
		'autoload' => ['psr-4' => ['FtsSqlFixture\\DevTool\\' => 'src/']],
	]));
	file_put_contents($work . '/devtoolfixture/src/DevTool.php', <<<'PHP'
	<?php

	declare(strict_types=1);

	namespace FtsSqlFixture\DevTool;

	use FtsSqlFixture\ScopedMath\Math;

	final class DevTool {
		public static function double(int $v): int {
			return Math::add($v, $v);
		}
	}
	PHP);

	$project = [
		'name' => 'fts-sql/scope-selftest',
		'description' => 'the scoping pipeline self-test project',
		'license' => 'AGPL-3.0-or-later',
		'repositories' => [
			['type' => 'path', 'url' => '../../fixture',
				'options' => ['symlink' => false, 'versions' => ['fts-sql/fixture-scoped-math' => '1.0.0']]],
			['type' => 'path', 'url' => '../../shapefixture',
				'options' => ['symlink' => false, 'versions' => ['fts-sql/fixture-scoped-shape' => '1.0.0']]],
			['type' => 'path', 'url' => '../../legacyfixture',
				'options' => ['symlink' => false, 'versions' => ['fts-sql/fixture-classmap-legacy' => '1.0.0']]],
			['type' => 'path', 'url' => '../../devtoolfixture',
				'options' => ['symlink' => false, 'versions' => ['fts-sql/fixture-dev-tool' => '1.0.0']]],
			['type' => 'path', 'url' => '../../badfixture',
				'options' => ['symlink' => false, 'versions' => ['fts-sql/fixture-files-autoload' => '1.0.0']]],
		],
		'config' => ['optimize-autoloader' => true],
		'autoload' => ['psr-4' => ['OCA\\FtsSql\\' => 'lib/']],
	];

	// Each scratch project stages the pipeline exactly like `make
	// appstore` stages it into the build directory.
	$stage = static function (string $dir, array $require, array $requireDev = []) use ($repo, $project, $encode): void {
		mkdir($dir, 0755, true);
		$project['require'] = $require;
		if ($requireDev !== []) {
			$project['require-dev'] = $requireDev;
		}
		file_put_contents($dir . '/composer.json', $encode($project));
		copy($repo . '/scoper.inc.php', $dir . '/scoper.inc.php');
		selftest_run(['cp', '-a', $repo . '/tools', $dir . '/'], $repo);
	};

	$good = $work . '/project/good';
	$bad = $work . '/project/bad';
	// ScopedMath is deliberately absent from require: it must arrive only
	// through the shape fixture's require, which is what the closure walk
	// is for — and the dev tool requires it too, which is what the
	// dev-shared pruning exception is for
	$stage($good,
		['fts-sql/fixture-scoped-shape' => '*', 'fts-sql/fixture-classmap-legacy' => '*'],
		['fts-sql/fixture-dev-tool' => '*']);
	$stage($bad, ['fts-sql/fixture-scoped-math' => '*', 'fts-sql/fixture-files-autoload' => '*']);

	// --- the positive case: the fixtures end up under the app's namespace
	foreach (['good' => $good, 'bad' => $bad] as $projectDir) {
		[$code, $output] = selftest_run(['composer', 'install', '--no-interaction'], $projectDir);
		if (!selftest_check($report, $code === 0, 'composer install failed in ' . $projectDir . PHP_EOL . $output)) {
			throw new RuntimeException('setup failed');
		}
	}

	[$code, $output] = selftest_run(
		[PHP_BINARY, $repo . '/tools/scope-vendor.php', $good, $repo . '/vendor/bin/php-scoper'],
		$repo,
	);
	selftest_check($report, $code === 0, 'the pipeline failed on the fixtures:' . PHP_EOL . $output);

	$class = SCOPING_PREFIX . '\FtsSqlFixture\ScopedMath\Math';
	selftest_check($report, is_file($good . '/lib/Vendor/FtsSqlFixture/ScopedMath/Math.php'),
		'the transitive class file is not at lib/Vendor/FtsSqlFixture/ScopedMath/Math.php');
	selftest_check($report, !is_dir($good . '/vendor/fts-sql/fixture-scoped-shape'),
		'the unscoped shape package is still in vendor/');
	selftest_check($report, !is_dir($good . '/vendor/fts-sql/fixture-classmap-legacy'),
		'the unscoped classmap package is still in vendor/');
	selftest_check($report, is_dir($good . '/vendor/fts-sql/fixture-scoped-math'),
		'the dev-shared math package was pruned from vendor/, breaking the dev tooling');

	require $good . '/vendor/autoload.php';
	selftest_check($report, class_exists($class),
		'class ' . $class . ' does not autoload through the project autoloader');
	if (class_exists($class)) {
		$file = (string)(new ReflectionClass($class))->getFileName();
		selftest_check($report, str_ends_with($file, '/lib/Vendor/FtsSqlFixture/ScopedMath/Math.php'),
			'class ' . $class . ' loads from ' . $file . ', not from lib/Vendor');
		selftest_check($report, $class::add(2, 3) === 5, 'the scoped class does not run');
	}
	selftest_check($report, !class_exists('FtsSqlFixture\ScopedShape\Shape'),
		'the unprefixed FtsSqlFixture\ScopedShape\Shape still autoloads: a runtime-only package must vanish');
	// the dev-shared exception: the unprefixed copy serves the dev tool,
	// exactly like Psalm's composer/pcre serves Psalm
	selftest_check($report, class_exists('FtsSqlFixture\DevTool\DevTool'),
		'the dev tool fixture does not autoload');
	if (class_exists('FtsSqlFixture\DevTool\DevTool')) {
		selftest_check($report, \FtsSqlFixture\DevTool\DevTool::double(3) === 6,
			'the dev tool cannot reach its unprefixed dependency');
	}

	// --- the transitive case: a class reaching a scoped dependency of
	// its own, through the app autoloader alone
	$shape = SCOPING_PREFIX . '\FtsSqlFixture\ScopedShape\Shape';
	selftest_check($report, is_file($good . '/lib/Vendor/FtsSqlFixture/ScopedShape/Shape.php'),
		'the scoped shape class file is not at lib/Vendor/FtsSqlFixture/ScopedShape/Shape.php');
	selftest_check($report, class_exists($shape),
		'class ' . $shape . ' does not autoload through the project autoloader');
	if (class_exists($shape)) {
		selftest_check($report, $shape::addViaMath(2, 3) === 5,
			'the scoped shape cannot reach the scoped transitive Math: a closure miss');
		selftest_check($report, $shape::dynamicallyBuilt() === 'the dynamically built class answered',
			'the scoped shape cannot build a class name from a string: an unpatched namespace literal');
	}

	// --- the classmap case: one global class at the prefix root, and
	// its licence carried beside it
	$legacy = SCOPING_PREFIX . '\FtsSqlLegacyZip';
	selftest_check($report, is_file($good . '/lib/Vendor/FtsSqlLegacyZip.php'),
		'the classmap class file is not at lib/Vendor/FtsSqlLegacyZip.php');
	selftest_check($report, class_exists($legacy),
		'class ' . $legacy . ' does not autoload through the project autoloader');
	if (class_exists($legacy)) {
		selftest_check($report, (new $legacy('demo'))->open() === 'opened:demo',
			'the classmap class does not run');
	}
	// php-scoper leaves a bridge: including the file also aliases the
	// original global name to the prefixed class, so the library's own
	// dynamic references keep working. The safe property is not that the
	// alias is absent — it is that the global name can only ever resolve
	// to the prefixed class, never to someone else's.
	if (class_exists('FtsSqlLegacyZip')) {
		$aliasTarget = (new ReflectionClass('FtsSqlLegacyZip'))->getName();
		selftest_check($report, $aliasTarget === $legacy,
			'the global FtsSqlLegacyZip name aliases to ' . $aliasTarget . ', not to ' . $legacy);
	}
	selftest_check($report, is_file($good . '/lib/Vendor/COPYING.txt'),
		'the classmap fixture licence was dropped instead of carried along');

	// --- the negative case: a files autoload must fail the pipeline
	[$code, $output] = selftest_run(
		[PHP_BINARY, $repo . '/tools/scope-vendor.php', $bad, $repo . '/vendor/bin/php-scoper'],
		$repo,
	);
	selftest_check($report, $code !== 0, 'the pipeline accepted a files-autoload package');
	selftest_check($report, str_contains($output, 'files'),
		'the files-autoload refusal does not say what it refused:' . PHP_EOL . $output);

	// --- the empty case: the repository itself has no runtime package today
	if (scoping_runtime_packages($repo) === []) {
		[$code, $output] = selftest_run([PHP_BINARY, $repo . '/tools/scope-vendor.php', $repo], $repo);
		selftest_check($report, $code === 0, 'the no-op run on the repository itself failed:' . PHP_EOL . $output);
		selftest_check($report, !is_dir($repo . '/' . SCOPING_TARGET_DIR),
			'the no-op run left a ' . SCOPING_TARGET_DIR . ' behind');
	}
} catch (Throwable $e) {
	$report($e->getMessage());
}

if ($failures !== []) {
	fwrite(STDERR, PHP_EOL . count($failures) . ' failure(s); the scratch projects are kept under ' . $work . PHP_EOL);
	exit(1);
}

scoping_rm($work);
echo 'scoping self-test: pipeline, organizer, closure, classmap, dev-shared pruning and the files-autoload refusal all hold' . PHP_EOL;
