<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

/*
 * ADR-024 binds the project to one rule: the default Pest layer must run
 * green on a bare clone - SQLite, no Docker, no Node, no services. That is
 * the pillar-three mitigation for adding a container matrix and a browser
 * layer to CI, and a rule nobody checks is a rule that quietly stops holding.
 *
 * These assertions describe the DEFAULT configuration, so they are meaningful
 * only when nothing has overridden it. The engine matrix overrides it on
 * purpose, and there they are skipped rather than failed - asserting "the
 * default is SQLite" inside a job whose whole point is running Postgres would
 * be testing the harness, not the contract.
 *
 * The real enforcement is the `bare-clone` CI job, which sets no override and
 * declares no services at all.
 */

$onlyWithoutOverride = fn (): bool => env('DB_CONNECTION', 'testing') !== 'testing';

$skipReason = 'Engine override in effect; the bare-clone contract describes the default configuration';

it('runs on SQLite by default', function (): void {
    expect(config('database.default'))->toBe('testing');
    expect(config('database.connections.testing.driver'))->toBe('sqlite');
})->skip($onlyWithoutOverride, $skipReason);

it('needs no database server', function (): void {
    expect(config('database.connections.testing.database'))->toBe(':memory:');
})->skip($onlyWithoutOverride, $skipReason);

it('reaches the database without a host or credentials', function (): void {
    $connection = config('database.connections.testing');

    expect($connection)
        ->not->toHaveKey('host')
        ->not->toHaveKey('username')
        ->not->toHaveKey('password');
})->skip($onlyWithoutOverride, $skipReason);

it('has the sqlite driver available in this php runtime', function (): void {
    // Unconditional: SQLite must be present on every runner, including the
    // matrix ones, because it is the floor every install is guaranteed.
    expect(extension_loaded('pdo_sqlite'))->toBeTrue();
});
