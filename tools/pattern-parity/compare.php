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

require __DIR__.'/../parity/provenance.php';

/** @return array<string, array{compiles: ?bool, matches: ?bool}> */
function patternResults(string $path, string $corpus, string $engine, array $ids): array
{
    $results = parityResults($path, 'pattern', $corpus, $engine, $ids);
    $outcomes = [];
    foreach ($results as $id => $row) {
        $keys = ['id', 'compiles', 'matches'];
        $diagnostic = $engine === 'v8' ? array_intersect(['timedOut', 'workerError'], array_keys($row)) : [];
        if ($diagnostic !== []) {
            $keys = [...$keys, ...$diagnostic];
        }
        if (! parityHasKeys((object) $row, $keys)
            || (! is_bool($row['compiles']) && ! ($row['compiles'] === null && $diagnostic !== []))
            || (! is_bool($row['matches']) && $row['matches'] !== null)
            || (! $row['compiles'] && $row['matches'] !== null)
            || count($diagnostic) > 1
            || ($diagnostic !== [] && ($row[reset($diagnostic)] !== true
                || $row['compiles'] === false || $row['matches'] !== null))) {
            throw new InvalidArgumentException("Malformed {$engine} pattern outcome for {$id}.");
        }
        // A timeout is diagnostic. Both engines can compile without giving a match verdict.
        $outcomes[$id] = ['compiles' => $row['compiles'], 'matches' => $row['matches']];
    }

    return $outcomes;
}

try {
    if (count($argv) !== 3) {
        throw new InvalidArgumentException('Usage: compare.php PCRE_DUMP V8_DUMP');
    }
    $corpus = parityRead(__DIR__.'/cases.json');
    $cases = json_decode($corpus, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($cases) || ! array_is_list($cases) || $cases === []) {
        throw new InvalidArgumentException('Expected a nonempty pattern corpus.');
    }
    $ids = [];
    foreach ($cases as $case) {
        if (! is_array($case) || ! is_string($case['id'] ?? null) || $case['id'] === ''
            || ! is_string($case['pattern'] ?? null) || ! is_string($case['subject'] ?? null)
            || isset($ids[$case['id']])) {
            throw new InvalidArgumentException('Pattern cases need unique ids and string patterns and subjects.');
        }
        $ids[$case['id']] = true;
    }
    $pcre = patternResults($argv[1], $corpus, 'pcre2', array_keys($ids));
    $ecma = patternResults($argv[2], $corpus, 'v8', array_keys($ids));
} catch (InvalidArgumentException|JsonException $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(2);
}

$live = [];
$handled = 0;
$overRefused = [];
$bothReject = 0;
$noVerdict = [];

foreach ($cases as $case) {
    $id = $case['id'];
    $compileDisagreement = is_bool($pcre[$id]['compiles']) && is_bool($ecma[$id]['compiles'])
        && $pcre[$id]['compiles'] !== $ecma[$id]['compiles'];
    if (! $compileDisagreement && (($pcre[$id]['compiles'] !== false && $pcre[$id]['matches'] === null)
        || ($ecma[$id]['compiles'] !== false && $ecma[$id]['matches'] === null))) {
        $noVerdict[] = $id;

        continue;
    }
    $diverges = $pcre[$id] !== $ecma[$id];

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
        if ($pcre[$id]['compiles'] === false) {
            $bothReject++;

            continue;
        }

        $overRefused[] = $case;
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
            json_encode($pcre[$case['id']]),
            json_encode($ecma[$case['id']]));
    }
}

exit($live !== [] ? 1 : ($noVerdict !== [] ? 3 : 0));
