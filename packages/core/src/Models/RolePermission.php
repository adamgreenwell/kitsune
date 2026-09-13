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
use Kitsune\Core\Tenancy\Attributes\Unscoped;

/**
 * One grant: a role holds a permission name — ADR-033.
 *
 * ⚠️ `#[Unscoped]` FOR THE REASON `EntryRelation` AND `EntryRevision` GIVE, not because authorization is
 * global. It is reached only through `Role`, which is `#[OrgScoped]` and enforces it — so the scope is
 * applied one level up, where the column that expresses it actually lives. It carries no `org_id` of its
 * own precisely so there is no copy to drift from the role's, and therefore no guard to keep honest.
 *
 * ⚠️ WHICH MEANS A DIRECT QUERY HERE IS UNSCOPED, and that is the cost of the choice. Anything asking "who
 * holds this permission" goes through `Role::query()`, which is scoped; `Permissions` does, and a reviewer
 * should treat a bare `RolePermission::query()` in a read path as a defect.
 *
 * @property int $id
 * @property int $role_id
 * @property string $permission
 */
#[Unscoped]
class RolePermission extends Model
{
    /** A grant is created and destroyed, never edited — so there is nothing for timestamps to date. */
    public $timestamps = false;

    protected $guarded = [];

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
