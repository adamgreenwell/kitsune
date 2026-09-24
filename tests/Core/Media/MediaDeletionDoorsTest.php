<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/*
 * Every door that could trash or restore an entry without the custody of its files — ADR-042 decision 5 (T1-T6).
 *
 * ⚠️ REFUSED BEFORE A STATEMENT RUNS, AND SAID SO. Each case asserts the refusal's own words, that the database saw no
 * statement, that the entry is live, that its public file is byte-for-byte where it was, and that no audit row was
 * written — so a refusal made by some other guard, or by the engine, cannot pass for this one. T2 shows the update the
 * guard stops is real on every engine, run past the guarantee through `toBase()`.
 */

const DOORS_PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\x0f\0\0\x01\x01\0\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82";

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake(MediaDisks::PRIVATE);

    $this->org = Org::create(['slug' => 'doors', 'name' => 'Doors']);
    app(Context::class)->setOrg($this->org);
    app(Context::class)->setSite(Site::create(['handle' => 'main', 'slug' => 'doors-main', 'name' => 'Main', 'locale' => 'en']));

    $this->image = EntryType::create(['org_id' => $this->org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);
    $this->article = EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);

    $source = tempnam(sys_get_temp_dir(), 'kitsune-doors-');
    file_put_contents($source, DOORS_PNG);
    $this->victim = MediaLibrary::store($source, 'logo.png', $this->image, 'public');
    unlink($source);

    $this->path = (string) DB::table('media_files')->where('entry_id', $this->victim->id)->value('path');
    $this->hash = hash('sha256', (string) Storage::disk('public')->get($this->path));
});

afterEach(fn () => app(Context::class)->forget());

/** Run a write and report what it threw and how many statements reached the database. */
function throughDoor(Closure $write): array
{
    $statements = 0;
    DB::listen(function () use (&$statements): void {
        $statements++;
    });

    try {
        $write();
    } catch (RuntimeException $refused) {
        return [$refused->getMessage(), $statements];
    }

    return [null, $statements];
}

/** The victim is live, its public file untouched, and no audit row was written since `$audits`. */
function expectVictimUntouched(int $audits): void
{
    $row = DB::table('entries')->where('id', test()->victim->id)->first();

    expect($row)->not->toBeNull()
        ->and($row->deleted_at)->toBeNull()
        ->and(hash('sha256', (string) Storage::disk('public')->get(test()->path)))->toBe(test()->hash)
        ->and(DB::table('audit_log')->count())->toBe($audits);
}

/** The victim reached through an alias, joined back to `entries`. */
function aliasedVictim(Builder $query): Builder
{
    return $query->from('entries as e')->join('entries', 'entries.org_id', '=', 'e.org_id')->where('e.id', test()->victim->id);
}

/*
 * T1. Every write door refuses a `from` that is not `entries`, inside the escape hatch too.
 */
it('refuses every write through an aliased entries table', function (string $door, Closure $write, bool $hatch): void {
    $audits = DB::table('audit_log')->count();

    [$message, $statements] = throughDoor(fn () => $hatch
        ? Entry::withoutScopeBecause('a test of the write doors', fn (Builder $query) => $write(aliasedVictim($query)))
        : $write(aliasedVictim(Entry::query())));

    expect($message)->toStartWith("Refusing to {$door} entries through [entries as e]")
        ->and($statements)->toBe(0);

    expectVictimUntouched($audits);
})->with([
    'update deleted_at through the alias' => ['update', fn (Builder $q) => $q->update(['e.deleted_at' => now()])],
    'update deleted_at' => ['update', fn (Builder $q) => $q->update(['deleted_at' => now()])],
    'update a title' => ['update', fn (Builder $q) => $q->update(['title' => 'x'])],
    'delete' => ['delete', fn (Builder $q) => $q->delete()],
    'force-delete' => ['force-delete', fn (Builder $q) => $q->forceDelete()],
    'increment' => ['increment', fn (Builder $q) => $q->increment('id', 0)],
    'decrement' => ['decrement', fn (Builder $q) => $q->decrement('id', 0)],
    'incrementEach' => ['increment', fn (Builder $q) => $q->incrementEach(['id' => 0])],
    'decrementEach' => ['decrement', fn (Builder $q) => $q->decrementEach(['id' => 0])],
    'upsert' => ['upsert', fn (Builder $q) => $q->upsert([['id' => 1, 'title' => 'x']], ['id'])],
    'touch, which reaches update' => ['update', fn (Builder $q) => $q->touch()],
])->with(['outside the hatch' => false, 'inside the hatch' => true]);

it('refuses a delete through an aliased table on a query without scopes', function (): void {
    $audits = DB::table('audit_log')->count();

    [$message, $statements] = throughDoor(fn () => aliasedVictim((new Entry)->newQueryWithoutScopes())->delete());

    expect($message)->toStartWith('Refusing to delete entries through [entries as e]')
        ->and($statements)->toBe(0);

    expectVictimUntouched($audits);
});

/*
 * T2. The control: past the guarantee, through `toBase()`, the update the guard stops lands on every engine — so T1 and
 * T3 cannot be passing because the engine refused the statement.
 */
it('shows the aliased update is real, past the guard', function (): void {
    Entry::query()->toBase()
        ->from('entries as e')
        ->join('entries', 'entries.org_id', '=', 'e.org_id')
        ->where('e.id', $this->victim->id)
        ->update(['e.deleted_at' => now()]);

    expect(DB::table('entries')->where('id', $this->victim->id)->value('deleted_at'))->not->toBeNull();
});

it('shows a joined update lands on the joined row or on the entry, by engine', function (): void {
    $parent = Entry::create(['entry_type_id' => $this->article->id, 'title' => 'Parent', 'slug' => 'parent']);
    $child = Entry::create(['entry_type_id' => $this->article->id, 'title' => 'Child', 'slug' => 'child', 'origin_id' => $parent->id]);

    Entry::query()->toBase()
        ->join('entries as p', 'p.id', '=', 'entries.origin_id')
        ->where('entries.id', $child->id)
        ->update(['p.deleted_at' => now()]);

    $trashed = static fn (Entry $entry): bool => DB::table('entries')->where('id', $entry->id)->value('deleted_at') !== null;

    // MySQL and MariaDB keep the qualifier and trash the parent; PostgreSQL and SQLite drop it and trash the child.
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) ? $trashed($parent) : $trashed($child))
        ->toBeTrue();
});

/*
 * T3. `deleted_at` qualified by any table but `entries` is refused, whatever the join.
 */
it('refuses deleted_at qualified by another table', function (string $join, string $key, bool $hatch): void {
    $parent = Entry::create(['entry_type_id' => $this->article->id, 'title' => 'Parent', 'slug' => 'parent']);
    $child = Entry::create(['entry_type_id' => $this->article->id, 'title' => 'Child', 'slug' => 'child', 'origin_id' => $parent->id]);
    $audits = DB::table('audit_log')->count();

    $write = static fn (Builder $query) => $query->join($join, explode(' as ', $join)[1].'.id', '=', 'entries.origin_id')
        ->whereKey($child->id)
        ->update([$key => now()]);

    [$message, $statements] = throughDoor(fn () => $hatch
        ? Entry::withoutScopeBecause('a test of the write doors', $write)
        : $write(Entry::query()));

    expect($message)->toStartWith("Refusing to write [{$key}]")
        ->and($statements)->toBe(0)
        ->and(DB::table('entries')->whereIn('id', [$parent->id, $child->id])->whereNotNull('deleted_at')->count())->toBe(0);

    expectVictimUntouched($audits);
})->with([
    'an alias' => ['entries as p', 'p.deleted_at'],
    'the table name in capitals' => ['entries as ENTRIES', 'ENTRIES.deleted_at'],
])->with(['outside the hatch' => false, 'inside the hatch' => true]);

/*
 * T4. No arithmetic door writes `deleted_at`, under any spelling, on the instance or in bulk.
 */
it('refuses deleted_at at every arithmetic door', function (Closure $write, string $key, bool $hatch): void {
    $audits = DB::table('audit_log')->count();

    [$message, $statements] = throughDoor(fn () => $hatch
        ? Entry::withoutScopeBecause('a test of the write doors', fn (Builder $query) => $write($query->whereKey($this->victim->id), $key))
        : $write(Entry::query()->whereKey($this->victim->id), $key));

    expect($message)->toStartWith("Refusing to write [{$key}] through an arithmetic update")
        ->and($statements)->toBe(0);

    expectVictimUntouched($audits);
})->with([
    'increment' => [fn (Builder $q, string $key) => $q->increment('id', 0, [$key => now()])],
    'decrement' => [fn (Builder $q, string $key) => $q->decrement('id', 0, [$key => now()])],
    'incrementEach' => [fn (Builder $q, string $key) => $q->incrementEach(['id' => 0], [$key => now()])],
    'decrementEach' => [fn (Builder $q, string $key) => $q->decrementEach(['id' => 0], [$key => now()])],
])->with([
    'deleted_at' => 'deleted_at',
    'DELETED_AT' => 'DELETED_AT',
    'entries.deleted_at' => 'entries.deleted_at',
])->with(['outside the hatch' => false, 'inside the hatch' => true]);

it('refuses deleted_at as the arithmetic column, and on the instance', function (): void {
    $audits = DB::table('audit_log')->count();

    [$asColumn, $none] = throughDoor(fn () => Entry::query()->whereKey($this->victim->id)->increment('deleted_at'));
    [$onInstance, $alsoNone] = throughDoor(fn () => $this->victim->increment('id', 0, ['deleted_at' => now()]));

    expect($asColumn)->toStartWith('Refusing to write [deleted_at] through an arithmetic update')
        ->and($onInstance)->toStartWith('Refusing to write [deleted_at] through an arithmetic update')
        ->and($none + $alsoNone)->toBe(0);

    expectVictimUntouched($audits);
});

/*
 * T5. A delete on a query without the soft-delete scope is refused, never guessed into an erasure.
 */
it('refuses a delete on a query without the soft-delete scope', function (Closure $query): void {
    $audits = DB::table('audit_log')->count();

    // Built before counting: a collection's toQuery() has to read the rows first.
    $scopeless = $query($this->victim->id);

    [$message, $statements] = throughDoor(fn () => $scopeless->delete());

    expect($message)->toContain('without the soft-delete scope')
        ->and($message)->toContain('Entry::query()->…->delete()')
        ->and($message)->toContain('forceDelete()')
        ->and($statements)->toBe(0);

    expectVictimUntouched($audits);
})->with([
    'newQueryWithoutScopes()' => [fn (int $id) => (new Entry)->newQueryWithoutScopes()->whereKey($id)],
    'a collection\'s toQuery()' => [fn (int $id) => Entry::query()->whereKey($id)->get()->toQuery()],
]);

it('moves an entry to the trash through a scoped query, the control', function (): void {
    $article = Entry::create(['entry_type_id' => $this->article->id, 'title' => 'Bin me', 'slug' => 'bin-me']);

    Entry::query()->whereKey($article->id)->delete();

    expect(DB::table('entries')->where('id', $article->id)->value('deleted_at'))->not->toBeNull()
        ->and(DB::table('audit_log')->where('action', 'entry.deleted')->exists())->toBeTrue();
});

/*
 * T6. A JSON path into `deleted_at` is refused: on SQLite it would store {} and trash the row while the trail recorded a
 * restore.
 */
it('refuses a JSON path into deleted_at', function (string $key, bool $hatch): void {
    $audits = DB::table('audit_log')->count();

    [$message, $statements] = throughDoor(fn () => $hatch
        ? Entry::withoutScopeBecause('a test of the write doors', fn (Builder $query) => $query->whereKey($this->victim->id)->update([$key => null]))
        : Entry::query()->whereKey($this->victim->id)->update([$key => null]));

    expect($message)->toStartWith("Refusing to write [{$key}]: `deleted_at` holds no JSON")
        ->and($statements)->toBe(0);

    expectVictimUntouched($audits);
})->with([
    'deleted_at->x' => 'deleted_at->x',
    'entries.deleted_at->x' => 'entries.deleted_at->x',
])->with(['outside the hatch' => false, 'inside the hatch' => true]);
