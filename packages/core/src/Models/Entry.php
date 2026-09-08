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
use Kitsune\Core\Audit\Auditor;
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
        foreach (['created', 'restored'] as $event) {
            static::{$event}(function (self $entry) use ($event): void {
                app(Auditor::class)->record("entry.{$event}", $entry);
            });
        }

        // ⚠️ `deleted` fires during a force-delete too, so recording it
        // unconditionally gave a permanently removed entry a SECOND
        // `entry.deleted` and never said it had become irrecoverable — the
        // one deletion an operator most needs to find in the trail.
        static::deleted(function (self $entry): void {
            if ($entry->isForceDeleting()) {
                return;
            }

            app(Auditor::class)->record('entry.deleted', $entry);
        });

        static::forceDeleted(function (self $entry): void {
            app(Auditor::class)->record('entry.force_deleted', $entry);
        });

        // ⚠️ `updated` is separate, because a RESTORE is an update too:
        // `restore()` nulls `deleted_at` and saves, so a delete/restore pair
        // was recorded as `entry.deleted` then `entry.updated` — the actual
        // lifecycle action hidden behind an ordinary-looking edit. The
        // `restored` listener above names it, and this one steps aside.
        static::updated(function (self $entry): void {
            if ($entry->wasChanged($entry->getDeletedAtColumn())) {
                return;
            }

            app(Auditor::class)->record('entry.updated', $entry);
        });

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
