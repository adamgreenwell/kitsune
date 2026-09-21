<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Modules;

/**
 * What `ModuleVerifier` found, including what it looked at.
 *
 * ⚠️ `examined` EXISTS SO A REFUSAL CAN BE TOLD FROM A SWEEP THAT NEVER RAN. Every negative case in this area
 * asserts that some module is refused, and a verifier that returned a refusal without loading a single class
 * would satisfy all of them — as would one whose directory walk silently found nothing because a path was
 * wrong. `ScopeDeclarationTest` records the same hazard from the other side: its earlier version guarded with
 * `class_exists()` and skipped what it could not load, "which made the skeleton half a silent no-op".
 */
final readonly class ModuleVerification
{
    /**
     * @param  string|null  $refusal  why the module may not load, or null
     * @param  list<class-string>  $examined  every class the sweep resolved from the module's own PSR-4 roots
     * @param  list<class-string>  $models  those of them that are concrete Eloquent models
     */
    public function __construct(
        public ?string $refusal,
        public array $examined,
        public array $models,
    ) {}

    public function passed(): bool
    {
        return $this->refusal === null;
    }
}
