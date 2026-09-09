<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRevision;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/*
 * ⚠️ `FieldType::toStorage()` had NO CALLER on any save path (issue #42).
 *
 * It is declared on the contract and implemented by all twelve types, and
 * nothing invoked it, so whatever a caller put in `values` was what got stored.
 * For `rich_text` that means the sanitiser never ran — and field-types.md §6
 * calls it the only XSS vector in v1 and requires sanitizing on write.
 *
 * Not exploitable when it was found, because no public route renders an entry.
 * It becomes live the moment one exists, and it becomes live SILENTLY, because
 * nothing fails when it does. That is what these tests are for.
 */

beforeEach(function (): void {
    $this->org = Org::create(['name' => 'Publisher', 'slug' => 'vc-pub']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['org_id' => $this->org->id, 'handle' => 'main', 'slug' => 'vc-main', 'name' => 'Main']);
    app(Context::class)->setSite($this->site);

    $this->type = EntryType::create([
        'org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles',
    ]);

    $this->body = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'body', 'type' => 'rich_text',
        'pii_class' => 'none', 'cardinality' => 1,
    ]);
    Field::create([
        'entry_type_id' => $this->type->id, 'field_storage_id' => $this->body->id,
        'label' => 'Body', 'ordering' => 0,
    ]);
});

afterEach(fn () => app(Context::class)->forget());

/** The bytes actually in the column, not what an accessor hands back. */
function storedValues(Entry $entry): array
{
    $raw = DB::table('entries')->where('id', $entry->getKey())->value('values');

    return json_decode((string) $raw, true) ?? [];
}

describe('rich text is sanitized on the way in', function (): void {
    it('stores the sanitized bytes, not the submitted ones', function (): void {
        /*
         * ⚠️ Asserted against the DATABASE, not against the sanitiser's return
         * value. The issue is explicit about this, and for a good reason: a test
         * that calls `sanitize()` and checks its output proves the sanitiser works,
         * which was never in doubt. What was missing is anything CALLING it, so the
         * only assertion that can fail for the right reason reads the column.
         */
        $entry = Entry::create([
            'entry_type_id' => $this->type->id,
            'title' => 'Hello',
            'values' => ['body' => '<p>Hello</p><script>alert(1)</script>'],
        ]);

        expect(storedValues($entry)['body'])->toBe('<p>Hello</p>')
            ->and(storedValues($entry)['body'])->not->toContain('script');
    });

    it('sanitizes on update as well as create', function (): void {
        $entry = Entry::create([
            'entry_type_id' => $this->type->id, 'title' => 'Hello',
            'values' => ['body' => '<p>Fine</p>'],
        ]);

        $entry->update(['values' => ['body' => '<p>Later</p><img src=x onerror=alert(1)>']]);

        expect(storedValues($entry)['body'])->not->toContain('onerror');
    });

    it('is idempotent, so an unrelated save does not re-mangle a stored value', function (): void {
        // ⚠️ The pipeline runs on EVERY save whose values are dirty, and erasure and
        // restore both save. Converting an already-converted value has to be a no-op
        // or the content drifts a little on each write.
        $entry = Entry::create([
            'entry_type_id' => $this->type->id, 'title' => 'Hello',
            'values' => ['body' => '<p>Hello <em>there</em></p>'],
        ]);

        $once = storedValues($entry)['body'];

        $entry->update(['values' => ['body' => $once]]);

        expect(storedValues($entry)['body'])->toBe($once);
    });

    it('leaves a key the caller did not send alone', function (): void {
        // Absent is not null. Converting an unsent key would write a null over every
        // field the submitting form happened not to include.
        $entry = Entry::create([
            'entry_type_id' => $this->type->id, 'title' => 'Hello',
            'values' => ['body' => '<p>Kept</p>'],
        ]);

        $entry->update(['title' => 'Retitled']);

        expect(storedValues($entry))->toHaveKey('body')
            ->and(storedValues($entry)['body'])->toBe('<p>Kept</p>');
    });
});

describe('the conversion cannot be skipped', function (): void {
    it('refuses a bulk write to values, which dispatches no events', function (): void {
        /*
         * ⚠️ The model is fully mass assignable, so "callers should convert first"
         * is not a mechanism. A bulk update dispatches nothing, so the pipeline
         * would not run and the unsanitized bytes would land — which is the shape
         * this project has now found nine times.
         */
        Entry::create([
            'entry_type_id' => $this->type->id, 'title' => 'Hello',
            'values' => ['body' => '<p>Fine</p>'],
        ]);

        expect(fn () => Entry::query()->update(['values' => ['body' => '<script>alert(1)</script>']]))
            ->toThrow(RuntimeException::class, 'cannot be written in bulk');
    });

    it('still allows the internal writes that legitimately set values directly', function (): void {
        // Erasure and restore write `values` through an instance save, which runs the
        // pipeline rather than skipping it. If the guard caught those too, erasure
        // would break — so this asserts the guard is narrow enough.
        $entry = Entry::create([
            'entry_type_id' => $this->type->id, 'title' => 'Hello',
            'values' => ['body' => '<p>Personal</p>'],
        ]);

        expect($entry->redactField('body'))->toBeGreaterThan(0)
            ->and(storedValues($entry->fresh())['body'])->toBeNull();
    });
});

describe('the pre-sanitization original is kept beside the revision', function (): void {
    it('records what the sanitizer removed', function (): void {
        // field-types.md §6: the original is kept so an author can see what went. It
        // is the revision's, not the entry's.
        $entry = Entry::create([
            'entry_type_id' => $this->type->id, 'title' => 'Hello',
            'values' => ['body' => '<p>Hello</p><script>alert(1)</script>'],
        ]);

        $revision = $entry->revisions()->latest('id')->firstOrFail();

        expect($revision->unsanitized_values['body'])->toBe('<p>Hello</p><script>alert(1)</script>')
            ->and($revision->values['body'])->toBe('<p>Hello</p>')
            // ⚠️ And NOT on the entry. That is the requirement, not a preference.
            ->and(storedValues($entry))->not->toHaveKey('body_original')
            ->and(json_encode(storedValues($entry)))->not->toContain('script');
    });

    it('keeps nothing when the conversion took nothing', function (): void {
        // ⚠️ A value that survived sanitising unchanged has no original worth
        // storing, and storing one would put a redundant copy of every value into a
        // column erasure has to sweep.
        $entry = Entry::create([
            'entry_type_id' => $this->type->id, 'title' => 'Hello',
            'values' => ['body' => '<p>Clean</p>'],
        ]);

        expect($entry->revisions()->latest('id')->firstOrFail()->unsanitized_values)->toBeNull();
    });

    it('keeps nothing for a type whose conversion is a cast', function (): void {
        // The seam is asked of the type: only `rich_text` declares its conversion
        // lossy, so a number does not leave `'5'` lying beside `5`.
        $number = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'count', 'type' => 'number',
            'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['format' => 'integer'],
        ]);
        Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $number->id,
            'label' => 'Count', 'ordering' => 1,
        ]);

        $entry = Entry::create([
            'entry_type_id' => $this->type->id, 'title' => 'Hello',
            'values' => ['count' => '5'],
        ]);

        expect(storedValues($entry)['count'])->toBe(5)
            ->and($entry->revisions()->latest('id')->firstOrFail()->unsanitized_values)->toBeNull();
    });

    it('does not attach one save\'s original to a later revision', function (): void {
        // ⚠️ The originals are transient and cleared by the recorder. Carrying them
        // forward would be a FALSE record of what an author wrote, which is worse
        // than no record at all.
        $entry = Entry::create([
            'entry_type_id' => $this->type->id, 'title' => 'Hello',
            'values' => ['body' => '<p>One</p><script>x</script>'],
        ]);

        $entry->update(['title' => 'Retitled']);

        $latest = $entry->revisions()->latest('id')->firstOrFail();

        expect($latest->title)->toBe('Retitled')
            ->and($latest->unsanitized_values)->toBeNull();
    });

    it('is never reachable by a restore, because a restore cannot read it', function (): void {
        /*
         * ⚠️ THE TRAP field-types.md §6 warns about — and asserting it needed care,
         * because the obvious test proves nothing.
         *
         * `restoreRevision()` fills the entry from `snapshot()`. An original stored as
         * a key inside `values` would be written straight back, putting unsanitized
         * HTML into `entries.values`. So the requirement is about WHERE the original
         * lives, and the assertions below are about that:
         *
         *   - the revision's `values` holds the SANITIZED form, not the original
         *   - `snapshot()` — everything a restore reads — carries no original
         *   - `SNAPSHOT_ATTRIBUTES` does not name the column, structurally
         *
         * ⚠️ My first version asserted only that the entry held sanitized bytes after
         * a restore, and it passed even with the original deliberately injected into
         * the revision's `values`. The reason is worth keeping: the conversion pipeline
         * runs on the restore's save too, so it re-sanitises on the way back in. That
         * is a genuine second line of defence and it is why the outcome assertion
         * cannot distinguish a correct layout from a broken one. It is kept at the end,
         * labelled as the belt rather than the braces.
         */
        $entry = Entry::create([
            'entry_type_id' => $this->type->id, 'title' => 'First',
            'values' => ['body' => '<p>First</p><script>alert(1)</script>'],
        ]);

        $original = $entry->revisions()->latest('id')->firstOrFail();

        // The original is kept, and kept OUT of the snapshot surface.
        expect($original->unsanitized_values['body'])->toContain('script')
            ->and($original->values['body'])->toBe('<p>First</p>')
            ->and(json_encode($original->snapshot()))->not->toContain('script')
            ->and(EntryRevision::SNAPSHOT_ATTRIBUTES)->not->toContain('unsanitized_values')
            ->and(array_keys($original->snapshot()))->not->toContain('unsanitized_values');

        $entry->update(['values' => ['body' => '<p>Second</p>']]);
        $entry->restoreRevision($original);

        // And the outcome, which the layout above is what actually guarantees.
        expect(storedValues($entry->fresh())['body'])->toBe('<p>First</p>')
            ->and(json_encode(storedValues($entry->fresh())))->not->toContain('script');
    });

    it('is reached by erasure, being personal data like any other value', function (): void {
        // ⚠️ Keeping an original gave personal data a SECOND home. A home erasure does
        // not know about is the defect this project has hit repeatedly — here it would
        // mean an erasure reporting success while the author's original text sat beside
        // the value it cleared (ADR-020).
        $personal = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'bio', 'type' => 'rich_text',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);
        Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $personal->id,
            'label' => 'Bio', 'ordering' => 2,
        ]);

        $entry = Entry::create([
            'entry_type_id' => $this->type->id, 'title' => 'Profile',
            'values' => ['bio' => '<p>Alex Doe</p><script>track()</script>'],
        ]);

        expect($entry->revisions()->latest('id')->firstOrFail()->unsanitized_values['bio'])
            ->toContain('Alex Doe');

        $entry->redactField('bio');

        foreach ($entry->revisions()->get() as $revision) {
            expect($revision->unsanitized_values['bio'] ?? null)->toBeNull()
                ->and(json_encode($revision->unsanitized_values))->not->toContain('Alex Doe');
        }
    });
});

describe('a quiet save cannot skip the conversion', function (): void {
    /*
     * ⚠️ The first version of this pipeline lived in a `saving` listener, and
     * `saveQuietly()`, `createQuietly()`, `updateQuietly()` and `withoutEvents()` all
     * suppress model events while still reaching the builder. So a `<script>` payload
     * went in unchanged AND was recorded unsanitized in the revision.
     *
     * My commit message for that version said "the guard is where the write is" while
     * the guard sat in an event, which is not where the write is. This project has
     * found that shape ten times; this is the first time I added one.
     *
     * Conversion happens in `AuditedBuilder::insertGetId()` and `update()` now — the
     * path every one of these still travels.
     */
    it('sanitizes a createQuietly', function (): void {
        $entry = Entry::withoutEvents(fn () => Entry::create([
            'entry_type_id' => test()->type->id,
            'org_id' => test()->org->id,
            'site_id' => test()->site->id,
            'type_handle' => 'article',
            'title' => 'Quiet',
            'values' => ['body' => '<p>Quiet</p><script>alert(1)</script>'],
        ]));

        expect(storedValues($entry)['body'])->toBe('<p>Quiet</p>')
            ->and(json_encode(storedValues($entry)))->not->toContain('script');
    });

    it('sanitizes a saveQuietly', function (): void {
        $entry = Entry::create([
            'entry_type_id' => $this->type->id, 'title' => 'Hello',
            'values' => ['body' => '<p>Fine</p>'],
        ]);

        $entry->values = ['body' => '<p>Later</p><script>alert(1)</script>'];
        $entry->saveQuietly();

        expect(storedValues($entry->fresh())['body'])->toBe('<p>Later</p>')
            ->and(json_encode(storedValues($entry->fresh())))->not->toContain('script');
    });

    it('sanitizes inside withoutEvents', function (): void {
        $entry = Entry::create([
            'entry_type_id' => $this->type->id, 'title' => 'Hello',
            'values' => ['body' => '<p>Fine</p>'],
        ]);

        Entry::withoutEvents(function () use ($entry): void {
            $entry->update(['values' => ['body' => '<p>Silent</p><img src=x onerror=alert(1)>']]);
        });

        expect(json_encode(storedValues($entry->fresh())))->not->toContain('onerror');
    });

    it('records the revision original from a quiet write too', function (): void {
        // ⚠️ The revision half of the same gap: a quiet write recorded the
        // unsanitized bytes into the snapshot as well, so history carried what the
        // entry did not.
        $entry = Entry::create([
            'entry_type_id' => $this->type->id, 'title' => 'Hello',
            'values' => ['body' => '<p>One</p>'],
        ]);

        $entry->values = ['body' => '<p>Two</p><script>x</script>'];
        $entry->saveQuietly();

        $revision = $entry->revisions()->latest('id')->firstOrFail();

        expect($revision->values['body'])->toBe('<p>Two</p>')
            ->and($revision->unsanitized_values['body'])->toContain('script');
    });
});

describe('a save that touches no field value resolves no schema', function (): void {
    it('runs no extra queries when only the title changes', function (): void {
        /*
         * ⚠️ The first version CLAIMED this optimisation in a comment and did not
         * implement it: it queried the entry type and loaded its fields and storage on
         * every save, whatever changed. On the 1 vCPU / SQLite floor (ADR-027) that is
         * several queries added to every write for nothing.
         *
         * The short-circuit is structural now rather than a check: only columns the
         * write actually carries are converted, so a title-only save never resolves a
         * field at all. Counting queries is the only way to assert an absence.
         */
        $entry = Entry::create([
            'entry_type_id' => $this->type->id, 'title' => 'First',
            'values' => ['body' => '<p>Body</p>'],
        ]);

        /*
         * ⚠️ Matched on where the statement STARTS, not on what it contains.
         *
         * `lockStorageHoldingData()` is a pre-existing `saved` listener and its query
         * is `select * from field_storage where is_locked = ? and exists (select *
         * from fields ...)` — so a `str_contains($sql, 'from fields')` matcher counts
         * it and this test fails for a reason that has nothing to do with the
         * conversion. I hit exactly that, and the tempting fix was to loosen the
         * assertion rather than sharpen the matcher.
         *
         * The conversion's own queries are the type lookup and its fields, both of
         * which START with those tables. The subquery does not.
         */
        $schemaQueries = 0;
        DB::listen(function ($query) use (&$schemaQueries): void {
            $sql = (string) preg_replace('/[`"]/', '', $query->sql);

            if (str_starts_with($sql, 'select * from entry_types')
                || str_starts_with($sql, 'select * from fields ')) {
                $schemaQueries++;
            }
        });

        $entry->update(['title' => 'Retitled']);

        expect($schemaQueries)->toBe(0);
    });

    it('does resolve the schema when a field value changes', function (): void {
        // The other side: the short-circuit must not become a way of never converting.
        $entry = Entry::create([
            'entry_type_id' => $this->type->id, 'title' => 'First',
            'values' => ['body' => '<p>Body</p>'],
        ]);

        $schemaQueries = 0;
        DB::listen(function ($query) use (&$schemaQueries): void {
            $sql = (string) preg_replace('/[`"]/', '', $query->sql);

            if (str_starts_with($sql, 'select * from entry_types')
                || str_starts_with($sql, 'select * from fields ')) {
                $schemaQueries++;
            }
        });

        $entry->update(['values' => ['body' => '<p>New</p><script>x</script>']]);

        expect($schemaQueries)->toBeGreaterThan(0)
            ->and(storedValues($entry->fresh())['body'])->toBe('<p>New</p>');
    });
});

describe('the conversion covers the arithmetic side door too', function (): void {
    it('refuses values smuggled in as an arithmetic assignment', function (): void {
        /*
         * ⚠️ Laravel's arithmetic methods take an `$extra` map of ordinary assignments
         * and forward straight to the query builder — reaching neither `update()` nor
         * `refusePerRowColumns()`.
         *
         * So `increment('ordering', 0, ['values' => '…<script>…'])` put raw bytes into
         * `entries.values` with no conversion, and the revision snapshotted them
         * unsanitized. This route had already been closed once for auditing and once
         * for versioning; the conversion was the third thing it skipped.
         *
         * Refused rather than converted, for the same reason a bulk update is: one
         * arithmetic statement can match any number of rows of any number of types.
         */
        $entry = Entry::create([
            'entry_type_id' => $this->type->id, 'title' => 'Hello',
            'values' => ['body' => '<p>Fine</p>'],
        ]);

        expect(fn () => Entry::query()->whereKey($entry->getKey())
            ->increment('author_id', 0, ['values' => json_encode(['body' => '<script>alert(1)</script>'])]))
            ->toThrow(RuntimeException::class, 'cannot be written in bulk');

        // Nothing was written.
        expect(storedValues($entry->fresh())['body'])->toBe('<p>Fine</p>');
    });

    it('still allows an arithmetic write that carries no field values', function (): void {
        // The refusal must be about the smuggled column, not about arithmetic.
        $entry = Entry::create([
            'entry_type_id' => $this->type->id, 'title' => 'Hello',
            'values' => ['body' => '<p>Fine</p>'],
        ]);

        // `author_id` because `entries` has no `ordering` column — a numeric column
        // that exists is all this needs.
        $entry->update(['author_id' => 1]);

        Entry::query()->whereKey($entry->getKey())->increment('author_id', 1);

        expect((int) $entry->fresh()->author_id)->toBe(2);
    });
});

describe('a type change reconverts what is already stored', function (): void {
    it('sanitizes a value that only becomes rich text on the destination type', function (): void {
        /*
         * ⚠️ `values` is keyed by handle and an unknown key passes through untouched —
         * nothing converts it, because no field claims it. So an entry can hold
         * `<script>` under a key its current type does not declare, and then move to a
         * type where that key IS `rich_text`: the value becomes rich text having never
         * met the sanitiser.
         *
         * `entry_type_id` is explicitly mutable (ADR-010), so this is a supported
         * operation rather than an edge case, and the type is what decides what the
         * stored bytes mean.
         */
        $bare = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'bare', 'name' => 'Bare', 'plural_name' => 'Bares',
        ]);

        // Stored under a key this type does not declare, so nothing converts it.
        $entry = Entry::create([
            'entry_type_id' => $bare->id,
            'title' => 'Smuggled',
            'values' => ['body' => '<p>Hi</p><script>alert(1)</script>'],
        ]);

        expect(json_encode(storedValues($entry)))->toContain('script');

        // Now move it to the type where `body` IS rich text.
        $entry->update(['entry_type_id' => $this->type->id]);

        expect(storedValues($entry->fresh())['body'])->toBe('<p>Hi</p>')
            ->and(json_encode(storedValues($entry->fresh())))->not->toContain('script');
    });

    it('leaves values alone when the type does not change', function (): void {
        // ⚠️ The trigger is a CHANGE of type, not the column merely being present in
        // the write — otherwise every save that touches `entry_type_id` would rewrite
        // the whole values blob for nothing.
        $entry = Entry::create([
            'entry_type_id' => $this->type->id, 'title' => 'Hello',
            'values' => ['body' => '<p>Kept</p>'],
        ]);

        $entry->update(['entry_type_id' => $this->type->id, 'title' => 'Retitled']);

        expect(storedValues($entry->fresh())['body'])->toBe('<p>Kept</p>');
    });
});
