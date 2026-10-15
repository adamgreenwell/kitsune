<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Credentials;

/**
 * Keep the writer's intended time explicit, even when Eloquent considers it unchanged.
 *
 * Existing MariaDB schemas may give the first TIMESTAMP an implicit ON UPDATE clause. Omitting an unchanged
 * changed_at then substitutes the database clock during replacement, removal, mode changes or APP_KEY resealing.
 * Use the raw attribute, preserving the original change time during resealing and the writer's integrity checks.
 *
 * @internal Credential persistence only; not an extension API.
 */
trait WritesExplicitChangeTime
{
    /** @return array<string, mixed> */
    protected function getDirtyForUpdate(): array
    {
        $dirty = parent::getDirtyForUpdate();
        $attributes = $this->getAttributes();
        if ($dirty !== [] && array_key_exists('changed_at', $attributes)) {
            $dirty['changed_at'] = $attributes['changed_at'];
        }

        return $dirty;
    }
}
