<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core;

final class Kitsune
{
    /**
     * The lowest hardware Kitsune is designed to run on (ADR-027).
     *
     * Held here rather than in documentation alone so the resource-floor
     * benchmark has a single value to assert against, and so raising it
     * is a visible code change rather than a quiet drift.
     */
    public const FLOOR_VCPU = 1;

    public const FLOOR_MEMORY_MB = 1024;

    public static function version(): string
    {
        return '0.0.1-dev';
    }
}
