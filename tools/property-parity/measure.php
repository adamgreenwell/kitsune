<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

/*
 * Each name's membership under PCRE, as ranges.
 *
 * ⚠️ SURROGATES ARE SKIPPED — U+D800 to U+DFFF are not valid UTF-8 and cannot appear in a value, and
 * probing them makes each engine report its own idea of an invalid subject rather than a membership
 * answer. Every other codepoint is tested: 1,112,064 of them per name.
 *
 * ⚠️ THE SAME MODIFIERS THE SERVER COMPILES. `Pattern::delimit()` sets `uD`, so the probe does too;
 * asking about a pattern the server never compiles is the mistake `pattern-parity`'s README opens with.
 */

$names = json_decode((string) file_get_contents($argv[1] ?? 'php://stdin'), true, 512, JSON_THROW_ON_ERROR);

$out = [];

foreach ($names as $name) {
    $expression = '/^\p{'.$name.'}$/uD';

    if (@preg_match($expression, 'a') === false) {
        $out[$name] = ['error' => 'does not compile'];

        continue;
    }

    $ranges = [];
    $start = null;
    $count = 0;

    for ($codepoint = 0; $codepoint <= 0x10FFFF; $codepoint++) {
        if ($codepoint === 0xD800) {
            $codepoint = 0xDFFF;

            continue;
        }

        $character = mb_chr($codepoint, 'UTF-8');
        $inside = $character !== false && @preg_match($expression, $character) === 1;

        if ($inside) {
            $count++;
            $start ??= $codepoint;
        } elseif ($start !== null) {
            $ranges[] = [$start, $codepoint - 1];
            $start = null;
        }
    }

    if ($start !== null) {
        $ranges[] = [$start, 0x10FFFF];
    }

    $out[$name] = ['count' => $count, 'sha' => sha1(json_encode($ranges, JSON_THROW_ON_ERROR)), 'ranges' => $ranges];
}

echo json_encode($out, JSON_THROW_ON_ERROR), "\n";
