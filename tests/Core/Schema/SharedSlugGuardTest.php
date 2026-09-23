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

/*
 * An org-shared entry has no slug — ADR-021's rule, made a guard by ADR-042 decision 2.
 *
 * ⚠️ EVERY REFUSAL IS ASSERTED BY ITS OWN WORDS AND AN UNCHANGED ROW. Several guards stand on `site_id` and `slug`
 * writes — the scope-key guard, the per-row column list, `scopedUnique` — and a test that asserted only "it threw"
 * would pass on whichever answered first. The escape hatch is exercised because the per-row list stands down there,
 * and this guard must not.
 */

beforeEach(function (): void {
    $this->org = Org::create(['slug' => 'slugs', 'name' => 'Slugs']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);

    $this->type = EntryType::create([
        'org_id' => $this->org->getKey(), 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles',
    ]);
});

afterEach(fn () => app(Context::class)->forget());

const SHARED_SLUG = 'Refusing to give an org-shared entry a slug';

function sharedEntry(array $attributes = []): Entry
{
    return Entry::create([
        'entry_type_id' => test()->type->getKey(), 'site_id' => null, 'title' => 'Shared', ...$attributes,
    ]);
}

function storedSlugAndSite(Entry $entry): array
{
    $row = DB::table('entries')->where('id', $entry->getKey())->first(['slug', 'site_id']);

    return [$row->slug, $row->site_id === null ? null : (int) $row->site_id];
}

describe('creating', function (): void {
    it('refuses a shared entry with a slug', function (): void {
        expect(fn () => sharedEntry(['slug' => 'shared-thing']))->toThrow(RuntimeException::class, SHARED_SLUG.' [shared-thing]');

        expect(DB::table('entries')->count())->toBe(0);
    });

    /** A quiet create skips the site stamp as well as every listener: absent is NULL, and the builder decides. */
    it('refuses it through a quiet create, where an absent site is a shared one', function (): void {
        expect(fn () => Entry::createQuietly([
            'entry_type_id' => $this->type->getKey(), 'org_id' => $this->org->getKey(), 'type_handle' => 'article',
            'title' => 'Quiet', 'slug' => 'quiet',
        ]))->toThrow(RuntimeException::class, SHARED_SLUG);

        expect(DB::table('entries')->count())->toBe(0);
    });

    /** `''` is a value, not an absence — the column holds it as NOT NULL. */
    it('refuses an empty-string slug on a shared entry', function (): void {
        expect(fn () => sharedEntry(['slug' => '']))->toThrow(RuntimeException::class, SHARED_SLUG);
    });

    /** The controls. */
    it('creates a shared entry with no slug, and a site entry with one', function (): void {
        $shared = sharedEntry();
        $kept = Entry::create(['entry_type_id' => $this->type->getKey(), 'title' => 'Kept', 'slug' => 'kept']);

        expect(storedSlugAndSite($shared))->toBe([null, null])
            ->and(storedSlugAndSite($kept))->toBe(['kept', $this->site->getKey()]);
    });
});

describe('saving', function (): void {
    /**
     * An org-defined slug-typed field is this same write: `SlugType` promotes to `entries.slug`, and the form's control
     * for it has that column as its state path, so refusing the column refuses the field.
     */
    it('refuses a slug given to a shared entry, through the instance and a quiet save', function (): void {
        $entry = sharedEntry();

        foreach (['save', 'saveQuietly'] as $save) {
            $entry->slug = 'late';

            expect(fn () => $entry->{$save}())->toThrow(RuntimeException::class, SHARED_SLUG.' [late]');

            expect(storedSlugAndSite($entry))->toBe([null, null]);
            $entry->refresh();
        }
    });

    it('refuses sharing an entry that still has its slug', function (): void {
        $entry = Entry::create(['entry_type_id' => $this->type->getKey(), 'title' => 'Kept', 'slug' => 'kept']);

        $entry->site_id = null;

        expect(fn () => $entry->save())->toThrow(RuntimeException::class, SHARED_SLUG);

        expect(storedSlugAndSite($entry))->toBe(['kept', $this->site->getKey()]);
    });

    /** The controls: a shared entry's other columns, and its soft delete and restore, write as they always did. */
    it('still saves a shared entry\'s title, and deletes and restores it', function (): void {
        $entry = sharedEntry();

        $entry->update(['title' => 'Renamed']);
        $entry->delete();
        Entry::withTrashed()->whereKey($entry->getKey())->restore();

        expect(DB::table('entries')->where('id', $entry->getKey())->value('title'))->toBe('Renamed')
            ->and(DB::table('entries')->where('id', $entry->getKey())->value('deleted_at'))->toBeNull();
    });
});

/*
 * ⚠️ INSIDE THE ESCAPE HATCH, where the per-row column list and the scope-key guard both stand down. What the hatch
 * decides is which path may write a column, not what it may hold.
 */
describe('inside withoutScopeBecause()', function (): void {
    it('refuses a bulk slug onto shared rows, under every spelling the engine folds', function (): void {
        $entry = sharedEntry();

        foreach (['slug', 'SLUG', 'entries.slug'] as $column) {
            expect(fn () => Entry::withoutScopeBecause('a test of the shared-slug rule', fn ($query) => $query
                ->whereKey($entry->getKey())->update([$column => 'bulk'])))
                ->toThrow(RuntimeException::class, SHARED_SLUG);
        }

        expect(storedSlugAndSite($entry))->toBe([null, null]);
    });

    it('refuses sharing slugged rows in bulk, by value and by raw expression', function (): void {
        $entry = Entry::create(['entry_type_id' => $this->type->getKey(), 'title' => 'Kept', 'slug' => 'kept']);

        foreach ([null, DB::raw('NULL')] as $site) {
            foreach (['site_id', 'SITE_ID'] as $column) {
                expect(fn () => Entry::withoutScopeBecause('a test of the shared-slug rule', fn ($query) => $query
                    ->whereKey($entry->getKey())->update([$column => $site])))
                    ->toThrow(RuntimeException::class, SHARED_SLUG);
            }
        }

        expect(storedSlugAndSite($entry))->toBe(['kept', $this->site->getKey()]);
    });

    /** Every arithmetic door takes an `$extra` of plain assignments, and each is its own call site. */
    it('refuses a slug carried in any arithmetic write\'s extra columns', function (): void {
        $entry = sharedEntry();

        $doors = [
            'increment' => fn ($query) => $query->increment('id', 0, ['slug' => 'counted']),
            'decrement' => fn ($query) => $query->decrement('id', 0, ['slug' => 'counted']),
            'incrementEach' => fn ($query) => $query->incrementEach(['id' => 0], ['slug' => 'counted']),
            'decrementEach' => fn ($query) => $query->decrementEach(['id' => 0], ['slug' => 'counted']),
        ];

        foreach ($doors as $door => $write) {
            expect(fn () => Entry::withoutScopeBecause('a test of the shared-slug rule', fn ($query) => $write($query->whereKey($entry->getKey()))))
                ->toThrow(RuntimeException::class, SHARED_SLUG);

            expect(storedSlugAndSite($entry))->toBe([null, null], "a slug landed through {$door}");
        }
    });

    /** Both columns in one write: the values decide, and a shared row with a slug is what they would make. */
    it('refuses a write that shares a row and gives it a slug at once', function (): void {
        $entry = Entry::create(['entry_type_id' => $this->type->getKey(), 'title' => 'Kept', 'slug' => 'kept']);

        expect(fn () => Entry::withoutScopeBecause('a test of the shared-slug rule', fn ($query) => $query
            ->whereKey($entry->getKey())->update(['site_id' => null, 'slug' => 'still-addressable'])))
            ->toThrow(RuntimeException::class, SHARED_SLUG.' [still-addressable]');

        expect(storedSlugAndSite($entry))->toBe(['kept', $this->site->getKey()]);
    });

    /**
     * ⚠️ A JSON PATH INTO EITHER COLUMN, which review found passing: its value is not what it stores. On SQLite
     * `update(['slug->x' => null])` leaves the text `'{}'` in `slug` — a non-null slug on a shared row.
     */
    it('refuses a JSON path into site_id or slug, whatever value it carries', function (): void {
        $shared = sharedEntry();
        $kept = Entry::create(['entry_type_id' => $this->type->getKey(), 'title' => 'Kept', 'slug' => 'kept']);

        foreach ([[$shared, ['slug->x' => null]], [$kept, ['site_id->k' => 1, 'slug' => 'addressable']]] as [$entry, $values]) {
            expect(fn () => Entry::withoutScopeBecause('a test of the shared-slug rule', fn ($query) => $query
                ->whereKey($entry->getKey())->update($values)))
                ->toThrow(RuntimeException::class, 'hold no JSON');
        }

        /*
         * Through a model a path is no path: Eloquent fills the whole column with `{"x":"y"}` before any query exists,
         * so an insert never carries one, and on a shared row it is refused as the slug it has become.
         */
        expect(fn () => Entry::createQuietly([
            'entry_type_id' => $this->type->getKey(), 'org_id' => $this->org->getKey(), 'type_handle' => 'article',
            'site_id' => null, 'title' => 'Path', 'slug->x' => 'y',
        ]))->toThrow(RuntimeException::class, SHARED_SLUG);

        expect(storedSlugAndSite($shared))->toBe([null, null])
            ->and(storedSlugAndSite($kept))->toBe(['kept', $this->site->getKey()]);
    });

    /** The control: sharing rows and clearing their slug in one write is exactly what the rule asks for. */
    it('shares rows in bulk when the same write clears their slug', function (): void {
        $entry = Entry::create(['entry_type_id' => $this->type->getKey(), 'title' => 'Kept', 'slug' => 'kept']);

        Entry::withoutScopeBecause('a test of the shared-slug rule', fn ($query) => $query
            ->whereKey($entry->getKey())->update(['site_id' => null, 'slug' => null]));

        expect(storedSlugAndSite($entry))->toBe([null, null]);
    });
});
