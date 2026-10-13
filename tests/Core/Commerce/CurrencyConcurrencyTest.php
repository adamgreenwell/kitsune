<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/** Copy the Testbench shell without following its vendor symlink or copying another test's bootstrap cache. */
function commerceCurrencyRaceCopyApplication(string $source, string $target): void
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
                commerceCurrencyRaceCopyApplication($item->getPathname(), $destination);
            }
        } else {
            File::copy($item->getPathname(), $destination);
        }
    }
}

/** @param list<Process> $processes */
function commerceCurrencyRaceBarrier(string $path, array $processes): array
{
    $deadline = microtime(true) + 15;

    while (! is_file($path)) {
        foreach ($processes as $process) {
            if (! $process->isRunning() && ! is_file($path)) {
                throw new RuntimeException('Currency race child exited before '.basename($path).': '
                    .$process->getOutput().$process->getErrorOutput());
            }
        }

        if (microtime(true) > $deadline) {
            throw new RuntimeException('Currency race barrier timed out: '.basename($path));
        }

        usleep(5000);
    }

    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

it('serialises currency selection against the first quoted price at actual SQLite level zero', function (string $ordering): void {
    $root = dirname(__DIR__, 3);
    $directory = sys_get_temp_dir().'/kitsune-currency-race-'.bin2hex(random_bytes(8));
    $processes = [];

    try {
        File::ensureDirectoryExists($directory);
        touch($directory.'/race.sqlite');

        foreach (['controller', 'A', 'B', 'read'] as $role) {
            commerceCurrencyRaceCopyApplication($root.'/vendor/orchestra/testbench-core/laravel', $directory.'/app-'.$role);
        }

        $child = function (string $mode, string $role) use ($root, $directory, $ordering, &$processes): Process {
            $process = new Process([
                PHP_BINARY, $root.'/tests/Core/Fixtures/CommerceCurrencyRaceProbe.php',
                $root, $directory, $mode, $role, $ordering,
            ], $root);
            $process->setTimeout(20);
            $processes[] = $process;

            return $process;
        };

        $child('init', 'controller')->mustRun();
        $initialized = commerceCurrencyRaceBarrier($directory.'/initialized.json', []);
        expect($initialized['level'])->toBe(0)->and($initialized['pdo_in_transaction'])->toBeFalse();

        $first = $child('worker', 'A');
        $first->start();
        $paused = commerceCurrencyRaceBarrier($directory.'/A-paused.json', [$first]);
        expect($paused['level'])->toBeGreaterThanOrEqual(1)->and($paused['pdo_in_transaction'])->toBeTrue();

        $second = $child('worker', 'B');
        $second->start();
        $attempted = commerceCurrencyRaceBarrier($directory.'/B-attempted.json', [$first, $second]);
        expect($attempted['level'])->toBe(1);

        // A is paused after its actual write, before commit. B must reach its own database refusal before A proceeds.
        // SQLite's deferred read-to-write upgrade refuses busy instead of waiting on the competing write lock.
        $secondResult = commerceCurrencyRaceBarrier($directory.'/B-first-result.json', [$first, $second]);
        expect($secondResult['success'])->toBeFalse()->and($secondResult['busy'])->toBeTrue();
        touch($directory.'/release-A');
        $first->wait();
        expect($first->isSuccessful())->toBeTrue($first->getOutput().$first->getErrorOutput());
        $firstResult = commerceCurrencyRaceBarrier($directory.'/A-first-result.json', []);
        expect($firstResult['success'])->toBeTrue();

        touch($directory.'/retry-B');
        $second->wait();
        expect($second->isSuccessful())->toBeTrue($second->getOutput().$second->getErrorOutput());
        $retry = commerceCurrencyRaceBarrier($directory.'/B-retry-result.json', []);

        if ($ordering === 'currency-first') {
            $stale = commerceCurrencyRaceBarrier($directory.'/B-stale-replay.json', []);
            expect($stale['success'])->toBeFalse()->and($stale['stale_quote'])->toBeTrue()
                ->and($stale['level'])->toBe(0)->and($stale['pdo_in_transaction'])->toBeFalse()
                ->and($retry['success'])->toBeTrue()->and($retry['currency'])->toBe('KWD');
        } else {
            expect($retry['success'])->toBeFalse()->and($retry['held_currency'])->toBeTrue();
        }

        $child('read', 'read')->mustRun();
        $stored = commerceCurrencyRaceBarrier($directory.'/readback.json', []);
        $currency = $ordering === 'price-first' ? 'USD' : 'KWD';
        expect($stored['org_currency'])->toBe($currency)
            ->and($stored['sellables'])->toHaveCount(1)
            ->and($stored['sellables'][0]['currency'])->toBe($currency)
            ->and((string) $stored['sellables'][0]['price_minor'])->toBe('123')
            ->and($stored['sellables'][0]['stock'])->toBeNull()
            ->and(array_count_values($stored['audit_actions'])['commerce.sellable_created'])->toBe(1)
            ->and(array_count_values($stored['audit_actions'])['commerce.currency_changed'])->toBe($ordering === 'price-first' ? 1 : 2);

        foreach ([$firstResult, $secondResult, $retry, $stored] as $result) {
            expect($result['level'])->toBe(0)->and($result['pdo_in_transaction'])->toBeFalse();
        }
    } finally {
        if (is_dir($directory)) {
            touch($directory.'/release-A');
            touch($directory.'/retry-B');
        }

        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0.2);
            }
        }

        File::deleteDirectory($directory);
    }
})->with(['price-first', 'currency-first'])->skip(
    fn (): bool => env('DB_CONNECTION', 'testing') !== 'testing',
    'This level-zero race uses its own SQLite files; server-engine lock waits are qualified separately.',
);
