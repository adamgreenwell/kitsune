<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use RuntimeException;

/**
 * `ProvisionsMembership` and the panel's tenancy, implemented as the skeleton's `User` implements them — for the first
 * owner's tests (ADR-026, as amended).
 *
 * ⚠️ WITH A SWITCH FOR EACH WAY A HOST COULD GET IT WRONG, because core's post-conditions are what catch a host that
 * does, and a post-condition only a correct host ever meets is one nothing tests:
 * - `rehash`: hashes the hash it was given, so the typed password would never sign in;
 * - `org` / `site`: that admission writes nothing;
 * - `null-email`: stores no address, a real NOT NULL failure whose bindings carry the hash;
 * - `throw-site`: site admission writes its row, then throws;
 * - `panel`: the panel refuses the account (`FirstOwnerPanelUser` only).
 */
trait ProvisionsLikeTheSkeleton
{
    /** @var 'rehash'|'org'|'site'|'null-email'|'throw-site'|'panel'|null */
    public static ?string $break = null;

    /** A connection name for the model other than the default — what `Permissions::assignmentsAreAbout()` refuses. */
    public static ?string $connectionOverride = null;

    public static function reset(): void
    {
        static::$break = null;
        static::$connectionOverride = null;
    }

    public function getConnectionName()
    {
        return static::$connectionOverride ?? parent::getConnectionName();
    }

    public static function provisionAccount(string $email, string $name, #[\SensitiveParameter] string $passwordHash): static
    {
        return static::query()->forceCreate([
            'name' => $name,
            'email' => static::$break === 'null-email' ? null : $email,
            'password' => static::$break === 'rehash' ? Hash::make($passwordHash) : $passwordHash,
        ]);
    }

    public function admitToOrg(Org $org): void
    {
        if (static::$break !== 'org') {
            $this->orgs()->attach($org->getKey());
        }
    }

    public function admitToSite(Site $site): void
    {
        if (static::$break !== 'site') {
            $this->sites()->attach($site->getKey());
        }

        if (static::$break === 'throw-site') {
            throw new RuntimeException('the host failed to let the owner in, for the sake of argument');
        }
    }

    /** @return BelongsToMany<Site, $this> */
    public function sites(): BelongsToMany
    {
        /* The keys named: Laravel derives them from the class, and only the skeleton's is called `User`. */
        return $this->belongsToMany(Site::class, 'site_user', 'user_id', 'site_id');
    }

    /** @return Collection<int, Site> */
    public function getTenants(Panel $panel): Collection
    {
        return Site::withoutScopeBecause(
            'tenant bootstrap, as the skeleton: the org context is derived from this result, so it cannot constrain it',
            fn ($query) => $query->whereIn('id', $this->sites()->allRelatedIds())->get(),
        );
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->sites()->allRelatedIds()->contains($tenant->getKey());
    }
}
