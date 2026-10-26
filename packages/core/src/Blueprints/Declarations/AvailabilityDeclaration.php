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
 * One sparse availability decision, applied through the existing resolver and blueprint receipt (ADR-039).
 *
 * No org selector or database ID: org means the org in context; the other targets are exact org-local handles.
 * Skip leaves an existing decision unchanged and never adopts it. A later version adds tuples, not overwrites.
 */
final readonly class AvailabilityDeclaration
{
    /**
     * @param  string  $entryType  an entry type this same definition declares and this blueprint owns
     * @param  'org'|'site_group'|'site'  $scope  the existing sparse inheritance level
     * @param  ?string  $target  null for org; an existing exact handle in the current org for a site group or site
     * @param  bool  $isEnabled  an explicit decision, not a copy of the inherited result
     * @param  OnCollision  $onCollision  Fail by default, including when an existing value already agrees
     */
    public function __construct(
        public string $entryType,
        public string $scope,
        public ?string $target,
        public bool $isEnabled,
        public OnCollision $onCollision = OnCollision::Fail,
    ) {}
}
