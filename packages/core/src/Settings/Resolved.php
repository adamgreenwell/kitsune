<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Settings;

/**
 * A settings value together with where it came from.
 *
 * Provenance is a first-class requirement, not a nicety (ADR-022). Magento's
 * scope system is widely understood to be hard to debug, and the community
 * maintains an extension whose entire purpose is showing which scope a value
 * came from. That extension existing is the specification for what Kitsune
 * ships by default.
 */
final class Resolved
{
    public function __construct(
        public readonly string $key,
        public readonly mixed $value,
        public readonly string $origin,
        public readonly ?string $originLabel = null,
    ) {}

    public function isInherited(): bool
    {
        return $this->origin !== 'site';
    }

    public function isDefault(): bool
    {
        return $this->origin === 'default';
    }

    /** Human-readable provenance, e.g. "inherited from site group Golfdom". */
    public function describe(): string
    {
        return match ($this->origin) {
            'site' => 'set on this site',
            'site_group' => 'inherited from site group'.($this->originLabel !== null ? " {$this->originLabel}" : ''),
            'org' => 'inherited from the organisation',
            default => 'platform default',
        };
    }
}
