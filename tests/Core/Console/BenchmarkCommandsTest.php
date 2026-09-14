<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Schema\DriverFactory;
use Kitsune\Core\Tenancy\Context;

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

    $this->artisan('kitsune:benchmark-floor', ['--entries' => 25])->assertSuccessful();

    expect(benchmarkFootprint())->toBe($before);
});

it('leaves the installation as it found it after the storage benchmark', function (): void {
    $before = benchmarkFootprint();

    $this->artisan('kitsune:benchmark-storage', ['--rows' => 20])->assertSuccessful();

    expect(benchmarkFootprint())->toBe($before);
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

it('forgets the previous invocation\'s mark, so a run that inserts nothing removes nothing', function (): void {
    /*
     * ⚠️ ONE COMMAND OBJECT SERVES EVERY INVOCATION in an application — Artisan resolves a command once and keeps
     * it — so a mark held on the object outlives the run that took it. Review found a `--keep` run leaving its mark
     * behind, and a second run whose volume was already met, inserting nothing and taking no mark of its own,
     * cleaning up above the first run's mark: deleting exactly the rows `--keep` had kept.
     */
    $this->artisan('kitsune:benchmark-floor', ['--entries' => 5, '--keep' => true])->assertSuccessful();

    $kept = benchmarkFootprint();

    $this->artisan('kitsune:benchmark-floor', ['--entries' => 5])
        ->assertSuccessful()
        ->expectsOutputToContain('content in scope: 5 entries');

    expect(benchmarkFootprint())->toBe($kept);
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

    expect(benchmarkFootprint())->toBe($before);
})->with([
    'floor' => ['kitsune:benchmark-floor', 'floor-benchmark'],
    'storage' => ['kitsune:benchmark-storage', 'benchmark'],
]);

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
