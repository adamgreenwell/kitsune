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

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\BufferedOutput;

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

/** A command's summary, rendered as `Command::table()` renders it. @param list<list<int|string>> $rows */
function benchSummary(array $headers, array $rows): string
{
    $output = new BufferedOutput;
    (new Table($output))->setHeaders($headers)->setRows($rows)->render();

    return $output->fetch();
}

/** Slice 5b's share of a run spent holding a lock: printed only when every hold that took a lock closed (T55). */
it('reads the share of a run its holds took, and none while a hold is open', function (): void {
    $holds = [['start' => 0, 'end' => 20_000_000], ['start' => null, 'end' => 90_000_000], ['start' => 50_000_000, 'end' => 80_000_000]];

    // 20 ms and 30 ms of a 100 ms run; the transaction that never took a lock is not a hold.
    expect(withdrawalBenchHeld($holds, 100.0))->toBe(50.0)
        ->and(withdrawalBenchHeld([...$holds, ['start' => 95_000_000, 'end' => null]], 100.0))->toBeNull()
        ->and(withdrawalBenchHeld([['start' => null, 'end' => null]], 100.0))->toBeNull()
        ->and(withdrawalBenchHeld([], 100.0))->toBeNull()
        ->and(withdrawalBenchHeld($holds, 0.0))->toBeNull();
});

/** Slice 5b's groups verify by the counts their command printed: a count off by one, or a label unlooked-for, is no figure (T55). */
it('verifies a command by the rows it counted under each label, and under no other', function (): void {
    $listed = benchSummary(['Label', 'Rows'], [['exposed', 1000], ['awaiting publication', 3]]);
    $forced = benchSummary(['Label', 'Rows', 'Settled', 'Nothing to do', 'Gone', 'Kept', 'Missing', 'Failed'], [['exposed', 1000, 998, 0, 0, 1, 0, 1]]);

    expect(withdrawalBenchCounts($listed, ['exposed' => 1000, 'awaiting publication' => 3]))->toBeTrue()
        ->and(withdrawalBenchCounts($listed, ['awaiting publication' => 3, 'exposed' => 1000]))->toBeTrue()
        ->and(withdrawalBenchCounts($listed, ['exposed' => 1000]))->toBeFalse()
        ->and(withdrawalBenchCounts($listed, ['exposed' => 999, 'awaiting publication' => 3]))->toBeFalse()
        ->and(withdrawalBenchCounts($listed, ['exposed' => 1000, 'awaiting publication' => 3, 'missing' => 1]))->toBeFalse()
        ->and(withdrawalBenchCounts('', ['exposed' => 1000]))->toBeFalse()
        // The rows, not what --force did with them.
        ->and(withdrawalBenchCounts($forced, ['exposed' => 1000]))->toBeTrue();
});

/* (M)'s contention rows print their figures only when the holder held, the build was seen and the prober reported (T55). */
it('prints no contention figure for a row that did not happen as its heading says', function (): void {
    $row = ['attempt' => 'a write to media_files', 'outcome' => 'ok', 'waited' => 1712.4, 'statement' => 2051.0, 'up' => 2210.9];

    expect(withdrawalBenchContentionLine([...$row, 'verified' => true]))->toBe('| a write to media_files | ok | 1712.4 | 2051.0 | 2210.9 |')
        ->and(withdrawalBenchContentionLine([...$row, 'verified' => false]))->toBe('| a write to media_files | not verified — no figure (ok) | | | |');
});

/* ...and a row is verified only when every part of it happened: the holder held, and committed after the build began (T55). */
it('verifies a contention row only when the holder held, the build was seen and the prober reported', function (): void {
    $seen = [
        'held' => true,
        'holder' => ['exit' => 0, 'last' => '{"outcome":"held until two seconds into the build","waited":0}'],
        'built' => true,
        'prober' => ['exit' => 0, 'outcome' => 'ok'],
    ];

    expect(withdrawalBenchContentionVerified($seen))->toBeTrue()
        ->and(withdrawalBenchContentionVerified([...$seen, 'holder' => null]))->toBeTrue()
        ->and(withdrawalBenchContentionVerified([...$seen, 'held' => false]))->toBeFalse()
        ->and(withdrawalBenchContentionVerified([...$seen, 'holder' => ['exit' => 1, 'last' => $seen['holder']['last']]]))->toBeFalse()
        ->and(withdrawalBenchContentionVerified([...$seen, 'holder' => ['exit' => 0, 'last' => 'PHP Fatal error']]))->toBeFalse()
        ->and(withdrawalBenchContentionVerified([...$seen, 'built' => false]))->toBeFalse()
        ->and(withdrawalBenchContentionVerified([...$seen, 'prober' => ['exit' => 255, 'outcome' => 'ok']]))->toBeFalse()
        ->and(withdrawalBenchContentionVerified([...$seen, 'prober' => ['exit' => 0, 'outcome' => 'no result: Fatal']]))->toBeFalse();
});
