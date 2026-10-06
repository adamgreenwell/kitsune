<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Entitlements\EntitlementRefusal;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Entitlements\GrantOutcome;
use Kitsune\Core\Models\Entitlement;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;

/*
 * One site's entitlement writes take its row in turn — ADR-040, on the engines whose locks are real.
 *
 * ⚠️ A RIVAL CONNECTION, NOT A RIVAL PROCESS, `CredentialRaceTest`'s reason: the rival holds the site's row and this
 * connection's write must wait on it and, past a short lock timeout, be refused with nothing written. A writer that
 * took no lock would sail past. The org and its sites are committed by the rival, because this connection's own rows
 * sit in `RefreshDatabase`'s transaction where no other connection can see, or lock, them.
 *
 * ⚠️ THE RIVAL'S LOCK IS SHARED: inserting an entitlement takes a shared lock on its site for the foreign key, and that
 * waits on a rival's exclusive lock too — so a rival holding FOR UPDATE would stall a writer that took no lock of its
 * own, and the test would pass with the site lock removed. A shared lock lets the foreign key's check through.
 */

beforeEach(function (): void {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite has one writer, and compiles the row lock away.');
    }

    Fx::boot();

    $rival = entitlementRival();
    $now = now();
    $rival->table('orgs')->insert(['slug' => 'race-entitlements', 'name' => 'Race', 'created_at' => $now, 'updated_at' => $now]);
    $this->orgId = (int) $rival->table('orgs')->where('slug', 'race-entitlements')->value('id');

    foreach (['one', 'two'] as $handle) {
        $rival->table('sites')->insert(['org_id' => $this->orgId, 'handle' => $handle, 'slug' => 'race-entitlements-'.$handle, 'name' => ucfirst($handle), 'locale' => 'en', 'created_at' => $now, 'updated_at' => $now]);
    }

    $this->siteIds = $rival->table('sites')->where('org_id', $this->orgId)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

    $this->afterRollback(function (): void {
        $sweep = DB::connection('entitlement-rival');
        $sweep->table('entitlements')->where('org_id', $this->orgId)->delete();
        $sweep->table('audit_log')->where('org_id', $this->orgId)->delete();
        $sweep->table('sites')->where('org_id', $this->orgId)->delete();
        $sweep->table('orgs')->where('id', $this->orgId)->delete();
        DB::purge('entitlement-rival');
    });

    app(Context::class)->setSite(Site::query()->withoutGlobalScopes()->findOrFail($this->siteIds[0]));
    Fx::declareReaders();
    $this->reader = Fx::reader(Org::query()->findOrFail($this->orgId));
    $this->id = (int) $this->reader->getKey();
});

afterEach(function (): void {
    if (! array_key_exists('entitlement-rival', config('database.connections') ?? [])) {
        return;
    }

    try {
        DB::connection('entitlement-rival')->rollBack();
    } catch (Throwable) {
        // Nothing open, which is the ordinary case.
    }

    Fx::tearDown();
});

function entitlementRival(): Connection
{
    $default = (string) config('database.default');

    config(['database.connections.entitlement-rival' => config("database.connections.{$default}")]);

    return DB::connection('entitlement-rival');
}

/** A short lock wait on this connection, run, then the session's own setting put back. */
function withShortLockWait(Closure $callback): mixed
{
    match (DB::connection()->getDriverName()) {
        'pgsql' => DB::statement("set lock_timeout = '500ms'"),
        default => DB::statement('set session innodb_lock_wait_timeout = 1'),
    };

    try {
        return $callback();
    } finally {
        match (DB::connection()->getDriverName()) {
            'pgsql' => null,
            default => DB::statement('set session innodb_lock_wait_timeout = default'),
        };
    }
}

function holdSiteShared(int $siteId): Connection
{
    $rival = entitlementRival();
    $rival->beginTransaction();
    $rival->table('sites')->where('id', $siteId)->sharedLock()->value('id');

    return $rival;
}

it('waits on a rival holding the site, is refused with nothing written when the wait runs out, and grants once it lets go', function (): void {
    $rival = holdSiteShared($this->siteIds[0]);

    try {
        withShortLockWait(function (): void {
            try {
                Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', null);
                $this->fail('the grant did not wait for the site\'s lock');
            } catch (EntitlementRefused $refused) {
                expect($refused->reason)->toBeIn([EntitlementRefusal::Database, EntitlementRefusal::Race])
                    ->and($refused->getPrevious())->toBeNull()
                    ->and($refused->getMessage())->not->toContain((string) $this->id);
            }
        });
    } finally {
        $rival->rollBack();
    }

    expect(Entitlement::query()->count())->toBe(0)
        ->and(Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', null))->toBe(GrantOutcome::Granted);
});

it('leaves nothing saved when the wait runs out inside a caller\'s transaction', function (): void {
    $rival = holdSiteShared($this->siteIds[0]);

    try {
        withShortLockWait(function (): void {
            try {
                DB::transaction(function (): void {
                    Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', null);
                });
                $this->fail('the nested grant did not wait for the site\'s lock');
            } catch (EntitlementRefused $refused) {
                expect($refused->reason)->toBeIn([EntitlementRefusal::Database, EntitlementRefusal::Race]);
            }
        });
    } finally {
        $rival->rollBack();
    }

    expect(Entitlement::query()->count())->toBe(0);
});

it('makes an erasure wait on a rival holding the org\'s second site', function (): void {
    $rival = holdSiteShared($this->siteIds[1]);

    try {
        withShortLockWait(function (): void {
            try {
                Fx::writer()->forget($this->id);
                $this->fail('the erasure did not wait for every site of the org');
            } catch (EntitlementRefused $refused) {
                expect($refused->reason)->toBeIn([EntitlementRefusal::Database, EntitlementRefusal::Race]);
            }
        });
    } finally {
        $rival->rollBack();
    }

    expect(Fx::writer()->forget($this->id))->toBe(0);
});
