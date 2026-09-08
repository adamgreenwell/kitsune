<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Models;

use Filament\Facades\Filament;
use Filament\Models\Contracts\HasTenants;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Contracts\RefusesCascadingDeletes;
use RuntimeException;

/**
 * Anything with its own base URL. Carries the locale (ADR-021), so
 * golfdom.com and golfdom.fr are two sites in one group rather than a site
 * with a locale axis bolted on.
 *
 * Org-scoped, not site-scoped: a Site cannot be scoped to itself, and the
 * site switcher must list every site the current org owns.
 */
/**
 * @property int $id
 * @property int $org_id
 * @property int|null $site_group_id
 * @property string $handle
 * @property string $slug
 * @property string $name
 * @property string $locale
 * @property string $url_strategy
 * @property string|null $base_url
 * @property bool $is_primary
 * @property array<string, mixed>|null $settings
 */
#[OrgScoped]
class Site extends Model implements RefusesCascadingDeletes
{
    use EnforcesScope;

    protected $guarded = [];

    protected $casts = [
        'settings' => 'array',
        'is_primary' => 'boolean',
    ];

    /**
     * The route key is the globally unique slug, never the org-unique handle.
     *
     * /admin/{site} carries no org segment, so the segment identifying a site
     * must be unique across the installation. handle is unique only within an
     * org, which means two customers may both use "golfdom" — and for a user
     * who belongs to both orgs, that URL would be genuinely ambiguous rather
     * than merely awkward, silently opening the wrong customer's site.
     */
    /**
     * ⚠️ Refuses while entries still reference it, because the database would
     * remove them itself.
     *
     * `entries.site_id` and `entries.entry_type_id` are both
     * `cascadeOnDelete`, so `$site->delete()` removed every entry INSIDE the
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
        static::deleting(fn (self $site) => $site->guardCascade());
    }

    /**
     * ⚠️ Refuses while entries still reference it, because the database would
     * remove them itself.
     *
     * `entries.site_id` is `cascadeOnDelete`, so deleting this removed every
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
    public function guardCascade(): void
    {
        $entries = Entry::withoutScopeBecause(
            'counting entries before their site is deleted, to refuse rather than cascade',
            fn ($query) => $query->withTrashed()->where('site_id', $this->getKey())->count(),
        );

        if ($entries === 0) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Site [%s] still holds %d entr%s, and the database would delete them by cascade — '
            .'permanently, with nothing in the audit trail saying they existed (ADR-020). Delete '
            .'the entries first, which is audited.',
            $this->handle,
            $entries,
            $entries === 1 ? 'y' : 'ies',
        ));
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Resolve a site from the URL without its own org scope.
     *
     * This is the bootstrap path and it cannot be scoped by the thing it
     * bootstraps: the current org is derived FROM the resolved site, so
     * applying OrgScope here means the lookup never matches and every admin
     * URL 404s. Found by driving the panel in a browser, not by reasoning.
     *
     * Isolation is NOT weakened. Resolution only turns a URL segment into a
     * candidate; authorisation is the pivot check in canAccessTenant(), and
     * every other Site query keeps the scope. Defence in depth is preserved
     * because the scope is stood down here alone, not on the model.
     *
     * @param  mixed  $value
     * @return $this|null
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        $user = auth()->user();

        return static::withoutScopeBecause(
            'tenant bootstrap: the org context is derived from this lookup, so it cannot constrain it',
            function ($query) use ($value, $field, $user) {
                $query->where($field ?? $this->getRouteKeyName(), $value);

                // Handles are unique per org, not globally — UNIQUE (org_id,
                // handle) — so two customers may both own a site called
                // "golfdom". Without narrowing to the signed-in user's sites,
                // this returns whichever row the engine happens to order
                // first, canAccessTenant() then rejects that wrong candidate,
                // and a legitimate user cannot reach their own admin. Which
                // customer breaks depends on row order.
                //
                // The pivot is the authority here, exactly as it is in
                // canAccessTenant(), so narrowing by it costs no isolation.
                if ($user instanceof HasTenants) {
                    $accessible = $user->getTenants(Filament::getCurrentOrDefaultPanel())
                        ->map(fn ($tenant) => $tenant->getKey())
                        ->all();

                    $query->whereIn($this->getKeyName(), $accessible);
                }

                return $query->first();
            },
        );
    }

    /** @return BelongsTo<Org, $this> */
    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class);
    }

    /** @return BelongsTo<SiteGroup, $this> */
    public function siteGroup(): BelongsTo
    {
        return $this->belongsTo(SiteGroup::class);
    }
}
