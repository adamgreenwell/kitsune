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
 * ⚠️ `entries.org_id` AND `entries.entry_type_id` WERE INDEPENDENT, and nothing asked whether they agreed.
 *
 * Every scope guard in this layer asks about a row's OWN scope keys. None asked whether the entry type those
 * keys sit beside belongs to the same org — so an entry could be created in org B's site carrying org A's
 * type, and pass. The skeleton seeder did exactly that at the line this suite's sibling fix corrects: the
 * project demonstrating the gap in its own fixtures, which is how it stayed invisible.
 *
 * The resulting row is a dead letter rather than a leak — `IdentifyEntryType` 404s it and
 * `EntryType::visibleFor()` hides the type from the other org — and that is the reason it broke no test. But
 * its values land under another org's field definitions in a shared storage row, which is the pairing
 * ADR-009 says this layer must refuse rather than merely render harmless.
 */

beforeEach(function (): void {
    $this->alpha = Org::create(['slug' => 'alpha', 'name' => 'Alpha']);
    $this->beta = Org::create(['slug' => 'beta', 'name' => 'Beta']);

    /* Context first, then the row — `EnforcesScope` refuses a named scope key nobody has vouched for. */
    app(Context::class)->setOrg($this->alpha);
    $this->alphaSite = Site::create(['handle' => 'alpha-main', 'slug' => 'alpha-main', 'name' => 'Alpha Main', 'locale' => 'en']);
    /*
     * ⚠️ `org_id` IS PASSED EXPLICITLY, because `EntryType` is `#[Unscoped]` — it takes no scope key from
     * the context the way `Site` and `Entry` do. A type created without one is GLOBAL, owned by nobody,
     * which is the `image`/`person` pattern rather than an org-owned type. Omitting it here made every
     * fixture global and the guard correctly waved them all through.
     */
    $this->alphaType = EntryType::create(['org_id' => $this->alpha->getKey(), 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);

    app(Context::class)->setOrg($this->beta);
    $this->betaSite = Site::create(['handle' => 'beta-main', 'slug' => 'beta-main', 'name' => 'Beta Main', 'locale' => 'en']);
    $this->betaType = EntryType::create(['org_id' => $this->beta->getKey(), 'handle' => 'confidential', 'name' => 'Confidential', 'plural_name' => 'Confidential']);

    /* The pattern `kitsune/person` and the seeded `image` type use: owned by nobody, offered to everyone. */
    $this->globalType = EntryType::create(['org_id' => null, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images']);

    app(Context::class)->forget();
});

it('refuses an entry typed by another org\'s entry type', function (): void {
    app(Context::class)->setSite($this->betaSite);

    expect(fn () => Entry::create([
        'entry_type_id' => $this->alphaType->getKey(),
        'title' => 'Should never exist',
        'slug' => 'cross-org',
        'status' => 'draft',
    ]))->toThrow(RuntimeException::class, 'belongs to org');

    expect(DB::table('entries')->count())->toBe(0);
});

it('permits an entry typed by its own org\'s type', function (): void {
    app(Context::class)->setSite($this->betaSite);

    Entry::create([
        'entry_type_id' => $this->betaType->getKey(),
        'title' => 'Fine',
        'slug' => 'fine',
        'status' => 'draft',
    ]);

    expect(DB::table('entries')->count())->toBe(1);
});

/** A global type belongs to no org, so it is available to every one of them — the guard must not break that. */
it('permits an entry typed by a global type in any org', function (): void {
    app(Context::class)->setSite($this->betaSite);

    Entry::create([
        'entry_type_id' => $this->globalType->getKey(),
        'title' => 'A picture',
        'slug' => 'a-picture',
        'status' => 'draft',
    ]);

    app(Context::class)->setSite($this->alphaSite);

    Entry::create([
        'entry_type_id' => $this->globalType->getKey(),
        'title' => 'Another picture',
        'slug' => 'another-picture',
        'status' => 'draft',
    ]);

    expect(DB::table('entries')->count())->toBe(2);
});

/**
 * ⚠️ RETYPING IS THE OTHER DOOR. The guard has to run on update as well, or a legitimate entry can be moved
 * onto another org's type afterwards — which is the same end state reached one save later.
 */
it('refuses retyping an existing entry onto another org\'s type', function (): void {
    app(Context::class)->setSite($this->betaSite);

    $entry = Entry::create([
        'entry_type_id' => $this->betaType->getKey(),
        'title' => 'Fine for now',
        'slug' => 'fine-for-now',
        'status' => 'draft',
    ]);

    expect(fn () => $entry->update(['entry_type_id' => $this->alphaType->getKey()]))
        ->toThrow(RuntimeException::class, 'belongs to org');

    expect(DB::table('entries')->where('id', $entry->getKey())->value('entry_type_id'))
        ->toBe($this->betaType->getKey());
});

/*
 * ⚠️ THE QUIET PATHS, WHICH THE FIRST VERSION OF THIS GUARD MISSED ENTIRELY.
 *
 * It was registered on `creating` and `updating`. `saveQuietly()`, `createQuietly()` and `withoutEvents()`
 * install a NullDispatcher, so neither listener ran while the write still arrived through
 * `AuditedBuilder::insertGetId()` and `::update()` — and worse than merely landing, `type_handle` is
 * restamped from the foreign type on that path, so the row answers this org's `ofType()` reads while
 * pointing at another org's schema. The check now lives in `convertFieldValuesForWrite()`, which every one of
 * these paths goes through.
 *
 * These are the cases that prove the guard is on the write and not on the event.
 */

it('refuses a quiet create', function (): void {
    app(Context::class)->setSite($this->betaSite);

    expect(fn () => Entry::createQuietly([
        'org_id' => $this->beta->getKey(),
        'site_id' => $this->betaSite->getKey(),
        'entry_type_id' => $this->alphaType->getKey(),
        'title' => 'Quiet',
        'slug' => 'quiet',
        'status' => 'draft',
    ]))->toThrow(RuntimeException::class, 'belongs to org');

    expect(DB::table('entries')->count())->toBe(0);
});

it('refuses a quiet retype', function (): void {
    app(Context::class)->setSite($this->betaSite);

    $entry = Entry::create([
        'entry_type_id' => $this->betaType->getKey(),
        'title' => 'Fine for now',
        'slug' => 'quiet-retype',
        'status' => 'draft',
    ]);

    $entry->entry_type_id = $this->alphaType->getKey();

    expect(fn () => $entry->saveQuietly())->toThrow(RuntimeException::class, 'belongs to org');

    expect(DB::table('entries')->where('id', $entry->getKey())->value('entry_type_id'))
        ->toBe($this->betaType->getKey());
});

it('refuses a create inside withoutEvents', function (): void {
    app(Context::class)->setSite($this->betaSite);

    expect(fn () => Entry::withoutEvents(fn () => Entry::create([
        'org_id' => $this->beta->getKey(),
        'site_id' => $this->betaSite->getKey(),
        'entry_type_id' => $this->alphaType->getKey(),
        'title' => 'Silent',
        'slug' => 'silent',
        'status' => 'draft',
    ])))->toThrow(RuntimeException::class, 'belongs to org');

    expect(DB::table('entries')->count())->toBe(0);
});

/**
 * ⚠️ AND THE BULK AND ARITHMETIC DOORS, which convert nothing.
 *
 * `increment('id', 0, $extra)` is this project's own demonstrated attack: `entries` has no ordinary numeric
 * column, so the arithmetic is a no-op and the `$extra` assignment is the whole point. `ScopedBuilder`
 * records the same shape landing `values` and a scope key past refusals that only `update()` ran.
 */
it('refuses a bulk retype, naming the column rather than its derived handle', function (): void {
    app(Context::class)->setSite($this->betaSite);

    $entry = Entry::create([
        'entry_type_id' => $this->betaType->getKey(),
        'title' => 'Bulk',
        'slug' => 'bulk',
        'status' => 'draft',
    ]);

    expect(fn () => Entry::query()->whereKey($entry->getKey())
        ->update(['entry_type_id' => $this->alphaType->getKey()]))
        /*
         * ⚠️ THE BRACKETED COLUMN, not the bare word. Before `entry_type_id` was listed, this write was
         * already refused — but incidentally, naming `[type_handle]`, whose reason text happens to contain
         * the string "entry_type_id" ("it is derived from entry_type_id"). So `toThrow(..., 'entry_type_id')`
         * passed with the fix reverted. Asserting which refusal fired is the whole discipline here.
         */
        ->toThrow(RuntimeException::class, '[entry_type_id] cannot be written in bulk');

    expect(DB::table('entries')->where('id', $entry->getKey())->value('entry_type_id'))
        ->toBe($this->betaType->getKey());
});

it('refuses an arithmetic $extra that retypes', function (): void {
    app(Context::class)->setSite($this->betaSite);

    $entry = Entry::create([
        'entry_type_id' => $this->betaType->getKey(),
        'title' => 'Arithmetic',
        'slug' => 'arithmetic',
        'status' => 'draft',
    ]);

    expect(fn () => Entry::query()->whereKey($entry->getKey())
        ->increment('id', 0, ['entry_type_id' => $this->alphaType->getKey()]))
        ->toThrow(RuntimeException::class, '[entry_type_id] cannot be written in bulk');

    expect(DB::table('entries')->where('id', $entry->getKey())->value('entry_type_id'))
        ->toBe($this->betaType->getKey());
});

/**
 * ⚠️ AND IT DOES NOT STAND DOWN FOR THE SCOPE HATCH, which is a deliberate divergence from every
 * neighbouring refusal in this layer and is therefore asserted rather than left to be discovered.
 *
 * `withoutScopeBecause()` suspends scope-key enforcement. Which org owns a type is not a scope question —
 * it is an integrity question about two columns of one row — so the refusal holds inside the hatch, on the
 * rule `ResolvesWrittenColumns` already states for the same reason.
 */
it('holds inside withoutScopeBecause, unlike the scope-key guards', function (): void {
    expect(fn () => Entry::withoutScopeBecause('test: reaching past the scope on purpose', fn () => Entry::create([
        'org_id' => $this->beta->getKey(),
        'site_id' => null,
        'entry_type_id' => $this->alphaType->getKey(),
        'title' => 'Hatched',
        'slug' => 'hatched',
        'status' => 'draft',
    ])))->toThrow(RuntimeException::class, 'belongs to org');

    expect(DB::table('entries')->count())->toBe(0);
});

/**
 * ⚠️ THE TYPE SIDE OF THE SAME INVARIANT, which the entry-side guard cannot reach.
 *
 * Every entry write refuses a type another org owns. Moving the TYPE arrives at the identical forbidden
 * pairing with no entry written at all — and `EntryType::guardOrgMove()` only asked whether the type's field
 * storage would follow it. The live case is `kitsune/person`: a global type with global storage, so that
 * check finds nothing, while every org's people are typed by it.
 */
it('refuses giving a global type an owner while other orgs hold entries of it', function (): void {
    app(Context::class)->setSite($this->betaSite);

    Entry::create([
        'entry_type_id' => $this->globalType->getKey(),
        'title' => 'Beta picture',
        'slug' => 'beta-picture',
        'status' => 'draft',
    ]);

    app(Context::class)->forget();

    expect(fn () => $this->globalType->update(['org_id' => $this->alpha->getKey()]))
        ->toThrow(RuntimeException::class, 'still carry it');

    expect(DB::table('entry_types')->where('id', $this->globalType->getKey())->value('org_id'))->toBeNull();
});

/** The move is legitimate when only the destination org's own entries carry the type. */
it('permits the move when every entry of the type is already in the destination org', function (): void {
    app(Context::class)->setSite($this->alphaSite);

    Entry::create([
        'entry_type_id' => $this->globalType->getKey(),
        'title' => 'Alpha picture',
        'slug' => 'alpha-picture',
        'status' => 'draft',
    ]);

    app(Context::class)->forget();

    $this->globalType->update(['org_id' => $this->alpha->getKey()]);

    expect(DB::table('entry_types')->where('id', $this->globalType->getKey())->value('org_id'))
        ->toBe($this->alpha->getKey());
});
