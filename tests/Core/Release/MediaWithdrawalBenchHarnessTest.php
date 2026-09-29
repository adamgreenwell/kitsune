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

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Console\MediaPruneCommand;
use Kitsune\Core\Media\MediaDisks;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Process\Process;

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

    expect(withdrawalBenchCounts(preg_split('/\R/', $listed), ['exposed' => 1000, 'awaiting publication' => 3]))->toBeTrue()
        ->and(withdrawalBenchCounts(preg_split('/\R/', $listed), ['awaiting publication' => 3, 'exposed' => 1000]))->toBeTrue()
        ->and(withdrawalBenchCounts(preg_split('/\R/', $listed), ['exposed' => 1000]))->toBeFalse()
        ->and(withdrawalBenchCounts(preg_split('/\R/', $listed), ['exposed' => 999, 'awaiting publication' => 3]))->toBeFalse()
        ->and(withdrawalBenchCounts(preg_split('/\R/', $listed), ['exposed' => 1000, 'awaiting publication' => 3, 'missing' => 1]))->toBeFalse()
        ->and(withdrawalBenchCounts([], ['exposed' => 1000]))->toBeFalse()
        // The rows, not what --force did with them.
        ->and(withdrawalBenchCounts(preg_split('/\R/', $forced), ['exposed' => 1000]))->toBeTrue();
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

/*
 * T145. Slice 5c's prune groups verify the rows prune printed a line each — exactly the seeded ones, no more and no fewer,
 * under the heading they belong to — in the line `MediaPruneCommand::rowLine()` writes for each, an extra copy's followed
 * by what --force would do with it.
 */
it('verifies a list of rows by exactly the rows under its heading, a line at a time', function (): void {
    $line = static fn (int $entry, string $path): string => MediaPruneCommand::rowLine((object) ['entry_id' => $entry, 'disk' => 'public', 'path' => $path]);
    $output = [
        'No orphaned media files.',
        'Trashed on a served disk — trashed, and still on a disk the web serves:',
        $line(2, 'media/1/2027/00/b.bin'),
        $line(1, 'media/1/2027/00/a.bin'),
        'Removed nothing.',
    ];
    $rows = [[1, 'public', 'media/1/2027/00/a.bin'], [2, 'public', 'media/1/2027/00/b.bin']];

    expect(withdrawalBenchListed($output, 'Trashed on a served disk', $rows))->toBeTrue()
        ->and(withdrawalBenchListed($output, 'Trashed on a served disk', [$rows[0]]))->toBeFalse()
        ->and(withdrawalBenchListed($output, 'Trashed on a served disk', [...$rows, [3, 'public', 'media/1/2027/00/c.bin']]))->toBeFalse()
        ->and(withdrawalBenchListed($output, 'Awaiting publication', $rows))->toBeFalse()
        ->and(withdrawalBenchListed($output, 'Awaiting publication', []))->toBeTrue()
        ->and(withdrawalBenchListed($output, 'Trashed on a served disk', [[1, 'kitsune-private', 'media/1/2027/00/a.bin'], $rows[1]]))->toBeFalse()
        // A row printed twice is not two rows.
        ->and(withdrawalBenchListed([...array_slice($output, 0, 3), $line(2, 'media/1/2027/00/b.bin'), ...array_slice($output, 3)], 'Trashed on a served disk', $rows))->toBeFalse()
        // Nor is a list printed twice one list, whether it holds the same rows again or one never seeded (review of 5c).
        ->and(withdrawalBenchListed([...$output, ...array_slice($output, 1, 3)], 'Trashed on a served disk', $rows))->toBeFalse()
        ->and(withdrawalBenchListed([...$output, $output[1], $line(9, 'media/1/2027/00/z.bin')], 'Trashed on a served disk', $rows))->toBeFalse();

    // Under its own heading only: prune prints rows awaiting publication before those trashed on a served disk.
    $awaiting = [5, 'kitsune-private', 'media/1/2027/00/p.bin'];
    $both = [
        'No orphaned media files.',
        'Awaiting publication — live and public, on a disk that is not the public one:',
        MediaPruneCommand::rowLine((object) ['entry_id' => 5, 'disk' => 'kitsune-private', 'path' => 'media/1/2027/00/p.bin']),
        'Trashed on a served disk — trashed, and still on a disk the web serves:',
        $line(1, 'media/1/2027/00/a.bin'),
    ];

    expect(withdrawalBenchListed($both, 'Awaiting publication', [$awaiting]))->toBeTrue()
        ->and(withdrawalBenchListed($both, 'Awaiting publication', [$awaiting, $rows[0]]))->toBeFalse()
        ->and(withdrawalBenchListed($both, 'Trashed on a served disk', [$rows[0]]))->toBeTrue()
        // Rows given one at a time, as the harness gives a hundred thousand of them.
        ->and(withdrawalBenchListed($both, 'Trashed on a served disk', (static function () use ($rows): Generator {
            yield $rows[0];
        })()))->toBeTrue();
});

/* ...an extra copy's under the entry it belongs to, and as one --force would remove: counted, a copy read back under
 * another entry — what a wrong packed id would print — or listed twice still passed (review of slice 5c). */
it('verifies extra copies by entry, path and what --force would do with each', function (): void {
    $line = static fn (int $entry, string $path, string $verdict = WITHDRAWAL_BENCH_REMOVABLE): string => MediaPruneCommand::rowLine((object) ['entry_id' => $entry, 'disk' => 'kitsune-private', 'path' => $path]).$verdict;
    $report = static fn (string ...$lines): array => ['No orphaned media files.', 'Extra copies — a copy beside the one its row names:', ...$lines, '2 removable extra copies.'];
    $rows = [[101, 'kitsune-private', 'media/1/2027/00/extra-0.bin'], [102, 'kitsune-private', 'media/1/2027/01/extra-1.bin']];
    $listed = static fn (array $report): bool => withdrawalBenchListed($report, 'Extra copies', $rows, WITHDRAWAL_BENCH_REMOVABLE);

    expect($listed($report($line(101, $rows[0][2]), $line(102, $rows[1][2]))))->toBeTrue()
        ->and($listed($report($line(7, $rows[0][2]), $line(7, $rows[1][2]))))->toBeFalse()
        ->and($listed($report($line(101, $rows[0][2]), $line(101, $rows[0][2]))))->toBeFalse()
        ->and($listed($report($line(101, 'media/1/2027/02/other.bin'), $line(102, $rows[1][2]))))->toBeFalse()
        ->and($listed($report($line(101, $rows[0][2]))))->toBeFalse()
        ->and($listed($report($line(101, $rows[0][2]), $line(102, $rows[1][2], ' — live, its row names [public], it belongs on [public]: kept: [public] could not be listed'))))->toBeFalse()
        // Its heading again, over the same rows or one never seeded, is a list printed twice.
        ->and($listed($report($line(101, $rows[0][2]), $line(102, $rows[1][2]), 'Extra copies — again:', $line(101, $rows[0][2]), $line(102, $rows[1][2]))))->toBeFalse()
        ->and($listed($report($line(101, $rows[0][2]), $line(102, $rows[1][2]), 'Extra copies — again:', $line(999, 'media/1/2027/02/never-seeded.bin'))))->toBeFalse();
});

/*
 * A listing's figure is a pass at the floor's limit, and reconcile's over (I') one that opened a copy on the public disk and
 * one on the served disk for each row held twice: a child at another limit, or a seed reconcile calls `private copy` and
 * opens nothing of, is no figure — checks no test reached until they were drawn out of the run — and nor is one that read
 * the target's copy twice and the served one never, which a total passed (review of slice 5c).
 */
it('verifies a listing child by its limit and, for reconcile, the copies it opened on each disk', function (): void {
    $each = static fn (int $public, int $served): array => ['public' => $public, 'bench-served' => $served];

    expect(withdrawalBenchChildVerified(['limit' => '128M', 'opened' => $each(1000, 1000)], 'kitsune:media-reconcile', ['extra' => 1000]))->toBeTrue()
        ->and(withdrawalBenchChildVerified(['limit' => '128M', 'opened' => $each(0, 0)], 'kitsune:media-reconcile', []))->toBeTrue()
        ->and(withdrawalBenchChildVerified(['limit' => '128M'], 'kitsune:media-prune', []))->toBeTrue()
        ->and(withdrawalBenchChildVerified(['limit' => '-1', 'opened' => $each(1000, 1000)], 'kitsune:media-reconcile', ['extra' => 1000]))->toBeFalse()
        ->and(withdrawalBenchChildVerified(['limit' => '-1'], 'kitsune:media-prune', []))->toBeFalse()
        ->and(withdrawalBenchChildVerified([], 'kitsune:media-prune', []))->toBeFalse()
        ->and(withdrawalBenchChildVerified(['limit' => '128M', 'opened' => $each(0, 0)], 'kitsune:media-reconcile', ['extra' => 1000]))->toBeFalse()
        ->and(withdrawalBenchChildVerified(['limit' => '128M', 'opened' => $each(1000, 0)], 'kitsune:media-reconcile', ['extra' => 1000]))->toBeFalse()
        // The target's copy read twice, the served one never; and a total, which cannot say which disk.
        ->and(withdrawalBenchChildVerified(['limit' => '128M', 'opened' => $each(2000, 0)], 'kitsune:media-reconcile', ['extra' => 1000]))->toBeFalse()
        ->and(withdrawalBenchChildVerified(['limit' => '128M', 'opened' => 2000], 'kitsune:media-reconcile', ['extra' => 1000]))->toBeFalse()
        ->and(withdrawalBenchChildVerified(['limit' => '128M'], 'kitsune:media-reconcile', ['extra' => 1000]))->toBeFalse();
});

/* ...and prune's, one that found no orphan and closed on exactly the seeded count of removable extra copies. */
it('verifies prune\'s closing by no orphan and exactly the removable count', function (): void {
    $summary = static fn (int $removable): string => sprintf('0 orphaned files, 0 leftover partial copies and %d removable extra copies listed and nothing removed. Re-run with --force to delete them.', $removable);

    expect(withdrawalBenchPruneClosing(['No orphaned media files.', $summary(1000)], 1000))->toBeTrue()
        ->and(withdrawalBenchPruneClosing([$summary(1000)], 1000))->toBeFalse()
        ->and(withdrawalBenchPruneClosing(['No orphaned media files.', $summary(11000)], 1000))->toBeFalse()
        ->and(withdrawalBenchPruneClosing(['No orphaned media files.'], 1000))->toBeFalse()
        ->and(withdrawalBenchPruneClosing(['No orphaned media files.'], null))->toBeTrue()
        ->and(withdrawalBenchPruneClosing(["\e[32mNo orphaned media files.\e[39m"], null))->toBeFalse()
        ->and(withdrawalBenchPruneClosing(['No orphaned media files.', '0 orphaned files, 0 leftover partial copies and 1 removable extra copy listed and nothing removed.'], 1))->toBeTrue();
});

/*
 * ...and a prune figure said to cost the pass over the table is one the pass ran in, and one said to cost nothing of it is
 * one it did not: the pass's own statement, whatever each grammar quotes it with, and no other (review of #155's fix).
 */
it('verifies a prune listing by whether the pass over the table ran', function (): void {
    expect(withdrawalBenchPassQuery('select "id", "entry_id", "disk", "path" from "media_files" where "id" > ? order by "id" asc limit 500'))->toBeTrue()
        ->and(withdrawalBenchPassQuery('select `id`, `entry_id`, `disk`, `path` from `media_files` order by `id` asc limit 500'))->toBeTrue()
        // rowDisks(), and the report's own read of the rows it prints, are not the pass.
        ->and(withdrawalBenchPassQuery('select "id", "disk" from "media_files" order by "id" asc limit 500'))->toBeFalse()
        ->and(withdrawalBenchPassQuery('select "media_files"."id", "media_files"."entry_id", "media_files"."disk", "media_files"."path" from "media_files" left join "entries"'))->toBeFalse()
        ->and(withdrawalBenchPassVerified(['passed' => 3], 3))->toBeTrue()
        // A pass that read only its first batch of three (review of slice 5c).
        ->and(withdrawalBenchPassVerified(['passed' => 1], 3))->toBeFalse()
        ->and(withdrawalBenchPassVerified(['passed' => 0], 3))->toBeFalse()
        ->and(withdrawalBenchPassVerified(['passed' => 0], 0))->toBeTrue()
        ->and(withdrawalBenchPassVerified(['passed' => 1], 0))->toBeFalse()
        ->and(withdrawalBenchPassVerified([], 0))->toBeFalse();
});

/*
 * ...and each group's seed settled before it is timed: on PostgreSQL vacuumed and analysed, so the dead rows the last
 * group's delete left do not slow this one; elsewhere nothing is asked (review of #155's fix).
 */
it('settles the tables a group seeds before it is timed, on PostgreSQL alone', function (): void {
    $asked = static function (string $driver, int $level): array {
        $statements = [];
        withdrawalBenchSettleTables($driver, $level, static function (string $sql) use (&$statements): bool {
            $statements[] = $sql;

            return true;
        });

        return $statements;
    };

    expect($asked('pgsql', 0))->toBe(['VACUUM ANALYZE media_files', 'VACUUM ANALYZE entries', 'VACUUM ANALYZE entry_relations'])
        ->and($asked('pgsql', 1))->toBe([])
        ->and($asked('sqlite', 0))->toBe([])
        ->and($asked('mysql', 0))->toBe([])
        ->and($asked('mariadb', 0))->toBe([]);
});

/* ...and that step run by each group's seed, after it and not after the group: the call reached, as well as its choice. */
it('runs the settle step after each group\'s seed, and not after the group', function (): void {
    Storage::fake('public');
    Storage::fake(MediaDisks::PRIVATE);
    config(['filesystems.disks.'.MediaDisks::PRIVATE.'.root' => Storage::disk(MediaDisks::PRIVATE)->path('')]);
    withdrawalBenchSeed();
    $bench = new WithdrawalBench('sqlite');
    $seen = [];
    (new ReflectionProperty($bench, 'settle'))->setValue($bench, static function () use (&$seen): void {
        $seen[] = DB::table('media_files')->count();
    });
    $call = static fn (string $method, mixed ...$args): mixed => (new ReflectionMethod($bench, $method))->invoke($bench, ...$args);
    [$before, $after] = $call('isolated', fn (): mixed => $call('seedRows', 150, 50, 16, 'spelt'));

    $before();

    // Once, with the seed's rows in the table: after the seed, not before the clear.
    expect($seen)->toBe([200])->and(DB::table('media_files')->count())->toBe(200);

    $after();

    expect($seen)->toBe([200]);
});

/* ...and (O)'s, one that closed on exactly the seeded orphans and listed exactly them, and nothing else (Codex, #155). */
it('verifies prune\'s orphans by exactly the count and the names seeded', function (): void {
    $summary = static fn (int $orphans): string => sprintf('%d orphaned file%s, 0 leftover partial copies and 0 removable extra copies listed and nothing removed. Re-run with --force to delete them.', $orphans, $orphans === 1 ? '' : 's');
    $heading = 'Orphaned media files — no row names their paths, on any disk:';
    $paths = ['media/1/2027/00/orphan-1.bin', 'media/1/2027/01/orphan-2.bin'];
    $lines = [$heading, '  [kitsune-private]  '.$paths[0], '  [kitsune-private]  '.$paths[1], $summary(2)];

    expect(withdrawalBenchOrphansClosing($lines, 2))->toBeTrue()
        ->and(withdrawalBenchOrphansClosing([$summary(12)], 2))->toBeFalse()
        ->and(withdrawalBenchOrphansClosing([$summary(2)], 1))->toBeFalse()
        ->and(withdrawalBenchOrphans($lines, 'kitsune-private', $paths))->toBeTrue()
        ->and(withdrawalBenchOrphans($lines, 'kitsune-private', [$paths[0]]))->toBeFalse()
        ->and(withdrawalBenchOrphans([$heading, '  [kitsune-private]  '.$paths[0]], 'kitsune-private', $paths))->toBeFalse()
        ->and(withdrawalBenchOrphans([...$lines, $heading], 'kitsune-private', $paths))->toBeFalse()
        ->and(withdrawalBenchOrphans($lines, 'public', $paths))->toBeFalse()
        ->and(withdrawalBenchOrphans([], 'kitsune-private', []))->toBeFalse()
        // A copy a row reaches under another spelling is a list of its own, which a run of orphans must not print.
        ->and(withdrawalBenchOnlyList([...$lines, 'Copies a row reaches under another spelling, on a disk it does not name:'], 'Orphaned media files'))->toBeFalse()
        ->and(withdrawalBenchOnlyList(['No orphaned media files.'], 'no list'))->toBeTrue();
});

/*
 * (O) and (O'), the harness's own seeds at a small scale, through prune itself: the orphans listed and nothing else, and
 * each row spelt as the disks read another path reaching its file, so nothing listed at all (Codex, #155).
 */
it('verifies the orphan and spelt seeds by what prune prints of them', function (string $kind): void {
    Storage::fake('public');
    Storage::fake(MediaDisks::PRIVATE);
    config(['filesystems.disks.'.MediaDisks::PRIVATE.'.root' => Storage::disk(MediaDisks::PRIVATE)->path('')]);
    withdrawalBenchSeed();
    $bench = new WithdrawalBench('sqlite');
    $call = static fn (string $method, mixed ...$args): mixed => (new ReflectionMethod($bench, $method))->invoke($bench, ...$args);
    // Past the pass's second batch, so a pass that read only its first is seen (review of slice 5c).
    [$before, $after] = $call('isolated', fn (): mixed => $call('seedRows', 1_150, $kind === 'none' ? 0 : 50, 16, $kind));
    $report = tempnam(sys_get_temp_dir(), 'kitsune-bench-report-');
    $passed = 0;
    DB::listen(static function (QueryExecuted $query) use (&$passed): void {
        $passed += withdrawalBenchPassQuery($query->sql) ? 1 : 0;
    });

    try {
        $before();
        $passed = 0;
        $output = new BufferedOutput;
        Artisan::call('kitsune:media-prune', [], $output);
        $printed = $output->fetch();
        file_put_contents($report, $printed);

        // The pass read every row where the seed lists an orphan — 1,150 or 1,200 of them, three reads — and ran not at
        // all where it lists none, as the child counts it.
        expect($call('prunePrinted', $report))->toBeTrue()
            ->and($passed)->toBe($kind === 'none' ? 0 : 3);

        // ...and the listing's own verify reads it: the figure is verified by a child that ran the pass as the seed calls
        // for, and not by one that ran it otherwise. The verify removes the report it read, so each reads a copy.
        $verify = $call('listing', 'kitsune:media-prune', [])['verify'];
        $state = static function (int $passed) use ($printed): array {
            $copy = (string) tempnam(sys_get_temp_dir(), 'kitsune-bench-report-');
            file_put_contents($copy, $printed);

            return ['exit' => 0, 'file' => $copy, 'child' => ['limit' => WITHDRAWAL_BENCH_CHILD_LIMIT, 'passed' => $passed]];
        };

        expect($verify($state($passed)))->toBeTrue()
            ->and($verify($state($kind === 'none' ? 1 : 0)))->toBeFalse()
            // ...nor by one whose pass read only its first batch.
            ->and($verify($state(1)))->toBeFalse();

        if ($kind !== 'orphan') {
            // A list printed where the seed leaves none, every count unchanged: not verified — (O') and (O'') list nothing at
            // all (review of slice 5c).
            $another = (string) preg_replace('/^(No orphaned media files\.)$/m', "\$1\nCopies a row reaches under another spelling, on a disk it does not name — kept:\n  entry 3  [kitsune-private]  media/1/2027/03/x.bin", $printed, 1);
            file_put_contents($report, $another);

            expect($another)->not->toBe($printed)->and($call('prunePrinted', $report))->toBeFalse();
        }

        if ($kind === 'orphan') {
            // An orphan's line gone, the count unchanged: not verified — the names are read, not only counted.
            file_put_contents($report, (string) preg_replace('/^  \[kitsune-private\]  [^\n]+\n/m', '', $printed, 1));

            expect($call('prunePrinted', $report))->toBeFalse();

            // The summary a count short of the names listed: not verified — the count is read as well as the names.
            $miscounted = (string) preg_replace('/^50 orphaned files/m', '49 orphaned files', $printed, 1);
            file_put_contents($report, $miscounted);

            expect($miscounted)->not->toBe($printed)->and($call('prunePrinted', $report))->toBeFalse();

            // Another list printed beside the orphans, every count unchanged: not verified — nothing else may be listed.
            $another = (string) preg_replace('/^(50 orphaned files)/m', "Copies a row reaches under another spelling, on a disk it does not name — kept:\n  entry 3  [kitsune-private]  media/1/2027/03/x.bin\n\$1", $printed, 1);
            file_put_contents($report, $another);

            expect($another)->not->toBe($printed)->and($call('prunePrinted', $report))->toBeFalse();
        }

        $after();
    } finally {
        @unlink($report);
    }
})->with(['orphans, at a small scale' => 'orphan', 'rows spelt as the disks read another, at a small scale' => 'spelt', 'no orphan, the control' => 'none']);

/*
 * (I'')'s served disk has the directories (I')'s misses walk, so (I') − (I'') is the copies held twice, not a path's
 * depth: without them each miss there failed at its first component, and the difference was mostly that (review of
 * slice 5c). The harness's own seed, at a small scale.
 */
it('gives the control\'s served disk the directories the served seed makes', function (): void {
    Storage::fake('public');
    Storage::fake(MediaDisks::PRIVATE);
    withdrawalBenchSeed();
    $bench = new WithdrawalBench('sqlite');
    $call = static fn (string $method, mixed ...$args): mixed => (new ReflectionMethod($bench, $method))->invoke($bench, ...$args);
    $tree = static function (): array {
        $root = storage_path('app/bench-served/media');
        $directories = [];

        if (is_dir($root)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
                if ($file->isDir()) {
                    $directories[] = substr($file->getPathname(), strlen($root));
                }
            }
        }

        sort($directories);

        return $directories;
    };
    $trees = [];

    try {
        foreach (['served' => 50, 'none' => 0] as $kind => $residue) {
            [$before, $after] = $call('withBenchServed', $call('isolated', fn (): mixed => $call('seedRows', 150, $residue, 16, $kind)));
            $before();
            $trees[$kind] = $tree();
            $after();
        }
    } finally {
        exec('rm -rf '.escapeshellarg(storage_path('app/bench-served')));
    }

    expect($trees['none'])->toBe($trees['served'])->and($trees['none'])->toHaveCount(102);
});

/*
 * A child's command is timed once the console application is built: timed with it, every child's figure counted some
 * 30 ms of building it, which a run in the harness's own process — 5b's figures — never did (review of slice 5c).
 */
it('times a child\'s command only once the console application is built', function (): void {
    // The kernel is final, so it is stood in for, not mocked: building the application takes 200 ms here.
    $console = new class
    {
        /** @var list<array{0: string, 1: object}> */
        public array $calls = [];

        public function call(string $command, array $parameters, object $output): int
        {
            $this->calls[] = [$command, $output];

            if ($command === 'env') {
                usleep(200_000);
            }

            return $command === 'env' ? 0 : 3;
        }
    };
    Artisan::swap($console);
    $output = new BufferedOutput;

    [$exit, $ms] = withdrawalBenchTimed('kitsune:media-prune', $output);

    expect(array_map(static fn (array $call): string => $call[0], $console->calls))->toBe(['env', 'kitsune:media-prune'])
        ->and($console->calls[0][1])->toBeInstanceOf(NullOutput::class)
        ->and($console->calls[1][1])->toBe($output)
        ->and($exit)->toBe(3)
        ->and($ms)->toBeLessThan(200.0);
});

/* A read-only reconcile passes over (I'')'s settled rows and (I')'s extra ones, and fails over any finding (slice 5c). */
it('expects a read-only reconcile to pass on no findings or extra copies alone', function (): void {
    expect(withdrawalBenchReconcileExit([]))->toBe(0)
        ->and(withdrawalBenchReconcileExit(['extra' => 1_000]))->toBe(0)
        ->and(withdrawalBenchReconcileExit(['exposed' => 1_000]))->toBe(1)
        ->and(withdrawalBenchReconcileExit(['extra' => 1, 'exposed' => 1]))->toBe(1);
});

/*
 * ...and prune's lines are built at their own size: the (N') verify holds a hundred thousand of them, and an unqualified
 * `sprintf` kept each in a 320-byte block, over three times its exact one — some 22 MB of the floor's 128 (review of
 * slice 5c).
 */
it('builds each listed line at its own size', function (): void {
    gc_collect_cycles();
    $before = memory_get_usage();
    $lines = [];

    for ($n = 1; $n <= 10_000; $n++) {
        $lines[] = MediaPruneCommand::rowLine((object) ['entry_id' => $n, 'disk' => 'kitsune-private', 'path' => 'media/1/2027/09/'.$n.'-bench.bin']);
    }

    expect((memory_get_usage() - $before) / 10_000)->toBeLessThan(200.0)
        ->and($lines)->toHaveCount(10_000);
});

/* `--only` runs the groups named by the name each first label opens with — G' is not G — and every group when none is named. */
it('selects a group by the letter its label opens with', function (): void {
    expect(withdrawalBenchSelected(['(G\') prune, read-only: 100,000 rows'], null))->toBeTrue()
        ->and(withdrawalBenchSelected(['(G\') prune, read-only: 100,000 rows'], ['G\'', 'N']))->toBeTrue()
        ->and(withdrawalBenchSelected(['(G\') prune, read-only: 100,000 rows'], ['G']))->toBeFalse()
        ->and(withdrawalBenchSelected(['(N\') prune, read-only: 10,000 rows awaiting publication'], ['N']))->toBeFalse()
        ->and(withdrawalBenchSelected([], ['N']))->toBeFalse();
});

/* ...and a name no group's first label opens with is named, so the run refuses rather than measure nothing — (E) included. */
it('names what --only gives that no group opens with', function (): void {
    $firstLabels = ['(A) trash, 8 KB: its hold', '(G\') prune, read-only: 100,000 rows', '(N) prune, read-only: 10,000 rows, each with an extra copy'];

    expect(withdrawalBenchUnknownOnly($firstLabels, ['G\'', 'N']))->toBe([])
        ->and(withdrawalBenchUnknownOnly($firstLabels, ['E', 'N', 'G']))->toBe(['E', 'G']);
});

/* T145, continued: a figure prune's run verifies is one whose report holds no list but the seeded one. */
it('verifies that prune printed no list but its own', function (): void {
    $line = MediaPruneCommand::rowLine((object) ['entry_id' => 1, 'disk' => 'public', 'path' => 'media/1/2027/00/a.bin']);
    $only = ['No orphaned media files.', 'Trashed on a served disk — trashed, and still on a disk the web serves:', $line];
    $more = [...$only, 'Awaiting publication — live and public, on a disk that is not the public one:', $line];

    expect(withdrawalBenchOnlyList($only, 'Trashed on a served disk'))->toBeTrue()
        ->and(withdrawalBenchOnlyList($more, 'Trashed on a served disk'))->toBeFalse()
        ->and(withdrawalBenchOnlyList(['Orphaned media files — no row names their paths, on any disk:'], 'Extra copies'))->toBeFalse();
});

/* ...and a figure no run verified says why when a run stopped — the floor's limit reached — rather than listed wrongly. */
it('says why a figure was not verified when a run stopped', function (): void {
    $wrong = [['ms' => 1.0, 'verified' => false, 'stopped' => null]];
    $stopped = [['ms' => 0.0, 'verified' => false, 'stopped' => "stopped at 128M: Allowed memory size of 134217728 bytes exhausted | here\nand more"]];

    expect(withdrawalBenchUnverified('(N) prune', $wrong))->toBe('| (N) prune | not verified — no figure | |')
        ->and(withdrawalBenchUnverified('(N) prune', $stopped))->toBe('| (N) prune | not verified — no figure (stopped at 128M: Allowed memory size of 134217728 bytes exhausted / here) | |');
});

/*
 * ...and a child that runs out of memory says so itself, as its last line — before any shutdown function registered after
 * the reporter, which is how Laravel's render, registered as the application boots, would bury it or fail in its turn.
 */
it('reports a stop as the last line a child prints, ahead of any later shutdown function', function (): void {
    $script = tempnam(sys_get_temp_dir(), 'kitsune-bench-stop-');
    file_put_contents($script, '<?php require '.var_export(dirname(__DIR__, 3).'/bin/benchmark-media-withdrawal.php', true).';'
        .' withdrawalBenchStopReporter();'
        .' register_shutdown_function(static function (): void { echo "a later shutdown function", PHP_EOL; });'
        .' $held = []; while (true) { $held[] = str_repeat("x", 1 << 16); }');

    try {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=16M', $script]);
        $process->run();
        $last = json_decode(trim((string) strrchr("\n".trim($process->getOutput()), "\n")), true);

        expect($last)->toBeArray()
            ->and($last['stopped'])->toContain('Allowed memory size')
            ->and($last['limit'])->toBe('16M')
            ->and($last['peak'])->toBeGreaterThan(0)
            ->and($process->getOutput())->not->toContain('a later shutdown function')
            ->and($process->getExitCode())->toBe(255);
    } finally {
        @unlink($script);
    }
});

/*
 * The run's directory goes when the parent finishes, and after a fatal error or one of the signals below — past a later
 * shutdown function that makes it again, as Laravel's handler does when it logs that error to storage_path('logs')
 * inside it (review of slice 5c).
 */
it('removes the run\'s directory after a fatal error, and after a later shutdown function makes it again', function (): void {
    $directory = sys_get_temp_dir().'/kitsune-bench-cleanup-'.bin2hex(random_bytes(6));
    mkdir($directory.'/storage/logs', 0700, true);
    $script = tempnam(sys_get_temp_dir(), 'kitsune-bench-cleanup-');
    file_put_contents($script, '<?php require '.var_export(dirname(__DIR__, 3).'/bin/benchmark-media-withdrawal.php', true).';'
        .' $directory = '.var_export($directory, true).'; withdrawalBenchCleanup($directory);'
        .' register_shutdown_function(static function () use ($directory): void { @mkdir($directory."/storage/logs", 0700, true); file_put_contents($directory."/storage/logs/laravel.log", "logged"); });'
        .' $held = []; while (true) { $held[] = str_repeat("x", 1 << 16); }');

    try {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=16M', $script]);
        $process->run();

        expect($process->getExitCode())->toBe(255)
            ->and(is_dir($directory))->toBeFalse();
    } finally {
        @unlink($script);
        exec('rm -rf '.escapeshellarg($directory));
    }
});

/*
 * ...and past Ctrl-C, Ctrl-\, SIGTERM and SIGHUP, which end a process PHP has no handler for with neither `finally` nor a
 * shutdown function run: a stopped run left its directory, and 100,000 seeded files, behind (review of slice 5c).
 */
it('removes the run\'s directory when the parent is stopped by a signal', function (int $signal): void {
    $directory = sys_get_temp_dir().'/kitsune-bench-cleanup-'.bin2hex(random_bytes(6));
    mkdir($directory.'/storage', 0700, true);
    $script = tempnam(sys_get_temp_dir(), 'kitsune-bench-cleanup-');
    file_put_contents($script, '<?php require '.var_export(dirname(__DIR__, 3).'/bin/benchmark-media-withdrawal.php', true).';'
        .' $directory = '.var_export($directory, true).'; withdrawalBenchCleanup($directory);'
        .' touch($directory."/ready"); while (true) { usleep(10_000); }');

    try {
        $process = new Process([PHP_BINARY, $script]);
        $process->start();

        for ($waited = 0; ! is_file($directory.'/ready') && $waited < 10_000; $waited += 10) {
            usleep(10_000);
        }

        $ready = is_file($directory.'/ready');
        $process->signal($signal);
        $process->wait();

        expect($ready)->toBeTrue()
            ->and($process->getExitCode())->toBe(128 + $signal)
            ->and(is_dir($directory))->toBeFalse();
    } finally {
        @unlink($script);
        exec('rm -rf '.escapeshellarg($directory));
    }
})->with(['Ctrl-C' => fn (): int => SIGINT, 'Ctrl-\\' => fn (): int => SIGQUIT, 'SIGTERM' => fn (): int => SIGTERM, 'SIGHUP' => fn (): int => SIGHUP])
    ->skip(! function_exists('pcntl_signal'), 'pcntl is not loaded, so no signal is caught');

/* ...and a child's report is plain text, colour codes or none asked for: the checks read it line by line, exactly. */
it('writes a child\'s report without colour codes, whatever the environment asks', function (): void {
    $before = getenv('FORCE_COLOR');
    putenv('FORCE_COLOR=1');
    $handle = fopen('php://memory', 'w+b');

    try {
        withdrawalBenchReportOutput($handle)->writeln('<info>No orphaned media files.</info>');
        rewind($handle);

        expect(stream_get_contents($handle))->toBe('No orphaned media files.'.PHP_EOL);
    } finally {
        fclose($handle);
        putenv($before === false ? 'FORCE_COLOR' : 'FORCE_COLOR='.$before);
    }
});

/*
 * ...and it is registered before the application boots, which registers Laravel's own shutdown handler: that one would
 * otherwise run first, and run out of memory again, and the report never be printed. A child boots through the seam that
 * orders the two; here, over a bare application's HandleExceptions, as the skeleton's boot registers it.
 */
it('registers a child\'s stop reporter before the application boots, and a parent\'s not at all', function (?string $child): void {
    $script = tempnam(sys_get_temp_dir(), 'kitsune-bench-boot-');
    file_put_contents($script, '<?php require '.var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true).';'
        .' require '.var_export(dirname(__DIR__, 3).'/bin/benchmark-media-withdrawal.php', true).';'
        .' withdrawalBenchBoot('.var_export($child, true).', static function (): void {'
        .' $app = new Illuminate\\Foundation\\Application(sys_get_temp_dir()); $app["env"] = "local";'
        .' (new Illuminate\\Foundation\\Bootstrap\\HandleExceptions)->bootstrap($app); });'
        .' $held = []; while (true) { $held[] = str_repeat("x", 1 << 16); }');

    try {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=16M', $script]);
        $process->run();
        $last = json_decode(trim((string) strrchr("\n".trim($process->getOutput()), "\n")), true);

        if ($child === null) {
            expect(is_array($last) && isset($last['stopped']))->toBeFalse();
        } else {
            expect($last)->toBeArray()
                ->and($last['stopped'])->toContain('Allowed memory size')
                ->and($last['limit'])->toBe('16M')
                ->and($process->getExitCode())->toBe(255);
        }
    } finally {
        @unlink($script);
    }
})->with(['a child' => ['kitsune:media-prune'], 'the parent' => [null]]);
