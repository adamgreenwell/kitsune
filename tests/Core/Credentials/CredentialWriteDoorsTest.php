<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Models\Credential;
use Kitsune\Core\Models\OrgCredentialMode;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Fx;

/*
 * Every public way to write `credentials` and `org_credential_modes`, walked outside the escape hatch and inside it —
 * ADR-040. `RolePermissionWriteDoorsTest`'s shape, for its reason: a claim that a door is shut one layer down is the
 * kind that rots, and inside `withoutScopeBecause()` `ScopedBuilder::upsert()` writes.
 */

beforeEach(function (): void {
    Fx::boot();
    $this->org = Fx::org('doors');
    Fx::member();
    Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));
    Fx::writer()->switchTo(CredentialMode::Live);
});

afterEach(fn () => Fx::tearDown());

/**
 * Every builder write, each with values of its own so one that lands cannot hide the next behind the unique index.
 *
 * @param  class-string<Credential|OrgCredentialMode>  $model
 * @return array<string, Closure>
 */
function everyCredentialWrite(string $model, int $orgId): array
{
    $isCredential = $model === Credential::class;
    $row = fn (string $tag): array => $isCredential
        ? ['org_id' => $orgId, 'slot' => 'fx.door-'.$tag, 'mode' => 'none', 'ciphertext' => 'plain', 'key_id' => null, 'changed_at' => now()]
        : ['org_id' => $orgId, 'mode' => 'test', 'changed_at' => now()];
    $column = $isCredential ? 'ciphertext' : 'mode';

    return [
        'update' => fn () => $model::query()->update([$column => 'test']),
        'delete' => fn () => $model::query()->delete(),
        'forceDelete' => fn () => $model::query()->forceDelete(),
        'insert' => fn () => $model::query()->insert($row('i1')),
        'insertGetId' => fn () => $model::query()->insertGetId($row('i2')),
        'insertOrIgnore' => fn () => $model::query()->insertOrIgnore($row('i3')),
        'insertUsing' => fn () => $model::query()->insertUsing(['org_id', 'changed_at'], $model::query()->selectRaw('org_id, changed_at')),
        'insertOrIgnoreUsing' => fn () => $model::query()->insertOrIgnoreUsing(['org_id', 'changed_at'], $model::query()->selectRaw('org_id, changed_at')),
        'insertOrIgnoreReturning' => fn () => $model::query()->insertOrIgnoreReturning($row('i4')),
        'upsert' => fn () => $model::query()->upsert([$row('i5')], $isCredential ? ['org_id', 'slot', 'mode'] : ['org_id']),
        'updateOrInsert' => fn () => $model::query()->updateOrInsert(['id' => 0], $row('i6')),
        'updateFrom' => fn () => $model::query()->updateFrom([$column => 'test']),
        'truncate' => fn () => $model::query()->truncate(),
        'increment' => fn () => $model::query()->increment('org_id', 0),
        'decrement' => fn () => $model::query()->decrement('org_id', 0),
        'incrementEach' => fn () => $model::query()->incrementEach(['org_id' => 0]),
        'decrementEach' => fn () => $model::query()->decrementEach(['org_id' => 0]),
        'touch' => fn () => $model::query()->touch('changed_at'),
    ];
}

/**
 * Every model write.
 *
 * @param  class-string<Credential|OrgCredentialMode>  $model
 * @return array<string, Closure>
 */
function everyCredentialModelWrite(string $model, int $orgId): array
{
    $isCredential = $model === Credential::class;
    $row = fn (string $tag): array => $isCredential
        ? ['slot' => 'fx.door-'.$tag, 'mode' => 'none', 'ciphertext' => 'plain', 'changed_at' => now()]
        : ['mode' => 'test', 'changed_at' => now()];
    $column = $isCredential ? 'ciphertext' : 'mode';

    return [
        'save' => function () use ($model, $column): void {
            $existing = $model::query()->firstOrFail();
            $existing->setAttribute($column, 'test');
            $existing->save();
        },
        'saveQuietly' => function () use ($model, $column): void {
            $existing = $model::query()->firstOrFail();
            $existing->setAttribute($column, 'test');
            $existing->saveQuietly();
        },
        'push' => function () use ($model, $column): void {
            $existing = $model::query()->firstOrFail();
            $existing->setAttribute($column, 'test');
            $existing->push();
        },
        'model delete' => fn () => $model::query()->firstOrFail()->delete(),
        'model forceDelete' => fn () => $model::query()->firstOrFail()->forceDelete(),
        'create' => fn () => $model::create($row('c1')),
        'firstOrCreate' => fn () => $model::query()->firstOrCreate($isCredential ? ['slot' => 'fx.door-c2'] : ['mode' => 'neither'], $row('c2')),
        'updateOrCreate' => fn () => $model::query()->updateOrCreate($isCredential ? ['slot' => 'fx.door-c3'] : ['mode' => 'neither'], $row('c3')),
    ];
}

it('refuses every write to either table, outside the escape hatch and inside it, and moves nothing', function (string $model, bool $hatch): void {
    $before = [DB::table('credentials')->get()->all(), DB::table('org_credential_modes')->get()->all()];
    $writes = [...everyCredentialWrite($model, $this->org->getKey()), ...everyCredentialModelWrite($model, $this->org->getKey())];
    $allowed = [];
    $otherwise = [];

    foreach ($writes as $method => $write) {
        try {
            $hatch ? $model::withoutScopeBecause('a test walking every write', fn () => $write()) : $write();
            $allowed[] = $method;
        } catch (RuntimeException $refusal) {
            // A guard's refusal, not any exception: a write that dies on an index looks the same to a bare catch. The
            // parent's own refusals of `truncate()`, `updateOrInsert()` and `updateFrom()` say they "cannot be" used.
            if (! str_contains($refusal->getMessage(), 'Refusing') && ! str_contains($refusal->getMessage(), 'cannot be')) {
                $otherwise[] = $method.': '.$refusal->getMessage();
            }
        } catch (Throwable $other) {
            $otherwise[] = $method.': '.$other::class.' '.$other->getMessage();
        }
    }

    expect($allowed)->toBe([], 'these writes were allowed: '.implode(', ', $allowed))
        ->and($otherwise)->toBe([], 'these failed for a reason that is not a guard: '.implode(' | ', $otherwise))
        ->and([DB::table('credentials')->get()->all(), DB::table('org_credential_modes')->get()->all()])->toEqual($before);
})->with([
    'credentials' => [Credential::class],
    'modes' => [OrgCredentialMode::class],
])->with(['outside the hatch' => [false], 'inside the hatch' => [true]]);

/** ⚠️ The door review found open on `role_permissions`, named on its own: inside the hatch, an upsert planted a row. */
it('refuses an upsert inside the escape hatch in the store\'s own words', function (): void {
    $plant = fn () => Credential::withoutScopeBecause('a test', fn ($query) => $query->upsert(
        [['org_id' => $this->org->getKey(), 'slot' => Fx::SHARED, 'mode' => 'none', 'ciphertext' => 'plain', 'key_id' => null, 'changed_at' => now()]],
        ['org_id', 'slot', 'mode'],
    ));

    expect($plant)->toThrow(RuntimeException::class, 'Refusing upsert() on credentials: a stored credential changes only through')
        ->and(DB::table('credentials')->value('ciphertext'))->not->toBe('plain');
});

it('knows about every write method the builder actually has', function (): void {
    $covered = array_keys(everyCredentialWrite(Credential::class, 1));
    $writeish = array_values(array_filter(
        get_class_methods(Credential::query()),
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

it('arms the window in one private place, which only the writer\'s three acts reach, and closes it in a finally', function (): void {
    $source = (string) file_get_contents((string) (new ReflectionClass(CredentialWriter::class))->getFileName());
    $lines = explode("\n", $source);
    $openers = [];

    foreach ($lines as $number => $line) {
        if (! str_contains($line, 'self::$writing = true')) {
            continue;
        }

        for ($back = $number; $back >= 0; $back--) {
            if (preg_match('/function (\w+)\(/', $lines[$back], $match) === 1) {
                $openers[] = $match[1];

                break;
            }
        }
    }

    expect($openers)->toBe(['save'])
        ->and((new ReflectionMethod(CredentialWriter::class, 'save'))->isPrivate())->toBeTrue()
        ->and(preg_match('/self::\$writing = true;\s*try \{\s*\$saved = \$row->save\(\);\s*\} finally \{\s*self::\$writing = false;\s*\}/', $source))->toBe(1)
        ->and(substr_count($source, '$this->save($row'))->toBe(3)
        ->and((new ReflectionProperty(CredentialWriter::class, 'writing'))->isPrivate())->toBeTrue();
});

it('leaves the window closed after a write that failed', function (): void {
    Credential::saving(static function (): never {
        throw new RuntimeException('a listener that failed');
    });

    try {
        Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));
    } catch (RuntimeException) {
    } finally {
        Credential::flushEventListeners();
        Credential::clearBootedModels();
    }

    expect(CredentialWriter::isWriting())->toBeFalse()
        ->and(fn () => Credential::query()->update(['ciphertext' => 'plain']))->toThrow(RuntimeException::class, 'Refusing update() on credentials');
});

it('registers no listener of core\'s own on either model beyond the scope\'s', function (): void {
    $dispatcher = Credential::getEventDispatcher();
    $count = static fn (string $model): int => array_sum(array_map(
        static fn (string $event): int => count($dispatcher->getListeners("eloquent.{$event}: {$model}")),
        ['retrieved', 'creating', 'created', 'updating', 'updated', 'saving', 'saved', 'deleting', 'deleted', 'restoring', 'restored'],
    ));

    // `EnforcesScope`'s creating and updating guards, and nothing else.
    expect($count(Credential::class))->toBe(2)
        ->and($count(OrgCredentialMode::class))->toBe(2);
});
