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

it('prints a constrained-run recipe built from the floor it just reported', function (): void {
    /*
     * ⚠️ THE RECIPE USED TO BE A THIRD COPY OF THE FLOOR. `--cpus=1 --memory=1g` was written into the output
     * by hand, so raising FLOOR_MEMORY_MB would have left every operator following a recipe that measures
     * against a floor this code no longer claims — and the command would have gone on printing it, confidently.
     */
    $this->artisan('kitsune:benchmark-floor', ['--entries' => 25])
        ->assertSuccessful()
        ->expectsOutputToContain('--cpus='.Kitsune::FLOOR_VCPU.' --memory='.Kitsune::FLOOR_MEMORY_MB.'m');
});

it('keeps the harness that reproduces it runnable', function (): void {
    // What the harness DOES is judged by tests/Core/Release/FloorHarnessTest.php, which runs it against stub
    // binaries. This only records that the two belong together: the constants live here, the runner there.
    expect(dirname(__DIR__, 2).'/bin/benchmark-floor.sh')->toBeReadableFile();
});

it('says when it seeded, because then its peak is not a request\'s', function (): void {
    /*
     * ⚠️ PHP KEEPS THE HEAP AN INSERT GREW, and resetting the peak does not give it back — so a run that seeded
     * reports the seeding as the request (Codex, #126). The command cannot un-seed itself, but it can say so on
     * the run it concerns: a first run seeds and warns, and a run that finds the entries already in place
     * inserts nothing and does not.
     */
    $this->artisan('kitsune:benchmark-floor', ['--entries' => 25, '--keep' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('seeded by this run: 25 entries')
        ->expectsOutputToContain('peak above includes the seeding');

    $this->artisan('kitsune:benchmark-floor', ['--entries' => 25])
        ->assertSuccessful()
        ->expectsOutputToContain('seeded by this run: 0 entries')
        ->doesntExpectOutputToContain('peak above includes the seeding');
});
