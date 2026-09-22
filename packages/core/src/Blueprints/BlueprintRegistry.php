<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Blueprints;

use RuntimeException;

/**
 * Where the definitions an installation knows about are collected — ADR-039.
 *
 * @internal
 *
 * ⚠️ NOT A CATALOGUE. Standing Principle #5 forbids an index, a directory or an install-from-URL path, and
 * this is none of them: it holds what the code on this machine has already registered, exactly as
 * `FieldTypeRegistry` does for field types and `AdminSurface` does for a module's admin contributions. Nothing
 * here fetches, discovers over a network, or knows a blueprint exists before some PHP has said so.
 *
 * ⚠️ IT IS THE SEAM THAT MAKES "PAYLOAD MAY LIVE ANYWHERE" TRUE. ADR-039 settles that the mechanism is core
 * and unreplaceable while a blueprint's payload may ship inside core, inside a module, or be handed to the
 * apply command. A module fills this in `registerModule()`, which is where `AdminSurface` is filled and for
 * the same reason: the kernel runs inside `$app->booted()`, so a registration made there is in time for a
 * console command and cannot run before the receipt that authorises the module has been read.
 */
final class BlueprintRegistry
{
    /** @var array<string, BlueprintDefinition> */
    private array $definitions = [];

    public function register(BlueprintDefinition $definition): self
    {
        $handle = $definition->handle();

        /*
         * ⚠️ REFUSED RATHER THAN OVERWRITTEN. Two definitions answering to one handle means `apply blog`
         * depends on registration order, and the receipt would record a handle without recording whose. A
         * module shadowing a first-party blueprint silently is the shape this refuses.
         */
        if (isset($this->definitions[$handle]) && $definition::class !== $this->definitions[$handle]::class) {
            throw new RuntimeException(sprintf(
                'Two blueprints claim the handle [%s]: %s is registered and %s wants it. A handle names one '
                .'blueprint — the receipt records the handle, not the class, so the second would be applied '
                .'under the first one\'s name.',
                $handle,
                $this->definitions[$handle]::class,
                $definition::class,
            ));
        }

        $this->definitions[$handle] = $definition;

        return $this;
    }

    public function get(string $handle): ?BlueprintDefinition
    {
        return $this->definitions[$handle] ?? null;
    }

    /** @return array<string, BlueprintDefinition> keyed by handle, sorted so output is stable */
    public function all(): array
    {
        $definitions = $this->definitions;
        ksort($definitions);

        return $definitions;
    }
}
