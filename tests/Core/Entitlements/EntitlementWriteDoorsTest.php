<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Entitlements\EntitlementWriter;
use Kitsune\Core\Models\Entitlement;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;

/*
 * Every public way to write `entitlements`, walked outside the escape hatch and inside it — ADR-040.
 * `CredentialWriteDoorsTest`'s shape, for its reason: a claim that a door is shut one layer down is the kind that rots,
 * and inside `withoutScopeBecause()` `ScopedBuilder::upsert()` writes — here, the easiest way to undo a refund.
 */

beforeEach(function (): void {
    Fx::boot();
    $this->org = Fx::org('doors');
    $this->site = Fx::site($this->org, 'main');
    Fx::declareReaders();
    $this->reader = Fx::reader();
    Fx::owner();
    Fx::writer()->grant((int) $this->reader->getKey(), 'course.advanced-php', 'test.order:1', null);
    Fx::writer()->revoke((int) $this->reader->getKey(), 'course.advanced-php', 'test.order:1');
});

afterEach(fn () => Fx::tearDown());

/**
 * Every builder write, each with values of its own so one that lands cannot hide the next behind the unique index.
 *
 * @return array<string, Closure>
 */
function everyEntitlementWrite(int $orgId, int $siteId): array
{
    $row = fn (string $tag): array => [
        'org_id' => $orgId, 'site_id' => $siteId, 'reader_id' => 'r-'.$tag, 'entitlement' => 'course.advanced-php',
        'source' => 'test.order:1', 'expires_at' => null, 'revoked_at' => null, 'changed_at' => '2026-10-06 00:00:00',
    ];

    return [
        'update' => fn () => Entitlement::query()->update(['revoked_at' => null]),
        'delete' => fn () => Entitlement::query()->delete(),
        'forceDelete' => fn () => Entitlement::query()->forceDelete(),
        'insert' => fn () => Entitlement::query()->insert($row('i1')),
        'insertGetId' => fn () => Entitlement::query()->insertGetId($row('i2')),
        'insertOrIgnore' => fn () => Entitlement::query()->insertOrIgnore($row('i3')),
        'insertUsing' => fn () => Entitlement::query()->insertUsing(['org_id', 'site_id', 'reader_id', 'entitlement', 'source', 'changed_at'], Entitlement::query()->selectRaw("org_id, site_id, 'r-i4', entitlement, source, changed_at")),
        'insertOrIgnoreUsing' => fn () => Entitlement::query()->insertOrIgnoreUsing(['org_id', 'site_id', 'reader_id', 'entitlement', 'source', 'changed_at'], Entitlement::query()->selectRaw("org_id, site_id, 'r-i5', entitlement, source, changed_at")),
        'insertOrIgnoreReturning' => fn () => Entitlement::query()->insertOrIgnoreReturning($row('i6')),
        'upsert' => fn () => Entitlement::query()->upsert([$row('i7')], ['site_id', 'reader_id', 'entitlement', 'source'], ['revoked_at']),
        'updateOrInsert' => fn () => Entitlement::query()->updateOrInsert(['id' => 0], $row('i8')),
        'updateFrom' => fn () => Entitlement::query()->updateFrom(['revoked_at' => null]),
        'truncate' => fn () => Entitlement::query()->truncate(),
        'increment' => fn () => Entitlement::query()->increment('site_id', 0),
        'decrement' => fn () => Entitlement::query()->decrement('site_id', 0),
        'incrementEach' => fn () => Entitlement::query()->incrementEach(['site_id' => 0]),
        'decrementEach' => fn () => Entitlement::query()->decrementEach(['site_id' => 0]),
        'touch' => fn () => Entitlement::query()->touch('changed_at'),
    ];
}

/** @return array<string, Closure> */
function everyEntitlementModelWrite(): array
{
    $row = fn (string $tag): array => [
        'reader_id' => 'r-'.$tag, 'entitlement' => 'course.advanced-php', 'source' => 'test.order:1',
        'changed_at' => CarbonImmutable::now(),
    ];

    return [
        'save' => function (): void {
            $existing = Entitlement::query()->firstOrFail();
            $existing->setAttribute('revoked_at', null);
            $existing->save();
        },
        'saveQuietly' => function (): void {
            $existing = Entitlement::query()->firstOrFail();
            $existing->setAttribute('revoked_at', null);
            $existing->saveQuietly();
        },
        'push' => function (): void {
            $existing = Entitlement::query()->firstOrFail();
            $existing->setAttribute('revoked_at', null);
            $existing->push();
        },
        'model delete' => fn () => Entitlement::query()->firstOrFail()->delete(),
        'model forceDelete' => fn () => Entitlement::query()->firstOrFail()->forceDelete(),
        'create' => fn () => Entitlement::create($row('c1')),
        'firstOrCreate' => fn () => Entitlement::query()->firstOrCreate(['reader_id' => 'r-c2'], $row('c2')),
        'updateOrCreate' => fn () => Entitlement::query()->updateOrCreate(['reader_id' => 'r-c3'], $row('c3')),
    ];
}

it('refuses every write, outside the escape hatch and inside it, and moves nothing', function (bool $hatch): void {
    $before = DB::table('entitlements')->get()->all();
    $writes = [...everyEntitlementWrite($this->org->getKey(), $this->site->getKey()), ...everyEntitlementModelWrite()];
    $allowed = [];
    $otherwise = [];

    foreach ($writes as $method => $write) {
        try {
            $hatch ? Entitlement::withoutScopeBecause('a test walking every write', fn () => $write()) : $write();
            $allowed[] = $method;
        } catch (RuntimeException $refusal) {
            // A guard's refusal, not any exception. The parent refuses `truncate()`, `updateOrInsert()` and
            // `updateFrom()` saying they "cannot be" used.
            if (! str_contains($refusal->getMessage(), 'Refusing') && ! str_contains($refusal->getMessage(), 'cannot be')) {
                $otherwise[] = $method.': '.$refusal->getMessage();
            }
        } catch (Throwable $other) {
            $otherwise[] = $method.': '.$other::class.' '.$other->getMessage();
        }
    }

    expect($allowed)->toBe([], 'these writes were allowed: '.implode(', ', $allowed))
        ->and($otherwise)->toBe([], 'these failed for a reason that is not a guard: '.implode(' | ', $otherwise))
        ->and(DB::table('entitlements')->get()->all())->toEqual($before);
})->with(['outside the hatch' => [false], 'inside the hatch' => [true]]);

/** ⚠️ The door review found open on `role_permissions`, named on its own: here it would clear a refund's `revoked_at`. */
it('refuses an upsert that would undo a refund, inside the escape hatch, in the store\'s own words', function (): void {
    $undo = fn () => Entitlement::withoutScopeBecause('a test', fn ($query) => $query->upsert(
        [['org_id' => $this->org->getKey(), 'site_id' => $this->site->getKey(), 'reader_id' => (string) $this->reader->getKey(), 'entitlement' => 'course.advanced-php', 'source' => 'test.order:1', 'revoked_at' => null, 'changed_at' => '2026-10-06 00:00:00']],
        ['site_id', 'reader_id', 'entitlement', 'source'],
        ['revoked_at'],
    ));

    expect($undo)->toThrow(RuntimeException::class, 'Refusing upsert() on entitlements: a reader\'s access changes only through')
        ->and(DB::table('entitlements')->value('revoked_at'))->not->toBeNull();
});

it('refuses an upsert, a force delete and a delete from inside the writer\'s save window', function (string $method, Closure $write): void {
    Entitlement::saving(static function () use ($write): void {
        $write();
    });

    try {
        expect(EntitlementWriter::isWriting())->toBeFalse()
            ->and(fn () => Fx::writer()->grant((int) $this->reader->getKey(), 'course.advanced-php', 'test.order:2', null))
            ->toThrow(RuntimeException::class, "Refusing {$method} on entitlements");
    } finally {
        Entitlement::flushEventListeners();
        Entitlement::clearBootedModels();
    }

    expect(DB::table('entitlements')->count())->toBe(1)
        ->and(EntitlementWriter::isWriting())->toBeFalse();
})->with([
    'upsert' => ['upsert()', fn () => Entitlement::query()->upsert([['reader_id' => 'x']], ['reader_id'])],
    'forceDelete' => ['forceDelete()', fn () => Entitlement::query()->forceDelete()],
    'delete' => ['delete()', fn () => Entitlement::query()->delete()],
]);

it('knows about every write method the builder actually has', function (): void {
    $covered = array_keys(everyEntitlementWrite(1, 1));
    $writeish = array_values(array_filter(
        get_class_methods(Entitlement::query()),
        static fn (string $method): bool => (bool) preg_match('/^(insert|update|upsert|delete|forceDelete|truncate|increment|decrement|replace|touch)/', $method),
    ));

    expect($writeish)->toContain('insertOrIgnore', 'update', 'upsert');

    $unknown = array_values(array_diff($writeish, $covered, [
        'insertOrIgnoreReturningId',   // delegates to insertOrIgnoreReturning()
        'updateOrCreate',             // firstOrNew() + save(): walked as a model write
        'incrementOrCreate',          // updateOrCreate()
        'upsertReturning',            // upsert()
        'deleteQuietly',              // delete()
        'forceDeleteQuietly',         // forceDelete()
        'updateQuietly',              // update()
    ]));

    expect($unknown)->toBe([], 'the builder has write methods this file does not cover: '.implode(', ', $unknown));
});

/**
 * The named method each line holding the needle sits in, a closure's line counted to the method that defines it.
 *
 * @return list<string>
 */
function entitlementWriterMethodsHolding(string $needle): array
{
    $lines = explode("\n", (string) file_get_contents((string) (new ReflectionClass(EntitlementWriter::class))->getFileName()));
    $methods = [];

    foreach ($lines as $number => $line) {
        if (! str_contains($line, $needle)) {
            continue;
        }

        for ($back = $number; $back >= 0; $back--) {
            if (preg_match('/function (\w+)\(/', $lines[$back], $match) === 1) {
                $methods[] = $match[1];

                break;
            }
        }
    }

    return $methods;
}

it('arms each window in one private place, reached from its own doors alone, and closes it in a finally', function (): void {
    $source = (string) file_get_contents((string) (new ReflectionClass(EntitlementWriter::class))->getFileName());

    expect(entitlementWriterMethodsHolding('self::$writing = true'))->toBe(['save'])
        ->and(entitlementWriterMethodsHolding('self::$erasing = true'))->toBe(['erase'])
        ->and((new ReflectionMethod(EntitlementWriter::class, 'save'))->isPrivate())->toBeTrue()
        ->and((new ReflectionMethod(EntitlementWriter::class, 'erase'))->isPrivate())->toBeTrue()
        ->and(preg_match('/self::\$writing = true;\s*try \{\s*\$saved = \$row->save\(\);\s*\} finally \{\s*self::\$writing = false;\s*\}/', $source))->toBe(1)
        ->and(preg_match('/self::\$erasing = true;\s*try \{.*?\} finally \{\s*self::\$erasing = false;\s*\}/s', $source))->toBe(1)
        // `give()` serves grant() and comp(); revoke() saves itself; forget() alone erases.
        ->and(entitlementWriterMethodsHolding('$this->save('))->toBe(['revoke', 'give'])
        ->and(entitlementWriterMethodsHolding('$this->give('))->toBe(['grant', 'comp'])
        ->and(entitlementWriterMethodsHolding('$this->erase('))->toBe(['forget'])
        ->and(preg_match_all('/->save\(|[\'"]save[\'"]/', $source))->toBe(3)
        ->and(preg_match_all('/->delete\(|[\'"]delete[\'"]/', $source))->toBe(1)
        ->and((new ReflectionProperty(EntitlementWriter::class, 'writing'))->isPrivate())->toBeTrue()
        ->and((new ReflectionProperty(EntitlementWriter::class, 'erasing'))->isPrivate())->toBeTrue();
});

it('locks the site\'s row in a grant, a comp and a revoke, and no other row', function (): void {
    // ⚠️ A ROW LOCK ON AN ENTITLEMENT NOT YET WRITTEN IS A GAP LOCK on MySQL and MariaDB. No engine here shows that, so
    // the source is asked.
    expect(entitlementWriterMethodsHolding('lockForUpdate('))->toBe(['forget', 'lockedRow'])
        ->and(entitlementWriterMethodsHolding('sharedLock('))->toBe([])
        ->and(entitlementWriterMethodsHolding('$this->lockedRow('))->toBe(['revoke', 'give']);
});

it('leaves both windows closed after a write that failed', function (): void {
    Entitlement::saving(static function (): never {
        throw new RuntimeException('a listener that failed');
    });

    try {
        Fx::writer()->grant((int) $this->reader->getKey(), 'course.advanced-php', 'test.order:2', null);
    } catch (RuntimeException) {
    } finally {
        Entitlement::flushEventListeners();
        Entitlement::clearBootedModels();
    }

    expect(EntitlementWriter::isWriting())->toBeFalse()
        ->and(EntitlementWriter::isErasing())->toBeFalse()
        ->and(fn () => Entitlement::query()->update(['revoked_at' => null]))->toThrow(RuntimeException::class, 'Refusing update() on entitlements');
});

it('registers no listener of core\'s own on the model beyond the scope\'s', function (): void {
    $dispatcher = Entitlement::getEventDispatcher();
    $count = array_sum(array_map(
        static fn (string $event): int => count($dispatcher->getListeners('eloquent.'.$event.': '.Entitlement::class)),
        ['retrieved', 'creating', 'created', 'updating', 'updated', 'saving', 'saved', 'deleting', 'deleted', 'restoring', 'restored'],
    ));

    // `EnforcesScope`'s creating and updating guards, and nothing else.
    expect($count)->toBe(2);
});
