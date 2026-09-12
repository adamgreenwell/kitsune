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
 * Refuse to compare a result set that does not cover the corpus, naming what is missing.
 *
 * ⚠️ THE MISSING-ID FALLBACK MADE THIS TOOL LIE, which review found. `outcome($pcre[$id] ?? null)`
 * turns an ABSENT measurement into `{compiles: null, matches: null}` — and two absences compare
 * EQUAL, so a case measured by neither file counted as agreement and vanished from every counter.
 * A stale pair of result files could therefore report `0 live defects` for a corpus it had never
 * measured, which is the one number this whole instrument exists to produce. One absence is worse
 * still in the other direction: it manufactures a divergence out of nothing.
 *
 * The ID sets must be EXACTLY equal rather than merely sufficient, because a result for a case that
 * no longer exists is the same staleness seen from the other side.
 *
 * ⚠️ A HARD EXIT RATHER THAN A WARNING. This tool's numbers are quoted in `docs/field-types.md` and
 * in review replies; a warning above a table is a number somebody will copy.
 */
function requireFullCoverage(string $side, array $results, array $cases): void
{
    $expected = array_column($cases, 'id');
    $measured = array_keys($results);

    /*
     * ⚠️ MULTIPLICITY, WHICH `array_diff()` DOES NOT SEE, and review found the gap: both measurement
     * scripts key their output by case ID, so a duplicated ID in `cases.json` means the second row
     * OVERWRITES the first and one pattern is never measured — while coverage looks complete, because
     * the ID is present. The tool would then compare one measurement against two different patterns and
     * could report zero live defects for a corpus it had not run.
     */
    $duplicated = array_values(array_unique(array_diff_assoc($expected, array_unique($expected))));

    $missing = array_values(array_diff($expected, $measured));
    $unknown = array_values(array_diff($measured, $expected));
    $malformed = [];

    foreach ($expected as $id) {
        if (! array_key_exists($id, $results)) {
            // Already counted as missing; reporting it twice would say the same thing twice.
            continue;
        }

        /*
         * ⚠️ `isset()` IS THE WRONG QUESTION HERE, which review found in the guard added the round
         * before: `"case-id": null` is valid JSON, `isset()` is false for it, and the early `continue`
         * skipped the shape check — so the ID set matched, the comparison ran, and
         * `array_key_exists('compiles', null)` died with a TypeError instead of this file's own
         * diagnostic and exit code. A guard whose failure mode is a stack trace is half a guard.
         */
        /*
         * ⚠️ THE TYPES, NOT ONLY THE KEYS, which review found the first version checking. A row such as
         * `{"compiles": "false", "matches": "false"}` — a hand-edited file, or another runner's idea of
         * JSON — has both keys and compares EQUAL to the same strings on the other side, so two
         * malformed rows read as engine agreement and the corpus reports no defect it never measured.
         * `compiles` is a boolean; `matches` is a boolean or null, where null means the engine gave no
         * verdict at all.
         */
        if (! is_array($results[$id])
            || ! array_key_exists('compiles', $results[$id])
            || ! array_key_exists('matches', $results[$id])
            || ! is_bool($results[$id]['compiles'])
            || ! (is_bool($results[$id]['matches']) || $results[$id]['matches'] === null)) {
            $malformed[] = $id;
        }
    }

    if ($missing === [] && $unknown === [] && $malformed === [] && $duplicated === []) {
        return;
    }

    $say = static fn (string $what, array $ids): string => $ids === []
        ? ''
        : sprintf("  %s (%d): %s\n", $what, count($ids), implode(', ', array_slice($ids, 0, 8)).(count($ids) > 8 ? ', …' : ''));

    fwrite(STDERR, sprintf(
        "The %s result file does not match cases.json, so no comparison is trustworthy:\n%s%s%s%s\n"
        ."Give every case a unique id, then re-run BOTH measurements against the current corpus:\n"
        ."  php  tools/pattern-parity/measure.php  > /tmp/pcre.json\n"
        ."  node tools/pattern-parity/measure.mjs  > /tmp/ecma.json\n",
        $side,
        $say('cases measured by neither name in the file', $missing),
        $say('results for cases that no longer exist', $unknown),
        $say('results that are not an object carrying a boolean `compiles` and a boolean-or-null `matches`', $malformed),
        $say('case IDs used more than once in cases.json, so one pattern is never measured', $duplicated),
    ));

    exit(2);
}

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

requireFullCoverage('PCRE', $pcre, $cases);
requireFullCoverage('ECMAScript', $ecma, $cases);

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

    /*
     * ⚠️ AND THE ECMASCRIPT SIDE IS DERIVED THE SAME WAY, which review found it not being: this read
     * `timedOut`, a DIAGNOSTIC the Node reader adds, so a valid row carrying `compiles: true` and
     * `matches: null` without it — an edited file, or another runner — was omitted from this count while
     * the comparison above correctly treated null as the third outcome. The summary would then report
     * zero unanswered cases for a case nobody answered. One question, asked of both files the same way.
     */
    $ecmaGaveNoVerdict = array_key_exists('matches', $ecma[$id])
        && $ecma[$id]['matches'] === null
        && ($ecma[$id]['compiles'] ?? false) === true;

    if ($pcreGaveNoVerdict || $ecmaGaveNoVerdict) {
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
