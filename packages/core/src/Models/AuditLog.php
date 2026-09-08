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
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kitsune\Core\Audit\AppendOnlyBuilder;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use RuntimeException;

/**
 * Actor, action and target. Never payloads (ADR-020).
 *
 * ⚠️ The absent columns are the design. "User 47 updated entry 1203" survives
 * an erasure; "User 47 changed name from X to Y" does not — it re-creates the
 * erased value inside the log meant to prove the erasure happened. An audit
 * log that captures diffs is a compliance liability wearing a helpful hat.
 *
 * `AuditLogShapeTest` asserts the column list exactly, so adding a `changes`
 * column fails the build rather than passing review on a busy day.
 *
 * @property int $id
 * @property int $org_id
 * @property int|null $site_id
 * @property int|null $actor_id
 * @property string $action
 * @property string $target_type
 * @property int|null $target_id
 */
#[OrgScoped]
class AuditLog extends Model
{
    use EnforcesScope;

    public const TABLE = 'audit_log';

    /** Written once, never updated — so there is no `updated_at`. */
    public const UPDATED_AT = null;

    protected $table = self::TABLE;

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];

    /**
     * ⚠️ Append-only, enforced rather than documented.
     *
     * An audit log that application code can rewrite is not evidence of
     * anything. `UPDATED_AT = null` says a row is written once; this makes it
     * true — otherwise `$guarded = []` plus an ordinary Eloquent model makes
     * accidental rewriting a one-liner, and deliberate rewriting invisible.
     *
     * The org cascade still removes rows when an org is deleted, because a
     * database-level `ON DELETE CASCADE` does not go through Eloquent. That
     * is the intended exception: erasing an organisation should not leave its
     * audit trail behind.
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException(
                'Audit rows are append-only. Rewriting one destroys the evidence the log exists to '
                .'preserve — record a new action instead (ADR-020).'
            );
        });

        static::deleting(function (): void {
            throw new RuntimeException(
                'Audit rows are append-only and cannot be deleted individually. Retention is an '
                .'operator policy applied to the table, not a per-row decision (ADR-020).'
            );
        });
    }

    /**
     * ⚠️ The append-only guard has to live HERE as well as in the model
     * events. `AuditLog::query()->update([...])` and `->delete()` compile
     * straight to SQL and fire no events at all, so the guards below covered
     * the instance path a test exercises and left the one-liner that rewrites
     * the whole table wide open.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    public function newEloquentBuilder($query): AppendOnlyBuilder
    {
        return new AppendOnlyBuilder($query);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFor(Builder $query, Model $target): Builder
    {
        return $query->where('target_type', $target->getMorphClass())
            ->where('target_id', $target->getKey());
    }
}
