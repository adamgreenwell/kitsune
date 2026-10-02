<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Blueprints\Declarations;

use Kitsune\Core\Blueprints\OnCollision;

/**
 * One role a blueprint declares, with the grants it holds — ADR-039's second key.
 *
 * ⚠️ NO `is_owner`. The owner flag is the widest grant there is (ADR-033): a blueprint cannot declare it at all.
 *
 * ⚠️ NO `org_id`. A role is created in the org being applied into, as an entry type is.
 *
 * ⚠️ NO PERMISSION STRINGS. Grants are entry type handle => actions, and the applier writes `entry.{type}.{action}`
 * from them. Every handle must be one this same definition's `entryTypes()` declares, so a typo is refused rather
 * than stored as a grant nobody can hold, and neither the wildcard nor somebody else's type can be named:
 * authority over a type a blueprint did not bring is the operator's to give.
 *
 * ⚠️ NO HOLDERS. Assigning a role is a person's authority change, audited as one (ADR-033), never a blueprint's.
 */
final readonly class RoleDeclaration
{
    /**
     * @param  string  $handle  lowercase snake_case — `^[a-z][a-z0-9_]*$`, at most 255 characters — unique within an org
     * @param  string  $name  what the admin shows
     * @param  array<string, list<string>>  $grants  entry type handle => actions from `Permissions::ACTIONS`, written
     *                                               out rather than `Permissions::ACTIONS` itself: a sixth action must
     *                                               not reach a role nobody reviewed for it
     * @param  OnCollision  $onCollision  Fail by default; Skip leaves a role already there exactly as it is, grants included
     */
    public function __construct(
        public string $handle,
        public string $name,
        public array $grants,
        public OnCollision $onCollision = OnCollision::Fail,
    ) {}
}
