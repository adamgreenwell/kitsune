<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Support\Colors\Color;
use Kitsune\Core\Filament\Colors\ContrastSafeRamp;

/**
 * A badge label is readable, and the arithmetic says so rather than a comment.
 *
 * ⚠️ THE DEFECT THIS GUARDS WAS FOUND BY axe, NOT BY REASONING (issue #55). Filament renders a
 * badge as shade 600 text on shade 50, and `Color::Amber` pairs them at **3.08:1** against a
 * 4.5:1 threshold. Every multi-select in the panel was affected; a selected relation chip was
 * simply the first one the seed produced.
 *
 * ⚠️ THE CONVERTER IS VALIDATED BEFORE IT IS TRUSTED. The first test below reproduces axe's own
 * numbers — `#fffbeb`, `#e17100`, 3.08:1 — because a contrast helper that is merely plausible
 * would let every later assertion here pass while being wrong. Three measurements in this
 * project have already come out wrong from unvalidated instruments.
 */
it('reproduces the ratio axe measured on the stock ramp', function (): void {
    $amber = Color::Amber;

    $measured = ContrastSafeRamp::ratio($amber[600], $amber[50]);

    // axe reported 3.08 for #e17100 on #fffbeb at 12px. Two decimal places, because agreeing to
    // one would agree with a converter that is subtly wrong.
    expect(round($measured, 2))->toBe(3.08)
        ->and($measured)->toBeLessThan(ContrastSafeRamp::MINIMUM_RATIO);
});

it('darkens the text shade until the badge pairing passes', function (): void {
    $fixed = ContrastSafeRamp::for(Color::Amber);

    expect(ContrastSafeRamp::ratio($fixed[600], $fixed[50]))
        ->toBeGreaterThanOrEqual(ContrastSafeRamp::MINIMUM_RATIO);
});

it('takes a shade the palette already ships rather than inventing one', function (): void {
    /*
     * A value from the vendor's own ramp stays recognisably the same hue and can be audited
     * against their numbers. Amber has nothing between 600 and 700 that reaches 5:1, so 700 is
     * the answer here — and asserting WHICH shade it took is what makes that visible.
     */
    $amber = Color::Amber;
    $fixed = ContrastSafeRamp::for($amber);

    expect($fixed[600])->toBe($amber[700]);
});

it('leaves every other shade untouched', function (): void {
    // ⚠️ An earlier version of this fix pasted all eleven shades into the skeleton, which would
    // have silently diverged the moment Filament updated its palette. Only 600 may change.
    $amber = Color::Amber;
    $fixed = ContrastSafeRamp::for($amber);

    foreach ($amber as $shade => $value) {
        if ($shade === 600) {
            continue;
        }

        expect($fixed[$shade])->toBe($value, "shade {$shade} was altered");
    }

    expect(array_keys($fixed))->toBe(array_keys($amber));
});

it('leaves a ramp that already passes completely alone', function (): void {
    /*
     * The other half, and the reason this is not "always darken". A palette that needs no help
     * must not be quietly altered — otherwise switching primary colour silently restyles the
     * panel for no accessibility gain.
     */
    $passing = null;

    foreach (['Slate', 'Blue', 'Indigo', 'Violet', 'Zinc'] as $name) {
        /** @var array<int, string> $ramp */
        $ramp = constant(Color::class.'::'.$name);

        if (ContrastSafeRamp::ratio($ramp[600], $ramp[50]) >= ContrastSafeRamp::MINIMUM_RATIO) {
            $passing = $ramp;

            break;
        }
    }

    expect($passing)->not->toBeNull('no Filament palette passes unaided, so this test proves nothing');
    expect(ContrastSafeRamp::for($passing))->toBe($passing);
});

it('refuses a ramp no shade can carry, rather than returning it', function (): void {
    // Fails closed: silently returning an unreadable ramp would put the defect back with a
    // helper in front of it implying otherwise.
    $hopeless = [50 => 'oklch(0.99 0.01 100)', 600 => 'oklch(0.98 0.01 100)'];

    expect(fn () => ContrastSafeRamp::for($hopeless))
        ->toThrow(RuntimeException::class, 'cannot be made readable');
});

it('refuses a colour notation it cannot parse', function (): void {
    // ⚠️ A mis-parsed colour yields a ratio that LOOKS measured and is not, which is worse than
    // a refusal. Filament states its palettes in oklch() today; anything else is refused.
    expect(fn () => ContrastSafeRamp::ratio('#e17100', 'oklch(0.987 0.022 95.277)'))
        ->toThrow(RuntimeException::class, 'oklch');
});

it('refuses a ramp with no background shade to compare against', function (): void {
    expect(fn () => ContrastSafeRamp::for([600 => 'oklch(0.666 0.179 58.318)']))
        ->toThrow(RuntimeException::class, 'must define shade 50');
});
