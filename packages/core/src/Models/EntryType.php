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
use RuntimeException;

/**
 * @property int $id
 * @property int|null $org_id
 * @property string $handle
 * @property string $name
 * @property string $plural_name
 * @property bool $is_system
 * @property int|null $subject_field_id
 * @property array<string, mixed>|null $settings
 */
#[Unscoped]
class EntryType extends Model
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

    /**
     * The field that identifies the data subject (ADR-020 primitive #2).
     *
     * "Give me everything you hold about this person" is unanswerable without
     * knowing which field identifies the person. A `customer` type nominates
     * its email; a `ticket` type nominates its `person` relation.
     *
     * @return BelongsTo<Field, $this>
     */
    public function subjectField(): BelongsTo
    {
        return $this->belongsTo(Field::class, 'subject_field_id');
    }

    /**
     * The `values` key holding the subject identifier, or null if none is set.
     *
     * The handle comes from the field STORAGE, not from the per-type field
     * row: storage owns the handle, and the handle is the JSON key.
     */
    public function subjectHandle(): ?string
    {
        return $this->subjectField?->fieldStorage?->handle;
    }

    /**
     * Types holding personal data that nobody can answer a request about.
     *
     * The enforcement that actually works. A fail-closed guard at save time
     * cannot: the subject IS one of the type's fields, so refusing to save
     * until one is nominated makes adding the first field impossible. What
     * can be enforced is visibility — this is the list of holes, and it is
     * the honest answer to "is a subject-access request answerable here?"
     *
     * @return Builder<static>
     */
    public static function withoutSubjectIdentifier(): Builder
    {
        return static::query()
            ->whereNull('subject_field_id')
            ->whereHas('fields.fieldStorage', function (Builder $query): void {
                $query->whereIn('pii_class', ['personal', 'sensitive']);
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

    public function isReservedHandle(): bool
    {
        return in_array(strtolower($this->handle), self::RESERVED_HANDLES, true);
    }

    protected static function booted(): void
    {
        // A nomination pointing at another type's field would answer a
        // subject-access request with somebody else's data, which is worse
        // than answering it with nothing.
        static::saving(function (self $type): void {
            if ($type->subject_field_id === null) {
                return;
            }

            $field = Field::query()->find($type->subject_field_id);

            if ($field === null || ($type->exists && $field->entry_type_id !== $type->getKey())) {
                throw new RuntimeException(
                    "Field [{$type->subject_field_id}] cannot identify the subject of [{$type->handle}]: "
                    .'it does not belong to this entry type. A nomination pointing elsewhere would answer '
                    .'a subject-access request with another person\'s data (ADR-020).'
                );
            }
        });

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
