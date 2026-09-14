<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

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
