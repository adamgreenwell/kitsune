<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialRefusal;
use Kitsune\Core\Credentials\CredentialRefused;
use Kitsune\Core\Models\Credential;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Fx;

/*
 * One org's credential writes take its row in turn — ADR-040, on the engines whose locks are real.
 *
 * ⚠️ A RIVAL CONNECTION, NOT A RIVAL PROCESS, `HostClaimSerializationTest`'s reason: one process cannot run two writers
 * at once, so the rival holds the org's row and this connection's write must wait on it — and, past a short lock
 * timeout, be refused with nothing written. A writer that took no lock would sail past and write: that is the mutation
 * this pins. The org is committed by the rival, because this connection's own rows sit in `RefreshDatabase`'s
 * transaction where no other connection can see, or lock, them.
 *
 * ⚠️ THE RIVAL'S LOCK IS SHARED, and that is the point (review). Inserting a row that refers to the org takes a shared
 * lock on it for the foreign key — InnoDB's S, PostgreSQL's FOR KEY SHARE — and that waits on a rival's exclusive
 * lock too, so a rival holding FOR UPDATE stalled a writer that took no lock of its own, and the test passed with the
 * org lock removed — measured on PostgreSQL. A shared lock lets the foreign key's check through, so only the writer's
 * own FOR UPDATE waits.
 */

beforeEach(function (): void {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite has one writer, and compiles the row lock away.');
    }

    Fx::boot();

    $rival = credentialRival();
    $rival->table('orgs')->insert(['slug' => 'race-credentials', 'name' => 'Race', 'created_at' => now(), 'updated_at' => now()]);
    $this->orgId = (int) $rival->table('orgs')->where('slug', 'race-credentials')->value('id');

    $this->afterRollback(function (): void {
        $sweep = DB::connection('credential-rival');
        $sweep->table('orgs')->where('slug', 'race-credentials')->delete();
        DB::purge('credential-rival');
    });

    app(Context::class)->setOrg(Org::query()->findOrFail($this->orgId));
});

afterEach(function (): void {
    if (! array_key_exists('credential-rival', config('database.connections') ?? [])) {
        return;
    }

    try {
        DB::connection('credential-rival')->rollBack();
    } catch (Throwable) {
        // Nothing open, which is the ordinary case.
    }

    Fx::tearDown();
});

function credentialRival(): Connection
{
    $default = (string) config('database.default');

    config(['database.connections.credential-rival' => config("database.connections.{$default}")]);

    return DB::connection('credential-rival');
}

it('waits on a rival holding the org, and is refused with nothing written when the wait runs out', function (): void {
    $rival = credentialRival();
    $rival->beginTransaction();
    $rival->table('orgs')->where('id', $this->orgId)->sharedLock()->value('id');

    match (DB::connection()->getDriverName()) {
        'pgsql' => DB::statement("set lock_timeout = '500ms'"),
        default => DB::statement('set session innodb_lock_wait_timeout = 1'),
    };

    try {
        Fx::writer()->set(Fx::PAYMENT, CredentialMode::Test, Fx::value('fx_test_'));
        $this->fail('the write did not wait for the org\'s lock');
    } catch (CredentialRefused $refused) {
        expect($refused->reason)->toBeIn([CredentialRefusal::Database, CredentialRefusal::Race])
            ->and($refused->getPrevious())->toBeNull();
    } finally {
        $rival->rollBack();

        // The session's own setting, put back: a later test on this connection waits as long as it always has.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            DB::statement('set session innodb_lock_wait_timeout = default');
        }
    }

    expect(Credential::query()->count())->toBe(0);

    // And once the rival lets go, the same write goes through.
    Fx::writer()->set(Fx::PAYMENT, CredentialMode::Test, $value = Fx::value('fx_test_'));

    expect(Fx::reader()->secret(Fx::PAYMENT)->reveal())->toBe($value);
});
