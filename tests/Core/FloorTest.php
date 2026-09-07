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

it('runs the floor benchmark against real content in scope', function (): void {
    // The previous version asserted memory_get_peak_usage() from inside the
    // test process, which only reports the Pest runner's high-water mark. It
    // was named and commented as though it guarded request memory, and it
    // guarded nothing: a later regression would pass, and unrelated earlier
    // tests could move the number.
    //
    // This instead exercises the command, which establishes a site context
    // and seeds real entries — without that context SiteScope adds
    // WHERE 1 = 0 and every sample measures an empty result set.
    $this->artisan('kitsune:benchmark-floor', ['--entries' => 25])
        ->assertSuccessful()
        ->expectsOutputToContain('content in scope: 25 entries');
});

it('reports a peak that leaves room for several workers', function (): void {
    // The real budget question is not one request but how many fit at once.
    // Loose on purpose: a regression guard against a step change, not a
    // precise budget that would fail for reasons unrelated to Kitsune.
    $this->artisan('kitsune:benchmark-floor', ['--entries' => 25])
        ->assertSuccessful()
        ->expectsOutputToContain('workers that fit in half the floor');
});
