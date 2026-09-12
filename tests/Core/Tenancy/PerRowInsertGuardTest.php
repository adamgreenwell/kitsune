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
use Kitsune\Core\Models\SiteGroup;
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

it('rests on a discriminator no caller can arrange', function (): void {
    /*
     * ⚠️ THIS TEST USED TO PIN "every guarded model increments", and that assumption is gone because it
     * rested on `getIncrementing()` — which review showed a caller can change:
     * `Site::query()->getModel()->setIncrementing(false)` then `insert()` was classified as a
     * non-incrementing model save although no model event ran, and landed an overlapping cross-org
     * claim. The key strategy was never the question; *"is this a model save"* was.
     *
     * So what is pinned now is the invariant the guards actually rest on: a model NOT inside its own
     * `performInsert()`/`performUpdate()` says so. Every instance a caller can reach — including one
     * handed to `setModel()` — is such a model, which is why the flag cannot be manufactured. There is
     * no setter for it either, and `guardedColumnsAreDerived()`'s mutator is already asserted
     * unreachable in its own test.
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
            ->and($model->isPerformingModelSave($model->newQuery()))->toBeFalse(
                "[{$class}] claims to be saved through a builder before any save has started",
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

describe('a quiet write is not a guarded write', function (): void {
    /*
     * ⚠️ THE DISCRIMINATOR WAS "THE ATTRIBUTE IS PRESENT ON THE MODEL", and review showed that proves
     * nothing. `createQuietly()`, `saveQuietly()`, `updateQuietly()` and anything inside
     * `withoutEvents()` populate a model's attributes while suppressing the `saving` callback that
     * derives and validates them — so the write reaching the builder looked exactly like a genuine
     * save. Measured on this branch before the fix, all three:
     *
     *   Site::query()->createQuietly([... 'base_url' => 'https://quiet.test/news'])
     *     -> canonical_host NULL, path_prefix NULL      a site declaring a URL and reachable at none
     *
     *   Site::query()->createQuietly([... 'base_url' => 'https://steal.test/news',
     *                                     'canonical_host' => 'steal.test', 'path_prefix' => '/news'])
     *     -> WROTE `steal.test/news` under a rival org while another held `steal.test/`
     *
     *   $site->base_url = 'https://after.test'; $site->saveQuietly()
     *     -> base_url after.test, canonical_host still before.test
     *
     * The second is the cross-org URL theft ADR-021 says has no framework safety net, reached through
     * the front door. `field-types.md` §6 states the principle this violates in as many words: *"a
     * guard has to sit where the write is, and an event is not where the write is."*
     */
    it('refuses a quiet create that leaves a derived column unwritten', function (): void {
        expect(fn () => Site::query()->createQuietly([
            'org_id' => $this->org->id, 'handle' => 'q1', 'slug' => 'q1', 'name' => 'Q1',
            'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://quiet.test/news',
        ]))->toThrow(RuntimeException::class, 'checks that derive and validate it did not run');

        expect(Site::withoutGlobalScopes()->where('handle', 'q1')->exists())->toBeFalse();
    });

    it('refuses a quiet create that supplies the derived columns itself', function (): void {
        /*
         * ⚠️ THE CASE PRESENCE COULD NEVER HAVE CAUGHT, and the worst of the three: every guarded
         * column IS on the model, supplied by the caller, so the old test passed and the overlap check
         * never ran. `steal.test/` is held by one org and this claims `steal.test/news` for another,
         * which the resolver's longest-prefix rule then serves from the wrong org.
         */
        Site::create([
            'org_id' => $this->org->id, 'handle' => 'owner', 'slug' => 'owner', 'name' => 'Owner',
            'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://steal.test/',
        ]);

        $rival = Org::create(['name' => 'Rival', 'slug' => 'rival-org']);

        /*
         * ⚠️ REFUSED EARLIER THAN IT USED TO BE, and the message assertion moved with it rather than
         * being loosened. `refuseDetachedScopeKeys()` now asks the Context about every scope key on a
         * declared-scope model — because the model behind the builder is evidence a caller can forge —
         * so a quiet create naming ANOTHER org is refused for the more fundamental reason before the
         * derived columns are reached. The outcome is the one that matters and is unchanged.
         */
        expect(fn () => Site::query()->createQuietly([
            'org_id' => $rival->id, 'handle' => 'thief', 'slug' => 'thief', 'name' => 'Thief',
            'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://steal.test/news',
            'canonical_host' => 'steal.test', 'path_prefix' => '/news',
        ]))->toThrow(RuntimeException::class, 'from a context scoped to');

        expect(Site::withoutGlobalScopes()->where('canonical_host', 'steal.test')->count())
            ->toBe(1, 'the quiet create landed a second claim on a host another org holds');

        /*
         * ⚠️ AND THE SAME WRITE INSIDE ITS OWN ORG, so the guard this test is named for is still the one
         * being tested. With the scope keys beyond reproach, supplying the derived columns by hand is
         * the only thing left wrong with it — `refuseOverlappingClaim()` permits one org arranging its
         * own sites, so nothing else can account for the refusal.
         */
        expect(fn () => Site::query()->createQuietly([
            'org_id' => $this->org->id, 'handle' => 'sibling', 'slug' => 'sibling', 'name' => 'Sibling',
            'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://steal.test/blog',
            'canonical_host' => 'steal.test', 'path_prefix' => '/blog',
        ]))->toThrow(RuntimeException::class, 'checks that derive and validate it did not run');

        expect(Site::withoutGlobalScopes()->where('canonical_host', 'steal.test')->count())->toBe(1);
    });

    it('refuses a quiet update that moves a derived column', function (): void {
        $site = Site::create([
            'org_id' => $this->org->id, 'handle' => 'mover', 'slug' => 'mover', 'name' => 'Mover',
            'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://before.test',
        ]);

        $site->base_url = 'https://after.test';

        expect(fn () => $site->saveQuietly())
            ->toThrow(RuntimeException::class, 'cannot be written in bulk');

        expect((string) Site::withoutGlobalScopes()->whereKey($site->getKey())->value('canonical_host'))
            ->toBe('before.test', 'the quiet update moved the site off its derived host');
    });

    it('leaves a quiet write that touches no guarded column alone', function (): void {
        /*
         * ⚠️ THE BOUND ON THE REFUSAL. A quiet save that changes only `name` derives nothing and needs
         * nothing derived, so refusing it would make the guard about events rather than about columns.
         */
        $site = Site::create([
            'org_id' => $this->org->id, 'handle' => 'renamed', 'slug' => 'renamed', 'name' => 'Before',
            'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://renamed.test',
        ]);

        $site->name = 'After';

        expect($site->saveQuietly())->toBeTrue()
            ->and((string) Site::withoutGlobalScopes()->whereKey($site->getKey())->value('name'))->toBe('After');
    });

    it('still allows a quiet ENTRY create, whose guards run at the builder', function (): void {
        /*
         * ⚠️ WHY THE FLAG SAYS "DERIVED" RATHER THAN "THE HOOK RAN". `Entry` is the one guarded model
         * whose columns are made correct where the write is: `AuditedBuilder` runs
         * `convertFieldValuesForWrite()` on both the insert and the update path, which is §6's
         * principle already applied. A flag meaning "the `saving` event fired" would have refused this
         * for no reason — and `AuditLogTest` asserts a quiet entry create in the current scope is
         * allowed, deliberately.
         */
        $type = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'page', 'name' => 'Page', 'plural_name' => 'Pages',
        ]);

        $site = Site::create([
            'org_id' => $this->org->id, 'handle' => 'entries', 'slug' => 'entries', 'name' => 'Entries',
        ]);

        app(Context::class)->setSite($site);

        $entry = Entry::query()->createQuietly([
            'org_id' => $this->org->id, 'site_id' => $site->id,
            'entry_type_id' => $type->id, 'type_handle' => 'page', 'title' => 'Quiet',
        ]);

        expect($entry->exists)->toBeTrue()->and($entry->title)->toBe('Quiet');
    });

    it('restamps a forged type handle on the quiet path too', function (): void {
        /*
         * ⚠️ WHICH THE FLAG'S CLAIM REQUIRED. `type_handle` is one of `Entry`'s guarded columns and its
         * restamp lived only in `saving`, so saying "derived" while a quiet write left a forged handle
         * intact would have been the same kind of false claim the presence check was. It is restamped
         * in `convertFieldValuesForWrite()` now, which is where the other guarded column is handled.
         */
        $type = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles',
        ]);

        $site = Site::create([
            'org_id' => $this->org->id, 'handle' => 'forged', 'slug' => 'forged', 'name' => 'Forged',
        ]);

        app(Context::class)->setSite($site);

        $entry = Entry::query()->createQuietly([
            'org_id' => $this->org->id, 'site_id' => $site->id,
            'entry_type_id' => $type->id, 'type_handle' => 'not_the_type', 'title' => 'Forged',
        ]);

        expect((string) Entry::withoutGlobalScopes()->whereKey($entry->getKey())->value('type_handle'))
            ->toBe('article', 'a quiet create kept a handle the type does not have');
    });
});

it('refuses a detached insert that names another org', function (): void {
    /*
     * ⚠️ THE SCOPE KEYS WERE UNGUARDED ON THIS PATH ALONE, and the hole is a cross-org WRITE rather
     * than a malformed column — review found it. `org_id` is not in `columnsRequiringModelSave()` and
     * does not need to be: `EnforcesScope`'s `creating` listener stamps it, and `insertGetId()`
     * dispatches nothing. So a caller in org A could name org B's id, omit every guarded column, and
     * leave `refuseDetachedInsert()` nothing to inspect. Measured before the fix: the row landed in
     * org B. `update()` and both of `AuditedBuilder`'s paths had always guarded the keys.
     */
    $theirs = Org::create(['name' => 'Theirs', 'slug' => 'theirs']);

    expect(fn () => Site::query()->insertGetId([
        'org_id' => $theirs->id, 'handle' => 'planted', 'slug' => 'planted', 'name' => 'Planted',
        'locale' => 'en', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(RuntimeException::class, 'from a context scoped to');

    expect(Site::withoutGlobalScopes()->where('handle', 'planted')->exists())->toBeFalse();
});

it('still lets a MODEL create name another org, which is a settled policy elsewhere', function (): void {
    /*
     * ⚠️ THE LIMIT IS MEASURED RATHER THAN CHOSEN. Guarding every insert refuses 37 tests across ten
     * files, because naming another org's id on a model create is a shape this codebase uses
     * deliberately — a fixture building a rival org's data, a console command seeding one. That policy
     * belongs where `EnforcesScope` runs, and the insert guard has no business relitigating it.
     *
     * The discriminator is whether a model instance is behind the builder — which is a different
     * question from the one presence answered wrongly for the guarded columns. There it was asked to
     * prove the guards RAN, which an attribute cannot do; here it answers whether these values came off
     * an instance at all.
     */
    $theirs = Org::create(['name' => 'Theirs', 'slug' => 'theirs']);

    $type = EntryType::create([
        'org_id' => $theirs->id, 'handle' => 'rival', 'name' => 'Rival', 'plural_name' => 'Rivals',
    ]);

    expect($type->exists)->toBeTrue()->and($type->org_id)->toBe($theirs->id);
});

it('restamps the type handle when either type column moves alone', function (): void {
    /*
     * ⚠️ THE CONDITION WAS `&&` AND IT WAS WRONG IN BOTH DIRECTIONS — review found it. Laravel's update
     * payload carries only the DIRTY columns, so a quiet update moving `entry_type_id` alone never
     * reached the restamp, and one forging `type_handle` alone never reached it either. The flag then
     * claimed the guarded columns were derived while the builder persisted the mismatch — and every
     * relation check and type lookup reads the handle rather than the id.
     */
    $page = EntryType::create([
        'org_id' => $this->org->id, 'handle' => 'page', 'name' => 'Page', 'plural_name' => 'Pages',
    ]);

    $article = EntryType::create([
        'org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles',
    ]);

    $site = Site::create([
        'org_id' => $this->org->id, 'handle' => 'restamp', 'slug' => 'restamp', 'name' => 'Restamp',
    ]);

    app(Context::class)->setSite($site);

    $entry = Entry::create([
        'entry_type_id' => $page->id, 'type_handle' => 'page', 'title' => 'Moving',
    ]);

    // Only the id is dirty: the handle is not in the payload at all, and must still be derived.
    $entry->entry_type_id = $article->id;
    $entry->saveQuietly();

    expect((string) Entry::withoutGlobalScopes()->whereKey($entry->getKey())->value('type_handle'))
        ->toBe('article', 'the handle was left stale when only the id moved');

    /*
     * ⚠️ AND THE INSTANCE AGREES WITH THE ROW, which review found this test could not see — and the
     * reason it could not see it is the reason the assertion is here. The restamp corrected `$values`
     * only, so the model kept reporting `page`; `finishSave()` then called `syncOriginal()` and adopted
     * that stale value as the clean original, making it indistinguishable from a saved one. Serialising
     * the returned entry, resolving its route or checking its relations all read the wrong type.
     *
     * `getOriginal()` as well as the attribute, because the attribute alone would pass while the model
     * merely held a dirty value waiting to overwrite the row on the next save.
     */
    expect($entry->type_handle)
        ->toBe('article', 'the returned model kept the stale handle after a quiet id move')
        ->and($entry->getOriginal('type_handle'))
        ->toBe('article', 'the stale handle was synced as the clean original')
        ->and($entry->toArray()['type_handle'])
        ->toBe('article', 'serialising the returned entry served the stale handle');

    /*
     * And only a forged handle is dirty: the id on the model is the truth it derives from.
     *
     * ⚠️ THIS HALF WAS VACUOUS UNTIL THE ASSERTIONS ABOVE PASSED. On a stale instance still reporting
     * `page`, assigning `page` is not a change — `saveQuietly()` issued no UPDATE at all, and the
     * expectation below was re-reading the row the previous save had written. Measured: `isDirty()`
     * returned false. It can only forge a handle now because the instance holds the derived one.
     */
    $entry->type_handle = 'page';

    expect($entry->isDirty('type_handle'))
        ->toBeTrue('the forged handle was not dirty, so this half never issued a write');

    $entry->saveQuietly();

    expect((string) Entry::withoutGlobalScopes()->whereKey($entry->getKey())->value('type_handle'))
        ->toBe('article', 'a forged handle survived when it was the only dirty column')
        ->and($entry->type_handle)
        ->toBe('article', 'the instance kept the forged handle it had just been refused');
});

it('records the derived proof only after every guard has passed', function (): void {
    /*
     * ⚠️ THE FLAG WAS ARMED IN THE FIRST LISTENER, which review found was a stale proof waiting to
     * happen. Listeners run in registration order and each guard throws rather than returning a
     * verdict, so arming early meant a save that aborted in a LATER guard left the flag set: catch the
     * exception, call `saveQuietly()` on the same instance, and the builder accepts the write on a
     * proof that no longer holds. `saved` clears the flag, and an aborted save never reaches `saved`.
     *
     * ⚠️ Each model arms in a listener OF ITS OWN, registered last, rather than at the end of the last
     * guard — which would work until the next guard is registered after it.
     */
    $type = EntryType::create([
        'org_id' => $this->org->id, 'handle' => 'aborter', 'name' => 'Aborter', 'plural_name' => 'Aborters',
    ]);

    $other = EntryType::create([
        'org_id' => $this->org->id, 'handle' => 'other', 'name' => 'Other', 'plural_name' => 'Others',
    ]);

    $storage = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'subject', 'type' => 'text', 'pii_class' => 'personal',
    ]);

    $foreign = Field::create([
        'entry_type_id' => $other->id, 'field_storage_id' => $storage->id, 'label' => 'Subject',
    ]);

    // A nomination naming another type's field: refused by a listener that runs AFTER the first.
    $type->subject_field_id = $foreign->id;

    expect(fn () => $type->save())->toThrow(RuntimeException::class);

    // ⚠️ The instance now carries the invalid value AND, before the fix, a proof that its guards ran.
    expect(fn () => $type->saveQuietly())
        ->toThrow(RuntimeException::class, 'cannot be written in bulk');

    expect(EntryType::withoutGlobalScopes()->whereKey($type->getKey())->value('subject_field_id'))
        ->toBeNull('the aborted save left a proof behind and the quiet retry used it');
});

it('refuses a detached insert from no scope at all', function (): void {
    /*
     * ⚠️ NO CONTEXT IS NOT PERMISSION, which review found. `guardScopeKeys()` accepts every value when
     * the context has none to compare against — right for an update, where the row already belongs to
     * somebody and the caller is not choosing — and wrong for a hand-rolled insert: a console command or
     * a queue job with no `Context` could name ANY org, and nothing could vouch for it either way. The
     * earlier fix satisfied the comparison; this one is the case that removes it.
     *
     * `AuditedBuilder` already treats a keyed write with no context this way for `Entry`, so this makes
     * the two agree rather than inventing a policy.
     */
    $victim = Org::create(['name' => 'Victim', 'slug' => 'victim']);

    app(Context::class)->forget();

    expect(fn () => Site::query()->insertGetId([
        'org_id' => $victim->id, 'handle' => 'nocontext', 'slug' => 'nocontext', 'name' => 'No Context',
        'locale' => 'en', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(RuntimeException::class, 'from no scope at all');

    expect(Site::withoutGlobalScopes()->where('handle', 'nocontext')->exists())->toBeFalse();
});

it('will not reuse a proof once a guarded value has changed under it', function (): void {
    /*
     * ⚠️ A BOOLEAN FLAG SAYS "SOME WRITE'S GUARDS RAN" AND CANNOT SAY WHICH, which review found two ways
     * past. `saved` clears it and an aborted save never reaches `saved` — so a `Site` update that derived
     * its URL columns and then failed in the LATER `updating` scope check left the proof standing: catch
     * that, change `base_url`, call `saveQuietly()`, and the builder accepted a write whose derived
     * columns belong to the previous value. And the mutator being public let a caller arm the proof on
     * `Site::query()`'s own model and hand-roll the insert.
     *
     * The proof is about VALUES now — these columns, derived to these values — so changing any of them
     * without deriving again invalidates it whatever became of the save that made it.
     */
    $site = Site::create([
        'org_id' => $this->org->id, 'handle' => 'proof', 'slug' => 'proof', 'name' => 'Proof',
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://proof.test',
    ]);

    $theirs = Org::create(['name' => 'Theirs', 'slug' => 'theirs']);

    // A save that derives, arms, and then aborts in a listener that runs after `saving`.
    $site->org_id = $theirs->id;
    $site->base_url = 'https://moved.test';

    expect(fn () => $site->save())->toThrow(RuntimeException::class);

    // The caller restores the org and changes the URL again, then goes quiet.
    $site->org_id = $this->org->id;
    $site->base_url = 'https://moved-again.test';

    expect(fn () => $site->saveQuietly())->toThrow(RuntimeException::class, 'cannot be written in bulk');

    expect((string) Site::withoutGlobalScopes()->whereKey($site->getKey())->value('canonical_host'))
        ->toBe('proof.test', 'a stale proof let a quiet save through');
});

it('keeps the proof mutator out of a caller\'s reach', function (): void {
    /*
     * ⚠️ REVIEW ASKED FOR THIS AND IT COSTS NOTHING. A public mutator let any caller take
     * `Site::query()`, arm the proof on the builder's own model, and then hand-roll an insert with
     * columns it authored. The models call it from closures declared inside their own `booted()`, so
     * class scope is all the visibility it ever needed.
     *
     * The value snapshot refuses a forged arming anyway — a fresh model snapshots nulls, and the insert
     * names real values — so this is the second lock on the door rather than the only one.
     */
    $method = new ReflectionMethod(Site::class, 'noteGuardedColumnsDerived');

    expect($method->isPublic())->toBeFalse('any caller can arm the proof')
        ->and((new ReflectionMethod(Site::class, 'guardedColumnsAreDerived'))->isPublic())
        ->toBeTrue('the builder has to be able to read it');
});

it('does not call two different numeric-looking strings the same value', function (): void {
    /*
     * ⚠️ `==` WAS A BYPASS, which review found. PHP considers two NUMERIC-LOOKING STRINGS loosely equal
     * when they name the same number, so `'0e1' == '0e2'` is TRUE — and a proof armed for a host of `0e1`
     * accepted a quiet retry that had changed it to `0e2`. A proof about values cannot use a comparison
     * that calls two different values the same.
     *
     * ⚠️ AND `===` ALONE WOULD BE TOO STRICT: a value arrives as an int on the model and a numeric string
     * from a form, and failing on `255` versus `'255'` would send an author looking for a bug that is not
     * there. Casting to string answers both.
     */
    $site = Site::create([
        'org_id' => $this->org->id, 'handle' => 'exponent', 'slug' => 'exponent', 'name' => 'Exponent',
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://0e1/news',
    ]);

    expect($site->canonical_host)->toBe('0e1');

    $theirs = Org::create(['name' => 'Theirs', 'slug' => 'theirs']);

    // A save that derives, arms and then aborts after `saving`.
    $site->org_id = $theirs->id;

    expect(fn () => $site->save())->toThrow(RuntimeException::class);

    // The caller restores the org and hand-edits only the derived host to another numeric-looking string.
    $site->org_id = $this->org->id;
    $site->canonical_host = '0e2';

    expect(fn () => $site->saveQuietly())->toThrow(RuntimeException::class, 'cannot be written in bulk');

    expect((string) Site::withoutGlobalScopes()->whereKey($site->getKey())->value('canonical_host'))
        ->toBe('0e1', 'a loose comparison called 0e1 and 0e2 the same value');
});

it('guards a non-incrementing model save, which insert() legitimately serves', function (): void {
    /*
     * ⚠️ STANDING ASIDE FROM THE BULK REFUSAL IS NOT THE SAME AS BEING SAFE, which review found and which
     * is the sharpest version of this branch's recurring shape. `$site->setIncrementing(false)` then
     * `saveQuietly()` is a GENUINE model save through this builder — `isPerformingModelSave($this)` is
     * true and `performInsert()` really does use `insert()` for a non-incrementing model, so
     * `refuseBulkCreate()` is right to step back — and a quiet save runs no listener, so nothing derived
     * anything.
     *
     * Measured: `org_id`, `canonical_host` and `path_prefix` persisted verbatim. TWO rows on one hostname
     * and a row planted under another org, in one call.
     *
     * ⚠️ `insertGetId()` had run the per-row guards since the round that added them and `insert()` had
     * not, which is the same asymmetry as the proof check one round earlier. They sit beside each other
     * now rather than a page apart.
     */
    $victim = Org::create(['name' => 'Victim', 'slug' => 'victim-nonincrementing']);

    Site::create([
        'org_id' => $this->org->id, 'handle' => 'owner', 'slug' => 'owner', 'name' => 'Owner',
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://steal.test/',
    ]);

    $site = new Site([
        'org_id' => $victim->id, 'handle' => 'thief', 'slug' => 'thief', 'name' => 'Thief',
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://steal.test/news',
        'canonical_host' => 'steal.test', 'path_prefix' => '/news',
    ]);
    $site->setIncrementing(false);
    $site->id = 999;

    expect(fn () => $site->saveQuietly())
        ->toThrow(RuntimeException::class, 'from a context scoped to')
        ->and(DB::table('sites')->where('canonical_host', 'steal.test')->count())
        ->toBe(1, 'a non-incrementing quiet save landed an overlapping claim')
        ->and(DB::table('sites')->where('org_id', $victim->id)->count())
        ->toBe(0, 'a non-incrementing quiet save planted a row under another org');

    /*
     * ⚠️ AND THE SAME SAVE INSIDE ITS OWN ORG IS STILL REFUSED, for the derived columns rather than the
     * scope keys — otherwise this would be testing the scope guard and the quiet path's real problem
     * would be untested.
     */
    $own = new Site([
        'org_id' => $this->org->id, 'handle' => 'sibling', 'slug' => 'sibling', 'name' => 'Sibling',
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://steal.test/blog',
        'canonical_host' => 'steal.test', 'path_prefix' => '/blog',
    ]);
    $own->setIncrementing(false);
    $own->id = 998;

    expect(fn () => $own->saveQuietly())
        ->toThrow(RuntimeException::class, 'cannot be written by insert()')
        ->and(DB::table('sites')->where('canonical_host', 'steal.test')->count())->toBe(1);
});

it('does not take a caller-arranged key strategy as proof of a model save', function (): void {
    /*
     * ⚠️ THE SECOND ARRANGEABLE DISCRIMINATOR, and review found it after the scope-key one. `insert()`
     * stood aside for a non-incrementing model, because `performInsert()` is that model's create path —
     * and `setIncrementing()` is reachable through the public `Builder::getModel()`:
     *
     *     $query = Site::query();
     *     $query->getModel()->setIncrementing(false);
     *     $query->insert([… 'canonical_host' => 'steal.test', 'path_prefix' => '/news']);
     *
     * No model event ran, so `refuseOverlappingClaim()` never saw it. Measured: ALLOWED, and TWO rows
     * on `steal.test` — one org holding `/` and this one holding `/news`, which the exact-match unique
     * index cannot prevent because the pairs differ. That is the ADR-021 theft.
     */
    Site::create([
        'org_id' => $this->org->id, 'handle' => 'owner', 'slug' => 'owner', 'name' => 'Owner',
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://steal.test/',
    ]);

    $query = Site::query();
    $query->getModel()->setIncrementing(false);

    expect(fn () => $query->insert([
        'org_id' => $this->org->id, 'handle' => 'thief', 'slug' => 'thief', 'name' => 'Thief',
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://steal.test/news',
        'canonical_host' => 'steal.test', 'path_prefix' => '/news',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(RuntimeException::class, 'cannot be created in bulk')
        ->and(DB::table('sites')->where('canonical_host', 'steal.test')->count())
        ->toBe(1, 'a caller-set key strategy let a bulk insert land an overlapping claim');
});

it('does not let a nested write borrow the save it is nested inside', function (): void {
    /*
     * ⚠️ A FLAG SAYING "A SAVE IS SOMEWHERE ON THE STACK" IS BORROWABLE, which review found and which is
     * the third thing this discriminator has had to stop being. A `creating` observer can issue a second
     * write through the model being saved — `$site->newQuery()->insert([…])` builds a DIFFERENT builder
     * around the SAME model — and the flag was true for it.
     *
     * Measured: the hand-written insert was classified as Laravel's own, skipped `refuseBulkCreate()`
     * entirely, and landed `steal.test/news` with caller-authored derived columns while another site
     * held `steal.test/`. TWO rows on one hostname, which the exact-match index cannot prevent.
     *
     * The model holds the exact builder it is being saved THROUGH now, and a guard asks whether it is
     * this one. A nested query is a new object, so the answer is no.
     */
    Site::create([
        'org_id' => $this->org->id, 'handle' => 'owner', 'slug' => 'owner', 'name' => 'Owner',
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://steal.test/',
    ]);

    $nested = false;
    $orgId = $this->org->id;

    // ⚠️ `new Site;` first, for the reason the cancellation test records: a model registers its own
    // listeners when it boots, so one registered before that runs ahead of all of them.
    new Site;

    Site::creating(function (Site $saving) use (&$nested, $orgId): void {
        if ($nested) {
            return;
        }

        $nested = true;

        /*
         * ⚠️ BOTH SPELLINGS, because review found the second one unguarded a round after the first.
         * `refuseBulkCreate()` learned to ask which builder the save is going through and
         * `refuseDetachedInsert()` did not, so `insertGetId()` still accepted the proof from the outer
         * save. Two questions, one mechanism, and they have to be asked by every door.
         */
        $row = [
            'org_id' => $orgId, 'handle' => 'thief', 'slug' => 'thief', 'name' => 'Thief',
            'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://steal.test/news',
            'canonical_host' => 'steal.test', 'path_prefix' => '/news',
            'created_at' => now(), 'updated_at' => now(),
        ];

        expect(fn () => $saving->newQuery()->insertGetId($row))
            ->toThrow(RuntimeException::class, 'cannot be written by insertGetId()');

        $saving->newQuery()->insert($row);
    });

    expect(fn () => Site::create([
        'org_id' => $orgId, 'handle' => 'trigger', 'slug' => 'trigger', 'name' => 'Trigger',
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://other.test',
    ]))->toThrow(RuntimeException::class, 'cannot be created in bulk')
        ->and(DB::table('sites')->where('canonical_host', 'steal.test')->count())
        ->toBe(1, 'a nested write borrowed the save it was nested inside');
});

it('does not take a caller-supplied model as proof of an instance update', function (): void {
    /*
     * ⚠️ THE THIRD, AND IT CONVERTS OTHER ROWS AGAINST ONE ENTRY'S SCHEMA. `Builder::setModel()` is
     * public, so a loaded entry can be put behind a bulk query: `AuditedBuilder::update()` then calls
     * `convertFieldValuesForWrite()` on THAT entry — arming a genuine proof — while the update runs
     * across every matching row.
     *
     * Measured: ALLOWED, 2 rows, and the second entry's `values` replaced by the first's conversion
     * with none of its own per-row validation run.
     */
    $site = Site::create(['org_id' => $this->org->id, 'handle' => 's', 'slug' => 's-setmodel', 'name' => 'S']);
    app(Context::class)->setSite($site);

    $type = EntryType::create([
        'org_id' => $this->org->id, 'handle' => 'page', 'name' => 'Page', 'plural_name' => 'Pages',
    ]);

    $first = Entry::create(['entry_type_id' => $type->id, 'title' => 'A', 'values' => ['x' => '1']]);
    $second = Entry::create(['entry_type_id' => $type->id, 'title' => 'B', 'values' => ['x' => '2']]);

    $query = Entry::query();
    $query->setModel($first);

    /*
     * ⚠️ DECODED RATHER THAN COMPARED AS BYTES, and the byte version was GATE RED on MySQL: its JSON
     * column type re-serialises what it stores, so the same value comes back as `{"x": "2"}` there and
     * `{"x":"2"}` on SQLite and Postgres. The claim is about the VALUE, so the assertion has to be too.
     * Invariant 5.
     */
    expect(fn () => $query->update(['values' => ['x' => 'bulk']]))
        ->toThrow(RuntimeException::class, 'cannot be written in bulk')
        ->and(json_decode((string) DB::table('entries')->where('id', $second->getKey())->value('values'), true))
        ->toBe(['x' => '2'], 'a bulk update converted another entry against the model behind the query');
});

it('does not take the builder\'s own model as proof of a scope key', function (): void {
    /*
     * ⚠️ THE EVIDENCE WAS FORGEABLE, which review found and which invalidated the comparison as an
     * authorisation rather than merely weakening it. `getModel()` and `setModel()` are public Laravel
     * API, so a caller can make every scope key on the builder's model match the row it is writing:
     *
     *     $query = Site::query();
     *     $query->getModel()->org_id = $victim->id;
     *     $query->insertGetId(['org_id' => $victim->id, … ]);   // and no URL columns to guard
     *
     * `$detached` comes out empty, the method returns before anything else looks, and the row is
     * written. Measured from an org's own context: ALLOWED, and the victim org owned the row.
     *
     * ⚠️ THE ANSWER IS THAT NEITHER INPUT TO THE DECISION IS MUTABLE NOW. Whether to check is read from
     * the CLASS — `ScopeResolver::for()` returns the scope a model declares with an attribute, which
     * cannot change at runtime — and what to check against is the Context, which is application state
     * reached through the container with an audited way to stand it down.
     */
    $victim = Org::create(['name' => 'Victim', 'slug' => 'victim-forged']);

    $query = Site::query();
    $query->getModel()->org_id = $victim->id;

    expect(fn () => $query->insertGetId([
        'org_id' => $victim->id, 'handle' => 'planted', 'slug' => 'planted', 'name' => 'Planted',
        'locale' => 'en', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(RuntimeException::class, 'from a context scoped to')
        ->and(DB::table('sites')->where('org_id', $victim->id)->count())
        ->toBe(0, 'a forged builder model planted a row under another org');

    /*
     * ⚠️ AND AN `#[Unscoped]` MODEL IS LEFT ALONE, because naming another org there is a settled shape
     * with a test of its own — a global entry type must be creatable for any org. The check is keyed on
     * what the model DECLARES, so this is a consequence of the rule rather than an exception to it.
     */
    $type = EntryType::create([
        'org_id' => $victim->id, 'handle' => 'global', 'name' => 'Global', 'plural_name' => 'Globals',
    ]);

    expect($type->exists)->toBeTrue()->and($type->org_id)->toBe($victim->id);
});

it('does not let a proof outlive the attempt that armed it', function (): void {
    /*
     * ⚠️ VALUE EQUALITY CANNOT SEE THIS, which is the point and is why it needed a second mechanism
     * rather than a stricter comparison. Validity changed while every snapshotted value stayed
     * identical — because what changed is the WORLD, not the row. Review found it.
     *
     * ⚠️ THE ABORT IS A LISTENER THAT THROWS, AND THE FIRST VERSION USED A UNIQUE-INDEX VIOLATION —
     * which was GATE RED on PostgreSQL. A failed statement there poisons the whole transaction, so every
     * assertion after it died with "current transaction is aborted" while SQLite and MySQL rolled back
     * only the statement. Staging a failure with the DATABASE makes the test about the engine's error
     * semantics; staging it with a listener asks the question this test is actually asking, identically
     * everywhere. Invariant 5.
     *
     * ⚠️ AND NOT THE SCOPE GUARD EITHER, although that is where review's scenario put it: since this
     * round it refuses the quiet retry too, so it would mask the mechanism under test. Everything here
     * is inside ONE org, so `refuseDetachedScopeKeys()` is satisfied and `refuseOverlappingClaim()`
     * permits an org arranging its own sites — the consumed proof is the only thing left that can
     * account for the refusal.
     *
     * The sequence:
     *
     *   1. a guard later than the arming listener refuses the save, which is exactly the shape review
     *      described — and it throws from inside performInsert(), which is why the try/finally lives
     *      there rather than around save()
     *   2. the guard stands down, so the row could now be written
     *   3. another org claims the same host at `/`, which overlaps `/news`
     *   4. the SAME INSTANCE retried with saveQuietly() runs no listener at all — so neither the overlap
     *      check nor anything else is consulted — and the proof still described these exact values, so
     *      the builder allowed it and both prefixes landed on one hostname
     */
    $refusing = true;

    Site::creating(function () use (&$refusing): void {
        if ($refusing) {
            throw new RuntimeException('a guard later than the arming listener refused this save');
        }
    });

    $site = new Site([
        'org_id' => $this->org->id, 'handle' => 'sneak', 'slug' => 'sneak', 'name' => 'Sneak',
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://sneak.test/news',
    ]);

    // Attempt one: arms the proof on `saving`, then the later guard refuses it on `creating`.
    expect(fn () => $site->save())->toThrow(RuntimeException::class, 'later than the arming listener')
        ->and($site->guardedColumnsAreDerived())
        ->toBeFalse('the proof outlived the attempt that armed it');

    $refusing = false;

    // A third org now claims the same host at the root, which overlaps `/news`.
    $third = Org::create(['name' => 'Third', 'slug' => 'third-proof']);
    app(Context::class)->setOrg($third);

    Site::create([
        'org_id' => $third->id, 'handle' => 'rival', 'slug' => 'rival', 'name' => 'Rival',
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://sneak.test',
    ]);

    app(Context::class)->setOrg($this->org);

    /*
     * ⚠️ THE RETRY IS QUIET, so nothing re-derives and nothing re-checks. The builder has only the proof
     * to go on, and the proof must be gone — a save that did not happen cannot vouch for one that is
     * happening now.
     */
    expect(fn () => $site->saveQuietly())->toThrow(RuntimeException::class, 'cannot be written by')
        ->and(DB::table('sites')->where('canonical_host', 'sneak.test')->count())
        ->toBe(1, 'the overlapping claim was written on a proof from an aborted attempt');
});

it('clears a proof when the save is cancelled before the write begins', function (): void {
    /*
     * ⚠️ `saving` FIRES BEFORE `performInsert()`, which review found the previous fix not covering. An
     * observer that returns false — or throws — after the arming listener leaves the attempt with
     * neither `performInsert()`'s `finally` nor `saved` having run, so the proof stood and a quiet retry
     * could present it after the world had changed underneath.
     *
     * ⚠️ THE ANSWER MOVED THE ARMING RATHER THAN ADDING A THIRD CLEAR. It fires on `creating`/`updating`
     * now, which are INSIDE `performInsert()`/`performUpdate()`, and those clear the proof on entry as
     * well as on exit. So a proof can only exist for the attempt that armed it: an abort before the
     * attempt starts leaves none to clear, and an attempt that starts destroys whatever it inherited.
     */
    $cancelling = true;

    /*
     * ⚠️ THE MODEL IS BOOTED FIRST, AND WITHOUT THIS LINE THE TEST PROVED NOTHING. A model registers its
     * own listeners when it boots, lazily, on first use — so a listener registered by a test before that
     * happens runs BEFORE all of them. Measured: the cancel landed first, `canonical_host` was still
     * null, and nothing had armed a proof for the fix to clear. The test passed against the reverted
     * code, which is how I found it.
     *
     * ⚠️ A CLOSURE, NOT AN ARROW FUNCTION, for the second half of the same lesson: `fn()` captures by
     * VALUE, so `$cancelling` would stay true for the listener's life and cancel the rival's create
     * below too.
     */
    new Site;

    Site::saving(function (Site $saving) use (&$cancelling): ?bool {
        if (! $cancelling) {
            return null;
        }

        /*
         * ⚠️ POSITION PINNED RATHER THAN ASSUMED. This listener has to run AFTER the model's own
         * `saving` chain, or the cancel lands before anything has happened and the test is about
         * nothing. The derivation is the visible evidence of that: `canonical_host` is derived from
         * `base_url` by one of those listeners, so if it is set, they have run.
         *
         * It deliberately does NOT assert that a proof is armed here — that is the thing the fix
         * moved. Arming is on `creating` now, inside the attempt, which is why a cancel at `saving`
         * cannot strand one by construction rather than by cleanup.
         */
        expect($saving->canonical_host)
            ->toBe('sneak.test', 'this listener runs before the model\'s own, so the cancel strands nothing');

        return false;
    });

    $site = new Site([
        'org_id' => $this->org->id, 'handle' => 'sneak', 'slug' => 'sneak', 'name' => 'Sneak',
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://sneak.test/news',
    ]);

    expect($site->save())->toBeFalse('the observer did not cancel the save')
        ->and($site->guardedColumnsAreDerived())
        ->toBeFalse('a cancelled save left its proof armed');

    $cancelling = false;

    // A rival claims the host at the root while the first attempt is abandoned.
    $rival = Org::create(['name' => 'Rival', 'slug' => 'rival-cancel']);
    app(Context::class)->setOrg($rival);

    Site::create([
        'org_id' => $rival->id, 'handle' => 'rival', 'slug' => 'rival', 'name' => 'Rival',
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://sneak.test',
    ]);

    app(Context::class)->setOrg($this->org);

    expect(fn () => $site->saveQuietly())->toThrow(RuntimeException::class, 'cannot be written by')
        ->and(DB::table('sites')->where('canonical_host', 'sneak.test')->count())
        ->toBe(1, 'a proof from a cancelled save let an overlapping claim through');
});

it('refuses a declared scope key with no context to vouch for it', function (): void {
    /*
     * ⚠️ NO CONTEXT IS NOT PERMISSION, and the previous round applied that to the hand-rolled path only.
     * `guardScopeKeys()` accepts every value when the context has none to compare against — right for an
     * UPDATE, where the row already belongs to somebody — and a quiet create suppresses `EnforcesScope`
     * entirely. Measured: `SiteGroup::createQuietly(['org_id' => $victim, …])` from a job with no
     * `Context` was ALLOWED, and the row was planted for an org nothing had vouched for.
     *
     * A model that DECLARES a scope says its keys mean something, so a non-null one with nothing to
     * check it against fails closed whatever the model behind the query says.
     */
    $victim = Org::create(['name' => 'Victim', 'slug' => 'victim-nocontext']);

    app(Context::class)->forget();

    expect(fn () => SiteGroup::createQuietly([
        'org_id' => $victim->id, 'handle' => 'planted', 'name' => 'Planted',
    ]))->toThrow(RuntimeException::class, 'from no scope at all')
        ->and(DB::table('site_groups')->where('org_id', $victim->id)->count())
        ->toBe(0, 'a quiet create with no context planted a row for another org');

    /*
     * ⚠️ AND THE HATCH STILL GETS THROUGH, or provisioning — the documented reason it exists — would be
     * the one caller this blocks. That is the shape of the mistake the round before last made.
     */
    $group = SiteGroup::withoutScopeBecause(
        'provisioning: a console command creates the first group before any context exists',
        fn () => SiteGroup::createQuietly([
            'org_id' => $victim->id, 'handle' => 'provisioned', 'name' => 'Provisioned',
        ]),
    );

    expect($group->exists)->toBeTrue();

    app(Context::class)->setOrg($this->org);
});

it('lets the reviewable escape hatch through the detached-key guard', function (): void {
    /*
     * ⚠️ THE REFUSAL NAMED A REMEDY THAT THE REFUSAL ITSELF DEFEATED, which review found — worse than
     * no message. `guardScopeKeys()` stands down under `ScopeWrites::suspended()`, and its comment says
     * "the reviewable escape hatch stands BOTH enforcers down, not one"; the no-context branch added
     * last round was a third enforcer, running in front of it, telling the caller to use a hatch it
     * would not honour.
     *
     * Provisioning with no `Context` is the documented reason that hatch exists.
     */
    app(Context::class)->forget();

    // `Org` is the root of the hierarchy and carries no scope of its own, so it needs no hatch.
    $org = Org::create(['name' => 'Provisioned', 'slug' => 'provisioned']);

    $id = Site::withoutScopeBecause(
        'provisioning: a console command establishes the first site before any context exists',
        fn ($query) => $query->insertGetId(siteRow($org->id, 'provisioned')),
    );

    expect($id)->toBeGreaterThan(0)
        ->and((int) DB::table('sites')->where('id', $id)->value('org_id'))->toBe($org->id);

    // And without the hatch it is still refused, so the stand-down is about the hatch and not the row.
    expect(fn () => Site::query()->insertGetId(siteRow($org->id, 'unhatched')))
        ->toThrow(RuntimeException::class, 'from no scope at all');

    app(Context::class)->setOrg($this->org);
});

it('refuses updateOrInsert, which no guard on this builder can reach', function (): void {
    /*
     * ⚠️ FORWARDED WHOLE TO THE QUERY BUILDER, so neither the insert overrides nor the update one runs
     * — which `AuditedBuilder::updateOrInsert()` has said in a docblock since #60 and this builder had
     * not learned. Review found it, and it has THREE consequences rather than the two that are obvious.
     * All three were measured before the fix.
     */
    $site = Site::query()->getModel();

    expect($site)->toBeInstanceOf(Site::class);

    /*
     * ⚠️ ONE — the unmatched predicate INSERTS, with the derived columns exactly as the caller left
     * them: `base_url = https://planted.test` and `canonical_host = NULL`. A site declaring a public
     * address and reachable at none is the sentence `RequiresModelSave` exists for.
     */
    expect(fn () => Site::query()->updateOrInsert(
        ['handle' => 'planted'],
        ['org_id' => $this->org->id, 'slug' => 'planted', 'name' => 'Planted', 'locale' => 'en',
            'url_strategy' => 'domain', 'base_url' => 'https://planted.test',
            'created_at' => now(), 'updated_at' => now()],
    ))->toThrow(RuntimeException::class, 'updateOrInsert() cannot be used');

    expect(DB::table('sites')->where('handle', 'planted')->exists())->toBeFalse();

    /*
     * ⚠️ TWO — the matched predicate UPDATES, past the per-row checks. `handle` is one of `EntryType`'s
     * guarded columns because ADR-012 RESERVES some of them, and `admin` is reserved: it collides with
     * a registered route. Measured moving to it through this door.
     */
    $type = EntryType::create([
        'org_id' => $this->org->id, 'handle' => 'page', 'name' => 'Page', 'plural_name' => 'Pages',
    ]);

    expect(fn () => EntryType::query()->updateOrInsert(['id' => $type->getKey()], ['handle' => 'admin']))
        ->toThrow(RuntimeException::class, 'updateOrInsert() cannot be used');

    expect((string) DB::table('entry_types')->where('id', $type->getKey())->value('handle'))->toBe('page');
});

it('refuses updateOrInsert before it can write another org\'s row', function (): void {
    /*
     * ⚠️ THREE, AND THE ONE THE FINDING DID NOT NAME: the global scope is never applied. Scopes are
     * applied by the ELOQUENT builder, and a call forwarded past it is unscoped — so this is not a
     * guard that failed but a boundary that was never consulted.
     *
     * The two lines below are the whole proof, on the same row in the same org context:
     *
     *   Site::query()->whereKey($rival)->update([…])          0 rows affected
     *   Site::query()->updateOrInsert(['id' => $rival], […])  the rival's site renamed
     *
     * ⚠️ Checked on `Site` and not on `EntryType`, and my first attempt used `EntryType` and proved
     * nothing: it is `#[Unscoped]` by declaration, because a global type must be visible from every
     * org. An ordinary scoped update reaches another org's row there LEGITIMATELY, so the comparison
     * that makes this a finding is unavailable on that model.
     */
    $theirs = Org::create(['name' => 'Theirs', 'slug' => 'theirs-uoi']);
    app(Context::class)->setOrg($theirs);

    $rival = Site::create(['org_id' => $theirs->id, 'handle' => 'rival', 'slug' => 'rival', 'name' => 'Rival']);

    app(Context::class)->setOrg($this->org);

    expect(Site::query()->whereKey($rival->getKey())->update(['name' => 'Scoped']))
        ->toBe(0, 'the org scope did not hide the rival row, so this test cannot show a bypass')
        ->and(fn () => Site::query()->updateOrInsert(['id' => $rival->getKey()], ['name' => 'Stolen']))
        ->toThrow(RuntimeException::class, 'another org')
        ->and((string) DB::table('sites')->where('id', $rival->getKey())->value('name'))
        ->toBe('Rival');
});

it('refuses truncate, which has no WHERE clause for a scope to narrow', function (): void {
    /*
     * ⚠️ THE SAME SWEEP FOUND THIS AND IT IS WORSE. A global scope constrains a WHERE clause and
     * `TRUNCATE` has none, so there is nothing to narrow: measured, two sites in two orgs and one org's
     * context left ZERO rows. It also bypasses the cascade refusal `delete()` and `forceDelete()` route
     * through, so every referenced entry goes with it.
     *
     * ⚠️ The sweep produced a rule rather than a list: `truncate()` belongs wherever `delete()` is
     * guarded. Three sibling builders override it and all three guard deletion; `GuardedStorageBuilder`
     * guards creation only and correctly has none, because truncating creates nothing.
     */
    $theirs = Org::create(['name' => 'Theirs', 'slug' => 'theirs-trunc']);
    app(Context::class)->setOrg($theirs);
    Site::create(['org_id' => $theirs->id, 'handle' => 'theirs', 'slug' => 'theirs', 'name' => 'Theirs']);

    app(Context::class)->setOrg($this->org);
    Site::create(['org_id' => $this->org->id, 'handle' => 'mine', 'slug' => 'mine', 'name' => 'Mine']);

    expect(fn () => Site::query()->truncate())
        ->toThrow(RuntimeException::class, 'every row in every org')
        ->and(DB::table('sites')->count())->toBe(2);
});

it('guards a reserved handle on a quiet or detached entry-type write', function (): void {
    /*
     * ⚠️ BOTH OTHER GUARDED COLUMNS ARE NULLABLE ON A GLOBAL TYPE, which review found is the gap: a
     * `createQuietly()` or a direct `insertGetId()` that omitted `org_id` and `subject_field_id` named no
     * guarded column at all, so the insert guard had nothing to inspect and the reserved-handle `saving`
     * listener never ran. A type could be planted on a handle ADR-012 reserves, which is the collision
     * with a registered route that listener exists to prevent.
     *
     * A guarded column list assembled from "the columns a guard DERIVES" missed the one a guard merely
     * REFUSES — and a refusal is as per-row as a derivation.
     */
    expect(fn () => EntryType::query()->createQuietly([
        'handle' => 'admin', 'name' => 'Admin', 'plural_name' => 'Admins',
    ]))->toThrow(RuntimeException::class, 'checks that derive and validate it did not run');

    expect(fn () => EntryType::query()->insertGetId([
        'handle' => 'admin', 'name' => 'Admin', 'plural_name' => 'Admins',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(RuntimeException::class, 'checks that derive and validate it did not run');

    expect(EntryType::withoutGlobalScopes()->where('handle', 'admin')->exists())->toBeFalse();

    // ⚠️ And an ordinary create still works, or the guard would be about the column rather than the write.
    $type = EntryType::create([
        'org_id' => $this->org->id, 'handle' => 'ordinary', 'name' => 'Ordinary', 'plural_name' => 'Ordinaries',
    ]);

    expect($type->exists)->toBeTrue();
});
