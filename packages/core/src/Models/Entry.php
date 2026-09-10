<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Audit\AuditedBuilder;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Relations\GuardedBelongsToMany;
use Kitsune\Core\Schema\RecordedRevisions;
use Kitsune\Core\Schema\RevisionWrites;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Contracts\RequiresModelSave;
use RuntimeException;

/**
 * One model for every user-defined entity type (ADR-010).
 *
 * Not a preference — forced. Filament caches a one-model-to-one-Resource map
 * and keys route-model binding and policies off getModel(), which must be
 * constant. Discovering that in month ten would have meant a rewrite.
 *
 * Consequences carried here: type is a discriminator column, values live in
 * JSON, per-type indexes need generated columns, and relations need a real
 * table. title/slug/status/published_at are promoted to real columns because
 * every list view, URL resolution and status filter touches them (ADR-015).
 *
 * @property int $id
 * @property int|null $site_id
 * @property int $org_id
 * @property int $entry_type_id
 * @property string $type_handle
 * @property int|null $author_id
 * @property string|null $slug
 * @property string|null $title
 * @property string $status
 * @property array<string, mixed>|null $values
 * @property array<string, int>|null $promoted_by
 */
#[SiteScoped]
class Entry extends Model implements RequiresModelSave
{
    use EnforcesScope;
    use SoftDeletes;

    /**
     * Mirrors the column default, so the in-memory model is not lying.
     *
     * Without it a freshly created entry has no `status` until it is read
     * back, which made the revision snapshot record NULL for a column the
     * database declares NOT NULL.
     */
    protected $attributes = ['status' => 'draft'];

    /**
     * The columns whose change constitutes a new version.
     *
     * ⚠️ One definition, because there are now TWO recorders — the model
     * events and the builder — and a versioned surface defined twice drifts.
     * `EntryRevision::SNAPSHOT_ATTRIBUTES` says what a revision stores; this
     * says what makes one, and `values` is in the second list only.
     *
     * @var list<string>
     */
    public const VERSIONED_COLUMNS = [...EntryRevision::SNAPSHOT_ATTRIBUTES, 'values'];

    protected $guarded = [];

    protected $casts = [
        'values' => 'array',
        'promoted_by' => 'array',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // ⚠️ Recorded BEFORE any guard reads a promoted column, and recorded rather
        // than derived. See `recordPromotedProvenance()`.
        static::saving(fn (self $entry) => $entry->recordPromotedProvenance());

        // type_handle is denormalised for routing lookups, so it must never
        // disagree with the type it points at.
        // ⚠️ A SITE change revalidates the relations pointing at this entry.
        //
        // The type veto ignores relations whose source is not visible from
        // here, which is right — otherwise one site could freeze another's
        // records. But that leaves a sequence: move a valid target to site B,
        // change it there to a type site A's field forbids (A's pivot is
        // invisible, so nothing objects), then move it back to A without
        // touching `type_handle`. No pivot guard runs on the final move, and
        // the relation resurfaces with a forbidden target — treated again as
        // the nominated subject.
        //
        // Checked on the way back in, scoped to the entry's own org so this
        // cannot become a way for one org to pin another's rows.
        static::saving(function (self $entry): void {
            if (! $entry->exists || ! $entry->isDirty('site_id')) {
                return;
            }

            $field = EntryRelation::forbidsTypeChange(
                (int) $entry->getKey(),
                (string) $entry->type_handle,
                (int) $entry->org_id,
                visibleOnly: false,
            );

            if ($field !== null) {
                throw new RuntimeException(
                    "Entry {$entry->getKey()} cannot move here: field [{$field}] relates to it and "
                    ."does not accept a [{$entry->type_handle}]. Its relation is invisible from "
                    .'where it is now, so moving it back would resurface a target that field '
                    .'refuses (ADR-020). Detach the relation first.'
                );
            }
        });

        static::saving(function (self $entry): void {
            // ⚠️ `type_handle` too, not only `entry_type_id`.
            //
            // The handle is DENORMALISED and fully mass assignable, and every
            // relational read and write resolves a target's type through it —
            // `guardTargetType()`, `forbidsTypeChange()`,
            // `RelationType::elementValidationRules()` and `scopeOfType()` all
            // read the handle, never the id. So `$entry->update(['type_handle'
            // => 'article'])` reached exactly the state this guard exists to
            // prevent, without the guard running: no restamp, no relation
            // check, and a field configured to accept `patient` went on naming
            // a target that now claims to be an `article`.
            //
            // A forged handle is now overwritten rather than merely refused,
            // because the column is derived: whatever the caller wrote, the
            // type it points at is the truth.
            if (! $entry->isDirty(['entry_type_id', 'type_handle'])) {
                return;
            }

            $entry->type_handle = EntryType::query()
                ->whereKey($entry->entry_type_id)
                ->value('handle') ?? $entry->type_handle;

            // ⚠️ Changing an entry's type can invalidate relations that point
            // AT it, and nothing was checking: the pivot guards only run when
            // a pivot changes. A field configured to accept `person` would go
            // on naming a target that had since become an `article`.
            if ($entry->exists
                && ($field = EntryRelation::forbidsTypeChange(
                    (int) $entry->getKey(),
                    (string) $entry->type_handle,
                    (int) $entry->org_id,
                )) !== null) {
                throw new RuntimeException(
                    "Entry {$entry->getKey()} cannot become a [{$entry->type_handle}]: field "
                    ."[{$field}] relates to it and does not accept that type. Detach the relation "
                    .'first — leaving it would point a configured field at something it refuses, '
                    .'and a subject identifier at the wrong kind of record (ADR-020).'
                );
            }
        });

        // ⚠️ The lock has to be SET by something, and nothing was setting it.
        //
        // ADR-006 says storage locks the moment data exists, and FieldStorage
        // has carried the guard since the first commit — but `is_locked`
        // defaulted to false and only tests ever wrote it. So every field in
        // every install was unlocked while holding content, and the guard
        // that refuses a decimal-to-integer change or a narrowed text field
        // was reachable only by a caller who had remembered to set the flag
        // by hand. A lock nobody arms is a comment.
        static::saved(fn (self $entry) => $entry->lockStorageHoldingData());

        // A revision per saved version, recorded AFTER the write.
        //
        // After, not before: a revision describing a save that then failed is
        // a history of things that never happened. And the entry row is the
        // current version rather than a pointer into history, so revision N
        // is a snapshot of what the entry became — the shape that answers
        // "what did this say last Tuesday" directly.
        // ⚠️ created and updated separately, NOT saved + wasRecentlyCreated.
        // That flag stays true for the lifetime of the instance, so every
        // later save on a freshly created entry recorded another revision —
        // including saves that changed nothing versioned at all.
        // ⚠️ There is deliberately NO `created` revision listener either, for the
        // reason the `updated` one went: it fires after `insertGetId()` has
        // returned and its transaction committed, so a concurrent updater can
        // commit and record version B before the initial version A is written —
        // leaving A newest while the live entry is B. Creation is recorded inside
        // the insert transaction, in `AuditedBuilder::insertGetId()`.

        // ⚠️ There is deliberately NO `updated` revision listener.
        //
        // It fired after `AuditedBuilder` had committed and released its row
        // lock, so two writers interleaved: T1 commits A, T2 commits and records
        // B, then T1 records A — leaving revision A newest while the live entry
        // is B, and a relation write in the same window could contaminate A's
        // snapshot. A version has to be recorded under the lock that made the
        // write atomic.
        //
        // Every update — instance, quiet, or bulk — is therefore recorded inside
        // the builder's transaction. `created` stays here because an insert has
        // no prior state to race with, and the builder covers the quiet case.
    }

    /**
     * Lock every storage row this entry now holds data for.
     *
     * Cheap once it has done its work: the query returns nothing as soon as a
     * type's fields are locked, which is the steady state. Written in bulk so
     * it does not re-enter FieldStorage's own guards, which have nothing to
     * check here — `is_locked` is not a shape attribute.
     *
     * ⚠️ All THREE strategies, not just inline. A promoted field's data is a
     * real column and a relational field's is rows in `entry_relations`, so
     * checking `values` alone would have left a `slug` or a `person` field
     * unlocked forever while holding content — the same emptiness the lock
     * had before anything set it, narrowed rather than fixed.
     */
    private function lockStorageHoldingData(): void
    {
        $candidates = FieldStorage::query()
            ->where('is_locked', false)
            ->whereHas('fields', fn (Builder $query) => $query->where('entry_type_id', $this->entry_type_id))
            ->get();

        if ($candidates->isEmpty()) {
            return;
        }

        $values = $this->values ?? [];

        $holding = $candidates->filter(function (FieldStorage $storage) use ($values): bool {
            return match ($storage->strategy()) {
                StorageStrategy::Promoted => $this->hasValueFor((string) $storage->promotedColumn()),
                StorageStrategy::Relational => $this->related()
                    ->wherePivot('field_storage_id', $storage->getKey())
                    ->exists(),
                StorageStrategy::Inline => $this->hasValueFor(null, $values[$storage->handle] ?? null),
            };
        });

        if ($holding->isEmpty()) {
            return;
        }

        // In bulk, so this does not re-enter FieldStorage's own guards —
        // which have nothing to check here, `is_locked` not being a shape
        // attribute.
        FieldStorage::query()->whereKey($holding->modelKeys())->update(['is_locked' => true]);
    }

    /** An empty string or an empty array is not data any more than null is. */
    private function hasValueFor(?string $column, mixed $value = null): bool
    {
        $value = $column !== null ? $this->getAttribute($column) : $value;

        return $value !== null && $value !== '' && $value !== [];
    }

    /**
     * ⚠️ Columns whose guards can only run per row.
     *
     * `type_handle` is DERIVED from `entry_type_id`, and every relational read
     * resolves a target's type through it. A bulk write bypassed the restamp
     * and the relation veto both, so a forged handle reached
     * `guardTargetType()`, `forbidsTypeChange()` and the subject queries.
     *
     * @return array<string, string>
     */
    public static function columnsRequiringModelSave(): array
    {
        return [
            'type_handle' => 'it is derived from entry_type_id, and a bulk write skips the restamp that keeps them agreeing.',
            // ⚠️ `values` because the value-conversion pipeline runs in `saving`.
            //
            // `FieldType::toStorage()` is what sanitises rich text, and a bulk write
            // dispatches nothing — so it would store exactly the bytes it was given,
            // `<script>` included (issue #42, field-types.md §6). The model is fully
            // mass assignable, so the only mechanism that holds is refusing the shape
            // that skips the conversion.
            //
            // This does not touch the writes that legitimately set `values` directly:
            // erasure and restore go through an instance save, which runs the
            // pipeline, and `ScopeWrites::suspended()` covers the internal paths.
            'values' => 'every value is converted through its field type on save, and rich text is '
                .'sanitized there — a bulk write dispatches nothing, so it would store what it was '
                .'handed.',
        ];
    }

    /**
     * ⚠️ Every write goes through the audited builder, which is itself a
     * ScopedBuilder — Entry needs the tenancy guards and the audit trail, and
     * a model has only one builder (ADR-020).
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    public function newEloquentBuilder($query): AuditedBuilder
    {
        return new AuditedBuilder($query, $this);
    }

    /**
     * This entry's outgoing relations, as a revision stores them.
     *
     * ⚠️ ADR-006 has THREE storage strategies and this project has already been
     * burned twice by code that handled two: a subject identifier that answered
     * with null for a relational field, and an erasure that reported success
     * while every link survived. A revision omitting relations was the same
     * omission a third time.
     *
     * Shaped `{field_storage_id: [target ids, in order]}`. Ordered explicitly,
     * because `ordering` is what the author arranged and restoring a set without
     * it would silently reshuffle a list of authors.
     *
     * Rows whose `field_storage_id` is NULL are skipped. That column is
     * `nullOnDelete`, so a null means the field definition is gone — there is no
     * field left to restore them into, and inventing one would be worse than
     * recording the loss.
     *
     * @return array<string, list<int>>
     */
    public function relationState(): array
    {
        if (! $this->exists) {
            return [];
        }

        $state = [];

        $rows = EntryRelation::query()
            ->where('source_entry_id', $this->getKey())
            ->whereNotNull('field_storage_id')
            ->orderBy('field_storage_id')
            ->orderBy('ordering')
            ->orderBy('target_entry_id')
            ->get(['field_storage_id', 'target_entry_id']);

        foreach ($rows as $row) {
            $state[(string) $row->field_storage_id][] = (int) $row->target_entry_id;
        }

        return $state;
    }

    /**
     * Record a revision when a PIVOT write changed this entry's relations.
     *
     * ⚠️ A pivot write fires no `Entry` event, so a relation change filed no
     * version at all — and the newest revision then no longer described the
     * entry, which makes "restore the latest version" silently revert it. Same
     * shape as the bulk-update gap, one storage strategy along.
     *
     * @param  array<string, list<int>>  $before
     */
    public function recordRevisionForRelationChange(array $before): bool
    {
        if (RevisionWrites::suspended() || $this->relationState() === $before) {
            return false;
        }

        $this->recordRevision();

        return true;
    }

    /**
     * Writes this entry's relations and reconciles the save's single revision, as one unit.
     *
     * ⚠️ ONE TRANSACTION AND ONE LOCK ACROSS BOTH HALVES, because the reconcile READS the relation
     * state back and a concurrent save can change it in between. Measured before this existed, with
     * a second save's sync landing in the window:
     *
     *     A chose [1]; B chose [2]; A's revision recorded [2]  => MIS-RECORDED
     *
     * `versioned()` locks the entry for each individual sync, so the writes themselves serialise —
     * but that lock is released when its transaction commits, and a reconcile outside it re-reads
     * whatever the last writer left. A's own revision then asserted B's relations: not a lost
     * write, but a false record of what an author did, which for a history is the same damage.
     *
     * Holding the lock here covers every field's sync AND the reconcile, which is the only span
     * over which "the relations as THIS save left them" is a meaningful thing to read.
     * `versioned()`'s own transaction nests as a savepoint and its lock is a no-op inside this one.
     *
     * ⚠️ ON THE MODEL RATHER THAN IN `SyncsFieldRelations`, so it can be tested. The trait's method
     * needs a Filament form to reach, and a test that reproduced the transaction shape itself would
     * assert a property of its own code rather than of this — which is how the first version of that
     * test came to pass whatever the trait did.
     *
     * @param  callable():void  $sync
     */
    public function writeRelationsAndReconcile(callable $sync): void
    {
        DB::transaction(function () use ($sync): void {
            // withoutGlobalScopes, as in `versioned()`: this is a lock rather than a read that
            // reaches a caller, and a scoped query that matched nothing would take no lock at all.
            self::query()->withoutGlobalScopes()->whereKey($this->getKey())->lockForUpdate()->get();

            /*
             * ⚠️ READ INSIDE THE LOCK, and it used to be passed in from outside — which review found.
             * A relations-only save read this before the transaction opened, so: it read X, another
             * request changed the relations to Y, and this request then wrote X back. The fallback
             * comparison saw final X equal to the before-state, filed nothing, and history was left
             * with a revision describing Y while the live entry held X.
             *
             * The whole point of the lock is that the relations do not move under this save, and a
             * before-state read outside it is a value from before that guarantee started. So the
             * parameter is gone rather than documented: a caller cannot pass a stale one if there is
             * nothing to pass.
             */
            $relationsBefore = $this->relationState();

            RevisionWrites::suspend($sync);

            $this->reconcileRevisionAfterRelationSync($relationsBefore);
        });
    }

    /**
     * Makes ONE revision represent a form save, after its relations have been written.
     *
     * ⚠️ A FORM SAVE WAS FILING 1 + N REVISIONS, one per relation field (issue #59). Measured:
     * `created=1  afterRelationSync=2  afterSecondField=3`. Two write paths each legitimately
     * file one — the entry write through the model event, and `GuardedBelongsToMany::sync()`
     * through `recordRevisionForRelationChange()` — and neither is wrong alone. What is new is
     * that a FORM save does both, because relation state is written after the entry exists
     * (ADR-015). The cost is phantom history and a 50-version budget consumed at 2x or worse.
     *
     * ⚠️ SUPPRESSING THE SYNC'S REVISION ALONE IS NOT THE FIX, and this method exists because
     * both obvious answers are wrong:
     *
     * - `entry_revisions` has a `relation_state` column, so a revision DOES capture relations.
     *   The entry write's snapshot is taken BEFORE they are written, so keeping only that one
     *   leaves history asserting "no relations" for the save that added them.
     * - Suspending both and always filing one at the end loses the change entirely on an edit
     *   where relations are the ONLY thing that changed: the entry write is not dirty, so it
     *   files nothing, and the sync's revision was the sole record.
     *
     * So the reconciler asks the WRITER which revision it filed, rather than inferring it: if this
     * save filed one, its relation state is completed in place — the same operation `redactField()`
     * already performs on a revision, so mutating one is precedented rather than a new liberty. If
     * it filed none, relations were the only change and one is recorded now.
     *
     * @param  array<string, list<int>>  $relationsBefore
     */
    public function reconcileRevisionAfterRelationSync(array $relationsBefore): bool
    {
        if (RevisionWrites::suspended()) {
            return false;
        }

        /*
         * ⚠️ THE REGISTER, NOT A GUESS, and review is the reason. Two guesses were tried and both
         * are wrong:
         *
         * - An id comparison — "the newest revision is newer than the one before my write" — completes
         *   SOMEBODY ELSE'S revision when a concurrent save files one in that window. The entry write
         *   and each relation sync are separate transactions, so the window is real.
         * - A STATE comparison — "the newest revision already describes the entry as this save left
         *   it" — replaced it, and review found the hole: the comparison covers `VERSIONED_COLUMNS`
         *   and CANNOT cover `relation_state`, because the relations are what is being written. Two
         *   editors saving the same title and different relations both concluded the newest revision
         *   was theirs; the second overwrote the first's relation snapshot, and one save disappeared
         *   from history. The docblock here claimed that case was "harmless". It was not.
         *
         * `RecordedRevisions` holds what this process actually wrote, so a concurrent request's
         * revision can never be mistaken for this one's — there is nothing to infer.
         */
        $mine = app(RecordedRevisions::class)->take((int) $this->getKey());

        if ($mine !== null) {
            $revision = $this->revisions()->whereKey($mine)->first();

            /*
             * ⚠️ Null is possible and is not an error: `pruneRevisions()` runs in the same write and
             * a 50-version budget can retire the revision just filed. Falling through records the
             * relation change on its own, which is the honest answer when the snapshot is gone.
             */
            /*
             * ⚠️ OWNING IT IS NOT ENOUGH — IT MUST STILL BE THE NEWEST, which review found. Save A
             * files its scalar revision; save B then completes a NEWER scalar-and-relation revision;
             * A finally syncs its relations. Completing A's older revision then leaves history ending
             * with B's, claiming B's relations, while A's later relation write is what is live. The
             * newest revision has to describe the newest state.
             *
             * Overtaken, this falls through and records a revision for the current state instead —
             * which is the same answer as "relations were the only change", because from history's
             * point of view that is exactly what this save now is.
             */
            $newest = $this->revisions()->max('id');

            if ($revision instanceof EntryRevision && (int) $newest === (int) $mine) {
                $current = $this->relationState();

                if ($revision->relation_state === $current) {
                    return false;
                }

                $revision->relation_state = $current;
                $revision->save();

                return true;
            }
        }

        /*
         * Nothing was filed, so the relations were the only change — and this is the case that
         * makes blanket suppression wrong. `recordRevisionForRelationChange()` still compares
         * against `$relationsBefore`, so a sync that changed nothing files nothing.
         *
         * ⚠️ FROM THE LOCKED ROW, NOT FROM THIS INSTANCE, which review found — and it is the same
         * defect as the overtaken check above, one layer down. `recordRevision()` snapshots
         * `$this->getAttribute(...)`, and on the overtaken path `$this` is precisely the save whose
         * scalars are no longer live: A wrote the title `A`, B overwrote it with `B` and filed a
         * newer revision, and A then fell through to here still holding `A`. History ended with a
         * revision claiming A's title and A's relations over a row holding B's title — a state that
         * never existed, and one "restore the latest version" would have reverted B's title to.
         *
         * The reload is what `RecordsRelationRevisions::versioned()` already does at its own call
         * site: it loads the entries inside the lock and records from those instances, so nothing
         * there is stale. This is the path that skipped it.
         */
        $this->syncScalarsFromLockedRow();

        return $this->recordRevisionForRelationChange($relationsBefore);
    }

    /**
     * Replace this instance's attributes with the row as it currently stands.
     *
     * ⚠️ Attributes only. `relationState()` reads the pivot table directly and `$retainedOriginals`
     * is a plain property, so this save's pre-sanitization originals survive a reload that its
     * scalars do not — which is right both ways round: the originals belong to this write, and the
     * scalars belong to whoever wrote them last.
     *
     * ⚠️ withoutGlobalScopes, as everywhere else in this transaction: the row is being read to
     * describe it, not to hand it to a caller, and a scoped query that matched nothing would leave
     * the stale attributes in place — failing open on exactly the case this exists for.
     */
    private function syncScalarsFromLockedRow(): void
    {
        $live = self::query()->withoutGlobalScopes()->whereKey($this->getKey())->first();

        // ⚠️ Gone is not an error and not a reason to write A's stale values either: a hard delete
        // in this window means there is no row for a revision to describe, and the comparison in
        // `recordRevisionForRelationChange()` is what decides whether to file one.
        if ($live instanceof self) {
            $this->setRawAttributes($live->getAttributes(), sync: true);
        }
    }

    /**
     * The values a revision would record, as they currently stand.
     *
     * ⚠️ RAW originals rather than accessor values. `published_at` casts to a
     * Carbon instance, and comparing two of those with `!==` compares object
     * identity — every restore would look like a change. The raw values are
     * scalars and strings, so a plain array comparison means what it says.
     *
     * ⚠️ Except for a JSON column, where the raw string is NOT the value.
     *
     * MySQL stores and returns `values` in its own key order, so a no-op restore
     * read `{"b":2,"a":1}` before and wrote `{"a":1,"b":2}` after — the same data,
     * a different serialisation, and a duplicate revision filed for a restore that
     * changed nothing. Found by the engine matrix: SQLite, PostgreSQL and MariaDB
     * all preserved the order and agreed, so a single-engine run would have shipped
     * it. And a duplicate is not merely untidy — history is bounded, so it costs a
     * genuine older version off the end.
     *
     * Nothing in Kitsune may depend on JSON key order (field order comes from
     * `fields.ordering`), which is exactly why the comparison must not either.
     *
     * @return array<string, mixed>
     */
    private function versionedState(): array
    {
        $state = [];

        foreach (self::VERSIONED_COLUMNS as $column) {
            $raw = $this->getRawOriginal($column);

            // Only the columns actually cast to a structure are decoded — a title
            // that happens to look like JSON is a title.
            $state[$column] = ($this->getCasts()[$column] ?? null) === 'array' && is_string($raw)
                ? self::keySorted((array) json_decode($raw, true))
                : $raw;
        }

        return $state;
    }

    /**
     * The same data with every level's keys in one order.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function keySorted(array $value): array
    {
        ksort($value);

        foreach ($value as $key => $nested) {
            if (is_array($nested)) {
                $value[$key] = self::keySorted($nested);
            }
        }

        return $value;
    }

    /**
     * Record a revision for a write that dispatched no model events.
     *
     * ⚠️ Bulk builder writes reach neither `created` nor `updated`, and
     * `Entry::query()->update(['status' => 'published'])` is a write this
     * project deliberately ALLOWS and audits (see `AuditLogTest`). So a bulk
     * publish moved a versioned column with no version recorded: history had a
     * gap, and the newest revision no longer described the entry — which makes
     * "restore the latest version" silently revert the bulk change.
     *
     * Recorded rather than refused, to match how the same seam is treated for
     * auditing: the rows already exist and can be named, so there is nothing to
     * fail closed about. `RevisionWrites::suspend()` is the reviewable opt-out.
     *
     * `$before` is the raw pre-write row, so the comparison is raw-to-raw and
     * cannot go wrong on a cast — a `values` array compared against its own
     * JSON encoding would differ on every write.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function recordRevisionForEventlessWrite(array $before, array $after): bool
    {
        if (RevisionWrites::suspended()) {
            return false;
        }

        foreach (self::VERSIONED_COLUMNS as $column) {
            if (($before[$column] ?? null) !== ($after[$column] ?? null)) {
                $this->recordRevision();

                return true;
            }
        }

        return false;
    }

    private function recordRevision(): void
    {
        // getAttribute, not only(): `only()` returns nothing for an attribute
        // the instance never set, so an entry created without an explicit
        // status snapshotted NULL and hit the revision's NOT NULL constraint.
        $snapshot = [];

        foreach (EntryRevision::SNAPSHOT_ATTRIBUTES as $attribute) {
            $snapshot[$attribute] = $this->getAttribute($attribute);
        }

        $revision = $this->revisions()->create([
            ...$snapshot,
            'values' => $this->values,
            // The third storage strategy. Kept OUT of the snapshot list because
            // `entries` has no such column and `restoreRevision()` fills the
            // entry from that list — a stray key there would try to write a
            // column that does not exist.
            'relation_state' => $this->relationState(),
            // ⚠️ The pre-sanitization originals, also OUT of the snapshot list and for
            // a sharper reason than `relation_state`: `restoreRevision()` fills the
            // entry from that list, so an original reachable through it would put
            // unsanitized HTML back into `entries.values` — the one thing
            // field-types.md §6 forbids. Its own column is a place a restore does not
            // read, and that is why it is one.
            'unsanitized_values' => $this->retainedOriginals === [] ? null : $this->retainedOriginals,
            'author_id' => $this->author_id,
        ]);

        /*
         * ⚠️ NOTED FOR THE RECONCILER, because this is the only place that KNOWS. A form save
         * writes the entry and then its relations (ADR-015), and the second half has to tell a
         * revision this save filed from one that was already there. Inferring it does not work —
         * see `RecordedRevisions` for the two attempts and why each failed.
         */
        app(RecordedRevisions::class)->note((int) $this->getKey(), (int) $revision->getKey());

        // Cleared HERE rather than left to expire. Carrying them forward would attach
        // this save's originals to a later revision — a false record of what an author
        // wrote, which is worse than no record.
        $this->retainedOriginals = [];

        $this->pruneRevisions();
    }

    /**
     * Keep the most recent KEEP_REVISIONS, drop the rest.
     *
     * Deleted rather than archived: an entry edited a thousand times would
     * otherwise carry a thousand full JSON snapshots, and ADR-027's floor is
     * one vCPU and a SQLite file. The bound is applied on write so the cost
     * cannot accumulate quietly between maintenance windows.
     */
    private function pruneRevisions(): void
    {
        $keep = $this->revisions()
            ->orderByDesc('id')
            ->limit(self::KEEP_REVISIONS)
            ->pluck('id');

        $this->revisions()->whereKeyNot($keep)->delete();
    }

    /**
     * @param  Builder<Entry>  $query
     * @return Builder<Entry>
     */
    public function scopeOfType(Builder $query, string $handle): Builder
    {
        return $query->where('type_handle', $handle);
    }

    /**
     * @param  Builder<Entry>  $query
     * @return Builder<Entry>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    /** Org-shared entries are not publicly addressable, so they carry no slug. */
    public function isShared(): bool
    {
        return $this->site_id === null;
    }

    /** @return BelongsTo<EntryType, $this> */
    public function entryType(): BelongsTo
    {
        return $this->belongsTo(EntryType::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * The value identifying this entry's data subject, if its type names one.
     *
     * Null means unanswerable rather than "no subject": the type nominated
     * nothing, and `EntryType::withoutSubjectIdentifier()` is the list of
     * types in that state (ADR-020).
     */
    public function subjectValue(): mixed
    {
        $storage = $this->entryType?->subjectStorage();

        if ($storage === null) {
            return null;
        }

        // ⚠️ All THREE storage strategies, not two.
        //
        // A relational subject lives in `entry_relations` and a promoted one
        // in its own column on `entries` — ADR-020 names the relation case
        // explicitly ("its email or its `person` relation"), and `slug` is
        // promoted. Reading only the JSON returned null for both, which is
        // indistinguishable from "no subject nominated".
        return match ($storage->strategy()) {
            StorageStrategy::Relational => $this->relatedIdsFor($storage)->all(),
            StorageStrategy::Promoted => $this->getAttribute((string) $storage->promotedColumn()),
            StorageStrategy::Inline => $this->values[$storage->handle] ?? null,
        };
    }

    /**
     * Entries of one type whose subject identifier matches.
     *
     * Scoped to a single type on purpose. `values` is JSON and the key
     * differs per type, so a cross-type query would be a union whose shape
     * depends on the data — subject-access EXPORT is v1.1 tooling, and this
     * is the v1.0 primitive it will be built from.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereSubjectIs(Builder $query, EntryType $type, mixed $identifier): Builder
    {
        $storage = $type->subjectStorage();

        if ($storage === null) {
            // Fail closed. Returning everything of this type would answer a
            // subject request with every person in it.
            return $query->whereRaw('1 = 0');
        }

        // ⚠️ A NULL identifier matches nothing, and has to be refused before
        // a strategy is chosen. Both the promoted and inline branches compile
        // `= null` to `IS NULL`, so a malformed subject-access request
        // returned every entry whose nominated field is null or absent — a
        // batch of records belonging to no identified subject, from the one
        // query that must never over-answer. `subjectValue()` already defines
        // null as unanswerable; this makes the query agree.
        if ($identifier === null) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('entry_type_id', $type->getKey());

        // Where the value lives decides where the predicate goes: a pivot
        // row, a real column, or a JSON path.
        return match ($storage->strategy()) {
            StorageStrategy::Relational => $query->whereExists(function ($pivot) use ($storage, $identifier): void {
                $pivot->selectRaw('1')
                    ->from('entry_relations')
                    // ⚠️ The target's VISIBILITY, in this same statement.
                    //
                    // `related()` hides an out-of-scope target through
                    // Entry's own SiteScope, but a raw EXISTS does not — and
                    // `attach($id)` never validates the related model, so the
                    // ordinary path can create a pivot row pointing anywhere.
                    //
                    // This was a separate `exists()` check before the builder
                    // ran, which is a gap rather than a guard: move the target
                    // to another site between the two and the EXISTS still
                    // matched on the pivot alone, returning a source record
                    // whose subject `related()` would no longer show. As a
                    // subquery there is no window, and no self-alias either —
                    // nothing inside it references the outer `entries`.
                    ->whereIn(
                        'entry_relations.target_entry_id',
                        static::query()->select('id')->whereKey($identifier)->toBase(),
                    )
                    ->whereColumn('entry_relations.source_entry_id', 'entries.id')
                    // ⚠️ org_id, matching what `related()` enforces through
                    // withPivotValue(). Without it a hostile pivot row
                    // carrying another org's org_id counts as this entry's
                    // subject — the exact row EntrySchemaTest already writes
                    // to prove the relationship refuses it (ADR-021).
                    ->whereColumn('entry_relations.org_id', 'entries.org_id')
                    ->where('entry_relations.field_storage_id', $storage->getKey())
                    ->where('entry_relations.target_entry_id', $identifier);
            }),
            StorageStrategy::Promoted => $query->where((string) $storage->promotedColumn(), $identifier),
            StorageStrategy::Inline => $query->where('values->'.$storage->handle, $identifier),
        };
    }

    /**
     * Erase one field everywhere it survives, revision history included.
     *
     * ADR-020: erasure has to reach revisions, because article revision 4
     * still holds the name just erased. Immutable revisions were rejected for
     * exactly this reason — they make the right to erasure unimplementable.
     *
     * Replaces in place rather than deleting the revision, so the history of
     * WHAT CHANGED WHEN survives an erasure of WHAT IT SAID. Returns how many
     * rows were rewritten, because an erasure that silently reached nothing
     * is indistinguishable from one that worked.
     */
    public function redactField(string $handle, mixed $replacement = null): int
    {
        // ⚠️ ONE transaction, with this entry LOCKED, and everything the erasure
        // depends on resolved INSIDE it.
        //
        // Two separate races lived here. The live change committed before the
        // revisions were loaded and rewritten, so a restore starting in that
        // interval could read an as-yet-unredacted revision and write the erased
        // value back onto the already-redacted entry. And the storages and this
        // model's own attributes were resolved BEFORE the lock, so a stale
        // instance could skip a live value another writer had just committed,
        // redact only the new revision, and return a positive count while the live
        // entry still held the data.
        //
        // The lock is the same row `restoreRevision()` takes first, so the two
        // serialise: whichever starts second sees the other's completed work
        // rather than half of it. An erasure that reports success has to mean it
        // (ADR-020).
        return (int) DB::transaction(function () use ($handle, $replacement): int {
            self::query()->withoutGlobalScopes()->whereKey($this->getKey())->lockForUpdate()->get();

            // Under the lock, so the attributes being swept are the committed
            // ones rather than whatever this instance was loaded with.
            $this->refresh();

            // ⚠️ Resolved through THIS ENTRY'S TYPE, not by handle alone.
            //
            // `field_storage` is UNIQUE (org_id, handle) and the model is
            // #[Unscoped], so a bare handle lookup can return ANOTHER ORG's row —
            // and then a relational erasure detaches on the wrong
            // field_storage_id, reports 0, and leaves every link intact. Two
            // orgs defining `email` is the ordinary case, not a contrived one.
            //
            // ⚠️ And EVERY match, not the first one. UNIQUE (org_id, handle)
            // lets a GLOBAL row and the org's own row share a handle, and
            // `Field::guardStorageOwnership()` permits both to attach to the same
            // type — so a bare `first()` picked one arbitrarily. If it took the
            // relational row it detached the pivots and returned, leaving
            // `values['contact']` and every revision copy of it intact while
            // reporting success; if it took the inline row the pivot survived
            // instead. Either way personal data remained and the caller's success
            // check passed, and WHICH depended on row order — so the same request
            // erased different data on different engines.
            //
            // Elsewhere this shadowing is resolved with explicit precedence
            // (`IdentifyEntryType`, `EntryType::visibleFor()`). Precedence is
            // wrong here: erasure has to reach the data, and the shadowed row
            // holds data too. So every match is erased and the counts are summed.
            // ⚠️ NOT filtered by entry type at all, and the two narrower versions of
            // this lookup were both wrong.
            //
            // `entries.entry_type_id` is mutable, and erasure has to reach history
            // (ADR-020). Filtering on the CURRENT type found no A-era field storage
            // after a move from A to B, so `redactStorage()` fell through to the
            // inline path, returned 0, and left the live promoted column or relation
            // AND every historical snapshot untouched while reporting success.
            //
            // ⚠️ Filtering on the types the REVISIONS record was the second attempt
            // and it fails for a subtler reason: history is bounded. Once an entry
            // accumulates `KEEP_REVISIONS` B-era versions, `pruneRevisions()` drops
            // the last A-era revision — and the type set silently forgets A while
            // A's promoted column or relation is still live on the row. A prunable
            // store cannot be the durable record of every former type.
            //
            // So the question is asked of the storage rows themselves, which ARE
            // durable: any row with this handle that this org could have used. That
            // over-approximates, and over-approximating is the safe direction here —
            // a storage row this entry never used has nothing of this entry's to
            // erase, so it costs a query and erases nothing. Under-approximating
            // leaves personal data behind and reports success.
            //
            // ⚠️ The over-approximation is safe for TWO of the three strategies,
            // and I claimed it was safe for all three. It is not.
            //
            // Inline data lives at `values->{handle}`, so every row with this handle
            // names the same JSON key — the handle alone decides the location.
            // Relational data lives in pivots carrying that storage's id and this
            // entry's, so a storage the entry never used has no such rows.
            //
            // A PROMOTED column is shared across handles. `SlugType::promotedColumn()`
            // returns `slug` for the type, not for the handle, so two differently
            // handled slug fields both project to `entries.slug`. Erasing a storage
            // this entry never used would then clear the slug that belongs to the
            // field it DOES use — real data loss, dressed as a privacy operation.
            // Hence `ownsPromotedColumn()`.
            //
            // The `org_id` filter is hygiene on top of that — consulting another
            // org's definitions is not this entry's business (ADR-021), and a global
            // row (`org_id` null) genuinely is shared by every org. It is stated
            // that way rather than as "the boundary", because removing it does not
            // let an erasure reach another org's data and claiming otherwise would
            // misdirect whoever audits this next.
            $storages = FieldStorage::query()
                ->where('handle', $handle)
                ->where(fn (Builder $query): Builder => $query
                    ->whereNull('org_id')
                    ->orWhere('org_id', $this->org_id))
                ->get()
                // ⚠️ And PROMOTED storage is filtered again, because for that one
                // strategy over-approximating is NOT safe. See below.
                ->filter(fn (FieldStorage $candidate): bool => $candidate->strategy() !== StorageStrategy::Promoted
                    || $this->ownsPromotedColumn($candidate))
                ->values();

            if ($storages->count() > 1) {
                return (int) $storages->sum(
                    fn (FieldStorage $storage): int => $this->redactStorage($handle, $storage, $replacement),
                );
            }

            return $this->redactStorage($handle, $storages->first(), $replacement);
        });
    }

    /**
     * Note which field storage wrote each promoted column on this entry.
     *
     * ⚠️ RECORDED, not derived, and three attempts at deriving it each failed in a
     * different way. A promoted column is named for its TYPE — every slug-typed
     * field projects to `entries.slug` — so the column itself cannot say whose value
     * it holds, and erasure has to know: clearing `slug` for a handle that never
     * wrote it destroys another field's data while reporting success (ADR-020).
     *
     *   the entry's CURRENT type      misses a field the entry has moved off
     *   the types its REVISIONS name  vanish when the bounded history is pruned
     *   any storage with the handle   grants a shared column to a field that
     *                                 never touched it
     *
     * None of those is a bug in the rule. The information was simply never written
     * down anywhere durable, and it is known at exactly one moment: when a field on
     * the entry's own type writes the column.
     *
     * Only a DIRTY column is attributed, so an unrelated save does not reassign
     * provenance, and nothing is recorded when no field on the current type projects
     * to the column — a value written with no owner has no owner to record, and
     * claiming one would be worse than admitting none.
     */
    /**
     * The columns some registered field type projects into.
     *
     * ⚠️ Derived from the REGISTRY, which answers without touching the database
     * because `promotedColumn()` is named for the type rather than stored per field.
     *
     * @return list<string>
     */
    private static function promotableColumns(FieldTypeRegistry $registry): array
    {
        $columns = [];

        foreach ($registry->all() as $candidate) {
            if (($column = $candidate->promotedColumn()) !== null) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /** Whether this write touches any column a field type could have promoted into. */
    private function touchesPromotedColumn(FieldTypeRegistry $registry): bool
    {
        foreach (self::promotableColumns($registry) as $column) {
            if ($this->isDirty($column)) {
                return true;
            }
        }

        return false;
    }

    private function recordPromotedProvenance(): void
    {
        $registry = app(FieldTypeRegistry::class);

        // ⚠️ THE DIRTY CHECK BELONGS HERE, NOT IN THE LOOP, and putting it in the loop
        // meant it was not a guard at all.
        //
        // `isDirty($column)` runs per field, so reaching it had already cost the type
        // lookup and a fields query — on every save of every entry, including one that
        // moved only `title`. Two queries against the 1 vCPU / SQLite floor in ADR-027,
        // for a column no field storage can write.
        //
        // `title`, `status` and `published_at` are PLATFORM columns (field-types.md §2):
        // they are not user-definable, so nothing projects into them and there is no
        // provenance to record. Only a column some registered type promotes can have any,
        // and the registry knows that list without a query.
        if (! $this->touchesPromotedColumn($registry)) {
            return;
        }

        $type = EntryType::query()->whereKey($this->entry_type_id)->first();

        if ($type === null) {
            return;
        }

        $provenance = $this->promoted_by ?? [];
        $before = $provenance;

        foreach ($type->fields()->with('fieldStorage')->get() as $field) {
            $storage = $field->fieldStorage;

            if ($storage === null || ! $registry->has((string) $storage->type)) {
                continue;
            }

            $column = $storage->promotedColumn();

            if ($column === null || ! $this->isDirty($column)) {
                continue;
            }

            $provenance[$column] = $storage->getKey();
        }

        if ($provenance !== $before) {
            $this->promoted_by = $provenance;
        }
    }

    /**
     * Whether a promoted storage's shared column holds THIS entry's data for it.
     *
     * ⚠️ Needed because a promoted column is named for its TYPE, not its handle:
     * every slug-typed storage projects to `entries.slug`, whatever it is called. So
     * "which storage does this entry's slug belong to?" is not answerable from the
     * column, and erasing the wrong one destroys a live value.
     *
     * The rule is ownership by the entry's CURRENT type, with one deliberate
     * exception: if nothing on the current type projects to that column, the value
     * is ORPHANED — left behind by a type the entry has since moved off — and the
     * storage being erased is the only thing that could have written it. Erasing it
     * then is correct, and refusing to would recreate the unreachable-data failure
     * that ADR-020 forbids.
     *
     * Both cases matter, and they pull in opposite directions:
     *
     *   moved to a type WITH its own slug field    the new field owns the column,
     *                                             so the old handle must not touch it
     *   moved to a type WITHOUT one                nobody owns it, so the old handle
     *                                             is exactly what should clear it
     *
     * ⚠️ No new schema, and no reliance on revisions. The entry's current type and
     * the field storage rows are both durable, which is what the previous two
     * attempts at this lookup each got wrong in turn.
     */
    private function ownsPromotedColumn(FieldStorage $storage): bool
    {
        $column = $storage->promotedColumn();

        if ($column === null) {
            return true;
        }

        $wroteIt = ($this->promoted_by ?? [])[$column] ?? null;

        // ⚠️ FAIL CLOSED when nothing recorded it. An unattributed value is one this
        // erasure cannot prove is its to clear, and guessing is what the three
        // derived rules did. Every value written through a field on the entry's type
        // is attributed, so this is the case where somebody set the column directly.
        return $wroteIt !== null && (int) $wroteIt === (int) $storage->getKey();
    }

    /**
     * Erase one storage definition's data wherever its strategy puts it.
     *
     * The handle is passed rather than read off the storage row, because the
     * JSON path below still has to run when NO storage row matched — an
     * unclassified or already-deleted definition must not make erasure a no-op.
     */
    private function redactStorage(string $handle, ?FieldStorage $storage, mixed $replacement): int
    {

        // Erasure has to reach wherever the value actually lives.
        //
        // A relational field's data is rows in `entry_relations`, with
        // nothing to replace in place — so erasure IS the detach. A promoted
        // field is a real column on `entries`. Both fell through to the JSON
        // path, found no key, and reported 0 while the data survived.
        if ($storage?->strategy() === StorageStrategy::Relational) {
            // ⚠️ NOT through `related()`. That relation carries
            // `withPivotValue('org_id', ...)`, which is right for reading and
            // wrong for erasing: a pivot row written with a different org_id
            // — reachable by overriding it in `attach()`'s pivot attributes —
            // does not match the predicate, so the detach skipped it and
            // reported 0 while the subject link survived.
            //
            // Erasure has to reach the row wherever it is, so it goes
            // straight at the pivot by the two keys that define it. Scoping
            // is the reader's protection; it must not become the attacker's.
            // ⚠️ withoutRevisions, for the reason the promoted and inline paths
            // use it: an erasure is not an authored version, and filing one would
            // add a row to the very history it is clearing. The relational path
            // did not need this until the relation builder started recording —
            // adding a recorder added a place erasure has to stand it down.
            $detached = (int) RevisionWrites::suspend(fn (): int => EntryRelation::query()
                ->where('source_entry_id', $this->getKey())
                ->where('field_storage_id', $storage->getKey())
                ->delete());

            // ⚠️ And the REVISIONS, which is the half a new column just broke.
            //
            // `relation_state` records the target ids at each version, so
            // deleting the live pivots and returning left every erased id sitting
            // in history — and a restore would recreate the relation, undoing the
            // erasure. ADR-020 requires erasure to reach revisions, and adding a
            // place where relations are stored added a place erasure has to sweep.
            return $detached + $this->redactRelationHistory($storage);
        }

        if ($storage?->strategy() === StorageStrategy::Promoted) {
            // ⚠️ The declared column, NOT the handle. `field_storage`
            // accepts any valid handle for a `slug` field, so one called
            // `public_slug` still writes `entries.slug` — and reading the
            // handle read a column that does not exist, then reported a
            // successful erasure having erased nothing.
            $column = (string) $storage->promotedColumn();

            $rewritten = 0;

            if ($this->getAttribute($column) !== $replacement) {
                // withoutRevisions, because an erasure is not an authored
                // version — filing the redacted state as a new revision would
                // add a row to the very history it is clearing.
                $this->withoutRevisions(function (self $entry) use ($column, $replacement): void {
                    $entry->setAttribute($column, $replacement);
                    $entry->save();
                });

                $rewritten++;
            }

            // Revisions snapshot the promoted columns too, so the sweep has to
            // reach them there as well — by the same column name, for the same
            // reason.
            //
            // ⚠️ `redactColumn`, stated rather than inferred. This dispatched
            // on strategy correctly and then called a method that decided
            // between column and JSON key by testing the name against the
            // snapshot list — so an INLINE field handled `status` was erased as
            // though it were promoted. See EntryRevision::redactColumn().
            foreach ($this->revisions()->get() as $revision) {
                $rewritten += $revision->redactColumn($column, $replacement) ? 1 : 0;
            }

            return $rewritten;
        }

        $rewritten = 0;

        $values = $this->values ?? [];

        if (array_key_exists($handle, $values)) {
            $this->withoutRevisions(function (self $entry) use ($values, $handle, $replacement): void {
                $entry->values = [...$values, $handle => $replacement];
                $entry->save();
            });

            $rewritten++;
        }

        // The handle, into `values`, whatever the handle happens to be called.
        foreach ($this->revisions()->get() as $revision) {
            $rewritten += $revision->redactValue($handle, $replacement) ? 1 : 0;
        }

        return $rewritten;
    }

    /**
     * Remove a storage definition's targets from every revision's snapshot.
     *
     * Returns how many revisions were rewritten, so the caller's count still
     * distinguishes an erasure that reached something from one that silently
     * matched nothing.
     *
     * ⚠️ The key is kept and set to NULL, and dropping it was wrong.
     *
     * Three states have to be distinguishable, and dropping the key collapsed two
     * of them:
     *
     *   a list  — this field had exactly these targets at that version
     *   `null`  — this field's history was erased; the version says nothing
     *   absent  — this field had no relations at that version
     *
     * An empty list and an absent key are STATEMENTS, and `replaceRelations()`
     * acts on them by clearing the field. So dropping the key made an erased
     * field indistinguishable from one that never had relations — and restoring a
     * redacted revision then deleted a replacement relation added after the
     * erasure, which is the opposite of what erasure is for. `null` says
     * "unknown", and a restore leaves an unknown field alone.
     */
    private function redactRelationHistory(FieldStorage $storage): int
    {
        $key = (string) $storage->getKey();
        $rewritten = 0;

        foreach ($this->revisions()->get() as $revision) {
            $state = $revision->relation_state;

            // A key already null has been erased before; there is nothing left
            // to sweep and rewriting it would inflate the count.
            if ($state === null || ($state[$key] ?? null) === null) {
                continue;
            }

            $state[$key] = null;

            $revision->relation_state = $state;
            $revision->save();

            $rewritten++;
        }

        return $rewritten;
    }

    /** @return Collection<int, int> */
    private function relatedIdsFor(FieldStorage $storage): Collection
    {
        return $this->related()
            ->wherePivot('field_storage_id', $storage->getKey())
            ->pluck('entries.id');
    }

    /**
     * The entries this one relates to through one field, in the author's order.
     *
     * ⚠️ ORDERED, which `relatedIdsFor()` above is not. That one answers "is anything
     * still attached" for the erasure and cardinality guards, where order is irrelevant.
     * A relation PICKER is different: an author arranges related entries and the
     * arrangement is the content — `entry_relations.ordering` exists for it, and reading
     * without it hands back a set that reshuffles on every page load.
     *
     * ⚠️ Public because a form needs it. The admin cannot hydrate a relation control from
     * a private method, and the alternative — a second query written in the panel — is a
     * second place for the field-storage constraint to be forgotten.
     *
     * @return list<int>
     */
    public function relatedIdsForField(FieldStorage $storage): array
    {
        return $this->related()
            ->wherePivot('field_storage_id', $storage->getKey())
            ->orderBy('entry_relations.ordering')
            // `id` as the tiebreaker, so two rows sharing an ordering do not swap places
            // between reads. Every row defaults to ordering 0, so this is the common case
            // rather than a corner of it.
            ->orderBy('entry_relations.id')
            ->pluck('entries.id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();
    }

    /**
     * Replaces the entries related through one field, keeping the given order.
     *
     * ⚠️ SCOPED TO ONE FIELD STORAGE, and this is the property the whole method rests on.
     * An entry relates through several fields at once — `authors` and `tags` both live in
     * `entry_relations` — so a sync that ignored `field_storage_id` would silently detach
     * every other field's relations while appearing to save one. Measured before relying
     * on it: `related()->wherePivot('field_storage_id', X)->sync(...)` leaves rows carrying
     * a different storage id untouched.
     *
     * ⚠️ `ordering` is the POSITION IN THE GIVEN ARRAY, not a column the caller supplies.
     * The author's arrangement is what the control returns, so deriving it here is the only
     * way it cannot disagree with what they saw.
     *
     * ⚠️ It goes through `related()` rather than the pivot table, which is what gets the
     * org stamp, the serialised write, the cardinality check and the relation revision —
     * all of which `GuardedBelongsToMany` supplies and a raw insert would skip.
     *
     * @param  list<int|string>  $ids
     */
    public function syncFieldRelations(FieldStorage $storage, array $ids): void
    {
        $payload = [];
        $position = 0;

        foreach ($ids as $id) {
            $id = (int) $id;

            // ⚠️ Duplicates collapse rather than throwing. A select can hand back the same
            // entry twice, and two rows pointing at one target is a state nothing else in
            // the schema expects — while refusing the save would lose the author's other
            // edits over a mistake the UI let them make.
            if ($id <= 0 || isset($payload[$id])) {
                continue;
            }

            $payload[$id] = [
                'field_storage_id' => $storage->getKey(),
                'ordering' => $position++,
            ];
        }

        $this->related()
            ->wherePivot('field_storage_id', $storage->getKey())
            ->sync($payload);
    }

    /**
     * How many revisions an entry keeps.
     *
     * The decision log lists "revision storage growth — full-JSON snapshots
     * get expensive; consider diffs" as an open question, and it is still
     * open. Retention is the honest interim answer: a bound that is applied
     * rather than a cost that accumulates silently while the question waits.
     * Diffs remain the better fix and would raise this number, not remove it.
     */
    public const KEEP_REVISIONS = 50;

    /**
     * Pre-conversion values awaiting the revision that will record them.
     *
     * ⚠️ Transient, and cleared by the recorder rather than left to expire. A
     * surviving entry here would attach one write's originals to a later, unrelated
     * revision — a false record of what an author wrote, which is worse than none.
     *
     * @var array<string, mixed>
     */
    private array $retainedOriginals = [];

    /**
     * Take over another instance's pending originals.
     *
     * ⚠️ Needed because the instance whose values were CONVERTED is not always the
     * instance that RECORDS. `AuditedBuilder` records inside the write transaction
     * and reloads the row to do it — deliberately, so the snapshot is persisted state
     * — and that reload is a different object.
     *
     * The source is cleared, so a hand-off cannot record the same originals twice.
     */
    public function carryRetainedOriginalsFrom(self $source): void
    {
        $this->retainedOriginals = $source->retainedOriginals;
        $source->retainedOriginals = [];
    }

    /**
     * Every value being written, put through its field type's `toStorage()`.
     *
     * ⚠️ Called from the BUILDER, not from a `saving` listener, and that was the
     * defect in the first version of this pipeline.
     *
     * `saveQuietly()`, `createQuietly()`, `updateQuietly()` and anything inside
     * `withoutEvents()` suppress model events while still reaching the builder — so
     * a `rich_text` payload containing a `<script>` tag went in unchanged and was
     * recorded unsanitized in the revision as well. My own commit message said "the
     * guard is where the write is" while the guard sat in an event, which is not
     * where the write is. This project has now found that shape ten times, and this
     * is the first time I have been the one to add it.
     *
     * ⚠️ The short-circuit is structural rather than a check: only columns actually
     * present in `$values` are converted, so a save that moves `title` or `site_id`
     * resolves no schema at all. The first version claimed that optimisation in a
     * comment and did not implement it — it queried the type and its fields on every
     * save, which on the 1 vCPU / SQLite floor (ADR-027) is several queries added to
     * every write for nothing.
     *
     * @param  array<string, mixed>  $values  Raw column values, as the builder has them.
     * @return array<string, mixed>
     */
    public function convertFieldValuesForWrite(array $values): array
    {
        $this->retainedOriginals = [];

        $registry = app(FieldTypeRegistry::class);

        // ⚠️ THE SHORT-CIRCUIT, before any query, and it has to come first to be one.
        //
        // The convertible columns are `values` plus whatever the registered types
        // promote to — which the REGISTRY knows without touching the database, because
        // `promotedColumn()` is named for the type. So a write carrying neither can be
        // dismissed here, and a save that moves only `title`, `status` or `site_id`
        // resolves no schema at all.
        //
        // The first version put its dirty check after resolving the type and its
        // fields, which is to say it did not have one. On the 1 vCPU / SQLite floor
        // (ADR-027) that was several queries on every write, for nothing.
        //
        // ⚠️ Shares `promotableColumns()` with `recordPromotedProvenance()` rather than
        // deriving the list again. That listener made the identical mistake — a dirty
        // check downstream of the queries it should avoid — and it was written on a
        // different branch, so the lesson did not travel. Two copies of this list would
        // also drift the moment a type starts promoting a column.
        $convertible = ['values', ...self::promotableColumns($registry)];

        // ⚠️ A TYPE CHANGE is a conversion trigger, because the type decides what the
        // stored bytes MEAN.
        //
        // `values` is keyed by handle and unknown keys pass through untouched, so an
        // entry can hold `<script>` under a key its current type does not declare —
        // nothing converts it, because no field claims it. Move the entry to a type
        // where that key IS `rich_text` and the value becomes rich text having never
        // met the sanitiser. `entry_type_id` is explicitly mutable (ADR-010), so this
        // is a supported operation and not an edge case.
        $convertible[] = 'entry_type_id';

        if (array_intersect(array_keys($values), $convertible) === []) {
            return $values;
        }

        $typeId = $values['entry_type_id'] ?? $this->entry_type_id;

        // ⚠️ Falsy rather than `=== null`: the docblock types this column non-null, so
        // a strict null check is dead code to static analysis — but a fresh instance
        // may genuinely not have set it yet, and an id of 0 is not a type either.
        if (! $typeId) {
            return $values;
        }

        $type = EntryType::query()->whereKey($typeId)->first();

        if ($type === null) {
            // The foreign key's job to report, not this one's.
            return $values;
        }

        // ⚠️ `values` reaches the builder ALREADY ENCODED on an instance save: the
        // array cast runs in `setAttribute()`, long before this. Decoding and
        // re-encoding in the shape it arrived is what keeps this from double-encoding
        // — the same trap the guarded builders hit with `newModelInstance()`.
        $encoded = array_key_exists('values', $values) && is_string($values['values']);
        $inline = match (true) {
            ! array_key_exists('values', $values) => null,
            is_string($values['values']) => json_decode($values['values'], true),
            is_array($values['values']) => $values['values'],
            default => null,
        };

        // ⚠️ On a type change that carries no `values` of its own, the STORED values
        // are converted against the destination type. Without this the trigger above
        // would resolve the new schema and then find nothing to apply it to.
        //
        // Only for a row that exists: on an insert there is nothing stored yet, and
        // seeding from the instance would write a key the caller never sent.
        $retyping = $this->exists
            && array_key_exists('entry_type_id', $values)
            && (int) $values['entry_type_id'] !== (int) $this->getRawOriginal('entry_type_id');

        if ($inline === null && $retyping) {
            $inline = $this->values ?? [];
            // Written back in the encoded shape, because that is what the column takes
            // and nothing else in this write is carrying it.
            $encoded = true;
        }

        foreach ($type->fields()->with('fieldStorage')->get() as $field) {
            $storage = $field->fieldStorage;

            if ($storage === null || ! $registry->has((string) $storage->type)) {
                continue;
            }

            $fieldType = $registry->get((string) $storage->type);
            $config = new FieldConfig($storage, $field, $this);
            $handle = (string) $storage->handle;

            if ($storage->strategy() === StorageStrategy::Inline) {
                // Absent is not null: a key the write does not carry must stay as it
                // is, not be overwritten with a converted null.
                if (is_array($inline) && array_key_exists($handle, $inline)) {
                    $submitted = $inline[$handle];
                    $inline[$handle] = $fieldType->toStorage($submitted, $config);

                    // Kept only when the type says its conversion is lossy AND the
                    // conversion took something — a value that survived unchanged has
                    // no original worth storing in a column erasure has to sweep.
                    if ($fieldType->retainsOriginal() && $inline[$handle] !== $submitted) {
                        $this->retainedOriginals[$handle] = $submitted;
                    }
                }

                continue;
            }

            if ($storage->strategy() !== StorageStrategy::Promoted) {
                continue;
            }

            $column = $storage->promotedColumn();

            // ⚠️ Only a column this write carries. The column is shared across handles
            // (`SlugType::promotedColumn()` is named for the type), so converting one
            // the write never touched would run another field's value through this
            // field's type.
            if ($column === null || ! array_key_exists($column, $values)) {
                continue;
            }

            $values[$column] = $fieldType->toStorage($values[$column], $config);
        }

        if ($inline !== null) {
            $values['values'] = $encoded ? json_encode($inline) : $inline;
        }

        return $values;
    }

    /**
     * Every saved version, newest first.
     *
     * @return HasMany<EntryRevision, $this>
     */
    public function revisionHistory(): HasMany
    {
        return $this->revisions()->orderByDesc('id');
    }

    /**
     * Refuse a revision that does not belong to this entry.
     *
     * ⚠️ ONE implementation for both call sites deliberately. The check runs twice
     * — once on the caller's instance and once on the locked row — and two copies
     * of the same rule are how the pre-lock and post-lock answers drift apart.
     */
    private function refuseForeignRevision(EntryRevision $revision): void
    {
        if ($revision->entry_id !== $this->getKey()) {
            throw new RuntimeException(
                "Revision [{$revision->getKey()}] belongs to another entry and cannot be restored onto this one."
            );
        }
    }

    /**
     * Put a revision's state back, as a NEW revision.
     *
     * History is append-only in the sense that matters: restoring version 3
     * does not delete versions 4 and 5, it adds version 6 that happens to
     * match 3. Rewriting history would make "what did this say last Tuesday"
     * unanswerable, which is the question revisions exist to answer.
     */
    public function restoreRevision(EntryRevision $revision): self
    {
        // ⚠️ A courtesy check, and NOT the one that decides. It reads the caller's
        // instance before any lock exists, so it can only report what was true
        // when that instance was loaded. The authoritative check is the identical
        // one inside the transaction, against the locked row.
        //
        // Kept because failing here costs nothing and gives the caller the error
        // without opening a transaction — but it is the second check that is load
        // bearing, and the two must not drift apart.
        $this->refuseForeignRevision($revision);

        // ⚠️ Everything that JUDGES happens inside the transaction, after the
        // row is locked — and refreshing and judging outside it was still a race.
        //
        // A refresh read the current type, another request changed it, and the
        // comparison then passed against a type that was already stale. Worse,
        // filling the snapshot did not mark `entry_type_id` dirty against the
        // in-memory value, so the save wrote type A's values onto a row that had
        // become type B and the final revision claimed a type the row did not
        // have. A guard that reads outside the lock it is protecting is not a
        // guard; it is a hint.
        DB::transaction(function () use ($revision): void {
            // The lock first, so nothing below can be answered from stale state.
            self::query()->withoutGlobalScopes()->whereKey($this->getKey())->lockForUpdate()->get();

            // ⚠️ And the REVISION is re-read under the same lock, because the
            // caller's instance can be stale in the one way that matters.
            //
            // `redactField()` sweeps revisions through separately loaded models,
            // so a caller holding an `EntryRevision` from before an erasure still
            // has the pre-erasure snapshot in memory — and restoring it wrote the
            // erased values and relation ids straight back. An erasure that
            // succeeded could be undone by a restore that never re-read anything
            // (ADR-020). Trusting a caller's snapshot is trusting a copy of the
            // data to be the data.
            $revision = $revision->newQueryWithoutScopes()
                ->whereKey($revision->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // ⚠️ And OWNERSHIP is re-checked on the locked row, because the check
            // above read a copy.
            //
            // `EntryRevision` is #[Unscoped] and writable, so `entry_id` can be
            // reassigned between the caller's load and this lock. A revision moved
            // onto this entry passed the outer check on its stale in-memory value
            // and had its snapshot restored here — and when both entries share a
            // type the type guard below agreed, so the values of an entry in
            // ANOTHER ORG could be written onto this one (ADR-021: org isolation
            // has no framework safety net).
            //
            // This is the same lesson as the type check three comments down, and
            // it was applied to the type and not to the owner: re-reading the row
            // under the lock is only half the job if the answers derived from it
            // are still the ones computed before.
            $this->refuseForeignRevision($revision);

            // ⚠️ And the refresh is needed for a second, separate reason: a model
            // from `create()` holds only the attributes the caller set, so `slug`
            // and `published_at` are in neither `$attributes` nor `$original`.
            // Filling them from the snapshot with the same nulls marks them dirty
            // and `wasChanged()` reports a change that never happened, filing a
            // redundant version for a no-op restore.
            $this->refresh();

            // ⚠️ Refused ACROSS a type change, rather than silently applied.
            //
            // `values` are keyed by field handle and mean whatever the entry's
            // type says they mean, and `entry_type_id` is mutable. Restoring a
            // revision authored under the old type wrote its values back onto an
            // entry that now resolves a different field set — the same JSON read
            // against the wrong schema, which is the quiet content destruction
            // ADR-006 locks storage shape to prevent, arriving by another door.
            //
            // Refused rather than restoring the old type as well: moving an entry
            // between types changes which fields apply, its URL, and the relations
            // pointing at it. That is a deliberate act, not a side effect of
            // asking for last Tuesday's text.
            if ($revision->entry_type_id !== $this->entry_type_id) {
                throw new RuntimeException(sprintf(
                    'Revision [%s] was authored while this entry was type [%s] and it is now type '
                    .'[%s]. Its values are keyed by that type\'s field handles, so restoring them '
                    .'here would read them against a different schema (ADR-006, ADR-010). Change '
                    .'the type back first if that is what you mean.',
                    (string) $revision->getKey(),
                    (string) $revision->entry_type_id,
                    (string) $this->entry_type_id,
                ));
            }

            $state = $revision->relation_state;

            // Validated before anything is written, so the refusal below can say
            // nothing has changed and be telling the truth.
            if ($state !== null) {
                $this->refuseMissingTargets($revision, $state);
            }

            $relationsBefore = $this->relationState();

            // ⚠️ A SNAPSHOT, because `wasChanged()` cannot answer this.
            //
            // It reads the model's `$changes`, which is populated by the last save
            // that actually wrote something — and neither `refresh()` nor a save
            // with nothing dirty clears it. So an instance that had already
            // performed an update and then restored its newest revision saw the
            // EARLIER edit's changes, filed a duplicate revision for a restore that
            // changed nothing, and with enough repetitions pruned a genuine older
            // version off the end of the bounded history.
            //
            // Comparing before and after answers the question actually being asked
            // — did this restore change the entry? — without depending on when
            // Eloquent last synced its bookkeeping. It is the same comparison
            // `recordRevisionForEventlessWrite()` makes, for the same reason.
            $scalarBefore = $this->versionedState();

            // ⚠️ ONE version, recorded at the END. The scalar save fires
            // `updated`, so a revision filed there described the entry with its
            // OLD relations — and when nothing scalar changed it filed nothing at
            // all, leaving the newest revision not describing the entry. Both
            // halves are one restore, so recording waits for both.
            RevisionWrites::suspend(function () use ($revision, $state): void {
                $this->fill($revision->snapshot())->save();

                // `null` means the revision predates the column and says nothing
                // about relations. `[]` means it says there were none.
                if ($state !== null) {
                    $this->replaceRelations($state);
                }
            });

            if ($this->versionedState() !== $scalarBefore || $this->relationState() !== $relationsBefore) {
                $this->recordRevision();
            }
        });

        return $this;
    }

    /**
     * A relation snapshot with the keys whose field storage has since gone removed.
     *
     * ⚠️ ONE implementation, called by the validation and by the rebuild, because
     * the two disagreeing is precisely the defect this exists to fix. A key
     * discarded by one and enforced by the other makes a revision unrestorable for
     * a reason neither half intends.
     *
     * Discarded rather than refused: deleting the storage row already nulled those
     * pivots (`nullOnDelete`), so the relation is gone as a concept — there is no
     * field left to restore it into, and no version of this entry that could have
     * it back. Refusing would make every revision written before that field was
     * removed permanently unrestorable. It is the same rule `relationState()`
     * applies when it skips a null `field_storage_id`.
     *
     * @param  array<array-key, mixed>  $state
     * @return array<array-key, mixed>
     */
    private static function withLiveStorageOnly(array $state): array
    {
        $live = FieldStorage::query()
            ->whereKey(array_map('intval', array_keys($state)))
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        return array_intersect_key($state, array_flip($live));
    }

    /**
     * Refuse a restore whose recorded targets are no longer there.
     *
     * ⚠️ Separate from the rebuild, and run BEFORE any write, because the
     * message promises nothing has changed.
     *
     * Quietly restoring the subset would report success for a version the author
     * cannot actually have back, and `entry_relations` cascades on delete so the
     * row would fail its foreign key regardless — this says why instead of
     * surfacing a constraint error.
     *
     * @param  array<string, list<int>|null>  $state
     */
    private function refuseMissingTargets(EntryRevision $revision, array $state): void
    {
        // ⚠️ Unknown fields are skipped, not flattened. A null marks a field
        // whose history was erased, and casting it to an int produced target 0 —
        // refusing every restore of a redacted revision because "entry 0 no
        // longer exists".
        // ⚠️ Filtered to LIVE storage first, so this agrees with the rebuild.
        //
        // `replaceRelations()` discards a key whose storage no longer exists — the
        // field is gone as a concept, so there is nothing to restore it into. This
        // check did not, so a revision naming a relation whose storage AND target
        // had both been deleted was refused for the missing target, even though the
        // rebuild would have thrown that key away regardless. An unrelated removed
        // field made the revision permanently unrestorable.
        //
        // Two filters that must agree, so there is one of them.
        $targets = collect(self::withLiveStorageOnly($state))
            ->filter(fn (mixed $ids): bool => $ids !== null)
            ->flatten()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique();

        if ($targets->isEmpty()) {
            return;
        }

        $missing = $targets->diff(
            self::query()->withoutGlobalScopes()->whereKey($targets->all())->pluck('id'),
        );

        if ($missing->isEmpty()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Revision [%s] relates this entry to %s, which no longer exist%s. Restoring it '
            .'would put back a version this entry cannot have again, so nothing has been '
            .'changed (ADR-015).',
            (string) $revision->getKey(),
            'entr'.($missing->count() === 1 ? 'y ' : 'ies ').$missing->implode(', '),
            $missing->count() === 1 ? 's' : '',
        ));
    }

    /**
     * Make this entry's field-backed relations exactly what the snapshot says.
     *
     * ⚠️ REPLACES, and an earlier version patched. It deleted only the storage
     * ids present in the snapshot, so a relation added to a DIFFERENT field after
     * the revision was taken survived a restore — and an empty snapshot was
     * treated as "nothing recorded" and skipped entirely, leaving every current
     * link attached while reporting a successful restore. `[]` is a statement:
     * at that version, this entry had no relations.
     *
     * Only field-backed rows are touched. A row whose `field_storage_id` is NULL
     * belongs to a deleted field definition, is not addressable by any field, and
     * was never recorded — so a restore has nothing to say about it and must not
     * remove it.
     *
     * @param  array<string, list<int>|null>  $state
     */
    private function replaceRelations(array $state): void
    {
        // ⚠️ A key whose value is NULL is UNKNOWN, not empty. Its history was
        // erased, so the revision says nothing about that field and a restore must
        // leave whatever is there now alone — otherwise restoring a redacted
        // revision deletes a replacement relation added after the erasure, which
        // is the opposite of what erasure is for.
        $unknown = array_map(
            'intval',
            array_keys(array_filter($state, fn (mixed $ids): bool => $ids === null)),
        );

        // Everything field-backed goes, except the fields marked unknown.
        EntryRelation::query()
            ->where('source_entry_id', $this->getKey())
            ->whereNotNull('field_storage_id')
            ->when($unknown !== [], fn ($query) => $query->whereNotIn('field_storage_id', $unknown))
            ->delete();

        // ⚠️ A snapshot can name storage that no longer exists, and the insert
        // below would hit the `entry_relations.field_storage_id` foreign key —
        // failing the whole restore because an unrelated field was removed.
        //
        // DISCARDED rather than refused, unlike a missing target entry. Deleting
        // the storage row already nulled those pivots (`nullOnDelete`), so the
        // relation is gone as a concept: there is no field left to restore it
        // into, and no version of this entry that could have it back. Refusing
        // would make every revision written before that field was removed
        // permanently unrestorable. It is the same rule `relationState()` applies
        // when it skips a null `field_storage_id`.
        foreach (self::withLiveStorageOnly($state) as $storageId => $ids) {
            // Unknown: nothing was deleted above and nothing is rebuilt here.
            if ($ids === null) {
                continue;
            }

            foreach ($ids as $ordering => $targetId) {
                EntryRelation::create([
                    // ⚠️ `org_id` explicitly. The `related()` relation supplies
                    // it through `withPivotValue()`, and writing `EntryRelation`
                    // directly does not — it defaulted to 0 and the model's own
                    // guard refused the row, rolling the whole restore back. A
                    // relation belongs to the org of the entry it hangs off.
                    'org_id' => $this->org_id,
                    'source_entry_id' => $this->getKey(),
                    'target_entry_id' => (int) $targetId,
                    'field_storage_id' => (int) $storageId,
                    'ordering' => $ordering,
                ]);
            }
        }
    }

    /** Run a save that leaves no revision behind. */
    public function withoutRevisions(callable $work): mixed
    {
        // ⚠️ Delegates to a SHARED flag rather than holding it on the instance.
        //
        // An instance property was right while `created` and `updated` were the
        // only recorders. Recording now also happens in the builder, which has
        // no instance to read — so an instance-private flag would stand down one
        // recorder and not the other, exactly as `withoutScopeBecause()` once
        // did before `ScopeWrites` existed. Erasure depends on this covering
        // both: redacting inside `withoutRevisions()` is what stops the redacted
        // state being filed as a new version of the history being cleared.
        return RevisionWrites::suspend(fn (): mixed => $work($this));
    }

    /** @return HasMany<EntryRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(EntryRevision::class);
    }

    /**
     * Outgoing relations through the real table (ADR-015).
     *
     * @return GuardedBelongsToMany<Entry, $this>
     */
    public function related(): GuardedBelongsToMany
    {
        return $this->relationsThrough('source_entry_id', 'target_entry_id');
    }

    /**
     * The reverse lookup that JSON storage cannot answer without a full scan:
     * "what references this asset?"
     *
     * @return GuardedBelongsToMany<Entry, $this>
     */
    public function referencedBy(): GuardedBelongsToMany
    {
        return $this->relationsThrough('target_entry_id', 'source_entry_id');
    }

    /**
     * Both relation directions, with org_id stamped and constrained.
     *
     * withPivotValue() does two jobs and both are wanted. It supplies org_id
     * on attach, without which every attach fails on a NOT NULL constraint —
     * the pivot has no model, so nothing else can stamp it, and each call
     * site would otherwise have to remember. It also constrains reads to the
     * same org, so a relation row belonging to another customer cannot
     * surface here even if one existed.
     *
     * A relation always belongs to the org of the entry it hangs off, so
     * there is no case where these could legitimately differ. org_id is
     * non-nullable and EnforcesScope stamps it on create, so it is always
     * available here.
     *
     * @return GuardedBelongsToMany<Entry, $this>
     */
    private function relationsThrough(string $foreignPivotKey, string $relatedPivotKey): GuardedBelongsToMany
    {
        // Constructed rather than via belongsToMany(), so this relation —
        // and only this one — serialises its writes. The cardinality check in
        // EntryRelation is a count followed by an insert, and two of those
        // interleave: concurrent attaches to the same single-valued relation
        // both count zero and both insert, restoring the two-subject state
        // the check exists to prevent (ADR-020).
        $relation = new GuardedBelongsToMany(
            self::query(),
            $this,
            'entry_relations',
            $foreignPivotKey,
            $relatedPivotKey,
            $this->getKeyName(),
            $this->getKeyName(),
            __FUNCTION__,
        );

        $relation
            // A pivot MODEL, so the field's own cardinality and targetTypes
            // are enforced when a row is written. `attach()` goes nowhere
            // near validation, so without this they were metadata nobody
            // checked — see EntryRelation.
            ->using(EntryRelation::class)
            ->withPivot(['org_id', 'field_storage_id', 'ordering']);

        // org_id is declared non-nullable, and EnforcesScope stamps it on
        // create, so by the time a relation is reached it is always set.
        return $relation->withPivotValue('org_id', $this->org_id);
    }
}
