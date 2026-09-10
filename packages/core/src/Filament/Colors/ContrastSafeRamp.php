<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Colors;

use RuntimeException;

/**
 * A Filament colour ramp whose badge pairing meets WCAG AA.
 *
 * ⚠️ THIS EXISTS BECAUSE A STOCK FILAMENT COLOUR CAN FAIL AA, MEASURED. Filament renders a
 * badge as shade **600 text on shade 50**, and `Color::Amber` pairs `#e17100` with `#fffbeb`
 * — **3.08:1** at 12px against the 4.5:1 threshold. axe reported exactly that on a selected
 * relation chip (issue #55). Every multi-select in a panel is affected, not only relations.
 *
 * ⚠️ A RAMP RATHER THAN A STYLESHEET. Filament decides the 600-on-50 pairing and Kitsune
 * cannot change it — but it can supply a 600 dark enough for the pairing to pass. Overriding
 * `fi-text-color-600` in CSS would fight Filament's own utilities and break on any upgrade
 * that renames them; a colour ramp is supported API.
 *
 * ⚠️ AND IT IS COMPUTED, NOT ASSERTED IN A COMMENT. The whole point of putting this in core
 * is that `ContrastSafeRampTest` can check the arithmetic, so a Filament palette change that
 * reintroduces the defect fails the suite instead of shipping. A promise about contrast that
 * nothing evaluates is the shape of promise this project keeps finding broken.
 */
final class ContrastSafeRamp
{
    /**
     * WCAG 2 AA for normal-size text. Badge labels render at 12px, which is not "large text"
     * under any reading of the guideline, so the 3:1 allowance does not apply.
     */
    public const MINIMUM_RATIO = 4.5;

    /** The pairing Filament uses for a badge in light mode. */
    private const TEXT_SHADE = 600;

    private const BACKGROUND_SHADE = 50;

    /**
     * The same ramp, with its text shade darkened until the badge pairing passes.
     *
     * Tries the darker shades Filament already ships, in order, rather than inventing a colour:
     * a value from the palette stays recognisably the same hue and can be audited against the
     * vendor's own numbers.
     *
     * @param  array<int, string>  $ramp
     * @return array<int, string>
     *
     * @throws RuntimeException when no shade in the ramp can carry the pairing
     */
    public static function for(array $ramp): array
    {
        $background = $ramp[self::BACKGROUND_SHADE] ?? null;

        if (! is_string($background)) {
            throw new RuntimeException(sprintf(
                'A colour ramp must define shade %d: it is the background Filament pairs a badge '
                .'label against, so without it the contrast cannot be checked at all.',
                self::BACKGROUND_SHADE,
            ));
        }

        $current = $ramp[self::TEXT_SHADE] ?? null;

        if (is_string($current) && self::ratio($current, $background) >= self::MINIMUM_RATIO) {
            // Already passes. Returned unchanged rather than darkened anyway, so a palette that
            // needs no help is not quietly altered.
            return $ramp;
        }

        foreach ([700, 800, 900, 950] as $darker) {
            $candidate = $ramp[$darker] ?? null;

            if (! is_string($candidate)) {
                continue;
            }

            if (self::ratio($candidate, $background) >= self::MINIMUM_RATIO) {
                $ramp[self::TEXT_SHADE] = $candidate;

                return $ramp;
            }
        }

        /*
         * ⚠️ Fails closed. A ramp nothing can carry is a panel whose badges are unreadable, and
         * silently returning it would put the defect back with a helper in front of it implying
         * otherwise.
         */
        throw new RuntimeException(sprintf(
            'No shade in this ramp reaches %.1f:1 against shade %d, so a badge label cannot be '
            .'made readable by darkening alone. Choose a different primary colour.',
            self::MINIMUM_RATIO,
            self::BACKGROUND_SHADE,
        ));
    }

    /**
     * The WCAG contrast ratio between two `oklch()` colours.
     *
     * ⚠️ The converter was validated against axe's own report before being trusted: it
     * reproduces `#fffbeb` for amber 50, `#e17100` for amber 600, and their 3.08:1 exactly.
     * Deriving a new number from an unvalidated instrument is how three earlier measurements in
     * this project came out wrong.
     */
    public static function ratio(string $a, string $b): float
    {
        $first = self::relativeLuminance(self::toLinearRgb($a));
        $second = self::relativeLuminance(self::toLinearRgb($b));

        $lighter = max($first, $second);
        $darker = min($first, $second);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * Parses `oklch(L C H)` and converts to linear-light sRGB.
     *
     * ⚠️ Fails closed on any other notation. Filament states its palettes in `oklch()` today; a
     * hex or `rgb()` value arriving here would be silently mis-parsed into a wrong ratio, and a
     * wrong ratio is worse than a refusal because it looks like a measurement.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private static function toLinearRgb(string $colour): array
    {
        if (preg_match('/^oklch\(\s*([0-9.]+)\s+([0-9.]+)\s+([0-9.]+)\s*\)$/i', trim($colour), $m) !== 1) {
            throw new RuntimeException(sprintf(
                'Cannot read [%s] as an oklch() colour. Refused rather than guessed: a '
                .'mis-parsed colour produces a contrast number that looks measured and is not.',
                $colour,
            ));
        }

        [$l, $c, $h] = [(float) $m[1], (float) $m[2], (float) $m[3]];

        $hRad = deg2rad($h);
        $a = $c * cos($hRad);
        $bb = $c * sin($hRad);

        // OKLab to LMS, cubed.
        $lms = [
            ($l + 0.3963377774 * $a + 0.2158037573 * $bb) ** 3,
            ($l - 0.1055613458 * $a - 0.0638541728 * $bb) ** 3,
            ($l - 0.0894841775 * $a - 1.2914855480 * $bb) ** 3,
        ];

        // LMS to linear sRGB.
        return [
            4.0767416621 * $lms[0] - 3.3077115913 * $lms[1] + 0.2309699292 * $lms[2],
            -1.2684380046 * $lms[0] + 2.6097574011 * $lms[1] - 0.3413193965 * $lms[2],
            -0.0041960863 * $lms[0] - 0.7034186147 * $lms[1] + 1.7076147010 * $lms[2],
        ];
    }

    /**
     * WCAG relative luminance.
     *
     * ⚠️ Computed from the GAMMA-ENCODED channels, which means encoding and decoding rather than
     * using the linear values directly. It looks redundant and is not: the encode clamps to the
     * sRGB gamut, and an out-of-gamut OKLCH value — which several of Filament's vivid shades are
     * — otherwise yields a luminance no display can produce and a ratio no user experiences.
     *
     * @param  array{0: float, 1: float, 2: float}  $linear
     */
    private static function relativeLuminance(array $linear): float
    {
        $channels = [];

        foreach ($linear as $value) {
            $clamped = max(0.0, min(1.0, $value));
            $encoded = $clamped <= 0.0031308
                ? 12.92 * $clamped
                : 1.055 * $clamped ** (1 / 2.4) - 0.055;

            $channels[] = $encoded <= 0.04045
                ? $encoded / 12.92
                : (($encoded + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
