<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Audit;

use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Tenancy\Context;

/**
 * Records what happened, and deliberately not what changed (ADR-020).
 *
 * ⚠️ There is no `$changes` parameter and there must never be one. The
 * signature is the enforcement: a caller cannot pass a payload because there
 * is nowhere to put it, which is a stronger guarantee than a convention
 * everybody agrees with and someone eventually breaks under deadline.
 *
 * Silent when there is no org context. An audit row needs an org — the model
 * is `#[OrgScoped]` — and console commands, migrations and the installer all
 * run without one. Throwing there would make audit an obstacle to routine
 * work, and an audit system people switch off records nothing at all.
 */
final class Auditor
{
    public function __construct(private readonly Context $context) {}

    public function record(string $action, ?Model $target = null): ?AuditLog
    {
        $orgId = $this->context->orgId();

        if ($orgId === null) {
            return null;
        }

        return AuditLog::create([
            'org_id' => $orgId,
            'site_id' => $this->context->siteId(),
            // NULL for the system acting on its own — a scheduled prune, a
            // replayed erasure. Attributing that to whoever happened to be
            // logged in would be a lie in the one place that must not hold
            // one.
            'actor_id' => auth()->id(),
            'action' => $action,
            'target_type' => $target?->getMorphClass(),
            'target_id' => $target?->getKey(),
            'created_at' => now(),
        ]);
    }
}
