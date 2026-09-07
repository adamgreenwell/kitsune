<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tenancy;

use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Attributes\Unscoped;
use ReflectionClass;

/**
 * Reads the scope attribute off a model class. Fails closed.
 *
 * Resolution is memoised per class because it runs on every model boot, and
 * reflection on a hot path is the kind of cost that turns into a support
 * thread about slow admin pages.
 */
final class ScopeResolver
{
    /** @var array<class-string, class-string|null> */
    private static array $cache = [];

    /**
     * @return class-string<SiteScoped|OrgScoped|Unscoped>
     *
     * @throws UndeclaredScopeException when a model declares none
     */
    public static function for(string $model): string
    {
        if (array_key_exists($model, self::$cache)) {
            $resolved = self::$cache[$model];

            return $resolved ?? throw UndeclaredScopeException::for($model);
        }

        $reflection = new ReflectionClass($model);
        $found = null;

        foreach ([SiteScoped::class, OrgScoped::class, Unscoped::class] as $attribute) {
            if ($reflection->getAttributes($attribute) !== []) {
                $found = $attribute;
                break;
            }
        }

        self::$cache[$model] = $found;

        return $found ?? throw UndeclaredScopeException::for($model);
    }

    /** Test seam. Attributes cannot change at runtime in production. */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
