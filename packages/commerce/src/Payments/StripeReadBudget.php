<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Payments;

use Closure;

/** One financial status read, not a renewed allowance for each page or checkpoint. @internal */
final class StripeReadBudget
{
    private const SECONDS = 20.0;

    // Older supported Guzzle/cURL stacks truncate fractional milliseconds to zero (an unbounded transfer).
    private const MIN_TRANSPORT_SECONDS = 0.001;

    /** @var Closure():float */
    private readonly Closure $clock;

    private readonly float $started;

    /** @param (Closure():float)|null $clock Monotonic seconds; injectable only for deterministic tests. */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
        $this->started = ($this->clock)();
    }

    public function remaining(): float
    {
        $remaining = self::SECONDS - (($this->clock)() - $this->started);
        if (! is_finite($remaining) || $remaining < self::MIN_TRANSPORT_SECONDS || $remaining > self::SECONDS) {
            throw new StripeUnavailable;
        }

        return $remaining;
    }
}
