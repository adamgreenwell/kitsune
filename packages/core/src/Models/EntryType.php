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
use Kitsune\Core\Exceptions\ReservedHandleException;
use Kitsune\Core\Tenancy\Attributes\Unscoped;
use Kitsune\Core\Tenancy\Context;

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
