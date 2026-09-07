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
 * These assertions are the in-suite half. The other half is the CI job that
 * runs this file with no services reachable at all.
 */

it('runs on SQLite by default', function (): void {
    expect(config('database.default'))->toBe('testing');
    expect(config('database.connections.testing.driver'))->toBe('sqlite');
});

it('needs no database server', function (): void {
    expect(config('database.connections.testing.database'))->toBe(':memory:');
});

it('reaches the database without a host or credentials', function (): void {
    $connection = config('database.connections.testing');

    expect($connection)
        ->not->toHaveKey('host')
        ->not->toHaveKey('username')
        ->not->toHaveKey('password');
});

it('has the sqlite driver available in this php runtime', function (): void {
    expect(extension_loaded('pdo_sqlite'))->toBeTrue();
});
