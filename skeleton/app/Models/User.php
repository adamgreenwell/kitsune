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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Kitsune\Core\Models\Site;

/**
 * Users are deliberately NOT entries (ADR-016) — different lifecycle,
 * different privacy obligations, and a different deletion story. Erasing a
 * user must not cascade-delete their articles.
 */
class User extends Authenticatable implements FilamentUser, HasTenants
{
    use Notifiable;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed'];
    }

    /** @return BelongsToMany<Site, $this> */
    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'site_user');
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
