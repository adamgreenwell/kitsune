<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

/**
 * A named set of permissions, owned by one org — ADR-033.
 *
 * ⚠️ THIS IS THE ONLY SCOPED THING IN THE RBAC LAYER, and everything else leans on it. `role_permissions`
 * carries no `org_id` and `role_user` carries no constraint about membership; both are safe because every
 * read goes through this model, which is `#[OrgScoped]` and enforces it. A resolver that reached
 * `role_permissions` directly would be unscoped, which is why `Permissions` does not.
 *
 * @property int $id
 * @property int $org_id
 * @property string $handle
 * @property string $name
 * @property bool $is_owner
 */
#[OrgScoped]
class Role extends Model
{
    use EnforcesScope;

    protected $guarded = [];

    protected $casts = ['is_owner' => 'boolean'];

    /** @return BelongsTo<Org, $this> */
    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class);
    }

    /** @return HasMany<RolePermission, $this> */
    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    /**
     * Grant a permission, refusing anything the registry does not recognise.
     *
     * ⚠️ VALIDATED HERE RATHER THAN TRUSTED, because a permission is a string and a misspelled one is
     * silently never granted — it fails closed, which is right, and invisibly, which is not. ADR-033 takes
     * the `pii_class` pattern from ADR-020: the answer is required now, and getting it wrong is a security
     * defect rather than a formatting one.
     */
    public function grant(string $permission): RolePermission
    {
        $permission = Permissions::validated($permission);

        /** @var RolePermission $row */
        $row = $this->permissions()->firstOrCreate(['permission' => $permission]);

        return $row;
    }

    /** Remove a grant. Silent when it was not held, because the end state is what was asked for. */
    public function revoke(string $permission): void
    {
        $this->permissions()->where('permission', $permission)->delete();
    }
}
