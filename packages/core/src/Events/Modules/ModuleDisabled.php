<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Events\Modules;

/**
 * A module was disabled — switched off for this installation.
 *
 * ⚠️ THESE TWO EVENTS ARE THE ONLY OBSERVABILITY A HOST GETS FOR A SWITCH THAT CHANGES THE RUNNING
 * APPLICATION, and the reason is a schema fact rather than an oversight: `audit_log.org_id` is `NOT NULL`
 * (ADR-020), an installation-level act has no org to file under, and `Auditor::record()` would therefore
 * record nothing. ADR-038 states that plainly rather than implying a module trail that does not exist.
 *
 * ⚠️ TWO EVENTS, NOT SIX. Install, upgrade and uninstall deliberately get none. After the v1.2 freeze an event
 * may be added and may not be removed (Standing Principle #2), so shipping fewer is the reversible direction.
 *
 * Observation only: no return channel, and nothing reads a listener's value. ADR-038 decided hooks are events
 * rather than filters, because a filter makes listener ordering load-bearing and creates exactly the contract
 * v1.2 must freeze.
 */
final readonly class ModuleDisabled
{
    public function __construct(public string $handle) {}
}
