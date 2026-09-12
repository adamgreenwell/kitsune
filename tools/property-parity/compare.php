<?php

declare(strict_types=1);

/*
 * Where PCRE and ECMAScript disagree about what a Unicode property MEANS.
 *
 * ⚠️ THIS EXISTS BECAUSE COMPILING IS NOT AGREEING, and the allowlist was built on the weaker test.
 * `Pattern::PORTABLE_PROPERTIES` admitted every name both engines accept, and `\p{Bidi_Mirrored}` is
 * accepted by both and means two different things: 428 codepoints in PCRE 10.48, 554 in ECMAScript
 * (Node 22.23.2), the difference one-directional. Rule 3 of the field-type contract is that
 * `apiSchema()` may only publish a constraint the consumer can enforce, so a name whose MEMBERSHIP
 * differs cannot be published at all — and every boundary proof that asks PCRE about membership is
 * wrong for the consumer as well, which is how the divergence became a cubic pattern the screen
 * accepted.
 *
 * ⚠️ AND IT IS THE PROOF FOR THE OTHER 228. The allowlist's docblock claimed a full-codepoint set
 * comparison for three ALIASES; this harness makes that claim for every name on the list and for every
 * script name the `Script=` prefix admits. Re-run it when either engine's Unicode version moves.
 *
 *   php  tools/property-parity/names.php    > /tmp/names.json
 *   php  tools/property-parity/measure.php  /tmp/names.json > /tmp/pcre.json
 *   node tools/property-parity/measure.mjs  /tmp/names.json > /tmp/ecma.json
 *   php  tools/property-parity/compare.php  /tmp/pcre.json /tmp/ecma.json
 *
 * Exit 1 means a publishable name diverges. Exit 2 means the dumps do not describe the same names,
 * which is a broken run rather than a finding.
 */

require __DIR__.'/../../vendor/autoload.php';

use Kitsune\Core\Fields\Pattern;

$pcre = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$ecma = json_decode((string) file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);

if (array_keys($pcre) !== array_keys($ecma)) {
    fwrite(STDERR, "The two dumps cover different names — regenerate both from one names file.\n");
    exit(2);
}

/** @var list<string> $allowlisted */
$allowlisted = (new ReflectionClass(Pattern::class))->getConstant('PORTABLE_PROPERTIES');

$diverge = [];
$oneEngine = [];
$neither = [];
$agree = 0;

foreach ($pcre as $name => $inPcre) {
    $inEcma = $ecma[$name];
    $pcreFailed = array_key_exists('error', $inPcre);
    $ecmaFailed = array_key_exists('error', $inEcma);

    if ($pcreFailed && $ecmaFailed) {
        $neither[] = $name;

        continue;
    }

    if ($pcreFailed || $ecmaFailed) {
        $oneEngine[] = $name.' ('.($pcreFailed ? 'ECMAScript only' : 'PCRE only').')';

        continue;
    }

    if ($inPcre['sha'] === $inEcma['sha']) {
        $agree++;

        continue;
    }

    $diverge[$name] = [$inPcre['count'], $inEcma['count']];
}

printf("swept %d names — %d agree exactly over %s codepoints each\n", count($pcre), $agree, number_format(0x110000 - 0x800));
printf("compiles in one engine only  %d%s\n", count($oneEngine), $oneEngine === [] ? '' : '  <= unpublishable by rule 3');
printf("compiles in neither          %d   (aliases and legacy codes; `compiles()` refuses them)\n", count($neither));
printf("MEMBERSHIP DIVERGES          %d%s\n", count($diverge), $diverge === [] ? '' : '  <= unpublishable by rule 3');

foreach ($diverge as $name => [$pcreCount, $ecmaCount]) {
    $listed = in_array($name, $allowlisted, true);

    printf(
        "  %-34s PCRE %6d   ECMAScript %6d   delta %+d   %s\n",
        $name,
        $pcreCount,
        $ecmaCount,
        $ecmaCount - $pcreCount,
        $listed ? 'ON THE ALLOWLIST — must come off' : 'not allowlisted',
    );
}

/*
 * ⚠️ THE VERDICT IS THE EXIT CODE, not the report. A name that diverges and is NOT publishable is a
 * finding about the engines; one that diverges and IS publishable is a defect in this repository, and
 * only the second fails the run.
 */
$publishableDivergence = array_filter(
    array_keys($diverge),
    static fn (string $name): bool => in_array($name, $allowlisted, true)
        || str_starts_with($name, 'Script='),
);

foreach ($oneEngine as $name) {
    fwrite(STDERR, "one-engine name: {$name}\n");
}

exit($publishableDivergence === [] && $oneEngine === [] ? 0 : 1);
