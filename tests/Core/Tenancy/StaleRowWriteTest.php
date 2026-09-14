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
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/**
 * An instance may not write over a row that moved or was retyped since it was loaded — ADR-021, ADR-033.
 *
 * ⚠️ THIS CLOSES A WINDOW NO POLICY CAN CLOSE ALONE, which review established: `Gate` answers before the write
 * begins, in another transaction, so a row retyped or moved in between was authorised by the answer for what
 * it used to be. `EntryPolicy` reads the stored row, so its answer is right when it is asked; the window is
 * between that answer and the write.
 *
 * ⚠️ AND THE CONSEQUENCE WAS MEASURED BEFORE THE FIX WAS CHOSEN, because it is narrower than it sounds. A
 * stale instance saving an unrelated field writes only that field —
 * `update "entries" set "title" = ?, "updated_at" = ? where "id" = ?` — so the denormalised `type_handle`
 * keeps whatever the other transaction set and nothing drifts. What is left is one edit, or one DELETION, by
 * somebody authorised for that row a moment earlier. The deletion is why this is a guard rather than a
 * documented limitation.
 */
beforeEach(function (): void {
    $this->org = Org::create(['slug' => 'stale', 'name' => 'Stale']);
    app(Context::class)->setOrg($this->org);

    $this->home = Site::create(['org_id' => $this->org->getKey(), 'handle' => 'home', 'slug' => 'home', 'name' => 'Home']);
    app(Context::class)->setSite($this->home);

    $this->article = EntryType::create([
        'org_id' => $this->org->getKey(), 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles',
    ]);
    $this->product = EntryType::create([
        'org_id' => $this->org->getKey(), 'handle' => 'product', 'name' => 'Product', 'plural_name' => 'Products',
    ]);

    $this->entry = Entry::create(['entry_type_id' => $this->article->getKey(), 'title' => 'Loaded as an article']);
});

afterEach(fn () => app(Context::class)->forget());

/** Change the stored row the way another transaction would: without going through this instance. */
function retypeStoredRow(Entry $entry, EntryType $type): void
{
    DB::table('entries')->where('id', $entry->getKey())->update([
        'entry_type_id' => $type->getKey(),
        'type_handle' => $type->handle,
    ]);
}

it('refuses an update when the stored row has been retyped underneath it', function (): void {
    /** @var Entry $stale */
    $stale = Entry::query()->whereKey($this->entry->getKey())->firstOrFail();

    retypeStoredRow($this->entry, $this->product);

    $stale->title = 'Edited by somebody authorised for articles';

    expect(fn () => $stale->save())
        ->toThrow(RuntimeException::class, 'somebody else moved or retyped it while this instance was in hand');

    // The edit did not land, and the other transaction's retype is untouched.
    expect(DB::table('entries')->where('id', $this->entry->getKey())->first(['title', 'type_handle']))
        ->title->toBe('Loaded as an article')
        ->type_handle->toBe('product');
});

it('refuses a force-delete of a row that moved to another site', function (): void {
    /*
     * The destructive half, and the reason this is a guard: an update is a field somebody may not have been
     * allowed to touch; this is a row that is gone.
     */
    $sibling = Site::create(['org_id' => $this->org->getKey(), 'handle' => 'sib', 'slug' => 'sib', 'name' => 'Sib']);

    /** @var Entry $stale */
    $stale = Entry::query()->whereKey($this->entry->getKey())->firstOrFail();

    DB::table('entries')->where('id', $this->entry->getKey())->update(['site_id' => $sibling->getKey()]);

    expect(fn () => $stale->forceDelete())
        ->toThrow(RuntimeException::class, 'somebody else moved or retyped it while this instance was in hand');

    expect(DB::table('entries')->where('id', $this->entry->getKey())->exists())->toBeTrue();
});

it('refuses a soft delete the same way, because it routes through the update path', function (): void {
    /** @var Entry $stale */
    $stale = Entry::query()->whereKey($this->entry->getKey())->firstOrFail();

    retypeStoredRow($this->entry, $this->product);

    expect(fn () => $stale->delete())
        ->toThrow(RuntimeException::class, 'somebody else moved or retyped it while this instance was in hand');

    expect(DB::table('entries')->where('id', $this->entry->getKey())->whereNull('deleted_at')->exists())->toBeTrue();
});

it('still lets an instance retype or move its own entry', function (): void {
    /*
     * ⚠️ THE GUARD COMPARES WHAT WAS LOADED, NOT WHAT IS BEING WRITTEN, and this is the half that would be
     * broken by getting that backwards: making the change YOURSELF makes the column dirty, and the comparison
     * is against the database rather than against the pending value. A guard that refused this would refuse
     * every legitimate retype.
     */
    $this->entry->entry_type_id = $this->product->getKey();
    $this->entry->save();

    expect(DB::table('entries')->where('id', $this->entry->getKey())->value('type_handle'))->toBe('product');

    // And an ordinary edit of an untouched row is not refused either.
    $this->entry->title = 'Renamed with nobody else involved';
    $this->entry->save();

    expect(DB::table('entries')->where('id', $this->entry->getKey())->value('title'))
        ->toBe('Renamed with nobody else involved');
});

it('leaves a bulk update to the scope, which is what narrows it', function (): void {
    /*
     * A bulk update is not aimed at one row, so there is no "the row it was loaded from" to compare — the
     * global scope is what keeps it inside this site. Refusing it here would be a refusal of the METHOD rather
     * than of the staleness, which is the distinction every guard in this layer has had to make.
     */
    retypeStoredRow($this->entry, $this->product);

    Entry::query()->whereKey($this->entry->getKey())->update(['title' => 'Renamed in bulk']);

    expect(DB::table('entries')->where('id', $this->entry->getKey())->value('title'))->toBe('Renamed in bulk');
});

it('refuses instance arithmetic over a row that moved', function (): void {
    /*
     * ⚠️ THE GUARD WENT INTO TWO DOORS AND THERE WERE SIX — review found the four arithmetic ones. Eloquent
     * sends `$entry->increment()` to `setKeysForSaveQuery($this->newQueryWithoutScopes())->increment()`: an
     * instance write, by the ORIGINAL key, with the scope removed, that never passes through `update()`.
     * So the one check standing between a stale instance and the row it no longer holds was not asked.
     */
    DB::table('entries')->where('id', $this->entry->getKey())->update(['origin_id' => 5]);

    /** @var Entry $stale */
    $stale = Entry::query()->whereKey($this->entry->getKey())->firstOrFail();

    retypeStoredRow($this->entry, $this->product);

    expect(fn () => $stale->increment('origin_id'))
        ->toThrow(RuntimeException::class, 'somebody else moved or retyped it while this instance was in hand')
        ->and(fn () => $stale->decrement('origin_id'))
        ->toThrow(RuntimeException::class, 'somebody else moved or retyped it while this instance was in hand')
        ->and(fn () => $stale->incrementEach(['origin_id' => 1]))
        ->toThrow(RuntimeException::class, 'somebody else moved or retyped it while this instance was in hand')
        ->and(fn () => $stale->decrementEach(['origin_id' => 1]))
        ->toThrow(RuntimeException::class, 'somebody else moved or retyped it while this instance was in hand');

    // Nothing moved, which is the half that says the refusals were refusals and not four exceptions.
    expect((int) DB::table('entries')->where('id', $this->entry->getKey())->value('origin_id'))->toBe(5);
});

it('asks about the row the write will land on, not the key attribute', function (): void {
    /*
     * ⚠️ `getKey()` DECIDED WHETHER TO CHECK, AND THE WRITE USES `getKeyForSaveQuery()` — review found the
     * gap, and it is the third time this project has met it: Eloquent writes and deletes by
     * `$this->original['id'] ?? $this->getKey()`, so nulling the attribute in memory left the guard's
     * condition false while the delete still targeted the row it was loaded from. The instance that had been
     * tampered with was the one instance the guard skipped.
     *
     * A force-delete is the shape that matters: it carries no payload, so nothing else objects to a null key.
     */
    /** @var Entry $stale */
    $stale = Entry::query()->whereKey($this->entry->getKey())->firstOrFail();

    retypeStoredRow($this->entry, $this->product);

    $stale->id = null;

    expect(fn () => $stale->forceDelete())
        ->toThrow(RuntimeException::class, 'somebody else moved or retyped it while this instance was in hand');

    // The row the write would have destroyed is still there.
    expect(DB::table('entries')->where('id', $this->entry->getKey())->exists())->toBeTrue();
});
