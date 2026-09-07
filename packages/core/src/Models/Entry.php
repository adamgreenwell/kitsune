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
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

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
class Entry extends Model
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
        static::saving(function (self $entry): void {
            if ($entry->isDirty('entry_type_id')) {
                $entry->type_handle = EntryType::query()
                    ->whereKey($entry->entry_type_id)
                    ->value('handle') ?? $entry->type_handle;
            }
        });
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
            StorageStrategy::Promoted => $this->getAttribute($storage->handle),
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

        $query->where('entry_type_id', $type->getKey());

        // Where the value lives decides where the predicate goes: a pivot
        // row, a real column, or a JSON path.
        return match ($storage->strategy()) {
            StorageStrategy::Relational => $query->whereExists(function ($pivot) use ($storage, $identifier): void {
                $pivot->selectRaw('1')
                    ->from('entry_relations')
                    ->whereColumn('entry_relations.source_entry_id', 'entries.id')
                    ->where('entry_relations.field_storage_id', $storage->getKey())
                    ->where('entry_relations.target_entry_id', $identifier);
            }),
            StorageStrategy::Promoted => $query->where($storage->handle, $identifier),
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
        $storage = FieldStorage::query()
            ->where('handle', $handle)
            ->whereHas('fields', fn (Builder $query): Builder => $query->where('entry_type_id', $this->entry_type_id))
            ->first();

        // Erasure has to reach wherever the value actually lives.
        //
        // A relational field's data is rows in `entry_relations`, with
        // nothing to replace in place — so erasure IS the detach. A promoted
        // field is a real column on `entries`. Both fell through to the JSON
        // path, found no key, and reported 0 while the data survived.
        if ($storage?->strategy() === StorageStrategy::Relational) {
            return $this->related()->wherePivot('field_storage_id', $storage->getKey())->detach();
        }

        if ($storage?->strategy() === StorageStrategy::Promoted) {
            if ($this->getAttribute($storage->handle) === $replacement) {
                return 0;
            }

            $this->setAttribute($storage->handle, $replacement);
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
     * @return BelongsToMany<Entry, $this>
     */
    public function related(): BelongsToMany
    {
        return $this->relationsThrough('source_entry_id', 'target_entry_id');
    }

    /**
     * The reverse lookup that JSON storage cannot answer without a full scan:
     * "what references this asset?"
     *
     * @return BelongsToMany<Entry, $this>
     */
    public function referencedBy(): BelongsToMany
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
     * @return BelongsToMany<Entry, $this>
     */
    private function relationsThrough(string $foreignPivotKey, string $relatedPivotKey): BelongsToMany
    {
        $relation = $this->belongsToMany(self::class, 'entry_relations', $foreignPivotKey, $relatedPivotKey)
            ->withPivot(['org_id', 'field_storage_id', 'ordering']);

        // org_id is declared non-nullable, and EnforcesScope stamps it on
        // create, so by the time a relation is reached it is always set.
        return $relation->withPivotValue('org_id', $this->org_id);
    }
}
