<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

function freeOrderCopyApplication(string $source, string $target): void
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
                freeOrderCopyApplication($item->getPathname(), $destination);
            }
        } else {
            File::copy($item->getPathname(), $destination);
        }
    }
}

/** @param list<Process> $processes */
function freeOrderBarrier(string $path, array $processes = []): array
{
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        foreach ($processes as $process) {
            if (! $process->isRunning()) {
                throw new RuntimeException('Free order child exited before '.basename($path).': '.$process->getOutput().$process->getErrorOutput());
            }
        }
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Free order barrier timed out: '.basename($path));
        }
        usleep(5000);
    }

    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

it('serializes free completion retries sibling-site stock and stale manual adjustments on independent native connections', function (string $scenario): void {
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir().'/kitsune-free-order-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($directory);
    touch($directory.'/race.sqlite');
    foreach (['controller', 'A', 'B', 'read', 'cleanup'] as $role) {
        freeOrderCopyApplication($root.'/vendor/orchestra/testbench-core/laravel', $directory.'/app-'.$role);
    }
    $processes = [];
    $child = static function (string $mode, string $role) use ($root, $directory, $scenario, &$processes): Process {
        $process = new Process([PHP_BINARY, __DIR__.'/Fixtures/FreeOrderRaceProbe.php', $root, $directory, $mode, $role, $scenario], $root);
        $process->setTimeout(35);
        $processes[] = $process;

        return $process;
    };
    try {
        $init = $child('init', 'controller');
        $init->mustRun();
        $initialized = freeOrderBarrier($directory.'/initialized.json', [$init]);
        $selected = getenv('DB_CONNECTION') ?: 'testing';
        $expectedDriver = in_array($selected, ['pgsql', 'mysql', 'mariadb'], true) ? $selected : 'sqlite';
        expect($initialized['driver'])->toBe($expectedDriver)->and($initialized['level'])->toBe(0)->and($initialized['pdoTransaction'])->toBeFalse();
        $a = $child('worker', 'A');
        $a->start();
        $paused = freeOrderBarrier($directory.'/A-paused.json', [$a]);
        expect($paused['level'])->toBeGreaterThanOrEqual(1)->and($paused['pdoTransaction'])->toBeTrue();
        $b = $child('worker', 'B');
        $b->start();
        $attempted = freeOrderBarrier($directory.'/B-attempted.json', [$a, $b]);
        expect($attempted['driver'])->toBe($expectedDriver)->and($attempted['level'])->toBe(1);
        usleep(100000);
        expect($b->isRunning())->toBeTrue()->and(is_file($directory.'/B-result.json'))->toBeFalse();
        touch($directory.'/release-A');
        $a->wait();
        $b->wait();
        expect($a->isSuccessful())->toBeTrue($a->getOutput().$a->getErrorOutput())->and($b->isSuccessful())->toBeTrue($b->getOutput().$b->getErrorOutput());
        foreach (['A', 'B'] as $role) {
            expect(freeOrderBarrier($directory.'/'.$role.'-result.json'))->toBe(['refused' => $role === 'B' && $scenario === 'manual', 'level' => 0, 'pdoTransaction' => false]);
            $child('worker', $role)->mustRun();
        }
        $child('read', 'read')->mustRun();
        $expected = $scenario !== 'orders'
            ? ['payments' => 0, 'allocations' => 2, 'awarded' => 2, 'unavailable' => 0, 'refunds' => 0, 'owed' => '0', 'movements' => 2, 'completions' => 1, 'freeAudits' => 1]
            : ['payments' => 0, 'allocations' => 4, 'awarded' => 3, 'unavailable' => 1, 'refunds' => 0, 'owed' => '0', 'movements' => 3, 'completions' => 2, 'freeAudits' => 2];
        expect(freeOrderBarrier($directory.'/readback.json'))->toBe(['driver' => $expectedDriver, 'stock' => '0', 'untracked' => null,
            ...$expected, 'grants' => 0, 'level' => 0, 'pdoTransaction' => false]);
    } finally {
        touch($directory.'/release-A');
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
})->with(['orders', 'duplicate', 'manual']);
