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
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tenancy\Contracts\RequiresModelSave;

/**
 * `columnsRequiringModelSave()` covers the INSERT family too — issue #60.
 *
 * ⚠️ IT COVERED HALF THE DOORS. `RequiresModelSave`'s docblock said the model event becomes *"the
 * only door rather than the first one"*, and that was true of `update()` alone. Measured before this:
 * `Site::query()->update(['base_url' => …])` refused, while
 * `Site::query()->insert([… 'base_url' => 'https://x.test' …])` created a row with
 * `canonical_host = NULL` — a site declaring a public URL and reachable at none, which is exactly
 * what the derived columns exist to prevent.
 *
 * ⚠️ AND THE OBVIOUS GUARD BREAKS EVERY CREATE, which is why the first attempt at this was reverted
 * and filed rather than retried. `Model::performInsert()` writes through this same builder, and
 * `refusePerRowColumns()` stands aside for a loaded instance by checking `$model->exists` — which is
 * FALSE during an insert. A guard keyed on it fires on the legitimate path.
 *
 * The discriminator is which METHOD was called, which is how `AuditedBuilder` already solved this for
 * `Entry`: `performInsert()` uses `insertGetId()` for an incrementing model, and a bulk caller uses
 * `insert()` or one of the `…Using` forms.
 */
beforeEach(function (): void {
    $this->org = Org::create(['name' => 'Guarded', 'slug' => 'guarded']);
    app(Context::class)->setOrg($this->org);
});

afterEach(fn () => app(Context::class)->forget());

/** A complete Site row, as a bulk caller would assemble it. */
function siteRow(int $orgId, string $handle): array
{
    return [
        'org_id' => $orgId, 'handle' => $handle, 'slug' => $handle, 'name' => ucfirst($handle),
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => "https://{$handle}.test",
        'created_at' => now(), 'updated_at' => now(),
    ];
}

it('refuses a bulk insert that names a per-row column', function (): void {
    expect(fn () => Site::query()->insert(siteRow($this->org->id, 'bulk')))
        ->toThrow(RuntimeException::class, 'cannot be created in bulk');

    // ⚠️ And nothing was written. A guard that throws after the insert would be worse than none.
    expect(DB::table('sites')->where('handle', 'bulk')->exists())->toBeFalse();
});

it('refuses every bulk creation path, not only the one that was reported', function (): void {
    /*
     * ⚠️ THE ENUMERATION IS THE POINT. This defect has been found on six guards in this project
     * because each was written for the path someone happened to hit. `insertOrIgnore` and the two
     * `…Using` forms are refused unconditionally, because `performInsert()` uses none of them and
     * there is therefore no per-row caller to protect.
     */
    expect(fn () => Site::query()->insertOrIgnore(siteRow($this->org->id, 'ignore')))
        ->toThrow(RuntimeException::class, 'cannot be created in bulk');

    expect(fn () => Site::query()->insertUsing(['org_id'], Site::query()->select('org_id')))
        ->toThrow(RuntimeException::class, 'cannot be created in bulk');

    expect(fn () => Site::query()->insertOrIgnoreUsing(['org_id'], Site::query()->select('org_id')))
        ->toThrow(RuntimeException::class, 'cannot be created in bulk');
});

it('refuses a bulk insert on every guarded model', function (): void {
    /*
     * ⚠️ ALL FOUR, because the issue named one. `Site` is where the defect was measured, and
     * `Entry`, `EntryType` and `Field` declare per-row columns too — so a guard written for `Site`
     * alone would have left three models with the same hole. This is the sixth time in this project
     * that a guard was found covering the path someone happened to hit.
     *
     * ⚠️ `Entry` IS REFUSED BY ITS OWN GUARD, with a different message: `AuditedBuilder::insert()`
     * throws "Entries cannot be written in bulk" and never reaches `ScopedBuilder`. Asserted as a
     * refusal rather than by message, because which guard fires first is not the property under test
     * — that the door is shut is.
     */
    $rows = [
        Site::class => ['org_id' => $this->org->id, 'handle' => 'x', 'slug' => 'x', 'name' => 'X'],
        EntryType::class => ['org_id' => $this->org->id, 'handle' => 'y', 'name' => 'Y', 'plural_name' => 'Ys'],
        Entry::class => ['entry_type_id' => 1, 'title' => 'Z'],
        Field::class => ['entry_type_id' => 1, 'field_storage_id' => 1, 'label' => 'L', 'ordering' => 0],
    ];

    foreach ($rows as $class => $row) {
        expect(fn () => $class::query()->insert($row + ['created_at' => now(), 'updated_at' => now()]))
            ->toThrow(RuntimeException::class, '', "[{$class}] allowed a bulk insert");
    }
});

it('refuses a DIRECT insertGetId, which performInsert alone may use', function (): void {
    /*
     * ⚠️ THE HOLE THE FIRST VERSION LEFT, found by review. `insertGetId()` is publicly callable, not
     * `performInsert()`'s private door — so `Site::query()->insertGetId([… 'base_url' => …])` wrote a
     * row with whatever `canonical_host` the caller chose, or none, which is the cross-org claim hole
     * the change was meant to close reached one method along. My enumeration test claimed to cover
     * every creation path and did not cover this one.
     *
     * ⚠️ THE DISCRIMINATOR IS THE MODEL BEHIND THE BUILDER, not the method. `performInsert()` builds
     * its query from `newModelQuery()`, so the builder's model IS the instance being saved and every
     * guarded value came off its own attributes. `Site::query()` builds one from a fresh, empty
     * instance, so a guarded column in the values has nothing to match.
     */
    expect(fn () => Site::query()->insertGetId(siteRow($this->org->id, 'direct') + [
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(RuntimeException::class, 'never set that value');

    expect(DB::table('sites')->where('handle', 'direct')->exists())->toBeFalse();
});

it('refuses insertOrIgnoreReturning, which performInsert never uses', function (): void {
    // Forwarded straight through before, and named in `AuditedBuilder`'s insertion surface — so its
    // absence here was an enumeration gap rather than a judgement.
    expect(fn () => Site::query()->insertOrIgnoreReturning(siteRow($this->org->id, 'returning') + [
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(RuntimeException::class, 'cannot be created in bulk');
});

it('lets a guarded column through when the model behind the query set it', function (): void {
    /*
     * ⚠️ THE OTHER HALF, and the reason the guard compares rather than refuses outright: `EntryType`
     * and `Field` guard columns that a create legitimately NAMES — `org_id`, `entry_type_id` — where
     * `Site`'s are derived. A blanket refusal of any insert naming a guarded column would have broken
     * two models' creates, which is the shape of the mistake this whole issue is about.
     */
    $type = EntryType::create([
        'org_id' => $this->org->id, 'handle' => 'named', 'name' => 'Named', 'plural_name' => 'Nameds',
    ]);

    expect($type->exists)->toBeTrue()
        ->and($type->org_id)->toBe($this->org->id);
});

it('still allows an ordinary create, which the reverted attempt did not', function (): void {
    /*
     * ⚠️ THE REGRESSION THAT SENT THIS BACK TO THE ISSUE TRACKER. Guarding the insert family by
     * `$model->exists` refused `Site::create()` as well, because `exists` is false during an insert.
     * This test is the one that would have caught it.
     */
    $site = Site::create([
        'org_id' => $this->org->id, 'handle' => 'model', 'slug' => 'model', 'name' => 'Model',
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://model.test',
    ]);

    expect($site->canonical_host)->toBe('model.test')
        ->and($site->path_prefix)->toBe('');
});

it('names the columns and the reason, so an importer can act on the refusal', function (): void {
    // A refusal an importer cannot act on is a wall rather than a guard.
    $message = '';

    try {
        Site::query()->insert(siteRow($this->org->id, 'why'));
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }

    foreach (array_keys(Site::columnsRequiringModelSave()) as $column) {
        expect($message)->toContain("[{$column}]");
    }

    expect($message)->toContain('Save the model instead.');
});

it('leaves a model with no per-row columns alone', function (): void {
    /*
     * ⚠️ THE GUARD IS OPT-IN, so a scoped model that declares nothing keeps its fast path. `Org` is
     * not a `RequiresModelSave`, and bulk-creating one is a legitimate thing a provisioning script
     * might do.
     */
    expect(new Org)->not->toBeInstanceOf(RequiresModelSave::class);

    Org::query()->insert([
        'name' => 'Bulk Org', 'slug' => 'bulk-org', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(DB::table('orgs')->where('slug', 'bulk-org')->exists())->toBeTrue();
});

it('is only safe while every guarded model increments', function (): void {
    /*
     * ⚠️ THE ASSUMPTION THAT MAKES THE METHOD A DISCRIMINATOR, asserted rather than trusted. A
     * non-incrementing model's `performInsert()` uses `insert()`, so refusing it there would break
     * creates exactly as the reverted attempt did — which is why `insert()` is guarded only when the
     * model increments.
     *
     * No `RequiresModelSave` model is non-incrementing today. The day one appears, this fails and
     * says why, rather than its creates failing and leaving somebody to work out the reason.
     */
    /*
     * ⚠️ FOUR MODELS, NOT ONE, and the first version of this test listed only `Site` because that is
     * the model the issue named. `Entry`, `EntryType` and `Field` declare per-row columns too, so the
     * guard reaches all of them — and the assertion below is what caught the short list.
     */
    $guarded = [Entry::class, EntryType::class, Field::class, Site::class];

    foreach ($guarded as $class) {
        $model = new $class;

        expect($model)->toBeInstanceOf(RequiresModelSave::class)
            ->and($model->getIncrementing())->toBeTrue(
                "[{$class}] does not increment, so insert() is its create path and cannot be refused",
            );
    }

    /*
     * ⚠️ AND THE LIST ABOVE MUST BE COMPLETE, or this asserts about a subset. Every model in the
     * package is checked, so a new `RequiresModelSave` model cannot be added without being listed.
     */
    $found = [];

    foreach (glob(__DIR__.'/../../../packages/core/src/Models/*.php') ?: [] as $file) {
        $class = 'Kitsune\\Core\\Models\\'.basename($file, '.php');

        if (! class_exists($class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        if (is_subclass_of($class, RequiresModelSave::class)) {
            $found[] = $class;
        }
    }

    sort($found);
    sort($guarded);

    expect($found)->toBe($guarded, 'a RequiresModelSave model is not covered by this test');
});
