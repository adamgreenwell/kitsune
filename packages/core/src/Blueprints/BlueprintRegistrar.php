<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Blueprints;

use Illuminate\Foundation\Application;
use LogicException;

/** @internal Candidate for registration by a trusted host or admitted provider at boot. */
final readonly class BlueprintRegistrar
{
    public function __construct(private Application $app, private BlueprintRegistry $registry) {}

    public function register(BlueprintDefinition $definition): void
    {
        if ($this->app->isBooted()) {
            throw new LogicException('Register blueprint definitions during application bootstrap.');
        }

        $this->registry->register($definition);
    }
}
