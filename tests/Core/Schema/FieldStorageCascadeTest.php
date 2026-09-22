<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/**
 * ⚠️ `fields.field_storage_id` is `cascadeOnDelete()`, and `FieldStorage` did not refuse.
 *
 * Every other model whose deletion takes content by foreign key — `EntryType`, `Field`, `Site` — implements
 * `RefusesCascadingDeletes`. `FieldStorage` did not, and could not have benefited from it if it had: its
 * `GuardedStorageBuilder` extends Eloquent's builder directly and carried no `delete()` override, so the
 * contract was unreachable from the one builder that model uses.
 *
 * What the gap costs is strategy-dependent, and an earlier version of this header got it wrong by copying a
 * stale claim out of `Field::guardCascade()`. `Entry::redactField()` does NOT resolve storage through
 * `fields`; it selects `field_storage` by handle within the org. So INLINE values stay erasable even with the
 * storage row gone. What does not survive erasure is RELATIONAL data — `entry_relations.field_storage_id` is
 * `nullOnDelete()`, so the pivots keep the values and lose every way of being matched to a field — and
 * PROMOTED values, whose `promoted_by` record can no longer be matched to a storage row (ADR-020).
 */
function storageWithAField(string $handle = 'probe_thing'): array
{
    $type = EntryType::create([
        'org_id' => null, 'handle' => 'probe_type', 'name' => 'Probe', 'plural_name' => 'Probes',
    ]);

    $storage = FieldStorage::create([
        'org_id' => null, 'handle' => $handle, 'type' => 'text', 'cardinality' => 1, 'pii_class' => 'none',
    ]);

    $field = Field::create([
        'entry_type_id' => $type->getKey(),
        'field_storage_id' => $storage->getKey(),
        'label' => 'Probe thing',
    ]);

    return [$type, $storage, $field];
}

it('refuses a query-builder delete that would cascade a field away', function (): void {
    [, $storage] = storageWithAField();

    expect(fn () => FieldStorage::query()->whereKey($storage->getKey())->delete())
        ->toThrow(RuntimeException::class, 'cannot be deleted while 1 field');

    /* The ASSERTION THAT MATTERS: the row it would have cascaded is still there. */
    expect(DB::table('fields')->count())->toBe(1)
        ->and(DB::table('field_storage')->where('id', $storage->getKey())->count())->toBe(1);
});

/** The refusal names the types, so an operator knows where to look rather than being told only that it failed. */
it('names the entry types holding the field in its refusal', function (): void {
    [, $storage] = storageWithAField();

    expect(fn () => $storage->delete())
        ->toThrow(RuntimeException::class, '(probe_type)');
});

/**
 * ⚠️ THE QUIET PATH, which is why the rule is a builder concern and not a `deleting` event.
 *
 * `deleteQuietly()` dispatches nothing, so a refusal written as a model event covers one path and misses
 * this one — the exact hole `RefusesCascadingDeletes` was created for, found seven times in this codebase.
 */
it('refuses a quiet delete too', function (): void {
    [, $storage] = storageWithAField();

    expect(fn () => $storage->deleteQuietly())
        ->toThrow(RuntimeException::class, 'cannot be deleted while');

    expect(DB::table('fields')->count())->toBe(1);
});

/** The ordering `PersonServiceProvider::uninstall()` takes by hand is now the one the code requires. */
it('permits the delete once the fields are gone', function (): void {
    [, $storage, $field] = storageWithAField();

    $field->delete();

    expect(fn () => $storage->delete())->not->toThrow(RuntimeException::class);

    expect(DB::table('field_storage')->where('id', $storage->getKey())->count())->toBe(0);
});

/** A storage row nothing references was always safe, and stays safe — the guard refuses on the reference. */
it('permits deleting storage that no field points at', function (): void {
    $storage = FieldStorage::create([
        'org_id' => null, 'handle' => 'unreferenced', 'type' => 'text', 'cardinality' => 1, 'pii_class' => 'none',
    ]);

    $storage->delete();

    expect(DB::table('field_storage')->where('id', $storage->getKey())->count())->toBe(0);
});

/**
 * ⚠️ THE SECOND FOREIGN KEY, which refusing on `fields` alone left open.
 *
 * `entry_relations.field_storage_id` is `nullOnDelete()`. Once the last `fields` row is gone — the very
 * remediation the refusal prints — deleting the storage NULLs every surviving pivot, and nothing can match
 * those rows to a field again. That is reachable without raw SQL: `Field::guardCascade()` counts live rows by
 * the entry's CURRENT type, so an entry retyped after its relations were written satisfies it at zero.
 */
it('refuses while relation pivots still name the storage, even with no fields left', function (): void {
    [$type, $storage, $field] = storageWithAField('probe_rel');

    $org = Org::create(['slug' => 'rel-org', 'name' => 'Rel']);
    app(Context::class)->setOrg($org);
    $site = Site::create(['handle' => 'rel', 'slug' => 'rel', 'name' => 'Rel', 'locale' => 'en']);
    app(Context::class)->setSite($site);

    $source = Entry::create(['entry_type_id' => $type->getKey(), 'title' => 'Source', 'slug' => 'source']);
    $target = Entry::create(['entry_type_id' => $type->getKey(), 'title' => 'Target', 'slug' => 'target']);

    DB::table('entry_relations')->insert([
        'org_id' => $org->getKey(),
        'source_entry_id' => $source->getKey(),
        'target_entry_id' => $target->getKey(),
        'field_storage_id' => $storage->getKey(),
    ]);

    /* The fields go first, as the refusal instructs — and that is exactly when the pivots become invisible. */
    $field->delete();

    expect(fn () => $storage->delete())
        ->toThrow(RuntimeException::class, '1 relation row');

    expect(DB::table('entry_relations')->whereNotNull('field_storage_id')->count())->toBe(1);

    app(Context::class)->forget();
});
