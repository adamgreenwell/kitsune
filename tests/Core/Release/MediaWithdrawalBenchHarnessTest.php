<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

/*
 * The withdrawal benchmark refuses to run where it would do harm, and prints no figure it did not verify — ADR-042
 * decision 5's measurement (T55).
 *
 * ⚠️ THE HARNESS'S OWN FUNCTIONS, REQUIRED RATHER THAN RUN. `bin/benchmark-media-withdrawal.php` drops and rebuilds the
 * database it is pointed at, so its refusals are asked directly, here, and never by pointing it at something real. It
 * runs only when it is the script; required, it defines its functions and returns.
 */

require_once dirname(__DIR__, 3).'/bin/benchmark-media-withdrawal.php';

const BENCH_RUN = '/tmp/kitsune-bench-withdrawal-run';

/** @return array<string, string> both media roots inside the run's directory */
function benchRoots(): array
{
    return ['public' => BENCH_RUN.'/storage/app/public/media/', 'kitsune-private' => BENCH_RUN.'/storage/app/kitsune/private/media/'];
}

it('refuses every database but its own', function (string $driver, string $database, string $words): void {
    expect(withdrawalBenchRefusal($driver, $database, 'local', BENCH_RUN, benchRoots()))->toContain($words);
})->with([
    'the shared-media benchmark\'s' => ['pgsql', 'kitsune_bench', 'shared-media benchmark'],
    'the shared-media benchmark\'s, as a SQLite file' => ['sqlite', '/tmp/kitsune_bench.sqlite', 'shared-media benchmark'],
    'a name that only contains its own' => ['mysql', 'kitsune_bench_withdrawal_old', 'is not [kitsune_bench_withdrawal]'],
    'the application\'s' => ['mariadb', 'kitsune', 'is not [kitsune_bench_withdrawal]'],
    'an in-memory SQLite database' => ['sqlite', ':memory:', 'in-memory'],
]);

it('refuses a production environment', function (): void {
    expect(withdrawalBenchRefusal('pgsql', 'kitsune_bench_withdrawal', 'production', BENCH_RUN, benchRoots()))->toContain('production');
});

it('refuses a media disk that writes outside its own directory', function (): void {
    $roots = [...benchRoots(), 'public' => '/var/www/storage/app/public/media/'];

    expect(withdrawalBenchRefusal('pgsql', 'kitsune_bench_withdrawal', 'local', BENCH_RUN, $roots))->toContain('outside this run\'s own directory');
});

/** A prefix is not a directory: a sibling whose name begins with the run's is outside it. */
it('refuses a media root beside its directory whose name begins the same', function (): void {
    $roots = [...benchRoots(), 'kitsune-private' => BENCH_RUN.'-other/media/'];

    expect(withdrawalBenchRefusal('pgsql', 'kitsune_bench_withdrawal', 'local', BENCH_RUN, $roots))->toContain('outside this run\'s own directory');
});

/** The control: its own database, its media inside its directory, on each engine's naming. */
it('runs against its own database, with its media inside its directory', function (string $driver, string $database): void {
    expect(withdrawalBenchRefusal($driver, $database, 'local', BENCH_RUN, benchRoots()))->toBeNull();
})->with([
    'PostgreSQL' => ['pgsql', 'kitsune_bench_withdrawal'],
    'a SQLite file' => ['sqlite', '/tmp/kitsune_bench_withdrawal.sqlite'],
]);

it('prints no figure unless every run verified', function (): void {
    expect(withdrawalBenchFigure('trash', [['ms' => 1.0, 'verified' => true], ['ms' => 2.0, 'verified' => false]]))->toBeNull()
        ->and(withdrawalBenchFigure('trash', []))->toBeNull()
        ->and(withdrawalBenchFigure('trash', [['ms' => 3.0, 'verified' => true], ['ms' => 1.0, 'verified' => true], ['ms' => 2.0, 'verified' => true]]))
        ->toBe('| trash | 2.0 | 3.0 |');
});
