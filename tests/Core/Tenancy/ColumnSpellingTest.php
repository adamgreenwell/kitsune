<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRelation;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Concerns\ResolvesWrittenColumns;
use Kitsune\Core\Tenancy\Context;

/*
 * A guarded column written under another spelling, on the two builders that kept their own copy of the comparison.
 *
 * SQLite, MySQL and MariaDB compare column names without regard to case, so `ORG_ID` IS `org_id` to the database.
 * `ScopedBuilder` learned that on #127; `GuardedRelationBuilder` (entry_relations) and `GuardedStorageBuilder`
 * (field_storage) each compared a written name EXACTLY against their own list, so an upper-cased guarded column
 * was a column they did not guard. And the model hooks behind them read each attribute by its own name, so a save
 * that sets `FIELD_STORAGE_ID` leaves `field_storage_id` untouched, passes every check, and the engine writes the
 * other one.
 *
 * Written from the attacker's side: every attempt here is refused when spelled `org_id`, and each is asserted
 * refused BY A GUARD — a database error would satisfy a bare RuntimeException, and PostgreSQL rejects a quoted
 * name it does not have, which is luck rather than a guard — and the tables are read below Eloquent afterwards.
 */

describe('the one comparison every guarded builder shares', function (): void {
    beforeEach(function (): void {
        $this->columns = new class
        {
            use ResolvesWrittenColumns {
                bareColumn as public;
                refuseAmbiguousColumns as public;
            }
        };
    });

    it('reads a written name as the column the database writes', function (string $written, string $column): void {
        expect($this->columns->bareColumn($written))->toBe($column);
    })->with([
        'as spelled' => ['org_id', 'org_id'],
        'upper-cased' => ['ORG_ID', 'org_id'],
        'qualified and mixed' => ['Entry_Relations.Org_Id', 'org_id'],
        'quoted by MySQL' => ['`field_storage`.`IS_LOCKED`', 'is_locked'],
        'quoted by PostgreSQL' => ['"field_storage"."Settings"', 'settings'],
        'quoted by SQL Server' => ['[org_id]', 'org_id'],
        'a JSON path' => ['settings->format', 'settings'],
        'a mis-cased JSON path' => ['SETTINGS->format', 'settings'],
        // The path comes off first: the last dot here is inside the path, not before the column.
        'a JSON path holding a dot' => ['settings->a.b', 'settings'],
        'a qualified JSON path holding a dot' => ['fs.Settings->a.b', 'settings'],
        // ASCII only, as every engine is: these are unknown columns on all three, measured.
        'an accented letter' => ['org_íd', 'org_íd'],
        'a dotted capital I' => ['ORG_İD', 'org_İd'],
    ]);

    it('refuses a column named twice, and allows several paths into one', function (): void {
        foreach ([
            'two spellings' => ['org_id' => 1, 'ORG_ID' => 2],
            'qualified beside bare' => ['entry_relations.org_id' => 1, 'org_id' => 1],
            'a whole column beside a path into it' => ['settings' => '{}', 'Settings->format' => 'integer'],
        ] as $shape => $values) {
            expect(fn () => $this->columns->refuseAmbiguousColumns($values))
                ->toThrow(RuntimeException::class, 'more than once', "{$shape} was allowed");
        }

        expect(fn () => $this->columns->refuseAmbiguousColumns(['settings->a' => 1, 'settings->b' => 2, 'org_id' => 1]))
            ->not->toThrow(RuntimeException::class);
    });
});

/**
 * Both guarded tables as the database holds them, read below every builder and scope.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function storedGuardedRows(): array
{
    return collect(['field_storage', 'entry_relations'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()
            ->map(fn (object $row): array => (array) $row)
            ->all()])
        ->all();
}

beforeEach(function (): void {
    $context = app(Context::class);

    $this->org = Org::create(['name' => 'Clinic', 'slug' => 'spelling-clinic']);
    $this->rival = Org::create(['name' => 'Rival', 'slug' => 'spelling-rival']);

    $context->setOrg($this->rival);
    $rivalSite = Site::create(['org_id' => $this->rival->id, 'handle' => 'rival', 'slug' => 'spelling-rival', 'name' => 'Rival']);
    $context->setSite($rivalSite);
    $rivalType = EntryType::create([
        'org_id' => $this->rival->id, 'handle' => 'patient', 'name' => 'Patient', 'plural_name' => 'Patients',
    ]);
    $this->rivalEntry = Entry::create(['entry_type_id' => $rivalType->id, 'title' => 'Theirs']);
    $this->rivalStorage = FieldStorage::create([
        'org_id' => $this->rival->id, 'handle' => 'links', 'type' => 'relation', 'pii_class' => 'none', 'cardinality' => -1,
    ]);

    $context->forget()->setOrg($this->org);
    $this->site = Site::create(['org_id' => $this->org->id, 'handle' => 'main', 'slug' => 'spelling-clinic', 'name' => 'Main']);
    $context->setSite($this->site);

    $this->patient = EntryType::create([
        'org_id' => $this->org->id, 'handle' => 'patient', 'name' => 'Patient', 'plural_name' => 'Patients',
    ]);
    $this->articleType = EntryType::create([
        'org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles',
    ]);
});

afterEach(fn () => app(Context::class)->forget());

describe('field storage', function (): void {
    beforeEach(function (): void {
        // Locked, as `lockStorageHoldingData()` leaves a field whose entries hold data (ADR-006).
        $this->locked = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'price', 'type' => 'number', 'pii_class' => 'none',
            'cardinality' => 1, 'is_locked' => true, 'settings' => ['format' => 'decimal'],
        ]);

        // Attached to a field of this org's type, so it cannot walk to another org (`guardOrgMove()`).
        $this->email = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'email', 'type' => 'text', 'pii_class' => 'personal', 'cardinality' => 1,
        ]);
        Field::create(['entry_type_id' => $this->patient->id, 'field_storage_id' => $this->email->id, 'label' => 'Email']);
    });

    it('refuses a guarded column under another spelling', function (Closure $attempt): void {
        $before = storedGuardedRows();
        $thrown = null;

        try {
            $attempt->call($this);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeInstanceOf(RuntimeException::class, 'the write was allowed')
            ->and($thrown)->not->toBeInstanceOf(QueryException::class, 'the database refused it, not a guard: '.$thrown?->getMessage());

        expect(storedGuardedRows())->toBe($before);
    })->with([
        // update(): every PER_ROW column, qualified or not, and the lock.
        'bulk ORG_ID' => [fn () => FieldStorage::query()->whereKey($this->locked->id)->update(['ORG_ID' => $this->rival->id])],
        'bulk PII_CLASS' => [fn () => FieldStorage::query()->whereKey($this->locked->id)->update(['PII_CLASS' => 'bogus'])],
        'bulk HANDLE on a locked field' => [fn () => FieldStorage::query()->whereKey($this->locked->id)->update(['HANDLE' => 'cost'])],
        'bulk Type on a locked field' => [fn () => FieldStorage::query()->whereKey($this->locked->id)->update(['Type' => 'text'])],
        'bulk CARDINALITY on a locked field' => [fn () => FieldStorage::query()->whereKey($this->locked->id)->update(['CARDINALITY' => 5])],
        'bulk SETTINGS on a locked field' => [fn () => FieldStorage::query()->whereKey($this->locked->id)->update(['SETTINGS' => '{"format":"integer"}'])],
        'bulk qualified Field_Storage.Org_Id' => [fn () => FieldStorage::query()->whereKey($this->locked->id)->update(['Field_Storage.Org_Id' => $this->rival->id])],
        'bulk IS_LOCKED cleared' => [fn () => FieldStorage::query()->whereKey($this->locked->id)->update(['IS_LOCKED' => false])],
        'bulk qualified field_storage.Is_Locked cleared' => [fn () => FieldStorage::query()->whereKey($this->locked->id)->update(['field_storage.Is_Locked' => 0])],
        // A JSON path is rooted at its column: `settings->format` writes `settings`, whatever the case.
        'bulk JSON path settings->format' => [fn () => FieldStorage::query()->whereKey($this->locked->id)->update(['settings->format' => 'integer'])],
        'bulk JSON path Settings->format' => [fn () => FieldStorage::query()->whereKey($this->locked->id)->update(['Settings->format' => 'integer'])],
        // The last-dot rule read this as the column `b`; MySQL and MariaDB write it into `settings`.
        'bulk JSON path with a dot' => [fn () => FieldStorage::query()->whereKey($this->locked->id)->update(['settings->a.b' => 'x'])],
        // The arithmetic family, and its `$extra` map of ordinary assignments.
        'increment CARDINALITY' => [fn () => FieldStorage::query()->whereKey($this->locked->id)->increment('CARDINALITY')],
        'decrement Cardinality' => [fn () => FieldStorage::query()->whereKey($this->locked->id)->decrement('Cardinality', 2)],
        'incrementEach CARDINALITY' => [fn () => FieldStorage::query()->whereKey($this->locked->id)->incrementEach(['CARDINALITY' => 1])],
        'increment extras ORG_ID' => [fn () => FieldStorage::query()->whereKey($this->locked->id)->increment('id', 0, ['ORG_ID' => $this->rival->id])],
        'increment extras Is_Locked cleared' => [fn () => FieldStorage::query()->whereKey($this->locked->id)->increment('id', 0, ['Is_Locked' => false])],
        'decrementEach extras IS_LOCKED cleared' => [fn () => FieldStorage::query()->whereKey($this->locked->id)->decrementEach(['id' => 0], ['IS_LOCKED' => false])],
        // insertGetId(): the per-row create path, which checks the row by attribute name.
        'insertGetId with SETTINGS a text field refuses' => [fn () => FieldStorage::query()->insertGetId([
            'org_id' => $this->org->id, 'handle' => 'notes', 'type' => 'text', 'pii_class' => 'none', 'cardinality' => 1,
            'SETTINGS' => '{"maxLength":100000}', 'created_at' => now(), 'updated_at' => now(),
        ])],
        'create with SETTINGS a text field refuses' => [fn () => FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'notes', 'type' => 'text', 'pii_class' => 'none', 'cardinality' => 1,
            'SETTINGS' => '{"maxLength":100000}',
        ])],
        'createQuietly with Settings a text field refuses' => [fn () => FieldStorage::createQuietly([
            'org_id' => $this->org->id, 'handle' => 'notes', 'type' => 'text', 'pii_class' => 'none', 'cardinality' => 1,
            'Settings' => '{"maxLength":100000}',
        ])],
        // A genuine model save: the hooks read the attribute by its name, and the engine writes the other one.
        'save IS_LOCKED cleared' => [fn () => $this->locked->fresh()->update(['IS_LOCKED' => false])],
        'save HANDLE on a locked field' => [fn () => $this->locked->fresh()->update(['HANDLE' => 'cost'])],
        'save TYPE on a locked field' => [fn () => $this->locked->fresh()->update(['TYPE' => 'text'])],
        'save Cardinality on a locked field' => [fn () => $this->locked->fresh()->update(['Cardinality' => -1])],
        'save Settings that move a locked projection' => [fn () => $this->locked->fresh()->update(['Settings' => '{"format":"integer"}'])],
        'save PII_CLASS' => [fn () => $this->locked->fresh()->update(['PII_CLASS' => 'bogus'])],
        'save ORG_ID from under an attached field' => [fn () => $this->email->fresh()->update(['ORG_ID' => $this->rival->id])],
        'quiet save HANDLE' => [fn () => $this->locked->fresh()->forceFill(['HANDLE' => 'cost'])->saveQuietly()],
        // Two names for one column: the guard read `is_locked`, and every engine keeps the LAST in an UPDATE.
        'save IS_LOCKED beside is_locked' => [fn () => $this->locked->fresh()->update(['is_locked' => true, 'IS_LOCKED' => false])],
    ]);

    it('still allows what it allowed, under any spelling', function (): void {
        // Arming a lock is the one bulk write the codebase needs, and a column no guard reads is nobody's business.
        $open = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'open', 'type' => 'text', 'pii_class' => 'none', 'cardinality' => 1,
        ]);

        FieldStorage::query()->whereKey($open->id)->update(['IS_LOCKED' => true, 'Is_Indexed' => true]);
        $this->email->fresh()->update(['handle' => 'contact']);

        expect(DB::table('field_storage')->where('id', $open->id)->first(['is_locked', 'is_indexed']))
            ->toEqual((object) ['is_locked' => 1, 'is_indexed' => 1])
            ->and(DB::table('field_storage')->where('id', $this->email->id)->value('handle'))->toBe('contact');
    })->skip(fn (): bool => DB::connection()->getDriverName() === 'pgsql', 'PostgreSQL has no column by another spelling');
});

describe('entry relations', function (): void {
    beforeEach(function (): void {
        // Single-valued and restricted to patients, which is the shape a nominated subject field has (ADR-020).
        $this->subject = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'subject', 'type' => 'relation', 'pii_class' => 'personal',
            'cardinality' => 1, 'settings' => ['targetTypes' => ['patient']],
        ]);
        $this->links = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'links', 'type' => 'relation', 'pii_class' => 'none', 'cardinality' => -1,
        ]);

        $this->src = Entry::create(['entry_type_id' => $this->patient->id, 'title' => 'Source']);
        $this->p1 = Entry::create(['entry_type_id' => $this->patient->id, 'title' => 'P1']);
        $this->p2 = Entry::create(['entry_type_id' => $this->patient->id, 'title' => 'P2']);
        $this->article = Entry::create(['entry_type_id' => $this->articleType->id, 'title' => 'An article']);

        // The subject field is full; the second row sits on the unlimited field, ready to be moved.
        $this->src->related()->attach($this->p1->id, ['field_storage_id' => $this->subject->id]);
        $this->src->related()->attach($this->p2->id, ['field_storage_id' => $this->links->id]);

        $this->held = EntryRelation::query()->where('target_entry_id', $this->p2->id)->sole();
        $this->named = EntryRelation::query()->where('target_entry_id', $this->p1->id)->sole();
    });

    it('refuses a guarded column under another spelling', function (Closure $attempt): void {
        $before = storedGuardedRows();
        $thrown = null;

        try {
            $attempt->call($this);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeInstanceOf(RuntimeException::class, 'the write was allowed')
            ->and($thrown)->not->toBeInstanceOf(QueryException::class, 'the database refused it, not a guard: '.$thrown?->getMessage());

        expect(storedGuardedRows())->toBe($before);
    })->with([
        // update(): every PER_ROW column, qualified or not.
        'bulk FIELD_STORAGE_ID onto a full single-valued field' => [fn () => EntryRelation::query()->whereKey($this->held->id)->update(['FIELD_STORAGE_ID' => $this->subject->id])],
        'bulk ORG_ID' => [fn () => EntryRelation::query()->whereKey($this->held->id)->update(['ORG_ID' => $this->rival->id])],
        'bulk Source_Entry_Id into another org' => [fn () => EntryRelation::query()->whereKey($this->held->id)->update(['Source_Entry_Id' => $this->rivalEntry->id])],
        'bulk TARGET_ENTRY_ID into another org' => [fn () => EntryRelation::query()->whereKey($this->held->id)->update(['TARGET_ENTRY_ID' => $this->rivalEntry->id])],
        'bulk qualified Entry_Relations.Org_Id' => [fn () => EntryRelation::query()->whereKey($this->held->id)->update(['Entry_Relations.Org_Id' => $this->rival->id])],
        // The arithmetic family, and its `$extra` map of ordinary assignments.
        'increment extras ORG_ID' => [fn () => EntryRelation::query()->whereKey($this->held->id)->increment('ordering', 1, ['ORG_ID' => $this->rival->id])],
        'increment SOURCE_ENTRY_ID' => [fn () => EntryRelation::query()->whereKey($this->held->id)->increment('SOURCE_ENTRY_ID')],
        'decrement Target_Entry_Id' => [fn () => EntryRelation::query()->whereKey($this->held->id)->decrement('Target_Entry_Id')],
        'incrementEach extras Field_Storage_Id' => [fn () => EntryRelation::query()->whereKey($this->held->id)->incrementEach(['ordering' => 1], ['Field_Storage_Id' => $this->subject->id])],
        'decrementEach FIELD_STORAGE_ID' => [fn () => EntryRelation::query()->whereKey($this->held->id)->decrementEach(['FIELD_STORAGE_ID' => 1])],
        // insertGetId(): the per-row create path. A second subject, of a type the field forbids.
        'insertGetId a second subject' => [fn () => EntryRelation::query()->insertGetId([
            'ORG_ID' => $this->org->id, 'SOURCE_ENTRY_ID' => $this->src->id, 'TARGET_ENTRY_ID' => $this->article->id,
            'FIELD_STORAGE_ID' => $this->subject->id, 'ordering' => 0,
        ])],
        'create a second subject' => [fn () => EntryRelation::create([
            'org_id' => $this->org->id, 'source_entry_id' => $this->src->id, 'target_entry_id' => $this->article->id,
            'Field_Storage_Id' => $this->subject->id,
        ])],
        'attach a second subject' => [fn () => $this->src->related()->attach($this->article->id, ['FIELD_STORAGE_ID' => $this->subject->id])],
        'attach on a rival org\'s storage' => [fn () => $this->src->related()->attach($this->article->id, ['Field_Storage_Id' => $this->rivalStorage->id])],
        'create with a source in another org' => [fn () => EntryRelation::create([
            'org_id' => $this->org->id, 'SOURCE_ENTRY_ID' => $this->rivalEntry->id, 'target_entry_id' => $this->p2->id,
        ])],
        // A genuine model save: the `updating` hook reads each attribute by its name.
        'save FIELD_STORAGE_ID onto a full single-valued field' => [fn () => $this->held->fresh()->update(['FIELD_STORAGE_ID' => $this->subject->id])],
        'save SOURCE_ENTRY_ID into another org' => [fn () => $this->held->fresh()->update(['SOURCE_ENTRY_ID' => $this->rivalEntry->id])],
        'save Target_Entry_Id of a forbidden type' => [fn () => $this->named->fresh()->update(['Target_Entry_Id' => $this->article->id])],
        'updateExistingPivot onto a full single-valued field' => [fn () => $this->src->related()->updateExistingPivot($this->p2->id, ['FIELD_STORAGE_ID' => $this->subject->id])],
        'quiet save FIELD_STORAGE_ID' => [fn () => EntryRelation::withoutEvents(fn () => $this->held->fresh()->update(['FIELD_STORAGE_ID' => $this->subject->id]))],
        // Two names for one column: the guard read `field_storage_id`, and SQLite keeps the FIRST in an INSERT.
        'insertGetId naming the storage twice' => [fn () => EntryRelation::query()->insertGetId([
            'FIELD_STORAGE_ID' => $this->subject->id, 'field_storage_id' => $this->links->id,
            'org_id' => $this->org->id, 'source_entry_id' => $this->src->id, 'target_entry_id' => $this->article->id,
        ])],
    ]);

    it('still allows what it allowed, under any spelling', function (): void {
        // `ordering` is permitted in bulk, and a relation attached under its own names still lands.
        EntryRelation::query()->whereKey($this->held->id)->update(['ORDERING' => 3]);
        $this->src->related()->attach($this->article->id, ['field_storage_id' => $this->links->id]);

        expect(DB::table('entry_relations')->where('id', $this->held->id)->value('ordering'))->toBe(3)
            ->and(DB::table('entry_relations')->where('target_entry_id', $this->article->id)->value('field_storage_id'))
            ->toBe($this->links->id);
    })->skip(fn (): bool => DB::connection()->getDriverName() === 'pgsql', 'PostgreSQL has no column by another spelling');
});
