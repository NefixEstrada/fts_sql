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
 * A second fixture with a files autoload must fail the pipeline loudly,
 * because such a package would ship but never load.
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
	foreach (['project', 'fixture/src', 'badfixture'] as $dir) {
		if (!mkdir($work . '/' . $dir, 0755, true)) {
			throw new RuntimeException('cannot create ' . $work . '/' . $dir);
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

	$project = [
		'name' => 'fts-sql/scope-selftest',
		'description' => 'the scoping pipeline self-test project',
		'license' => 'AGPL-3.0-or-later',
		'repositories' => [
			['type' => 'path', 'url' => '../../fixture',
				'options' => ['symlink' => false, 'versions' => ['fts-sql/fixture-scoped-math' => '1.0.0']]],
			['type' => 'path', 'url' => '../../badfixture',
				'options' => ['symlink' => false, 'versions' => ['fts-sql/fixture-files-autoload' => '1.0.0']]],
		],
		'config' => ['optimize-autoloader' => true],
		'autoload' => ['psr-4' => ['OCA\\FtsSql\\' => 'lib/']],
	];

	// Each scratch project stages the pipeline exactly like `make
	// appstore` stages it into the build directory.
	$stage = static function (string $dir, array $require) use ($repo, $project, $encode): void {
		mkdir($dir, 0755, true);
		$project['require'] = $require;
		file_put_contents($dir . '/composer.json', $encode($project));
		copy($repo . '/scoper.inc.php', $dir . '/scoper.inc.php');
		selftest_run(['cp', '-a', $repo . '/tools', $dir . '/'], $repo);
	};

	$good = $work . '/project/good';
	$bad = $work . '/project/bad';
	$stage($good, ['fts-sql/fixture-scoped-math' => '*']);
	$stage($bad, ['fts-sql/fixture-scoped-math' => '*', 'fts-sql/fixture-files-autoload' => '*']);

	// --- the positive case: the fixture ends up under the app's namespace
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
	selftest_check($report, $code === 0, 'the pipeline failed on the PSR-4 fixture:' . PHP_EOL . $output);

	$class = SCOPING_PREFIX . '\FtsSqlFixture\ScopedMath\Math';
	selftest_check($report, is_file($good . '/lib/Vendor/FtsSqlFixture/ScopedMath/Math.php'),
		'the scoped class file is not at lib/Vendor/FtsSqlFixture/ScopedMath/Math.php');
	selftest_check($report, !is_dir($good . '/vendor/fts-sql'),
		'the unscoped package is still in vendor/fts-sql');

	require $good . '/vendor/autoload.php';
	selftest_check($report, class_exists($class),
		'class ' . $class . ' does not autoload through the project autoloader');
	if (class_exists($class)) {
		$file = (string)(new ReflectionClass($class))->getFileName();
		selftest_check($report, str_ends_with($file, '/lib/Vendor/FtsSqlFixture/ScopedMath/Math.php'),
			'class ' . $class . ' loads from ' . $file . ', not from lib/Vendor');
		selftest_check($report, $class::add(2, 3) === 5, 'the scoped class does not run');
	}
	selftest_check($report, !class_exists('FtsSqlFixture\ScopedMath\Math'),
		'the unprefixed FtsSqlFixture\ScopedMath\Math still autoloads');

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
echo 'scoping self-test: pipeline, organizer, pruning and the files-autoload refusal all hold' . PHP_EOL;
