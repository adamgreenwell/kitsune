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

        expect(fn () => Site::query()->createQuietly([
            'org_id' => $rival->id, 'handle' => 'thief', 'slug' => 'thief', 'name' => 'Thief',
            'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://steal.test/news',
            'canonical_host' => 'steal.test', 'path_prefix' => '/news',
        ]))->toThrow(RuntimeException::class, 'checks that derive and validate it did not run');

        expect(Site::withoutGlobalScopes()->where('canonical_host', 'steal.test')->count())
            ->toBe(1, 'the quiet create landed a second claim on a host another org holds');
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

    // And only a forged handle is dirty: the id on the model is the truth it derives from.
    $entry->type_handle = 'page';
    $entry->saveQuietly();

    expect((string) Entry::withoutGlobalScopes()->whereKey($entry->getKey())->value('type_handle'))
        ->toBe('article', 'a forged handle survived when it was the only dirty column');
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
