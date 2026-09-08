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
use Illuminate\Support\Collection;
use Kitsune\Core\Exceptions\ReservedHandleException;
use Kitsune\Core\Tenancy\Attributes\Unscoped;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tenancy\Contracts\RefusesCascadingDeletes;
use Kitsune\Core\Tenancy\ScopedBuilder;
use RuntimeException;

/**
 * @property int $id
 * @property int|null $org_id
 * @property string $handle
 * @property string $name
 * @property string $plural_name
 * @property bool $is_system
 * @property array<string, mixed>|null $settings
 */
#[Unscoped]
class EntryType extends Model implements RefusesCascadingDeletes
{
    /**
     * Handles that would collide with a route segment (ADR-012).
     *
     * A type literally named "create" would make /c/create/create ambiguous.
     * Rejected at creation time rather than escaped later, because the
     * collision is with the URL contract, not with SQL.
     */
    public const RESERVED_HANDLES = [
        'create', 'edit', 'delete', 'update', 'view', 'index',
        // Registered by EntryResource::getPages(). Adding a page without
        // adding its segment here reopens the collision — this entry exists
        // because exactly that happened with /{type}/{record}/related.
        //
        // Both the URL segment and the page key are reserved. They differ
        // here ('related' vs 'relations'), and reserving one word nobody
        // needs as an entry type costs nothing next to a route collision
        // that cannot be escaped away.
        'related', 'relations',
    ];

    /**
     * The entry types a site may use, in display order.
     *
     * Core rather than the admin panel: ADR-002 keeps core headless-capable,
     * and the API needs the same answer the sidebar does.
     *
     * @return Collection<int, static>
     */
    public static function visibleFor(?Site $site, ?int $orgId = null): Collection
    {
        $orgId ??= $site?->org_id;

        // ⚠️ This closure captures SCALARS ONLY, and that is load-bearing.
        //
        // once() hashes the closure's captured variables, and hashes an
        // object by spl_object_id — which PHP recycles the moment an object
        // is collected. A memo keyed on the Site object therefore cannot
        // reliably tell two sites apart inside one process, and under Octane
        // or a queue worker one site is served the other's list.
        //
        // A first attempt captured the object AND an unused $siteKey to fix
        // the key. Pint stripped the unused variable from the use clause and
        // the bug came straight back. So the values that make the key correct
        // have to be values the body genuinely uses — which is why
        // enabledMapFor() takes scope keys rather than a Site.
        $siteId = $site?->getKey();
        $siteGroupId = $site?->site_group_id;

        return once(function () use ($orgId, $siteId, $siteGroupId) {
            return static::query()
                ->where(function (Builder $query) use ($orgId): void {
                    $query->whereNull('org_id');

                    if ($orgId !== null) {
                        $query->orWhere('org_id', $orgId);
                    }
                })
                ->orderBy('ordering')
                ->orderBy('handle')
                ->get()
                // Collapse shadowed handles FIRST, applying the same
                // precedence IdentifyEntryType uses: an org's own type wins
                // over the global one it shadows. Filtering before collapsing
                // produced two items pointing at one URL when both were
                // enabled, and — worse — kept the global item alive when the
                // org row that actually resolves was disabled, offering a
                // link guaranteed to 404.
                ->sortBy(fn (self $type): int => $type->org_id === null ? 1 : 0)
                ->unique('handle')
                ->pipe(function ($types) use ($siteId, $siteGroupId, $orgId) {
                    // One query for every type, not one per type: ADR-012's
                    // 201-type case would otherwise render ~202 queries.
                    $enabled = EntryTypeAvailability::enabledMapFor(
                        $types->pluck('id')->all(), $siteId, $siteGroupId, $orgId,
                    );

                    return $types->filter(fn (self $type): bool => $enabled[$type->getKey()] ?? true);
                })
                ->sortBy('ordering')
                ->values();
        });
    }

    protected $guarded = [];

    protected $casts = [
        'settings' => 'array',
        'is_system' => 'boolean',
    ];

    /**
     * Unscoped by declaration, constrained by query.
     *
     * org_id NULL means a global system type available to every org, so a
     * blanket OrgScope would hide exactly the rows every org needs. This
     * scope is therefore opt-in per query rather than global — and callers
     * that forget it see global types only, never another org's.
     *
     * @param  Builder<EntryType>  $query
     * @return Builder<EntryType>
     */
    public function scopeAvailableToCurrentOrg(Builder $query): Builder
    {
        $orgId = app(Context::class)->orgId();

        return $query->where(function (Builder $inner) use ($orgId): void {
            $inner->whereNull('org_id');

            if ($orgId !== null) {
                $inner->orWhere('org_id', $orgId);
            }
        });
    }

    /**
     * ⚠️ Refuses while entries still reference it, because the database would
     * remove them itself.
     *
     * `entries.entry_type_id` is `cascadeOnDelete`, so deleting this removed every
     * referenced entry INSIDE the database: no per-row model event, so no audit
     * row, and a hard DELETE, so Entry's SoftDeletes never applied and the rows
     * were unrecoverable. Verified by probe — three entries gone, zero audit
     * rows, the org still present, so not the org-cascade exception ADR-020
     * documents.
     *
     * Reachable from the BUILDER as well as from `deleting`, through
     * `RefusesCascadingDeletes`. Written only as a model event it covered one
     * path: `query()->delete()`, `deleteQuietly()` and `withoutEvents()` all
     * dispatch straight past it, which is the same shape found on four other
     * guards in this project.
     *
     * Deleting an ORG still takes everything. That cascade happens in the
     * database and fires nothing here, which is what keeps the documented
     * exception working.
     */
    /**
     * ⚠️ This model is #[Unscoped] and still needs the builder.
     *
     * It does not `use EnforcesScope` — global types must be visible to every
     * org, so it constrains by query instead — which meant it had no builder
     * at all, and the cascade refusal was reachable only through the model
     * event. `EntryType::query()->delete()` walked past it.
     *
     * ScopedBuilder's scope-key check is inert here rather than wrong: a global
     * type's `org_id` is NULL and skipped, and an org's own type matches the
     * context it is created in.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return ScopedBuilder<$this>
     */
    public function newEloquentBuilder($query): ScopedBuilder
    {
        return new ScopedBuilder($query, $this);
    }

    public function guardCascade(): void
    {
        $entries = Entry::withoutScopeBecause(
            'counting entries before their type is deleted, to refuse rather than cascade',
            fn ($query) => $query->withTrashed()->where('entry_type_id', $this->getKey())->count(),
        );

        if ($entries === 0) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Entry type [%s] still has %d entr%s, and the database would delete them by cascade — '
            .'permanently, with nothing in the audit trail saying they existed (ADR-020). Delete '
            .'the entries first, which is audited.',
            $this->handle,
            $entries,
            $entries === 1 ? 'y' : 'ies',
        ));
    }

    public function isReservedHandle(): bool
    {
        return in_array(strtolower($this->handle), self::RESERVED_HANDLES, true);
    }

    /**
     * ⚠️ Refuses while entries still reference it, because the database would
     * remove them itself.
     *
     * `entries.entry_type_id` are both
     * `cascadeOnDelete`, so `$type->delete()` removed every entry INSIDE the
     * database: no per-row model event, so no audit row, and a hard DELETE, so
     * Entry's SoftDeletes never applied and the rows were unrecoverable.
     * Verified by probe — three entries gone, zero audit rows, the org still
     * present, so not the documented org-cascade exception.
     *
     * AuditedBuilder's guarantee is "no Eloquent path creates, changes or
     * removes an entry without an audit row or a refusal", and it enumerates
     * where it stops: `toBase()` and `DB::`. This was neither. Refusing here
     * is the refusal that claim allows for, and it forces the removal through
     * the audited path where it belongs.
     *
     * Deleting an ORG still takes everything, which ADR-020 documents as the
     * intended exception — that cascade happens in the database and fires no
     * model event, so this guard correctly never sees it.
     */
    protected static function booted(): void
    {
        static::deleting(fn (self $type) => $type->guardCascade());

        // Rejected at creation time, not escaped later. The collision is with
        // the URL contract, not with SQL: a type named "create" would make
        // /c/create/create ambiguous, and no amount of escaping fixes that
        // (ADR-012). Knowing the handle is reserved was never the hard part —
        // enforcing it was, and until now nothing did.
        static::saving(function (self $type): void {
            if ($type->isDirty('handle') && $type->isReservedHandle()) {
                throw new ReservedHandleException($type->handle);
            }
        });
    }

    /** @return BelongsTo<Org, $this> */
    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class);
    }

    /** @return HasMany<Entry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    /** @return HasMany<Field, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(Field::class);
    }
}
