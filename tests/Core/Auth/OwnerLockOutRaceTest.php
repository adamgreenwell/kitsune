<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\TestUser;

/**
 * Two concurrent changes cannot both pass the last-owner guard — issue #84, found by review.
 *
 * ⚠️ IT IS A CHECK-THEN-ACT ON A CONDITION NO CONSTRAINT EXPRESSES. "This org still has somebody who can
 * administer it" is a count across `roles`, `role_user` and the host's membership pivot, so there is no
 * unique index to fall back on: two transactions demoting the last two held owner roles could each read
 * the OTHER, pass, and commit. Each change is individually safe and the pair locks the org out for good —
 * and ADR-033 has no permission that would let anybody else put the flag back.
 *
 * ⚠️ WHAT THIS FILE MEASURES, AND WHAT IT LEAVES TO THE ENGINE. It asserts that the guard's reads carry
 * `FOR UPDATE`, that they run inside the transaction that performs the write, and that the re-read answers
 * from committed data rather than from a snapshot. That `FOR UPDATE` then makes a second transaction WAIT
 * is the database's guarantee, tested by its own authors; re-testing it here would be asserting InnoDB.
 *
 * ⚠️ AND THE MATRIX IS THE POINT OF THE THIRD TEST. Postgres runs READ COMMITTED, so an ordinary read is
 * already current and the defect is invisible there; MySQL and MariaDB run REPEATABLE READ, where the
 * guard answered from the transaction's snapshot. A single-engine suite would have called this fixed.
 */
beforeEach(function (): void {
    /*
     * ⚠️ NOTHING IS READ HERE ON PURPOSE — no `Org::create()`, no context. Under REPEATABLE READ the
     * transaction's snapshot is established by its first READ, and the staleness test below needs its
     * snapshot to be older than another connection's commit. A fixture that reads first would take the
     * snapshot too late and the test would pass with the fix reverted.
     */
    config(['auth.providers.users.model' => TestUser::class]);
});

afterEach(function (): void {
    app(Context::class)->forget();

    if (array_key_exists('rival', config('database.connections') ?? [])) {
        try {
            DB::connection('rival')->rollBack();
        } catch (Throwable) {
            // Nothing open, which is the ordinary case.
        }

        DB::purge('rival');
    }
});

afterAll(function (): void {
    /*
     * ⚠️ COMMITTED ROWS FROM ANOTHER CONNECTION OUTLIVE `RefreshDatabase`, so the test that needs them has
     * to sweep them — and it cannot do so itself while it still holds locks on them. `HostClaimSerializationTest`
     * records the same shape, including why resolving the connection belongs inside the `try`: on SQLite a
     * second connection to `:memory:` is a different, empty database.
     */
    try {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        $default = (string) config('database.default');
        config(['database.connections.sweep' => config("database.connections.{$default}")]);

        $sweep = DB::connection('sweep');

        $org = $sweep->table('orgs')->where('slug', 'race-org')->value('id');

        if ($org !== null) {
            $roles = $sweep->table('roles')->where('org_id', $org)->pluck('id');

            $sweep->table('role_user')->whereIn('role_id', $roles)->delete();
            $sweep->table('roles')->whereIn('id', $roles)->delete();
            $sweep->table('org_user')->where('org_id', $org)->delete();
            $sweep->table('orgs')->where('id', $org)->delete();
        }

        $sweep->table('users')->where('email', 'like', 'race-%@kitsune.test')->delete();

        DB::purge('sweep');
    } catch (Throwable) {
        // The engine may have rolled the whole schema away already, which is equally clean.
    }
});

/** A second connection to the SAME database, so another transaction's committed work is real. */
function rivalConnectionForOwners(): Connection
{
    $default = (string) config('database.default');

    config(['database.connections.rival' => config("database.connections.{$default}")]);

    return DB::connection('rival');
}

it('lets a holder keep ownership through a second owner role', function (): void {
    /*
     * ⚠️ THE EXCLUSION WAS A PERSON AND IT HAD TO BE AN ASSIGNMENT — review found the guard refusing a safe
     * removal. A member holding two owner roles who gives one up is still an owner through the other, but
     * `exceptUser` took them out of EVERY owner role, so the count came back empty and the removal was
     * refused. Being told "you would lock yourself out" while demonstrably not is the failure mode that
     * teaches somebody to reach for the database.
     */
    $org = Org::create(['slug' => 'two-hats', 'name' => 'Two hats']);
    app(Context::class)->setOrg($org);

    /** @var TestUser $user */
    $user = TestUser::create(['email' => 'twohats@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => $org->getKey(), 'user_id' => $user->getKey()]);

    $first = Role::create(['handle' => 'owner-a', 'name' => 'Owner A', 'is_owner' => true]);
    $second = Role::create(['handle' => 'owner-b', 'name' => 'Owner B', 'is_owner' => true]);

    $first->assignTo($user->getKey());
    $second->assignTo($user->getKey());

    // Safe, and it has to be allowed: the second owner role is still theirs.
    $first->removeFrom($user->getKey());

    expect(DB::table('role_user')->where('role_id', $first->getKey())->count())->toBe(0)
        ->and(DB::table('role_user')->where('role_id', $second->getKey())->count())->toBe(1);

    // And the LAST one is still refused, or the fix would have opened the door it was narrowing.
    expect(fn () => $second->removeFrom($user->getKey()))
        ->toThrow(RuntimeException::class, 'last member of this organisation');

    expect(DB::table('role_user')->where('role_id', $second->getKey())->count())->toBe(1);
});

it('checks the owner set under a lock, inside the transaction that writes', function (): void {
    /*
     * ⚠️ TWO PROPERTIES, ONE INSTRUMENT, and both were false before this round. `removeFrom()` ran its
     * check BEFORE `DB::transaction()` opened — so whatever it read was released before the delete — and
     * the reads carried no lock at all, so two transactions never queued behind each other.
     *
     * The transaction DEPTH is what says "inside": `RefreshDatabase` supplies level 1 and the method's own
     * `DB::transaction()` makes it 2, so a check running at level 1 is a check outside the write.
     */
    $org = Org::create(['slug' => 'locked', 'name' => 'Locked']);
    app(Context::class)->setOrg($org);

    /** @var TestUser $keeper */
    $keeper = TestUser::create(['email' => 'keeper@kitsune.test']);
    /** @var TestUser $other */
    $other = TestUser::create(['email' => 'other@kitsune.test']);

    foreach ([$keeper, $other] as $member) {
        DB::table('org_user')->insert(['org_id' => $org->getKey(), 'user_id' => $member->getKey()]);
    }

    $role = Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true]);
    $role->assignTo($keeper->getKey());
    $role->assignTo($other->getKey());

    $ownerReads = [];

    /*
     * ⚠️ FILTERED ON THE CONNECTION. `Connection::listen()` registers on the APPLICATION dispatcher, so a
     * listener hears every connection's queries — the trap this project has recorded once already.
     */
    DB::listen(function (QueryExecuted $query) use (&$ownerReads): void {
        if ($query->connectionName !== DB::getDefaultConnection()) {
            return;
        }

        /*
         * ⚠️ SCHEMA INTROSPECTION NAMES THE TABLE TOO, AND ON POSTGRES IT INLINES IT. `Permissions` asks
         * `Schema::getForeignKeys('role_user')` to learn whose ids the assignments hold, and Postgres's
         * version of that query carries `tc.relname = 'role_user'` as a LITERAL — so it matched this filter
         * and was reported as an owner read that took no lock. It is not one, and it should not take a lock.
         *
         * MySQL and MariaDB bind the table name instead of inlining it, and SQLite parses its own DDL, so
         * this failed on exactly one engine out of four: the driver-divergence class AGENTS.md invariant 5 is
         * about, caught by the matrix rather than by reading.
         */
        foreach (['pg_constraint', 'information_schema', 'sqlite_master'] as $introspection) {
            if (str_contains($query->sql, $introspection)) {
                return;
            }
        }

        if (str_contains($query->sql, 'is_owner') || str_contains($query->sql, 'role_user')) {
            $ownerReads[] = ['sql' => $query->sql, 'depth' => DB::transactionLevel()];
        }
    });

    $role->removeFrom($other->getKey());

    $selects = array_values(array_filter(
        $ownerReads,
        static fn (array $read): bool => str_starts_with($read['sql'], 'select'),
    ));

    // Not vacuous: the guard has to have run at all.
    expect($selects)->not->toBe([]);

    $shallow = array_values(array_filter($selects, static fn (array $read): bool => $read['depth'] < 2));

    expect($shallow)->toBe([], 'these owner reads ran outside the write\'s transaction: '
        .implode(', ', array_column($shallow, 'sql')));

    /*
     * ⚠️ SQLITE IS EXCLUDED FROM THE CLAUSE HALF AND THAT IS NOT A GAP. It compiles `FOR UPDATE` to
     * nothing and serialises writers at the database level, so there is no clause to find and the
     * guarantee arrives by another route. The depth assertion above still runs on every engine.
     */
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $unlocked = array_values(array_filter(
            $selects,
            static fn (array $read): bool => ! str_contains($read['sql'], 'for update'),
        ));

        expect($unlocked)->toBe([], 'these owner reads took no lock: '.implode(', ', array_column($unlocked, 'sql')));
    }
});

it('sees the owner set another transaction committed, not an older snapshot', function (): void {
    /*
     * ⚠️ HOLDING A LOCK IS WORTHLESS IF THE READ ANSWERS FROM AN OLDER POINT IN TIME, which under MySQL's
     * and MariaDB's REPEATABLE READ it does: the snapshot belongs to the transaction, and `RefreshDatabase`
     * supplies one around every test — which is also the ordinary shape of a save inside a caller's
     * transaction. A locking read sees the latest committed row instead.
     *
     * The fixture is committed by ANOTHER CONNECTION on purpose. Rows written inside this test's
     * transaction are invisible to it, so a second transaction could neither hold nor change them — and
     * "another transaction committed something" is the entire condition under test.
     *
     * ⚠️ POSTGRES PASSES EITHER WAY, and saying so is the point of the matrix. It runs READ COMMITTED, so
     * an ordinary read is already current there; the defect lives on the two MySQL engines, and a
     * single-engine suite would have called this fixed.
     */
    $rival = rivalConnectionForOwners();

    $orgId = $rival->table('orgs')->insertGetId([
        'slug' => 'race-org', 'name' => 'Race', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $keeper = $rival->table('users')->insertGetId(['email' => 'race-keeper@kitsune.test']);
    $spare = $rival->table('users')->insertGetId(['email' => 'race-spare@kitsune.test']);

    foreach ([$keeper, $spare] as $member) {
        $rival->table('org_user')->insert(['org_id' => $orgId, 'user_id' => $member]);
    }

    $first = $rival->table('roles')->insertGetId([
        'org_id' => $orgId, 'handle' => 'owner-first', 'name' => 'First',
        'is_owner' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $second = $rival->table('roles')->insertGetId([
        'org_id' => $orgId, 'handle' => 'owner-second', 'name' => 'Second',
        'is_owner' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $rival->table('role_user')->insert([
        ['role_id' => $first, 'user_id' => $keeper],
        ['role_id' => $second, 'user_id' => $spare],
    ]);

    /*
     * ⚠️ THIS READ IS THE INSTRUMENT AND WITHOUT IT THE TEST MEASURES NOTHING. In REPEATABLE READ the
     * consistent snapshot is created by the transaction's first NON-LOCKING read, and everything above is
     * either a write or on another connection — so with no ordinary read here the snapshot would be taken
     * later, inside the guard, after the demotion below had already committed. An ordinary read would then
     * see current data anyway and the test would pass with the locks removed. Measured: it did, on both
     * MySQL engines, until this line existed.
     *
     * It doubles as the fixture check, since the count is what the guard is about to disagree with.
     */
    $pinned = Role::query()->withoutGlobalScopes()->where('org_id', $orgId)->where('is_owner', true)->count();

    expect($pinned)->toBe(2);

    $org = Org::query()->whereKey($orgId)->first();
    app(Context::class)->setOrg($org);

    // The other transaction now demotes the OTHER owner role and commits.
    $rival->table('roles')->where('id', $second)->update(['is_owner' => false]);

    /*
     * ⚠️ NOT VACUOUS, AND THE ASSERTION NAMES ITS OWN FAILURE MODE. On MySQL and MariaDB this transaction's
     * ordinary read still reports two owner roles after the rival's commit — the stale answer the guard used
     * to trust. On Postgres (READ COMMITTED) it already reports one, which is why the defect never existed
     * there and why one engine would have called this fixed.
     */
    $stale = Role::query()->withoutGlobalScopes()->where('org_id', $orgId)->where('is_owner', true)->count();

    expect($stale)->toBe(
        in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) ? 2 : 1,
        'this engine does not hold the snapshot the test needs, so it cannot discriminate here',
    );

    /** @var Role $role */
    $role = Role::query()->withoutGlobalScopes()->whereKey($first)->lockForUpdate()->first();

    // `$first` is now the only held owner role in the org, so taking it from its last holder locks the
    // org out — and the guard can only know that if it reads what the rival committed.
    expect(fn () => $role->removeFrom($keeper))
        ->toThrow(RuntimeException::class, 'last member of this organisation');

    expect(DB::table('role_user')->where('role_id', $first)->where('user_id', $keeper)->count())->toBe(1);
})->skip(
    fn (): bool => DB::connection()->getDriverName() === 'sqlite',
    'SQLite gives a second connection to :memory: a different, empty database, so there is no other transaction to have committed',
);
