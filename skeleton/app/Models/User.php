<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Attributes\OrgScopedThroughPivot;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

/**
 * Users are deliberately NOT entries (ADR-016) — different lifecycle,
 * different privacy obligations, and a different deletion story. Erasing a
 * user must not cascade-delete their articles.
 *
 * ⚠️ ORG-SCOPED THROUGH A PIVOT, and both halves of that matter.
 *
 * ADR-021 lists users among the org-scoped models. Two things made that
 * unimplementable until now, and each needed its own answer:
 *
 *  1. Users belong to MANY orgs, so `OrgScope`'s `org_id = current` never
 *     applied. `architecture.md` §3 models it through `org_user`, and
 *     `#[OrgScopedThroughPivot]` tests membership rather than reading a
 *     column.
 *
 *  2. Authentication resolves a user BEFORE any org context exists, and the
 *     scope fails closed with no org — so login would match nobody. That
 *     carve-out lives in `OrgAwareUserProvider`, because Laravel's provider
 *     builds its own query and never calls a method on this model — a
 *     carve-out here would have looked right and never run.
 *
 * The exposure this closes: `User` was globally readable, so any admin UI
 * listing users would have enumerated every account on the installation.
 * Nothing listed users yet, which is why it was a gap rather than an
 * incident (issue #21).
 */
#[OrgScopedThroughPivot(table: 'org_user', foreignKey: 'user_id')]
class User extends Authenticatable implements FilamentUser, HasLocalePreference, HasTenants
{
    // ⚠️ Without this the attribute above is documentation, not enforcement.
    // `User` declared `#[Unscoped]` for greppability and applied no trait, so
    // nothing would have enforced the new scope either — a model can be
    // labelled correctly and still be unconstrained.
    use EnforcesScope;
    use Notifiable;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed'];
    }

    /**
     * The locale this user reads the admin in, or null for no preference.
     *
     * ⚠️ Laravel's OWN contract (`HasLocalePreference`), not a Kitsune interface.
     * Core must not require a particular auth schema — ADR-002 keeps it
     * headless-capable and the users table belongs to the application — so it asks
     * this and never learns that the answer is a column. An application that keeps
     * the preference in a settings blob, an identity provider claim, or nowhere at
     * all satisfies the same contract.
     *
     * Null is a real answer and a different one from any locale: it means fall
     * through to the site's, which is what `LocaleResolver` does.
     */
    public function preferredLocale(): ?string
    {
        return $this->locale;
    }

    /** @return BelongsToMany<Site, $this> */
    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'site_user');
    }

    /** @return BelongsToMany<Org, $this> */
    public function orgs(): BelongsToMany
    {
        return $this->belongsToMany(Org::class, 'org_user');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * The bootstrap query, and the one place the scope must be stood down.
     *
     * Site is #[OrgScoped], so this query is normally constrained to the
     * current org — but the current org is derived FROM the resolved tenant,
     * which is what this method returns. Scoped, it returns nothing, the user
     * appears to belong to no sites, and /admin 404s.
     *
     * Standing the scope down here is safe because authorisation comes from
     * the site_user pivot rather than from org context: a user can only ever
     * reach sites explicitly granted to them. The reason is recorded in the
     * call so it is greppable in review.
     *
     * @return Collection<int, Site>
     */
    public function getTenants(Panel $panel): Collection
    {
        return Site::withoutScopeBecause(
            'tenant bootstrap: the org context is derived from this result, so it cannot constrain it',
            fn ($query) => $query->whereIn('id', $this->sites()->allRelatedIds())->get(),
        );
    }

    public function canAccessTenant(Model $tenant): bool
    {
        // Same reasoning: the pivot is the authority, not org context.
        return $this->sites()->allRelatedIds()->contains($tenant->getKey());
    }
}
