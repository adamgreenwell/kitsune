<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRelation;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Concerns\ResolvesWrittenColumns;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tenancy\ScopedBuilder;

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
 * And MySQL's fold is wider than ASCII: it takes `İ` to `i` and the Kelvin sign to `k`, so a name outside ASCII
 * reached a guarded column on every builder that folded with `strtolower()` — `ScopedBuilder` included. Those names
 * are refused outright now, and the cases below that spell one are live bypasses on MySQL alone. A relation's KEYS
 * are judged the same way — as the database stores them, which for a fractional id on MySQL and MariaDB is another row.
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
        // A key inside the JSON document is data, not a column name, and may hold anything.
        'a JSON path key outside ASCII' => ["settings->na\u{00EF}ve", 'settings'],
    ]);

    it('refuses a name outside ASCII rather than folding it', function (string $written): void {
        expect(fn () => $this->columns->bareColumn($written))->toThrow(RuntimeException::class, 'outside ASCII');
    })->with([
        /*
         * MySQL 8.4 folds a dotted capital I onto `i` and the Kelvin sign onto `k`, so to it each of these IS the
         * guarded column — measured through PDO with utf8mb4, the charset Laravel connects with. `strtolower()`
         * folds neither, so a guard comparing the folded names saw a column it does not guard.
         */
        'a dotted capital I' => ["ORG_\u{0130}D"],
        'the Kelvin sign' => ["is_loc\u{212A}ed"],
        'qualified' => ["field_storage.\u{0130}S_LOCKED"],
        'before a JSON path' => ["SETT\u{0130}NGS->format"],
        // Unknown columns on every engine, and refused all the same: the guard need not know which characters fold.
        'an accented letter' => ["org_\u{00ED}d"],
        'a fullwidth letter' => ["\u{FF4F}rg_id"],
    ]);

    it('refuses a name outside ASCII beside its own column, before any duplicate is judged', function (): void {
        // Two keys to PHP, one column to MySQL — and whichever value it keeps, no guard compared it.
        expect(fn () => $this->columns->refuseAmbiguousColumns(['org_id' => 1, "ORG_\u{0130}D" => 2]))
            ->toThrow(RuntimeException::class, 'outside ASCII');
    });

    it('adds no inherited name to the builder every scoped model gets', function (): void {
        /*
         * A plugin subclasses `ScopedBuilder` to give a scoped model a builder of its own, so a protected method here
         * is a name that subclass inherits and collides with. `bareColumn()` was protected before the trait and is
         * still; the two refusals were private and are still — measured, a plugin subclass declaring a private
         * `refuseMisnamedGuardedColumn()` failed to load while the refusal was protected.
         */
        $method = fn (string $name): ReflectionMethod => new ReflectionMethod(ScopedBuilder::class, $name);

        expect($method('bareColumn')->isProtected())->toBeTrue()
            ->and($method('refuseMisnamedGuardedColumn')->isPrivate())->toBeTrue()
            ->and($method('refuseAmbiguousColumns')->isPrivate())->toBeTrue();
    });

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
    return storedRows(['field_storage', 'entry_relations']);
}

/**
 * These tables as the database holds them, read below every builder and scope.
 *
 * @param  list<string>  $tables
 * @return array<string, list<array<string, mixed>>>
 */
function storedRows(array $tables): array
{
    return collect($tables)
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
        // Outside ASCII: MySQL folds `İ` onto `i` and the Kelvin sign onto `k`, so these reach the guarded columns.
        "bulk \u{0130}S_LOCKED cleared" => [fn () => FieldStorage::query()->whereKey($this->locked->id)->update(["\u{0130}S_LOCKED" => false])],
        'bulk is_locked cleared, with the Kelvin sign' => [fn () => FieldStorage::query()->whereKey($this->locked->id)->update(["is_loc\u{212A}ed" => false])],
        "bulk ORG_\u{0130}D" => [fn () => FieldStorage::query()->whereKey($this->locked->id)->update(["ORG_\u{0130}D" => $this->rival->id])],
        "bulk P\u{0130}\u{0130}_CLASS" => [fn () => FieldStorage::query()->whereKey($this->locked->id)->update(["P\u{0130}\u{0130}_CLASS" => 'bogus'])],
        "bulk SETT\u{0130}NGS on a locked field" => [fn () => FieldStorage::query()->whereKey($this->locked->id)->update(["SETT\u{0130}NGS" => '{"format":"integer"}'])],
        "increment CARD\u{0130}NAL\u{0130}TY" => [fn () => FieldStorage::query()->whereKey($this->locked->id)->increment("CARD\u{0130}NAL\u{0130}TY")],
        "save \u{0130}S_LOCKED cleared" => [fn () => $this->locked->fresh()->update(["\u{0130}S_LOCKED" => false])],
        // Two names for one column: the guard read `is_locked`, and every engine keeps the LAST in an UPDATE.
        'save IS_LOCKED beside is_locked' => [fn () => $this->locked->fresh()->update(['is_locked' => true, 'IS_LOCKED' => false])],
    ]);

    it('refuses clearing a lock in bulk, whatever PHP makes of the value', function (Closure $attempt): void {
        /*
         * ⚠️ THE GUARD ASKED `(bool) $value === false`, WHICH IS PHP'S QUESTION AND NOT THE DATABASE'S. An object is
         * true to PHP, and so are `'00'`, `'0.0'`, `' 0'`, `'-0'` and `'0e0'` — and SQLite, MySQL and MariaDB store
         * each of them as 0. PostgreSQL stores `'false'`, `'off'`, `'no'` and `'f'` as false. Each cleared a locked
         * field's lock, after which an ordinary save renamed it. And the arithmetic doors pass an AMOUNT, not a
         * destination, so `decrement('is_locked')` cleared it on three engines with no value to judge at all.
         */
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
        'a raw 0' => [fn () => FieldStorage::query()->whereKey($this->locked->id)->update(['is_locked' => DB::raw('0')])],
        'a raw false' => [fn () => FieldStorage::query()->whereKey($this->locked->id)->update(['is_locked' => DB::raw('false')])],
        "'00'" => [fn () => FieldStorage::query()->whereKey($this->locked->id)->update(['is_locked' => '00'])],
        "'0.0'" => [fn () => FieldStorage::query()->whereKey($this->locked->id)->update(['is_locked' => '0.0'])],
        "' 0'" => [fn () => FieldStorage::query()->whereKey($this->locked->id)->update(['is_locked' => ' 0'])],
        "'-0'" => [fn () => FieldStorage::query()->whereKey($this->locked->id)->update(['is_locked' => '-0'])],
        "'0e0'" => [fn () => FieldStorage::query()->whereKey($this->locked->id)->update(['is_locked' => '0e0'])],
        "'false'" => [fn () => FieldStorage::query()->whereKey($this->locked->id)->update(['is_locked' => 'false'])],
        "'off'" => [fn () => FieldStorage::query()->whereKey($this->locked->id)->update(['is_locked' => 'off'])],
        "'f'" => [fn () => FieldStorage::query()->whereKey($this->locked->id)->update(['is_locked' => 'f'])],
        'arithmetic extras' => [fn () => FieldStorage::query()->whereKey($this->locked->id)->increment('id', 0, ['is_locked' => '00'])],
        'decrement' => [fn () => FieldStorage::query()->whereKey($this->locked->id)->decrement('is_locked')],
        'increment by -1' => [fn () => FieldStorage::query()->whereKey($this->locked->id)->increment('is_locked', -1)],
        'incrementEach' => [fn () => FieldStorage::query()->whereKey($this->locked->id)->incrementEach(['is_locked' => -1])],
        'decrementEach' => [fn () => FieldStorage::query()->whereKey($this->locked->id)->decrementEach(['is_locked' => 1])],
    ]);

    it('still arms a lock in bulk, with the values that can only mean true', function (mixed $armed): void {
        $open = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'open', 'type' => 'text', 'pii_class' => 'none', 'cardinality' => 1,
        ]);

        FieldStorage::query()->whereKey($open->id)->update(['is_locked' => $armed]);

        expect((bool) DB::table('field_storage')->where('id', $open->id)->value('is_locked'))->toBeTrue();
    })->with([
        'true' => [true],
        '1' => [1],
        "'1'" => ['1'],
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
        // Outside ASCII: MySQL folds `İ` onto `i`, so these reach the guarded columns.
        "bulk F\u{0130}ELD_STORAGE_\u{0130}D onto a full single-valued field" => [fn () => EntryRelation::query()->whereKey($this->held->id)->update(["F\u{0130}ELD_STORAGE_\u{0130}D" => $this->subject->id])],
        "bulk ORG_\u{0130}D" => [fn () => EntryRelation::query()->whereKey($this->held->id)->update(["ORG_\u{0130}D" => $this->rival->id])],
        "save F\u{0130}ELD_STORAGE_\u{0130}D onto a full single-valued field" => [fn () => $this->held->fresh()->update(["F\u{0130}ELD_STORAGE_\u{0130}D" => $this->subject->id])],
        "attach a second subject under F\u{0130}ELD_STORAGE_\u{0130}D" => [fn () => $this->src->related()->attach($this->article->id, ["F\u{0130}ELD_STORAGE_\u{0130}D" => $this->subject->id])],
        // Two names for one column: the guard read `field_storage_id`, and SQLite keeps the FIRST in an INSERT.
        'insertGetId naming the storage twice' => [fn () => EntryRelation::query()->insertGetId([
            'FIELD_STORAGE_ID' => $this->subject->id, 'field_storage_id' => $this->links->id,
            'org_id' => $this->org->id, 'source_entry_id' => $this->src->id, 'target_entry_id' => $this->article->id,
        ])],
    ]);

    it('refuses a key that is not a whole id, which MySQL and MariaDB round onto another row', function (Closure $attempt): void {
        /*
         * ⚠️ THE GUARDS LOOKED THE KEY UP, AND THE ENGINE ROUNDED IT. `guardStorageOwnership()` asked for the storage
         * `'5.4'` names, found none — MySQL and MariaDB compare `id = '5.4'` as a number — and returned as though the
         * storage were global; cardinality and target type did the same. Then the engine stored 5, the rival org's
         * storage. And the org stamp was compared as `(int) '1.9' === 1` and stored as 2. Measured on both. SQLite and
         * PostgreSQL refuse the fraction themselves, which is luck, not a guard.
         */
        expect($this->rival->id)->toBe($this->org->id + 1);

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
        'attach on a rival org\'s storage' => [fn () => $this->src->related()->attach($this->article->id, ['field_storage_id' => $this->rivalStorage->id.'.4'])],
        'create on a rival org\'s storage' => [fn () => EntryRelation::create([
            'org_id' => $this->org->id, 'source_entry_id' => $this->src->id, 'target_entry_id' => $this->article->id,
            'field_storage_id' => $this->rivalStorage->id.'.4',
        ])],
        'save onto a rival org\'s storage' => [fn () => $this->held->fresh()->update(['field_storage_id' => $this->rivalStorage->id.'.4'])],
        'a stamp that rounds into the rival org' => [fn () => $this->src->related()->attach($this->article->id, [
            'field_storage_id' => $this->links->id, 'org_id' => $this->org->id.'.9',
        ])],
        'a source that rounds onto another entry' => [fn () => EntryRelation::create([
            'org_id' => $this->org->id, 'source_entry_id' => $this->src->id.'.4', 'target_entry_id' => $this->article->id,
            'field_storage_id' => $this->links->id,
        ])],
        // A whole id naming no storage: every check stood aside as though it were global, and only the foreign key spoke.
        'a storage that does not exist' => [fn () => $this->src->related()->attach($this->article->id, [
            'field_storage_id' => (int) DB::table('field_storage')->max('id') + 1000,
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

describe('a column spelled outside ASCII, on the builders every other model shares', function (): void {
    beforeEach(function (): void {
        // A site that claims an address, a field with its storage, and a role — each guarding a column with an `i`.
        $this->claimed = Site::create([
            'org_id' => $this->org->id, 'handle' => 'claimed', 'slug' => 'spelling-claimed', 'name' => 'Claimed',
            'url_strategy' => 'domain', 'base_url' => 'https://claimed.spelling.test',
        ]);
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'summary', 'type' => 'text', 'pii_class' => 'none', 'cardinality' => 1,
        ]);
        $this->other = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'other', 'type' => 'text', 'pii_class' => 'none', 'cardinality' => 1,
        ]);
        $this->field = Field::create(['entry_type_id' => $this->patient->id, 'field_storage_id' => $storage->id, 'label' => 'Summary']);
        $this->role = Role::create(['handle' => 'spelling-editor', 'name' => 'Editor']);
    });

    it('refuses the column rather than letting MySQL fold it onto a guarded one', function (Closure $attempt): void {
        $tables = ['orgs', 'sites', 'entry_types', 'fields', 'field_storage', 'roles', 'audit_log', 'entries'];
        $before = storedRows($tables);
        $thrown = null;

        try {
            $attempt->call($this);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeInstanceOf(RuntimeException::class, 'the write was allowed')
            ->and($thrown)->not->toBeInstanceOf(QueryException::class, 'the database refused it, not a guard: '.$thrown?->getMessage());

        expect(storedRows($tables))->toBe($before);
    })->with([
        // `ScopedBuilder`'s scope keys: a row moved into another org, from this org's context.
        "a site moved by ORG_\u{0130}D" => [fn () => Site::query()->whereKey($this->claimed->id)->update(["ORG_\u{0130}D" => $this->rival->id])],
        "a site's address taken by CANON\u{0130}CAL_HOST" => [fn () => Site::query()->whereKey($this->claimed->id)->update(["CANON\u{0130}CAL_HOST" => 'stolen.example.test'])],
        "a site's prefix moved by PATH_PREF\u{0130}X" => [fn () => Site::query()->whereKey($this->claimed->id)->update(["PATH_PREF\u{0130}X" => 'x'])],
        "a site's settings replaced by SETT\u{0130}NGS" => [fn () => Site::query()->whereKey($this->claimed->id)->update(["SETT\u{0130}NGS" => '"not a map"'])],
        "an org's settings replaced by SETT\u{0130}NGS" => [fn () => Org::query()->whereKey($this->org->id)->update(["SETT\u{0130}NGS" => '"not a map"'])],
        "a field repointed by F\u{0130}ELD_STORAGE_\u{0130}D" => [fn () => Field::query()->whereKey($this->field->id)->update(["F\u{0130}ELD_STORAGE_\u{0130}D" => $this->other->id])],
        "a field moved by ENTRY_TYPE_\u{0130}D" => [fn () => Field::query()->whereKey($this->field->id)->update(["ENTRY_TYPE_\u{0130}D" => $this->articleType->id])],
        "a type moved by ORG_\u{0130}D" => [fn () => EntryType::query()->whereKey($this->articleType->id)->update(["ORG_\u{0130}D" => $this->rival->id])],
        "a subject nominated by SUBJECT_F\u{0130}ELD_\u{0130}D" => [fn () => EntryType::query()->whereKey($this->patient->id)->update(["SUBJECT_F\u{0130}ELD_\u{0130}D" => $this->field->id])],
        "a role promoted by \u{0130}S_OWNER" => [fn () => Role::query()->update(["\u{0130}S_OWNER" => true])],
        "a role promoted by a save of \u{0130}S_OWNER" => [fn () => $this->role->fresh()->update(["\u{0130}S_OWNER" => true])],
        "an audit row appended to another org by ORG_\u{0130}D" => [fn () => AuditLog::query()->insert([["ORG_\u{0130}D" => $this->rival->id, 'action' => 'forged.spelled', 'created_at' => now()]])],
        "an entry moved by S\u{0130}TE_\u{0130}D" => [fn () => Entry::query()->update(["S\u{0130}TE_\u{0130}D" => null])],
        "an entry retyped by ENTRY_TYPE_\u{0130}D" => [fn () => Entry::query()->update(["ENTRY_TYPE_\u{0130}D" => $this->articleType->id])],
    ]);
});
