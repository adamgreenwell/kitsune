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

use Kitsune\Core\Fields\Pattern;

$cases = json_decode((string) file_get_contents($argv[1] ?? __DIR__.'/cases.json'), true, 512, JSON_THROW_ON_ERROR);
$out = [];

foreach ($cases as $case) {
    $delimited = Pattern::delimit($case['pattern']);
    $compiles = $delimited !== null && @preg_match($delimited, '') !== false;
    $matches = null;

    if ($compiles) {
        $result = @preg_match($delimited, $case['subject']);
        // null means PCRE ERRORED rather than answered — a backtrack limit, most likely, which
        // is a third outcome and not the same as "no match".
        $matches = $result === false ? null : (bool) $result;
    }

    $out[$case['id']] = ['compiles' => $compiles, 'matches' => $matches];
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
