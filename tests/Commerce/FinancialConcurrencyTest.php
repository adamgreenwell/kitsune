<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

function financialCopyApplication(string $source, string $target): void
{
    File::ensureDirectoryExists($target);
    foreach (new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS) as $item) {
        if ($item->getFilename() === 'vendor' || $item->isLink()) {
            continue;
        }
        $destination = $target.'/'.$item->getFilename();
        if ($item->isDir()) {
            if (str_ends_with($destination, '/bootstrap/cache')) {
                File::ensureDirectoryExists($destination);
            } else {
                financialCopyApplication($item->getPathname(), $destination);
            }
        } else {
            File::copy($item->getPathname(), $destination);
        }
    }
}

/** @param list<Process> $processes */
function financialBarrier(string $path, array $processes = []): array
{
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        foreach ($processes as $process) {
            if (! $process->isRunning()) {
                throw new RuntimeException('Financial child exited before '.basename($path).': '.$process->getOutput().$process->getErrorOutput());
            }
        }
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Financial barrier timed out: '.basename($path));
        }
        usleep(5000);
    }

    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

it('serializes selected-unit refunds and stale manual revokes on independent selected-engine connections', function (string $scenario): void {
    $selected = getenv('DB_CONNECTION') ?: 'testing';
    $driver = in_array($selected, ['pgsql', 'mysql', 'mariadb'], true) ? $selected : 'sqlite';
    if ($scenario === 'restoration' && $driver === 'sqlite') {
        $this->markTestSkipped('The ordinary core revoke race is qualified on server row locks; SQLite snapshot contention has separate core retry coverage.');
    }
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir().'/kitsune-financial-race-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($directory, 0700);
    touch($directory.'/race.sqlite');
    foreach (['controller', 'A', 'B', 'during', 'read', 'cleanup'] as $role) {
        financialCopyApplication($root.'/vendor/orchestra/testbench-core/laravel', $directory.'/app-'.$role);
    }
    $processes = [];
    $child = static function (string $mode, string $role) use ($root, $directory, $scenario, &$processes): Process {
        $process = new Process([PHP_BINARY, __DIR__.'/Fixtures/FinancialRaceProbe.php', $root, $directory, $mode, $role, $scenario], $root);
        $process->setTimeout(45);
        $processes[] = $process;

        return $process;
    };
    try {
        $init = $child('init', 'controller');
        $init->mustRun();
        $initialized = financialBarrier($directory.'/initialized.json', [$init]);
        expect($initialized['driver'])->toBe($driver)->and($initialized['level'])->toBe(0)
            ->and($initialized['pdoTransaction'])->toBeFalse()->and($initialized['providerCalls'])->toBe(0);
        $a = $child('worker', 'A');
        $b = $child('worker', 'B');
        $a->start();
        $b->start();
        foreach (['A', 'B'] as $role) {
            expect(financialBarrier($directory.'/'.$role.'-ready.json', [$a, $b]))
                ->toBe(['driver' => $driver, 'level' => 0, 'pdoTransaction' => false]);
        }
        touch($directory.'/go-A');
        $paused = financialBarrier($directory.'/A-paused.json', [$a, $b]);
        expect($paused['driver'])->toBe($driver)->and($paused['level'])->toBeGreaterThanOrEqual(1)->and($paused['pdoTransaction'])->toBeTrue();
        $child('during', 'during')->mustRun();
        $during = financialBarrier($directory.'/during.json');
        expect($during['driver'])->toBe($driver)->and($during['intents'])->toBe($scenario === 'refund' ? 0 : 1)
            ->and($during['revoked'])->toBe($scenario === 'restoration')->and($during['providerCalls'])->toBe(0);
        touch($directory.'/go-B');
        $attempted = financialBarrier($directory.'/B-attempted.json', [$a, $b]);
        expect($attempted['driver'])->toBe($driver)->and($attempted['level'])->toBeGreaterThanOrEqual(1);
        usleep(100000);
        expect($b->isRunning())->toBeTrue()->and(is_file($directory.'/B-result.json'))->toBeFalse();
        touch($directory.'/release-A');
        $a->wait();
        $b->wait();
        foreach (['A' => $a, 'B' => $b] as $role => $process) {
            expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
            expect(financialBarrier($directory.'/'.$role.'-result.json'))->toBe(['driver' => $driver,
                'refused' => $scenario === 'refund' && $role === 'B', 'providerCalls' => 0, 'level' => 0, 'pdoTransaction' => false]);
        }
        // Replaying the exact successful request/fresh failure cannot duplicate money or undo a later manual revoke.
        $child('worker', 'A')->mustRun();
        $child('read', 'read')->mustRun();
        $read = financialBarrier($directory.'/read.json');
        expect($read)->toBe(['driver' => $driver, 'intents' => 1, 'requestAudits' => 1, 'revoked' => true,
            'source' => 'commerce.order:'.$initialized['order'], 'sourceRevokes' => $scenario === 'refund' ? 1 : 2,
            'sourceRestorations' => $scenario === 'refund' ? 0 : 1, 'providerIdentity' => $scenario === 'refund' ? null : 're_AccessAdjustment',
            'firstSubmitted' => null, 'awarded' => 1, 'stockMovements' => 1, 'providerCalls' => 0, 'level' => 0, 'pdoTransaction' => false]);
    } finally {
        foreach (['release-A', 'go-A', 'go-B'] as $barrier) {
            touch($directory.'/'.$barrier);
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0.2);
            }
        }
        try {
            $child('cleanup', 'cleanup')->mustRun();
        } finally {
            File::deleteDirectory($directory);
        }
    }
})->with(['refund', 'restoration']);
