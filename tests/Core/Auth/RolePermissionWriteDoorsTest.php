<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\RolePermission;
use Kitsune\Core\Tenancy\Context;

/**
 * Every public way to write `role_permissions`, walked — ADR-033, and review walked it first.
 *
 * ⚠️ THIS FILE EXISTS BECAUSE A DOCBLOCK CLAIMED COVERAGE IT DID NOT HAVE. `GuardedGrantBuilder` said the
 * insert-or-ignore family was "already refused for every model by `ScopedBuilder`" — and it is not, for this
 * model: `refuseBulkCreate()` returns early for anything that is not `RequiresModelSave`, and
 * `guardEveryInsertedRow()` inspects SCOPE KEYS, of which an `#[Unscoped]` table has none. Both guards stood
 * aside and `RolePermission::query()->insertOrIgnore([…])` attached a grant to any role on the installation.
 *
 * A claim about somebody else's code is the kind that rots quietly. So the surface is enumerated here, and
 * the second test fails if Laravel grows a write method this list has not been taught about.
 */
beforeEach(function (): void {
    $this->org = Org::create(['slug' => 'doors', 'name' => 'Doors']);
    app(Context::class)->setOrg($this->org);

    $this->role = Role::create(['handle' => 'editor', 'name' => 'Editor']);
    $this->role->grant('entry.article.view');

    $this->row = RolePermission::query()->where('role_id', $this->role->getKey())->firstOrFail();
});

afterEach(fn () => app(Context::class)->forget());

/**
 * Every write method on the builder, and how to reach it.
 *
 * The keys are the method names the sweep below checks against, so adding a case here is what teaches it.
 *
 * @return array<string, Closure>
 */
function everyGrantWrite(RolePermission $row, Role $role): array
{
    /*
     * ⚠️ EVERY CASE WRITES A DIFFERENT PERMISSION, AND THE FIRST VERSION DID NOT. They share one table and
     * a unique index on (role_id, permission) — so when a guard is missing and one write LANDS, the next
     * case collides with it and dies on the index. That reads as "refused" and the sweep under-reports:
     * measured, `insertUsing()` was completely unguarded and this file said it was covered, because
     * `insertOrIgnore()` ran first and planted the row it would have written.
     *
     * Distinct values make each case independent of every other one's outcome.
     */
    $row = fn (string $permission): array => ['role_id' => $role->getKey(), 'permission' => $permission];

    return [
        'update' => fn () => RolePermission::query()->update(['permission' => 'entry.article.u1']),
        'delete' => fn () => RolePermission::query()->delete(),
        'forceDelete' => fn () => RolePermission::query()->forceDelete(),
        'insert' => fn () => RolePermission::query()->insert($row('entry.article.i1')),
        'insertGetId' => fn () => RolePermission::query()->insertGetId($row('entry.article.i2')),
        'insertOrIgnore' => fn () => RolePermission::query()->insertOrIgnore($row('entry.article.i3')),
        'insertUsing' => fn () => RolePermission::query()->insertUsing(
            ['role_id', 'permission'],
            // ⚠️ ONE ROW, pinned by the seeded permission: a subquery returning N rows inserts N copies of
            // the same literal pair and dies on the unique index, which is a database error wearing a
            // guard's clothes. Measured — that is what hid this door the first two times.
            RolePermission::query()->where('permission', 'entry.article.view')->selectRaw("role_id, 'entry.article.i4' as permission"),
        ),
        'insertOrIgnoreUsing' => fn () => RolePermission::query()->insertOrIgnoreUsing(
            ['role_id', 'permission'],
            RolePermission::query()->where('permission', 'entry.article.view')->selectRaw("role_id, 'entry.article.i5' as permission"),
        ),
        'insertOrIgnoreReturning' => fn () => RolePermission::query()->insertOrIgnoreReturning($row('entry.article.i6')),
        'upsert' => fn () => RolePermission::query()->upsert([$row('entry.article.i7')], ['role_id', 'permission']),
        'updateOrInsert' => fn () => RolePermission::query()->updateOrInsert(['id' => 0], $row('entry.article.i8')),
        'updateFrom' => fn () => RolePermission::query()->updateFrom(['permission' => 'entry.article.u2']),
        'truncate' => fn () => RolePermission::query()->truncate(),
        'increment' => fn () => RolePermission::query()->increment('role_id', 0, ['permission' => 'entry.article.u3']),
        'decrement' => fn () => RolePermission::query()->decrement('role_id', 0, ['permission' => 'entry.article.u4']),
        'incrementEach' => fn () => RolePermission::query()->incrementEach(['role_id' => 0], ['permission' => 'entry.article.u5']),
        'decrementEach' => fn () => RolePermission::query()->decrementEach(['role_id' => 0], ['permission' => 'entry.article.u6']),
        // ⚠️ Eloquent writes `touch($column)` through `toBase()`, past every override above — measured, it rewrote this
        // org's grant AND a rival org's to a timestamp, revoking both with no audit.
        'touch' => fn () => RolePermission::query()->touch('permission'),
    ];
}

it('refuses every public write, and changes nothing on the way', function (): void {
    $before = RolePermission::query()->orderBy('id')->pluck('permission', 'id')->all();

    expect($before)->toHaveCount(1);

    $allowed = [];
    $failedOtherwise = [];

    foreach (everyGrantWrite($this->row, $this->role) as $method => $write) {
        try {
            $write();
            $allowed[] = $method;
        } catch (RuntimeException $refusal) {
            /*
             * ⚠️ A GUARD'S REFUSAL, NOT ANY EXCEPTION AT ALL — and the difference is not academic. A write
             * that dies on the unique index looks exactly like a guarded one to a bare `catch (Throwable)`,
             * and that is how the first version of this file reported `insertUsing()` as covered while it was
             * wide open. The message has to say somebody refused it on purpose.
             */
            if (! str_contains($refusal->getMessage(), 'Refusing') && ! str_contains($refusal->getMessage(), 'cannot be')) {
                $failedOtherwise[] = $method.': '.$refusal->getMessage();
            }
        } catch (Throwable $other) {
            $failedOtherwise[] = $method.': '.$other::class;
        }
    }

    expect($allowed)->toBe([], 'these writes to role_permissions were allowed: '.implode(', ', $allowed))
        ->and($failedOtherwise)->toBe([], 'these failed for a reason that is not a guard: '.implode(' | ', $failedOtherwise));

    // ⚠️ And nothing moved. A refusal that throws after writing would pass the loop above.
    expect(RolePermission::query()->orderBy('id')->pluck('permission', 'id')->all())->toBe($before);

    // ⚠️ Nor is this a refusal of the TABLE: the legitimate door still opens.
    $this->role->grant('entry.article.publish');

    expect(RolePermission::query()->count())->toBe(2);

    $this->role->revoke('entry.article.publish');

    expect(RolePermission::query()->count())->toBe(1);
});

it('knows about every write method the builder actually has', function (): void {
    /*
     * ⚠️ THE ANTI-DRIFT HALF, because the list above is only as good as its completeness — and the defect
     * this file was written for was exactly an incomplete list. Laravel adds builder methods between minor
     * versions; one that writes and is not enumerated above would slip through silently.
     *
     * So the sweep asks the builder what it can do and fails on anything that looks like a write and is not
     * covered. It is a NAME check on purpose: it cannot know what a method does, and a name-shaped guess that
     * over-reports is a test somebody has to think about rather than one that goes quiet.
     */
    $covered = array_keys(everyGrantWrite($this->row, $this->role));

    $writeish = array_values(array_filter(
        get_class_methods(RolePermission::query()),
        static fn (string $method): bool => (bool) preg_match(
            '/^(insert|update|upsert|delete|forceDelete|truncate|increment|decrement|replace|touch)/',
            $method,
        ),
    ));

    // Not vacuous: the reflection has to have found the family this file is about.
    expect($writeish)->toContain('insertOrIgnore', 'update', 'delete');

    $unknown = array_values(array_diff($writeish, $covered, [
        // ⚠️ NOT A WRITE, and each exclusion says why rather than sitting in a list.
        // `updateOrCreate`/`updateOrInsert`-shaped helpers that route through the methods above need no
        // separate door; what is excluded here either returns a builder or delegates to a covered method.
        'insertOrIgnoreReturningId',   // delegates to insertOrIgnoreReturning()
        'updateOrCreate',             // resolves to firstOrNew() + save(), i.e. insertGetId()/update()
        'incrementOrCreate',          // resolves to updateOrCreate()
        'upsertReturning',            // delegates to upsert()
        'deleteQuietly',              // delegates to delete() with events suppressed
        'forceDeleteQuietly',         // delegates to forceDelete()
        'updateQuietly',              // delegates to update()
    ]));

    expect($unknown)->toBe([], 'the builder has write methods this file does not cover: '.implode(', ', $unknown));
});

it('opens the window from inside Role and nowhere else', function (): void {
    /*
     * ⚠️ THE FIRST VERSION'S OPENER WAS PUBLIC, which review correctly called out as leaving the capability
     * public: `RolePermission::throughRole(fn () => RolePermission::create(...))` armed the window from
     * outside and every check in `Role::grant()` was skipped. A private boolean does not make a capability
     * private when a public method arms it.
     *
     * This reads the SOURCE, because that is where the property is: an assignment to `self::$writingGrants`
     * outside `grant()` or `revoke()` is the defect, whatever it is named.
     */
    expect(method_exists(RolePermission::class, 'throughRole'))->toBeFalse()
        ->and(method_exists(RolePermission::class, 'writingThroughRole'))->toBeFalse();

    $source = (string) file_get_contents((string) (new ReflectionClass(Role::class))->getFileName());

    // Every line that arms it, with the method it sits in resolved by scanning backwards for a signature.
    $lines = explode("\n", $source);
    $openers = [];

    foreach ($lines as $number => $line) {
        if (! str_contains($line, 'self::$writingGrants = true')) {
            continue;
        }

        for ($back = $number; $back >= 0; $back--) {
            if (preg_match('/function (\w+)\(/', $lines[$back], $match) === 1) {
                $openers[] = $match[1];

                break;
            }
        }
    }

    // Not vacuous: it has to have found the two legitimate ones.
    expect($openers)->toBe(['grant', 'revoke']);

    // And nothing outside the class can reach the flag, so a reader is all that is public.
    $reflected = new ReflectionProperty(Role::class, 'writingGrants');

    expect($reflected->isPrivate())->toBeTrue()
        ->and($reflected->isStatic())->toBeTrue();
});
