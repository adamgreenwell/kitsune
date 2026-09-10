<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

/*
 * Reports where the two engines disagree, and — the part that matters — whether the screen
 * or grammar ALREADY refuses each disagreement. A divergence that is refused is handled; one
 * that is accepted is a live defect.
 */
require __DIR__.'/../../vendor/autoload.php';

use Kitsune\Core\Fields\Pattern;

$cases = json_decode((string) file_get_contents(__DIR__.'/cases.json'), true, 512, JSON_THROW_ON_ERROR);
$pcre = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$ecma = json_decode((string) file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);

/**
 * The comparable part of a measurement: did it compile, and did it match.
 *
 * Diagnostics such as `timedOut` are deliberately dropped — they describe HOW an engine failed to
 * answer, not WHAT it answered, and including them makes richer reporting look like disagreement.
 */
function outcome(?array $result): array
{
    return [
        'compiles' => $result['compiles'] ?? null,
        'matches' => $result['matches'] ?? null,
    ];
}

$live = [];
$handled = 0;
$overRefused = [];
$bothReject = 0;

foreach ($cases as $case) {
    $id = $case['id'];
    /*
     * ⚠️ COMPARED ON NORMALISED OUTCOMES, NOT ON RESULT SHAPES, and comparing shapes contradicted
     * this tool's own stated symmetry. The ECMAScript side carries a `timedOut` key that the PCRE
     * side has no equivalent for, so `{compiles:true,matches:null}` versus
     * `{compiles:true,matches:null,timedOut:true}` compared as DIFFERENT — reporting a divergence
     * created purely by one engine supplying more diagnostics than the other. Both mean "no
     * verdict"; `timedOut` is kept for diagnosis and excluded from the comparison. Found by review.
     */
    $diverges = outcome($pcre[$id] ?? null) !== outcome($ecma[$id] ?? null);

    $compiles = Pattern::compiles($case['pattern']);
    $refused = ! $compiles || Pattern::unpublishable($case['pattern']) !== null;

    if ($diverges && ! $refused) {
        $live[] = $case;
    } elseif ($diverges) {
        $handled++;
    } elseif ($refused) {
        /*
         * ⚠️ NOT EVERY REFUSED-BUT-AGREEING CASE IS A COST, and counting them together
         * overstated it by two. Both engines REJECTING a pattern is agreement too — refusing
         * `^(?:(?<y>a)|(?<y>b))$`, which neither compiles, costs nothing.
         */
        if (($pcre[$id]['compiles'] ?? false) === false) {
            $bothReject++;

            continue;
        }

        $overRefused[] = $case;
    }
}

/*
 * ⚠️ A TIMEOUT IS ITS OWN OUTCOME, on both sides. PCRE reports `matches: null` when it exhausts
 * its backtrack limit; the ECMAScript side reports `matches: null` with `timedOut` when it cannot
 * answer inside its deadline. Both mean "the engine gave no verdict", and either collapsed into
 * "no match" would hide catastrophic backtracking rather than report it.
 */
$noVerdict = [];

foreach ($cases as $case) {
    $id = $case['id'];

    /*
     * ⚠️ `??` CANNOT DETECT A PRESENT NULL, which is exactly what a no-verdict result is. PHP's
     * null-coalescing treats `['matches' => null]` as absent and yields the default, so
     * `($pcre[$id]['matches'] ?? true) === null` was never true and this line omitted the PCRE
     * backtrack-limit case it exists to record. `array_key_exists` asks the question actually
     * meant. Found by review.
     */
    $pcreGaveNoVerdict = array_key_exists($id, $pcre)
        && array_key_exists('matches', $pcre[$id])
        && $pcre[$id]['matches'] === null
        && ($pcre[$id]['compiles'] ?? false) === true;

    if ($pcreGaveNoVerdict || ($ecma[$id]['timedOut'] ?? false) === true) {
        $noVerdict[] = $id;
    }
}

printf("divergent AND accepted  %d   <= live defects\n", count($live));
printf("divergent AND refused   %d\n", $handled);
printf("agrees BUT refused      %d   <= expressiveness cost\n", count($overRefused));
printf("refused, both reject    %d   (agreement to REJECT — refusing costs nothing)\n", $bothReject);
printf("no verdict from an engine %d  (%s)\n", count($noVerdict), implode(', ', $noVerdict));

/*
 * ⚠️ A case can still appear as an over-refusal because its SUBJECT does not discriminate.
 * `\bab\b` on `ab` agrees simply because both engines treat ASCII word boundaries alike; the
 * divergence needs non-ASCII input, which `word-boundary-accented-word` supplies. Read this
 * list as candidates to examine, not as a verdict.
 */
echo "\n(a case may appear below because its subject does not discriminate — check the subject before concluding)\n";

foreach (['LIVE DEFECTS' => $live, 'AGREES BUT REFUSED' => $overRefused] as $heading => $set) {
    if ($set === []) {
        continue;
    }

    echo "\n=== {$heading} ===\n";

    foreach ($set as $case) {
        printf("  %-34s %s\n", $case['id'], var_export($case['pattern'], true));
        printf("      PCRE %-28s ECMA %s\n",
            json_encode($pcre[$case['id']] ?? null),
            json_encode($ecma[$case['id']] ?? null));
    }
}
