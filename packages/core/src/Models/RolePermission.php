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
use Illuminate\Database\Query\Builder;
use Kitsune\Core\Auth\GuardedGrantBuilder;
use Kitsune\Core\Tenancy\Attributes\Unscoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

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
 * ⚠️ AND THAT SENTENCE COVERED READS WHILE THE WRITES WERE WIDE OPEN, which review found. `Unscoped` means
 * no clause narrows a write either, and this table has no `org_id` for one to narrow — so
 * `RolePermission::query()->delete()` revoked every org's grants and `RolePermission::create([...])` attached
 * one to another org's role, skipping validation, the audit row and the memo flush. "A reviewer should treat
 * it as a defect" is attention, not enforcement: `GuardedGrantBuilder` refuses every write that did not come
 * through `Role::grant()` or `revoke()`, which are the paths that ask the org question.
 *
 * @property int $id
 * @property int $role_id
 * @property string $permission
 */
#[Unscoped]
class RolePermission extends Model
{
    /**
     * ⚠️ THE TRAIT BESIDE THE ATTRIBUTE, because the attribute alone is a LABEL. Review found it missing, and
     * AGENTS.md invariant 2 exists because of exactly this: `User` carried `#[Unscoped]` without
     * `EnforcesScope` for two phases and was "labelled correctly and completely unconstrained". Booting the
     * trait is what runs `ScopeResolver`, which is what makes the declaration part of the fail-closed runtime
     * contract rather than a comment with syntax.
     *
     * For an `#[Unscoped]` model the resolver applies no scope — that is the point of the declaration — so
     * what this buys is the model being *checked* rather than merely annotated.
     */
    use EnforcesScope;

    /** A grant is created and destroyed, never edited — so there is nothing for timestamps to date. */
    public $timestamps = false;

    protected $guarded = [];

    /**
     * ⚠️ THE WINDOW THAT AUTHORISES A WRITE HERE LIVES ON `Role`, NOT ON THIS CLASS, and review is the reason.
     * The first version put a public `throughRole()` opener here, which made the capability public: any
     * caller could hold it open around a write of their own and the claim that grants change only through
     * `Role::grant()`/`revoke()` was still unenforced. `Role` arms a private static inline and exposes a
     * reader, so the door can be OBSERVED from here and opened only from there.
     *
     * @param  Builder  $query
     */
    public function newEloquentBuilder($query): GuardedGrantBuilder
    {
        return new GuardedGrantBuilder($query, $this);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
