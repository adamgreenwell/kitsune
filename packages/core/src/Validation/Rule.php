<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Validation;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Entry point for the scoped rules, so calling sites read like Laravel's own.
 *
 * Named to be the obvious thing to reach for: `Rule::scopedUnique(...)` next
 * to Laravel's `Rule::unique(...)`, with the difference visible in the name
 * rather than buried in a docblock nobody opens.
 */
final class Rule
{
    /** @param class-string<Model> $model */
    public static function scopedUnique(
        string $model,
        string $column,
        mixed $ignoreId = null,
        ?Closure $using = null,
        ?Closure $normalise = null,
    ): ScopedUnique {
        return new ScopedUnique($model, $column, $ignoreId, $using, $normalise);
    }

    /** @param class-string<Model> $model */
    public static function scopedExists(string $model, ?string $column = null, ?Closure $using = null): ScopedExists
    {
        return new ScopedExists($model, $column, $using);
    }
}
