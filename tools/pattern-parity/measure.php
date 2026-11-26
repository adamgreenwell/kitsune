<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

/*
 * The SERVER side of the parity measurement.
 *
 * ⚠️ COMPILES WHAT KITSUNE COMPILES, which is `Pattern::delimit()`'s output — not the raw
 * authored source. That rewrites `.` and `\s` to explicit ECMAScript-equivalent classes and
 * sets the `uD` modifiers. Measuring the raw source instead reports divergences Kitsune does
 * not have: it invented seven of them the first time this was run, four from the rewrite and
 * three from the missing `D`.
 */
require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/../parity/provenance.php';

use Kitsune\Core\Fields\Pattern;

$corpus = parityRead($argv[1] ?? __DIR__.'/cases.json');
$cases = json_decode($corpus, true, 512, JSON_THROW_ON_ERROR);
$out = [];

foreach ($cases as $case) {
    $delimited = Pattern::delimit($case['pattern']);
    $compiles = $delimited !== null;
    $matches = null;

    if ($compiles) {
        $probe = @preg_match($delimited, '');
        if ($probe !== false) {
            $result = @preg_match($delimited, $case['subject']);
            $matches = $result === false ? null : (bool) $result;
        } elseif (preg_last_error() === PREG_INTERNAL_ERROR) {
            // An invalid expression is reported as a PCRE internal error. Resource limits leave no match verdict.
            $compiles = false;
        }
    }

    $out[] = ['id' => $case['id'], 'compiles' => $compiles, 'matches' => $matches];
}

echo json_encode(parityDump('pattern', $corpus, parityPcreEngine(), $out), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
