<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Kitsune\Core\Console\BenchmarkFloorCommand;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Schema\DriverFactory;
use Kitsune\Core\Tenancy\Context;
use Symfony\Component\Process\Process;

/*
 * The benchmarks run on an operator's installation — ADR-027's floor is a claim a host can check on its own
 * hardware, which is why they ship in core. So they have to behave like it: ask before writing to production,
 * and leave nothing behind.
 *
 * ⚠️ BOTH WERE FOUND MISSING BY INSTALLING THE SPLIT INTO A HOST (issue #8). The floor benchmark left its org,
 * site, entry type and every inserted entry; the storage benchmark removed its entries and left the rest; none
 * of the three asked before running in production.
 */

/**
 * Every row a benchmark could leave, counted around every scope.
 *
 * ⚠️ RAW TABLE COUNTS, because the models' own queries are the wrong instrument: `Org` soft-deletes, so its query
 * hides a trashed org — exactly the residue this is looking for — and the org and site scopes hide rows outside
 * whatever context the command left behind.
 *
 * @return array<string, int>
 */
function benchmarkFootprint(): array
{
    return collect(['orgs', 'sites', 'entry_types', 'entries', 'audit_log'])
        ->mapWithKeys(static fn (string $table): array => [$table => DB::table($table)->count()])
        ->all();
}

/**
 * The tenancy context a benchmark was called in, which it has to give back.
 *
 * ⚠️ A FIXTURE SETS THE APPLICATION'S ONE `Context`, and review found nothing restoring it: a caller running more
 * than one command in an application was left scoped to the benchmark's org — one a failed fixture had rolled
 * back, or cleanup had just removed.
 *
 * @return array{0: ?int, 1: ?int}
 */
function benchmarkContext(): array
{
    return [app(Context::class)->orgId(), app(Context::class)->siteId()];
}

/** Whether a benchmark's run lock is free on this host — asked the way a second run asks, and let go at once. */
function benchmarkLockIsFree(string $command): bool
{
    $path = BenchmarkFloorCommand::runLockPath($command);
    File::ensureDirectoryExists(dirname($path));

    $handle = fopen($path, 'c');
    $free = flock($handle, LOCK_EX | LOCK_NB);

    if ($free) {
        flock($handle, LOCK_UN);
    }

    fclose($handle);

    return $free;
}

/*
 * ⚠️ THE ENVIRONMENT GOES BACK BEFORE TEARDOWN, OR EVERY OTHER TEST FAILS IN THE FULL SUITE — and never alone.
 *
 * With an in-memory database Testbench alternates. One test migrates by path and caches nothing; the next migrates
 * through a `MigrateProcessor`, and `tearDownInteractsWithMigrations()` runs its `migrate:rollback` — which also
 * resets the refresh state, so the pattern repeats. `migrate:rollback` confirms in production. A test below that
 * left the application in production therefore failed at teardown, on alternate tests only, with a question no
 * expectation had been set for, while the command it appeared to be about had finished cleanly.
 *
 * Found the slow way: a trace wrapped around the command came back empty, which is what said the question was
 * asked after it.
 */
afterEach(function (): void {
    app()->detectEnvironment(static fn (): string => 'testing');
});

it('leaves the installation as it found it after the floor benchmark', function (): void {
    $before = benchmarkFootprint();
    $context = benchmarkContext();

    $this->artisan('kitsune:benchmark-floor', ['--entries' => 25])->assertSuccessful();

    // And the run lock goes with the run, or the next run of it is refused.
    expect(benchmarkFootprint())->toBe($before)
        ->and(benchmarkContext())->toBe($context)
        ->and(benchmarkLockIsFree('kitsune:benchmark-floor'))->toBeTrue();
});

it('leaves the installation as it found it after the storage benchmark', function (): void {
    $before = benchmarkFootprint();
    $context = benchmarkContext();

    $this->artisan('kitsune:benchmark-storage', ['--rows' => 20])->assertSuccessful();

    expect(benchmarkFootprint())->toBe($before)
        ->and(benchmarkContext())->toBe($context);
});

it('keeps what it made when asked to', function (): void {
    /*
     * ⚠️ THE OTHER HALF, or the two tests above could pass against a command that never wrote anything. With
     * `--keep` the fixture and its rows are still there, so the footprint really did move and really was
     * put back.
     */
    $before = benchmarkFootprint();

    $this->artisan('kitsune:benchmark-floor', ['--entries' => 25, '--keep' => true])->assertSuccessful();

    $after = benchmarkFootprint();

    expect($after['orgs'])->toBe($before['orgs'] + 1)
        ->and($after['entries'])->toBe($before['entries'] + 25)
        ->and(DB::table('orgs')->where('slug', 'floor-benchmark')->exists())->toBeTrue();
});

it('removes only the rows its own run inserted, and keeps a corpus an earlier run kept', function (): void {
    /*
     * ⚠️ REVIEW FOUND THE STORAGE BENCHMARK'S CLEANUP MATCHING `bench-%` ACROSS THE SITE, so a run without
     * `--keep` deleted the corpus an earlier run had kept. Two locale counts give the runs different slugs, so
     * the second run succeeds and its cleanup is the only thing under test.
     */
    $this->artisan('kitsune:benchmark-storage', ['--rows' => 5, '--locales' => 2, '--keep' => true])->assertSuccessful();

    $kept = benchmarkFootprint();

    $this->artisan('kitsune:benchmark-storage', ['--rows' => 5])->assertSuccessful();

    expect(benchmarkFootprint())->toBe($kept);
});

it('gives each invocation its own rows, so a run that inserts nothing removes nothing', function (): void {
    /*
     * ⚠️ ONE COMMAND OBJECT SERVES EVERY INVOCATION in an application — Artisan resolves a command once and keeps
     * it — so state held on the object outlives the run that set it. Review found a `--keep` run leaving its mark
     * behind, and a second run whose volume was already met, inserting nothing and taking no mark of its own,
     * cleaning up above the first run's mark: deleting exactly the rows `--keep` had kept. A token belongs to one
     * invocation, as the mark did not.
     */
    $this->artisan('kitsune:benchmark-floor', ['--entries' => 5, '--keep' => true])->assertSuccessful();

    $kept = benchmarkFootprint();

    $this->artisan('kitsune:benchmark-floor', ['--entries' => 5])
        ->assertSuccessful()
        ->expectsOutputToContain('content in scope: 5 entries');

    expect(benchmarkFootprint())->toBe($kept);
});

it('removes none of the rows another run inserts while it is running', function (): void {
    /*
     * ⚠️ AN ID RANGE IS NOT OWNERSHIP ONCE TWO RUNS OVERLAP — review found it. A run's rows were everything in its
     * site above the mark it took, and a second run on the same site inserts above that mark too, so whichever run
     * cleaned up first removed the other's rows along with its own.
     *
     * The other run's rows are written inside this one, as its first chunk lands, rather than hoped for from a
     * second process. The fixture is kept from an earlier run, so cleanup cannot pass by removing a whole org.
     */
    $this->artisan('kitsune:benchmark-floor', ['--entries' => 0, '--keep' => true])->assertSuccessful();

    $written = false;

    DB::listen(function (QueryExecuted $query) use (&$written): void {
        if ($written || ! str_starts_with(str_replace(['"', '`'], '', $query->sql), 'insert into entries')) {
            return;
        }

        $written = true;
        $ours = DB::table('entries')->where('slug', 'like', 'floor-%')->first();

        DB::table('entries')->insert(array_map(static fn (int $i): array => [
            'site_id' => $ours->site_id,
            'org_id' => $ours->org_id,
            'entry_type_id' => $ours->entry_type_id,
            'type_handle' => 'article',
            'status' => 'published',
            'slug' => "floor-another-run-{$i}",
            'title' => "Another run's entry {$i}",
            'values' => json_encode([]),
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], [1, 2, 3]));
    });

    $this->artisan('kitsune:benchmark-floor', ['--entries' => 5])->assertSuccessful();

    // The other run's three rows survive; this run's five are gone.
    expect($written)->toBeTrue()
        ->and(DB::table('entries')->where('slug', 'like', 'floor-another-run-%')->count())->toBe(3)
        ->and(DB::table('entries')->where('slug', 'like', 'floor-%')->count())->toBe(3);
});

it('keeps a fixture it created once another run has joined it', function (string $command, array $options, string $org): void {
    /*
     * ⚠️ CREATING THE ORG DID NOT MAKE IT THIS RUN'S ALONE — review found the whole-org delete going around the
     * per-run tokens. A second run finds the org through `firstOrCreate()` and inserts under it, and force-deleting
     * the org cascades through that run's rows. Unlike the test above nothing is kept beforehand, so this run
     * creates the fixture itself; the other run's rows are written inside it, as its first chunk lands.
     */
    $written = false;

    DB::listen(function (QueryExecuted $query) use (&$written): void {
        if ($written || ! str_starts_with(str_replace(['"', '`'], '', $query->sql), 'insert into entries')) {
            return;
        }

        $written = true;
        $ours = DB::table('entries')->orderByDesc('id')->first();

        DB::table('entries')->insert(array_map(static fn (int $i): array => [
            'site_id' => $ours->site_id,
            'org_id' => $ours->org_id,
            'entry_type_id' => $ours->entry_type_id,
            'type_handle' => 'article',
            'status' => 'published',
            'slug' => "another-run-{$i}",
            'title' => "Another run's entry {$i}",
            'values' => json_encode([]),
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], [1, 2, 3]));
    });

    $this->artisan($command, $options)->assertSuccessful();

    // The other run's rows, and the fixture they hang off, survive; this run's own rows are gone.
    expect($written)->toBeTrue()
        ->and(DB::table('entries')->count())->toBe(3)
        ->and(DB::table('entries')->where('slug', 'like', 'another-run-%')->count())->toBe(3)
        ->and(DB::table('orgs')->where('slug', $org)->exists())->toBeTrue();
})->with([
    'floor' => ['kitsune:benchmark-floor', ['--entries' => 5], 'floor-benchmark'],
    'storage' => ['kitsune:benchmark-storage', ['--rows' => 5], 'benchmark'],
]);

it('gives back an org-only context even when the benchmark chose a site of that org', function (): void {
    /*
     * ⚠️ `setOrg()` KEEPS A SITE OF THE ORG IT IS GIVEN, and review found the restore relying on it: a caller in the
     * benchmark's own org with no site went on scoped to the benchmark's site. The fixture is kept from an earlier
     * run, so this run finds it and selects a site of the org the caller is already in.
     */
    $this->artisan('kitsune:benchmark-floor', ['--entries' => 0, '--keep' => true])->assertSuccessful();

    $org = Org::query()->where('slug', 'floor-benchmark')->firstOrFail();
    app(Context::class)->forget()->setOrg($org);

    $this->artisan('kitsune:benchmark-floor', ['--entries' => 0])->assertSuccessful();

    expect(benchmarkContext())->toBe([$org->getKey(), null]);
});

it('drops only the generated columns its own run added, and keeps those an earlier run kept', function (): void {
    /*
     * ⚠️ REVIEW FOUND CLEANUP DROPPING EVERY `bench_idx_*` IT COUNTED, whether or not this run had added it, so a
     * run without `--keep` removed the column and index an earlier run had kept. Two locale counts let the second
     * run finish, as above.
     *
     * DDL implicitly commits on MySQL, so RefreshDatabase's rollback cannot be trusted to undo either run. The test
     * removes the column, its index and the benchmark org itself, as SchemaSyncCommandTest does.
     */
    $driver = DriverFactory::for(DB::connection());
    $indexes = static fn (): array => array_map(
        static fn (array $index): string => strtolower((string) $index['name']),
        Schema::getIndexes('entries'),
    );

    try {
        $this->artisan('kitsune:benchmark-storage', ['--rows' => 5, '--locales' => 2, '--indexed' => 1, '--keep' => true])->assertSuccessful();

        $kept = benchmarkFootprint();

        $this->artisan('kitsune:benchmark-storage', ['--rows' => 5, '--indexed' => 1])->assertSuccessful();

        expect(Schema::getColumnListing('entries'))->toContain('bench_idx_0')
            ->and($indexes())->toContain('entries_bench_0')
            ->and(benchmarkFootprint())->toBe($kept);
    } finally {
        // Index first: MySQL's DROP INDEX has no IF EXISTS, and a column an index references cannot be dropped.
        if (in_array('entries_bench_0', $indexes(), true)) {
            DB::statement($driver->dropIndexSql('entries', 'entries_bench_0'));
        }

        if (in_array('bench_idx_0', Schema::getColumnListing('entries'), true)) {
            DB::statement($driver->dropGeneratedColumnSql('entries', 'bench_idx_0'));
        }

        DB::table('orgs')->where('slug', 'benchmark')->delete();
    }
});

it('creates its fixture whole or not at all', function (string $command, string $slug): void {
    /*
     * ⚠️ A SITE SLUG IS UNIQUE ACROSS THE INSTALLATION, and cleanup cannot begin until the fixture exists.
     * Review found another org already owning the benchmark's slug failing the site insert after the org was
     * created, which left that org behind.
     */
    $someone = Org::create(['name' => 'Someone', 'slug' => 'someone']);
    app(Context::class)->setOrg($someone);
    Site::create(['org_id' => $someone->id, 'handle' => $slug, 'slug' => $slug, 'name' => 'Theirs', 'locale' => 'en']);

    $before = benchmarkFootprint();

    expect(fn () => $this->artisan($command)->run())->toThrow(QueryException::class);

    // Nothing left in the database, and the caller's context back — not the org the fixture rolled back.
    expect(benchmarkFootprint())->toBe($before)
        ->and(benchmarkContext())->toBe([$someone->getKey(), null]);
})->with([
    'floor' => ['kitsune:benchmark-floor', 'floor-benchmark'],
    'storage' => ['kitsune:benchmark-storage', 'benchmark'],
]);

it('refuses a second run while another process holds the lock, and runs again once that process is gone', function (string $command): void {
    /*
     * ⚠️ OVERLAPPING RUNS SHARE WHAT NO PER-RUN TOKEN DIVIDES — review found the storage benchmark's generated
     * columns dropped under a longer run, and a fixture joined without inserting anything deleted under the run that
     * joined it. So a run holds a lock for as long as its process lives.
     *
     * ⚠️ HELD BY ANOTHER PROCESS, AND THEN KILLED. The first lock was a cache lock on a clock: it expired under a long
     * run, and a run killed before its `finally` left it held for an hour. A holder in a separate process is what a
     * real second run faces, and SIGKILL runs none of the holder's cleanup — so a free lock afterwards is the
     * operating system's release, not anything a command did.
     */
    $path = BenchmarkFloorCommand::runLockPath($command);
    File::ensureDirectoryExists(dirname($path));

    $holder = new Process([PHP_BINARY, '-r', '$h = fopen($argv[1], "c"); flock($h, LOCK_EX); echo "held\n"; sleep(60);', $path]);
    $holder->start();
    $holder->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'held'));

    $before = benchmarkFootprint();

    try {
        $this->artisan($command)->expectsOutputToContain('already in progress')->assertFailed();

        expect(benchmarkFootprint())->toBe($before)
            ->and(benchmarkLockIsFree($command))->toBeFalse();
    } finally {
        if ($holder->isRunning()) {
            $holder->signal(9);
        }

        $holder->wait();
    }

    expect(benchmarkLockIsFree($command))->toBeTrue();
})->with(['kitsune:benchmark-floor', 'kitsune:benchmark-storage', 'kitsune:benchmark-admin']);

it('asks before writing to a production installation', function (string $command): void {
    app()->detectEnvironment(static fn (): string => 'production');

    $before = benchmarkFootprint();

    $this->artisan($command)
        ->expectsConfirmation('Are you sure you want to run this command?', 'no')
        ->assertFailed();

    expect(benchmarkFootprint())->toBe($before);
})->with(['kitsune:benchmark-floor', 'kitsune:benchmark-storage', 'kitsune:benchmark-admin']);

it('runs in production when forced, the way Laravel\'s own destructive commands do', function (): void {
    app()->detectEnvironment(static fn (): string => 'production');

    $this->artisan('kitsune:benchmark-floor', ['--entries' => 5, '--force' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('content in scope: 5 entries');
});
