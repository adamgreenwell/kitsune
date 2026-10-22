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
use Illuminate\Database\Query\Builder as QueryBuilder;
use Kitsune\Core\Tenancy\Attributes\Unscoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

/**
 * Per-site entry type availability, on the same sparse inheritance as
 * settings (ADR-022).
 *
 * Sparse means a row exists only where someone made a decision. Absent at
 * every level is enabled — the same rule settings use, rather than a second
 * inheritance system to learn and debug.
 *
 * Unscoped by declaration: rows are reached through an EntryType and a Site,
 * both of which are already constrained, and the resolution below walks the
 * hierarchy explicitly rather than relying on a global scope.
 *
 * @property int $id
 * @property int $entry_type_id
 * @property string $scope_type
 * @property int $scope_id
 * @property bool $is_enabled
 */
#[Unscoped]
class EntryTypeAvailability extends Model
{
    use EnforcesScope;

    public const TABLE = 'entry_type_availability';

    protected $table = self::TABLE;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['is_enabled' => 'boolean'];

    /**
     * Most specific decision wins: site, then site group, then org.
     *
     * With no site there is nothing to disable against, so the type is
     * available — this path is reached by console commands and the API
     * before any site is established.
     */
    public static function isEnabledFor(EntryType $type, ?Site $site): bool
    {
        return static::enabledMapFor(
            [$type->getKey()],
            $site?->getKey(),
            $site?->site_group_id,
            $site?->org_id,
        )[$type->getKey()] ?? true;
    }

    /**
     * Resolve availability for many types in ONE query.
     *
     * Navigation asks about every type an org owns. Called per type, that is
     * a query each — roughly 202 on the 201-type case ADR-012 measured, on a
     * request whose whole point was being flat in N. It also loaded every
     * availability row for a type, including decisions belonging to other
     * sites entirely.
     *
     * This fetches only the three scope keys that can apply to this site,
     * for all candidate types at once, and resolves precedence in memory.
     *
     * Takes the three scope keys rather than the Site, because callers
     * memoise on what they pass in. A `once()` key that contains an object
     * is keyed by `spl_object_id`, and PHP recycles those handles — see
     * `EntryType::visibleFor()`, where that produced a real defect.
     *
     * @param  array<int, int|string>  $typeIds
     * @return array<int|string, bool>
     */
    public static function enabledMapFor(
        array $typeIds,
        int|string|null $siteId,
        int|string|null $siteGroupId,
        int|string|null $orgId,
    ): array {
        if ($typeIds === [] || $siteId === null) {
            return [];
        }

        $rows = static::query()
            ->whereIn('entry_type_id', $typeIds)
            ->where(function ($query) use ($siteId, $siteGroupId, $orgId): void {
                foreach (self::levels($siteId, $siteGroupId, $orgId) as [$type, $id]) {
                    $query->orWhere(fn ($q) => $q->where('scope_type', $type)->where('scope_id', $id));
                }
            })
            ->get()
            ->groupBy('entry_type_id');

        $resolved = [];

        foreach ($typeIds as $id) {
            $forType = $rows->get($id, collect())
                ->keyBy(fn (self $row): string => $row->scope_type.':'.$row->scope_id);

            $resolved[$id] = true; // absent at every level = enabled (ADR-022)

            foreach (self::levels($siteId, $siteGroupId, $orgId) as [$type, $scopeId]) {
                $key = $type.':'.$scopeId;
                if ($forType->has($key)) {
                    $resolved[$id] = $forType->get($key)->is_enabled;
                    break;
                }
            }
        }

        return $resolved;
    }

    /**
     * Apply the SAME sparse precedence before an entries page's offset. A false decision excludes an entry only when
     * no more-specific decision exists. All subqueries are in the one candidate read; no per-type PHP query.
     * Fixed internal tables/columns only, so the query builder owns quoting and configured table prefixes.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     *
     * @internal The private-offer list, not a new availability extension API.
     */
    public static function constrainEnabledEntries(Builder $query, Site $site): Builder
    {
        $levels = self::levels($site->getKey(), $site->site_group_id, $site->org_id);

        foreach ($levels as $position => [$type, $id]) {
            $higher = array_slice($levels, 0, $position);
            $query->whereNotExists(function (QueryBuilder $decision) use ($type, $id, $higher): void {
                $decision->select('disabled_type.entry_type_id')
                    ->from(self::TABLE.' as disabled_type')
                    ->whereColumn('disabled_type.entry_type_id', 'entries.entry_type_id')
                    ->where('disabled_type.scope_type', $type)
                    ->where('disabled_type.scope_id', $id)
                    ->where('disabled_type.is_enabled', false);

                if ($higher !== []) {
                    $decision->whereNotExists(function (QueryBuilder $override) use ($higher): void {
                        $override->select('higher_type.entry_type_id')->from(self::TABLE.' as higher_type')
                            ->whereColumn('higher_type.entry_type_id', 'entries.entry_type_id')
                            ->where(function (QueryBuilder $scopes) use ($higher): void {
                                foreach ($higher as [$type, $id]) {
                                    $scopes->orWhere(fn (QueryBuilder $scope) => $scope
                                        ->where('higher_type.scope_type', $type)->where('higher_type.scope_id', $id));
                                }
                            });
                    });
                }
            });
        }

        return $query;
    }

    /** @return list<array{0: string, 1: int|string|null}> ordered most-specific first, shared by both reads */
    private static function levels(int|string|null $siteId, int|string|null $siteGroupId, int|string|null $orgId): array
    {
        return [['site', $siteId], ['site_group', $siteGroupId], ['org', $orgId]];
    }
}
