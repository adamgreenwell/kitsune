<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Kitsune;

/*
 * ADR-027 sets the resource floor as a designed constraint rather than a
 * readout of whatever the code ends up needing. A floor nobody measures is a
 * floor that quietly rises, so raising it must be a visible code change.
 */

it('states the floor in code, not only in documentation', function (): void {
    expect(Kitsune::FLOOR_VCPU)->toBe(1);
    expect(Kitsune::FLOOR_MEMORY_MB)->toBe(1024);
});

it('keeps one request well inside the memory floor', function (): void {
    // Measured at 38.5 MB peak on 2026-09-07, in a container limited to
    // 1 vCPU and 1 GB. The assertion is deliberately loose — it is a
    // regression guard against a step change, not a precise budget, and a
    // tight bound would fail for reasons unrelated to Kitsune.
    $peakMb = memory_get_peak_usage(true) / 1_048_576;

    expect($peakMb)->toBeLessThan(Kitsune::FLOOR_MEMORY_MB * 0.25);
});
