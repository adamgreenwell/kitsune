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
use Kitsune\Core\Audit\AuditedBuilder;
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Relations\GuardedBelongsToMany;
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
 * @property string|null $slug
 * @property string|null $title
 * @property string $status
 * @property array<string, mixed>|null $values
 */
#[SiteScoped]
class Entry extends Model implements RequiresModelSave
{
    use EnforcesScope;
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'values' => 'array',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
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
        $storages = FieldStorage::query()
            ->where('handle', $handle)
            ->whereHas('fields', fn (Builder $query): Builder => $query->where('entry_type_id', $this->entry_type_id))
            ->get();

        if ($storages->count() > 1) {
            return (int) $storages->sum(
                fn (FieldStorage $storage): int => $this->redactStorage($handle, $storage, $replacement),
            );
        }

        return $this->redactStorage($handle, $storages->first(), $replacement);
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
            return EntryRelation::query()
                ->where('source_entry_id', $this->getKey())
                ->where('field_storage_id', $storage->getKey())
                ->delete();
        }

        if ($storage?->strategy() === StorageStrategy::Promoted) {
            // ⚠️ The declared column, NOT the handle. `field_storage` accepts
            // any valid handle for a `slug` field, so one called
            // `public_slug` still writes `entries.slug` — and reading the
            // handle read a column that does not exist, then reported a
            // successful erasure having erased nothing.
            $column = (string) $storage->promotedColumn();

            if ($this->getAttribute($column) === $replacement) {
                return 0;
            }

            $this->setAttribute($column, $replacement);
            $this->save();

            // Revisions snapshot `values` only, so a promoted column has no
            // revision history to sweep — the column IS the whole record.
            return 1;
        }

        $rewritten = 0;

        $values = $this->values ?? [];

        if (array_key_exists($handle, $values)) {
            $values[$handle] = $replacement;
            $this->values = $values;
            $this->save();
            $rewritten++;
        }

        foreach ($this->revisions()->get() as $revision) {
            $rewritten += $revision->redact($handle, $replacement) ? 1 : 0;
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
