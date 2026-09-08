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
use Kitsune\Core\Audit\AuditedBuilder;
use Kitsune\Core\Fields\FieldTypeRegistry;
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
        // ADR-020 primitive 4: actor, action and target. The audit rows are
        // written from model events rather than from the admin, so the API
        // and the console are audited by the same code path — an audit trail
        // that only covers the UI is an audit trail with a documented hole.
        // ⚠️ NOTHING is audited from model events. Every action, creation
        // included, is derived in AuditedBuilder — see the class docblock.
        // Listening here as well double-recorded ordinary writes, and a
        // `created` listener is silently skipped by createQuietly() and by
        // anything inside withoutEvents(), which insert the row regardless.
        static::saving(function (self $entry): void {
            if ($entry->isDirty('entry_type_id')) {
                $entry->type_handle = EntryType::query()
                    ->whereKey($entry->entry_type_id)
                    ->value('handle') ?? $entry->type_handle;
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
     * ⚠️ PROMOTED columns as well as `values`. A configured slug lives in
     * `entries.slug` because SlugType is promoted (ADR-015), so deriving held
     * fields from `values` alone never selected its storage row and left it
     * unlocked while holding live data — free to change shape afterwards.
     *
     * Relation rows are the third case, and they arrive with
     * `entry_relations` in #30.
     */
    private function lockStorageHoldingData(): void
    {
        $unlocked = FieldStorage::query()
            ->where('is_locked', false)
            ->whereHas('fields', fn (Builder $query) => $query->where('entry_type_id', $this->entry_type_id))
            ->get();

        if ($unlocked->isEmpty()) {
            return;
        }

        $registry = app(FieldTypeRegistry::class);
        $values = $this->values ?? [];

        $holding = $unlocked->filter(function (FieldStorage $storage) use ($registry, $values): bool {
            $column = $registry->get($storage->type)->promotedColumn();

            $value = $column !== null
                ? $this->getAttribute($column)
                : ($values[$storage->handle] ?? null);

            return $value !== null && $value !== '' && $value !== [];
        });

        if ($holding->isEmpty()) {
            return;
        }

        FieldStorage::query()->whereKey($holding->modelKeys())->update(['is_locked' => true]);
    }

    /**
     * Route every write through the auditing builder (ADR-020).
     *
     * ⚠️ Bulk writes dispatch NO per-model events, so listeners alone cover
     * the row-at-a-time path only: `Entry::query()->update()` and its
     * siblings would have changed or removed entries leaving no trace, while
     * the claim was that the API and the console go through the same code
     * path as the admin.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    public function newEloquentBuilder($query): AuditedBuilder
    {
        return new AuditedBuilder($query);
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
