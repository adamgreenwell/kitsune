<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Expression;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tenancy\ScopeWrites;

beforeEach(function (): void {
    $this->morphMapBeforeAuditTypes = Relation::morphMap();
    $this->org = Org::create(['name' => 'Audit types', 'slug' => 'audit-types']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['org_id' => $this->org->id, 'handle' => 'main', 'slug' => 'audit-types-main', 'name' => 'Main']);
    app(Context::class)->setSite($this->site);
    $this->type = EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
});

afterEach(function (): void {
    Relation::morphMap($this->morphMapBeforeAuditTypes, false);
    app(Context::class)->forget();
});

dataset('unstoreable audit identity types', [
    'empty' => [''],
    'NUL' => ["Named\\Actor\0Hidden"],
    'overlong' => [str_repeat('a', 256)],
    'invalid UTF-8' => ["Actor\xff"],
]);

it('rolls an unauditable entry write back with a portable identity-type refusal', function (string $alias, string $column, string $operation): void {
    $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Before']);
    $rows = Entry::count();
    $audits = AuditLog::count();
    $class = $column === 'actor_type' ? AuthUser::class : Entry::class;
    Relation::morphMap([$alias => $class], false);

    if ($column === 'actor_type') {
        $actor = new AuthUser;
        $actor->forceFill(['id' => 17]);
        Auth::login($actor);
    }

    $write = match ($operation) {
        'create' => fn () => Entry::create(['entry_type_id' => $this->type->id, 'title' => 'After']),
        'save' => fn () => $entry->update(['title' => 'After']),
        'bulk' => fn () => Entry::query()->whereKey($entry->getKey())->update(['title' => 'After']),
        'delete' => fn () => $entry->delete(),
    };

    expect($write)->toThrow(RuntimeException::class, "[{$column}] must be")
        ->and(Entry::count())->toBe($rows)
        ->and(AuditLog::count())->toBe($audits);

    $stored = DB::table('entries')->where('id', $entry->getKey())->first();
    expect($stored->title)->toBe('Before')->and($stored->deleted_at)->toBeNull();
})->with('unstoreable audit identity types')->with(['actor_type', 'target_type'])->with(['create', 'save', 'bulk', 'delete']);

it('checks every append door and column spelling before any row is inserted', function (string $column, string $door): void {
    $safe = ['org_id' => $this->org->id, 'site_id' => $this->site->id, 'action' => 'fixture.identity', 'actor_type' => 'Actor', 'target_type' => 'Target', 'created_at' => now()];
    $bad = $safe;
    unset($bad[strtolower($column)]);
    $bad[$column] = "Type\0Hidden";

    $write = match ($door) {
        'quiet' => fn () => AuditLog::withoutEvents(fn () => AuditLog::create($bad)),
        'id' => fn () => AuditLog::query()->insertGetId($bad),
        'insert' => fn () => AuditLog::query()->insert([$safe, $bad]),
        'ignore' => fn () => AuditLog::query()->insertOrIgnore([$safe, $bad]),
        'returning' => fn () => AuditLog::query()->insertOrIgnoreReturning([$safe, $bad]),
    };

    expect($write)->toThrow(RuntimeException::class, '['.strtolower($column).'] must be')
        ->and(AuditLog::count())->toBe(0);
})->with(['actor_type', 'ACTOR_TYPE', 'target_type', 'TARGET_TYPE'])->with(['quiet', 'id', 'insert', 'ignore', 'returning']);

it('keeps storage validation when scope checks and model events are explicitly suspended', function (string $column): void {
    $row = ['org_id' => $this->org->id, 'action' => 'fixture.identity', $column => "Type\0Hidden", 'created_at' => now()];

    expect(fn () => ScopeWrites::suspend(fn () => AuditLog::withoutEvents(fn () => AuditLog::create($row))))
        ->toThrow(RuntimeException::class, "[{$column}] must be")
        ->and(AuditLog::count())->toBe(0);
})->with(['actor_type', 'target_type']);

it('refuses values whose identity text is not supplied explicitly', function (mixed $value, string $column): void {
    $row = ['org_id' => $this->org->id, 'action' => 'fixture.identity', $column => $value, 'created_at' => now()];

    expect(fn () => AuditLog::create($row))->toThrow(RuntimeException::class, "[{$column}] must be")
        ->and(AuditLog::count())->toBe(0);
})->with([
    'SQL expression' => [new Expression("'Bounded'")],
    'boolean' => [true],
    'float' => [1.5],
    'array' => [['Bounded']],
])->with(['actor_type', 'target_type']);

it('checks qualified identity columns before sending SQL to any engine', function (string $column, string $door): void {
    $row = ['org_id' => $this->org->id, 'action' => 'fixture.identity', 'audit_log.'.$column => "Type\0Hidden", 'created_at' => now()];
    $write = $door === 'id'
        ? fn () => AuditLog::query()->insertGetId($row)
        : fn () => AuditLog::query()->insert([$row]);

    expect($write)->toThrow(RuntimeException::class, '['.strtolower($column).'] must be')
        ->and(AuditLog::count())->toBe(0);
})->with(['ACTOR_TYPE', 'TARGET_TYPE'])->with(['id', 'insert']);

it('preserves bounded morph aliases exactly, counting Unicode characters rather than bytes', function (string|int $alias, string $column): void {
    // A sole key 0 is Laravel's list-of-models shorthand, not a keyed alias map.
    Relation::morphMap(['fixture.unused' => Org::class, $alias => $column === 'actor_type' ? AuthUser::class : Entry::class], false);

    if ($column === 'actor_type') {
        $actor = new AuthUser;
        $actor->forceFill(['id' => 17]);
        Auth::login($actor);
    }

    Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Attributed']);

    expect(AuditLog::query()->sole()->getAttribute($column))->toBe((string) $alias);
})->with([
    'ASCII boundary' => [str_repeat('a', 255)],
    'two-byte boundary' => [str_repeat('é', 255)],
    'four-byte boundary' => [str_repeat('🦊', 255)],
    'integer alias' => [0],
])->with(['actor_type', 'target_type']);

it('refuses an anonymous actor by its actual runtime class name without storing a partial identity', function (): void {
    $actor = new class extends AuthUser {};
    $actor->forceFill(['id' => 17]);
    Auth::login($actor);

    expect(str_contains($actor::class, "\0"))->toBeTrue()
        ->and(fn () => Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Unauditable']))
        ->toThrow(RuntimeException::class, '[actor_type] must be')
        ->and(Entry::count())->toBe(0)
        ->and(AuditLog::count())->toBe(0);
});

it('records an anonymous Eloquent actor through an explicit bounded morph alias', function (): void {
    $actor = new class extends AuthUser {};
    $actor->forceFill(['id' => 17]);
    Relation::morphMap(['host.actor' => $actor::class], false);
    Auth::login($actor);

    $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Attributed']);

    expect(AuditLog::for($entry)->sole()->actor_type)->toBe('host.actor')
        ->and(AuditLog::for($entry)->sole()->actor_id)->toBe('17');
});

it('retains NULL for a system action without a target', function (): void {
    $audit = AuditLog::create(['action' => 'fixture.system', 'actor_type' => null, 'target_type' => null]);

    expect($audit->fresh()->actor_type)->toBeNull()->and($audit->fresh()->target_type)->toBeNull();
});
