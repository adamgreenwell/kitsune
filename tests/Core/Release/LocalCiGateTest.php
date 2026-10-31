<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->localCiSource = dirname(__DIR__, 3).'/bin/ci-local.sh';
    $this->localCiDir = realpath(sys_get_temp_dir()).'/kitsune-local-ci-'.bin2hex(random_bytes(6));
    File::makeDirectory($this->localCiDir.'/bin', 0700, true);
    File::makeDirectory($this->localCiDir.'/vendor/bin', 0700, true);
    File::makeDirectory($this->localCiDir.'/parent-temp', 0700, true);
    File::put($this->localCiDir.'/parent-temp/keep', 'operator file');

    $stub = <<<'PHP'
    #!/usr/bin/env php
    <?php
    $root = getenv('LOCAL_CI_TEST_ROOT');
    $kind = basename($argv[0]);
    $record = [
        'kind' => $kind,
        'args' => array_slice($argv, 1),
        'cwd' => getcwd(),
        'memory' => ini_get('memory_limit'),
        'precision' => ini_get('precision'),
        'timeout' => getenv('COMPOSER_PROCESS_TIMEOUT'),
        'connection' => getenv('DB_CONNECTION'),
        'temp' => realpath(sys_get_temp_dir()),
        'scan' => php_ini_scanned_files(),
    ];
    file_put_contents($root.'/calls.jsonl', json_encode($record)."\n", FILE_APPEND);
    file_put_contents(sys_get_temp_dir().'/test-process-cache', 'owned by this invocation');
    exit((int) getenv($kind === 'composer' ? 'LOCAL_CI_COMPOSER_EXIT' : 'LOCAL_CI_PINT_EXIT'));
    PHP;

    foreach (['bin/composer', 'vendor/bin/pint'] as $path) {
        File::put($this->localCiDir.'/'.$path, $stub);
        chmod($this->localCiDir.'/'.$path, 0755);
    }
});

afterEach(function (): void {
    File::deleteDirectory($this->localCiDir);
});

function runLocalCiGate(string $source, string $dir, array $env = [], array $args = []): Process
{
    expect(is_file($source))->toBeTrue('bin/ci-local.sh must provide the documented full local gate');
    File::copy($source, $dir.'/bin/ci-local.sh');

    $process = new Process(['/bin/bash', $dir.'/bin/ci-local.sh', ...$args], $dir.'/parent-temp', array_replace([
        'PATH' => $dir.'/bin:'.getenv('PATH'),
        'LOCAL_CI_TEST_ROOT' => $dir,
        'LOCAL_CI_COMPOSER_EXIT' => '0',
        'LOCAL_CI_PINT_EXIT' => '0',
        'PHP_INI_SCAN_DIR' => false,
        'COMPOSER_PROCESS_TIMEOUT' => '300',
        'DB_CONNECTION' => 'mysql',
        'TMPDIR' => $dir.'/parent-temp',
    ], $env));
    $process->setTimeout(30);
    $process->run();

    return $process;
}

function localCiCalls(string $dir): array
{
    if (! is_file($dir.'/calls.jsonl')) {
        return [];
    }

    return array_map(
        fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        file($dir.'/calls.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
    );
}

it('runs the full SQLite gate with test-only limits and cleans only its own short temporary directory', function (): void {
    $run = runLocalCiGate($this->localCiSource, $this->localCiDir);

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput());
    $calls = localCiCalls($this->localCiDir);
    expect(array_column($calls, 'kind'))->toBe(['composer', 'pint'])
        ->and($calls[0]['args'])->toBe(['ci'])
        ->and($calls[1]['args'])->toBe(['--test', 'skeleton', '--config', 'skeleton/pint.json']);

    foreach ($calls as $call) {
        expect($call['cwd'])->toBe($this->localCiDir)
            ->and($call['memory'])->toBe('512M')
            ->and($call['timeout'])->toBe('0')
            ->and($call['connection'])->toBe('testing')
            ->and(strlen($call['temp']))->toBeLessThan(40)
            ->and($call['temp'])->not->toBe($this->localCiDir.'/parent-temp')
            ->and(is_dir($call['temp']))->toBeFalse();
    }

    expect($calls[0]['temp'])->toBe($calls[1]['temp'])
        ->and(File::get($this->localCiDir.'/parent-temp/keep'))->toBe('operator file');
});

it('preserves an explicitly selected INI scan directory and its unrelated configuration', function (): void {
    $scan = $this->localCiDir.'/scan config';
    File::makeDirectory($scan, 0700);
    $ini = "memory_limit=128M\nprecision=13\n";
    File::put($scan.'/operator.ini', $ini);

    $run = runLocalCiGate($this->localCiSource, $this->localCiDir, ['PHP_INI_SCAN_DIR' => $scan]);
    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput())
        ->and(File::get($scan.'/operator.ini'))->toBe($ini);

    foreach (localCiCalls($this->localCiDir) as $call) {
        expect($call['memory'])->toBe('512M')
            ->and($call['precision'])->toBe('13')
            ->and($call['scan'])->toContain($scan.'/operator.ini');
    }
});

it('retains an explicit empty scan-directory choice instead of enabling the system scan directory', function (): void {
    $run = runLocalCiGate($this->localCiSource, $this->localCiDir, ['PHP_INI_SCAN_DIR' => '']);
    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput());

    foreach (localCiCalls($this->localCiDir) as $call) {
        $scanned = array_values(array_filter(array_map('trim', explode(',', $call['scan']))));
        expect($scanned)->toHaveCount(1)
            ->and($scanned[0])->toStartWith($call['temp'].'/ini/')
            ->and($call['memory'])->toBe('512M');
    }
});

it('returns the failing gate status and still removes its own temporary files', function (string $gate, string $exit, array $expected): void {
    $run = runLocalCiGate($this->localCiSource, $this->localCiDir, [$gate => $exit]);
    $calls = localCiCalls($this->localCiDir);

    expect($run->getExitCode())->toBe((int) $exit)
        ->and(array_column($calls, 'kind'))->toBe($expected)
        ->and(is_dir($calls[0]['temp']))->toBeFalse()
        ->and(File::get($this->localCiDir.'/parent-temp/keep'))->toBe('operator file');
})->with([
    'Composer failed' => ['LOCAL_CI_COMPOSER_EXIT', '23', ['composer']],
    'skeleton Pint failed' => ['LOCAL_CI_PINT_EXIT', '17', ['composer', 'pint']],
]);

it('refuses unsupported arguments before starting a gate', function (): void {
    $run = runLocalCiGate($this->localCiSource, $this->localCiDir, args: ['--skip-tests']);

    expect($run->getExitCode())->toBe(2)
        ->and(localCiCalls($this->localCiDir))->toBe([]);
});
