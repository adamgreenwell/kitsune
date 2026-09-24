<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Kitsune\Core\Auth\RevokesRoleAssignments;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Attributes\OrgScopedThroughPivot;

/**
 * A host user the panel can admit and give sites to — `TestUser`, plus what the skeleton's `User` adds for Filament.
 *
 * ⚠️ THE ATTRIBUTES ARE RESTATED, BECAUSE PHP DOES NOT INHERIT THEM. Without `#[OrgScopedThroughPivot]` here this
 * model declares no scope, and membership — which `Permissions` asks of the model's own scoped query — would fail.
 *
 * The sites a user reaches and whether the panel admits them are held on the instance rather than in a `site_user`
 * table, which core's suite does not have: the gate asks `getTenants()`, not the table, so this is the seam it uses.
 */
#[ObservedBy(RevokesRoleAssignments::class)]
#[OrgScopedThroughPivot(table: 'org_user', foreignKey: 'user_id')]
class PanelUser extends TestUser implements FilamentUser, HasTenants
{
    /** @var list<int> */
    public array $reachableSiteIds = [];

    public bool $admittedToPanel = true;

    /** The stored password hash, which the test table has no column for; changing it is a password changed elsewhere. */
    public string $passwordHash = 'hash-before';

    /**
     * Sites listed by `getTenants()` that `canAccessTenant()` still refuses — a host's two answers can disagree, and
     * Filament asks the second one at the door of each site.
     *
     * @var list<int>
     */
    public array $refusedSiteIds = [];

    public function getAuthPassword(): string
    {
        return $this->passwordHash;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->admittedToPanel;
    }

    /** @return Collection<int, Site> */
    public function getTenants(Panel $panel): Collection
    {
        return Site::withoutScopeBecause(
            'tenant bootstrap in a test host, as the skeleton\'s User does it',
            fn ($query) => $query->whereIn('id', $this->reachableSiteIds)->get(),
        );
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return in_array((int) $tenant->getKey(), $this->reachableSiteIds, true)
            && ! in_array((int) $tenant->getKey(), $this->refusedSiteIds, true);
    }
}
