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

require __DIR__.'/../parity/provenance.php';

$corpus = parityRead($argv[1] ?? 'php://stdin');
$names = json_decode($corpus, true, 512, JSON_THROW_ON_ERROR)['names'];

$out = [];

foreach ($names as $name) {
    $expression = '/^\p{'.$name.'}$/uD';

    if (@preg_match($expression, 'a') === false) {
        if (preg_last_error() !== PREG_INTERNAL_ERROR) {
            throw new RuntimeException("PCRE could not give a verdict for {$name}: ".preg_last_error_msg());
        }
        $out[] = ['id' => $name, 'error' => 'does not compile'];

        continue;
    }

    $ranges = [];
    $start = null;
    $count = 0;

    for ($codepoint = 0; $codepoint <= 0x10FFFF; $codepoint++) {
        if ($codepoint === 0xD800) {
            if ($start !== null) {
                $ranges[] = [$start, 0xD7FF];
                $start = null;
            }
            $codepoint = 0xDFFF;

            continue;
        }

        $character = mb_chr($codepoint, 'UTF-8');
        $match = $character === false ? false : @preg_match($expression, $character);
        if ($match === false) {
            throw new RuntimeException(sprintf('PCRE could not give a verdict for %s at U+%04X.', $name, $codepoint));
        }

        if ($match === 1) {
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

    $out[] = ['id' => $name, 'count' => $count, 'sha' => sha1(json_encode($ranges, JSON_THROW_ON_ERROR)), 'ranges' => $ranges];
}

echo json_encode(parityDump('property', $corpus, parityPcreEngine(), $out), JSON_THROW_ON_ERROR), "\n";
