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
