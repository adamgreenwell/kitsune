<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection;
use Kitsune\Core\Auth\Contracts\ProvisionsMembership;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Attributes\OrgScopedThroughPivot;

/**
 * The contract and the tenancy, and the scope attribute — but not `EnforcesScope`, so no membership scope is registered,
 * and the first owner's bootstrap refuses it (ADR-026, as amended).
 */
#[OrgScopedThroughPivot(table: 'org_user', foreignKey: 'user_id')]
class FirstOwnerUnscopedUser extends Authenticatable implements HasTenants, ProvisionsMembership
{
    protected $table = 'users';

    protected $guarded = [];

    public static function provisionAccount(string $email, string $name, #[\SensitiveParameter] string $passwordHash): static
    {
        return static::query()->forceCreate(['email' => $email, 'name' => $name, 'password' => $passwordHash]);
    }

    public function admitToOrg(Org $org): void {}

    public function admitToSite(Site $site): void {}

    /** @return Collection<int, Site> */
    public function getTenants(Panel $panel): Collection
    {
        return collect();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return false;
    }
}
