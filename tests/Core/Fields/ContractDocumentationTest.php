<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Fields\FieldType;

/**
 * `docs/field-types.md` §3 reproduces the `FieldType` interface. This pins them together.
 *
 * ⚠️ WRITTEN BECAUSE THEY HAD DRIFTED, AND NOBODY COULD HAVE NOTICED. The document
 * declared three methods the interface has never had — `formComponent()`,
 * `tableColumn()` and `generatedColumnType()` — and omitted six it does have. A reader
 * implementing a field type from the document would have written two methods nothing
 * calls and missed `promotedColumn()`, `elementValidationRules()`, `validateSettings()`,
 * `retainsOriginal()` and `suggestedPiiClass()`.
 *
 * ⚠️ It is the CLASS of defect that matters here, not the instance. Fixing the prose
 * alone would leave the next divergence exactly as invisible as this one was: prose has
 * no compiler, so a contract described in two places has one place that is checked and
 * one that is hoped for. This is the check.
 *
 * The document stays the human-readable contract on purpose — it carries the reasoning
 * the interface cannot — so this asserts they AGREE rather than replacing one with the
 * other.
 */
$signaturesFromDocumentation = function (): array {
    $markdown = file_get_contents(dirname(__DIR__, 3).'/docs/field-types.md');

    expect($markdown)->toBeString();

    // The contract block is the first fenced PHP block after the section heading, so the
    // section is located first rather than grepping the whole document — §4's tables and
    // §9's checklist also mention method names, and matching those would make this pass
    // or fail for the wrong reasons.
    // ⚠️ Delimited LINE BY LINE, because a fence cannot be found by searching for the
    // next occurrence of three backticks: the block's own doc comments contain inline
    // code spans, and the first version of this parser stopped at one of them and
    // reported five methods missing that were documented six lines further down. A
    // parser that finds a prefix of the truth is worse than one that finds nothing.
    // ⚠️ The heading position is CHECKED, not cast. `mb_strpos` returns false when the
    // heading is absent and `(int) false` is 0, so a renamed section silently made this
    // scan the whole document from the top — where it found a different `php` fence and
    // all four tests passed. Verified by renaming the heading: the guard below could not
    // see it, because the parser had quietly found something plausible instead.
    $heading = mb_strpos((string) $markdown, '## 3. The contract');

    expect($heading)->not->toBeFalse('docs/field-types.md no longer has a "## 3. The contract" section');

    $lines = explode("\n", substr((string) $markdown, (int) $heading));
    $section = '';
    $inside = false;

    foreach ($lines as $line) {
        if (! $inside && str_starts_with(trim($line), '```php')) {
            $inside = true;

            continue;
        }

        if ($inside && trim($line) === '```') {
            break;
        }

        if ($inside) {
            $section .= $line."\n";
        }
    }

    preg_match_all(
        '/public\s+(static\s+)?function\s+(\w+)\s*\(([^)]*)\)\s*:\s*([^;]+);/',
        $section,
        $matches,
        PREG_SET_ORDER,
    );

    $signatures = [];

    foreach ($matches as $match) {
        $signatures[$match[2]] = [
            // ⚠️ Parameter TYPES, not just a count, and the count alone was the gap. A
            // method changing from `projection(FieldConfig $config)` to
            // `projection(SomethingElse $x)` kept an identical representation, so the
            // document could hold an incompatible signature while all four tests passed —
            // recreating exactly the drift this file exists to catch.
            'parameters' => documentedParameters($match[3]),
            'returns' => trim($match[4]),
            // ⚠️ And staticness. `handle()`, `label()` and `icon()` are static; a switch
            // either way is a breaking change to every implementation and was invisible
            // here.
            'static' => trim($match[1]) !== '',
        ];
    }

    return $signatures;
};

/**
 * The parameter types a documented signature declares, in order.
 *
 * Types only — a rename is a documentation improvement, not drift, and comparing names
 * would make this test fail for edits that improve the document.
 *
 * @return list<string>
 */
function documentedParameters(string $inside): array
{
    if (trim($inside) === '') {
        return [];
    }

    return array_map(
        // `mixed $input` -> `mixed`; a promoted or defaulted parameter keeps its type.
        static fn (string $parameter): string => (string) (preg_split('/\s+/', trim($parameter))[0] ?? ''),
        explode(',', $inside),
    );
}

$spell = function (?ReflectionType $type): string {
    if (! $type instanceof ReflectionNamedType) {
        return (string) $type;
    }

    return ($type->allowsNull() && $type->getName() !== 'mixed' && $type->getName() !== 'null' ? '?' : '')
        .class_basename($type->getName());
};

$signaturesFromInterface = function () use ($spell): array {
    $signatures = [];

    foreach ((new ReflectionClass(FieldType::class))->getMethods() as $method) {
        $signatures[$method->getName()] = [
            'parameters' => array_map(
                static fn (ReflectionParameter $parameter): string => $spell($parameter->getType()),
                $method->getParameters(),
            ),
            'static' => $method->isStatic(),
            // ⚠️ Built from getName(), not from casting the type. `(string) $type`
            // ALREADY carries the `?`, so prepending one produced `??string` and the
            // first run failed on `promotedColumn()` for a difference that did not
            // exist. Reflection also reports the fully-qualified name, where the
            // document writes what a reader types.
            'returns' => $spell($method->getReturnType()),
        ];
    }

    return $signatures;
};

it('documents every method the interface declares', function () use ($signaturesFromDocumentation, $signaturesFromInterface): void {
    $documented = array_keys($signaturesFromDocumentation());
    $declared = array_keys($signaturesFromInterface());

    sort($documented);
    sort($declared);

    $missing = array_values(array_diff($declared, $documented));

    expect($missing)->toBe([], 'docs/field-types.md §3 omits: '.implode(', ', $missing));
});

it('documents no method the interface does not declare', function () use ($signaturesFromDocumentation, $signaturesFromInterface): void {
    /*
     * ⚠️ THE DIRECTION THAT ACTUALLY BIT. A missing method is a gap a reader might
     * survive; an invented one sends them to implement `formComponent(): Component`
     * against an interface that has no such method and a seam that rejects it (ADR-029).
     * Three of the document's declarations were fictional.
     */
    $invented = array_values(array_diff(
        array_keys($signaturesFromDocumentation()),
        array_keys($signaturesFromInterface()),
    ));

    expect($invented)->toBe([], 'docs/field-types.md §3 declares methods that do not exist: '.implode(', ', $invented));
});

it('agrees with the interface on parameter count and return type', function () use ($signaturesFromDocumentation, $signaturesFromInterface): void {
    /*
     * ⚠️ Names alone would not have caught `generatedColumnType(SchemaDriver)` becoming
     * `projection(FieldConfig)` if the name had stayed — and a signature can drift
     * without one. Parameter COUNT and return type are compared rather than full
     * parameter spelling, because the latter fails on whitespace and inline comments and
     * a test that cries wolf gets deleted.
     */
    $documented = $signaturesFromDocumentation();
    $declared = $signaturesFromInterface();

    foreach (array_intersect_key($declared, $documented) as $name => $actual) {
        expect($documented[$name]['parameters'])
            ->toBe($actual['parameters'], "docs/field-types.md §3 gives {$name}() the wrong parameter types")
            ->and($documented[$name]['returns'])
            ->toBe($actual['returns'], "docs/field-types.md §3 gives {$name}() the wrong return type")
            ->and($documented[$name]['static'])
            ->toBe($actual['static'], "docs/field-types.md §3 disagrees on whether {$name}() is static");
    }
});

it('finds the contract block at all', function () use ($signaturesFromDocumentation, $signaturesFromInterface): void {
    /*
     * ⚠️ The guard on the guard. Every assertion above passes vacuously if the section
     * heading is renamed or the fence is reformatted — `array_diff` of two empty lists is
     * empty. A parser that silently finds nothing is the failure mode a documentation
     * test is most likely to have, and the least likely to be noticed.
     *
     * ⚠️ Compared against the INTERFACE's count rather than a literal, which the first
     * version hard-coded at 19. That version failed the moment `control()` was added —
     * correctly reporting a mismatch, but for the wrong reason and in a way that makes
     * every future interface change look like a broken test. A hard-coded count is a
     * second place to remember, which is the class of defect this whole file exists to
     * remove.
     *
     * It is still not vacuous: the interface count is asserted non-zero, so two empty
     * lists cannot agree their way past it.
     */
    $declared = count($signaturesFromInterface());

    expect($declared)->toBeGreaterThan(0, 'reflection over FieldType found no methods at all')
        ->and($signaturesFromDocumentation())->toHaveCount($declared);
});

it('pins the published candidate count to the harness case file', function (): void {
    /*
     * ⚠️ THE NUMBER HAD NEVER BEEN TRUE. §4 published "103 candidate constructs" from the commit
     * that landed the harness onwards, and `cases.json` held 123 in that same commit — then 126,
     * 132, 138 and 141 as each review round added its own case, with the prose never moving. A
     * measurement is a claim about a file; if the file is not consulted the number is decoration.
     *
     * ⚠️ AND IT IS THE ONE NUMBER THAT DISCREDITS THE REST. The harness README's own opening is
     * that an earlier version reported seven divergences where there are three, and that either
     * error "would discredit a real finding". A denominator nobody can reproduce does exactly
     * that to every ratio built on it — including "of N candidates, two are refused today".
     *
     * Both spellings are checked, because they are two independent chances to update one of them.
     */
    $markdown = (string) file_get_contents(dirname(__DIR__, 3).'/docs/field-types.md');
    $cases = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3).'/tools/pattern-parity/cases.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($cases)->toBeArray()->and($cases)->not->toBeEmpty();

    $count = count($cases);

    expect(mb_substr_count($markdown, "{$count} candidate constructs"))
        ->toBe(1, "docs/field-types.md §4 does not say \"{$count} candidate constructs\", which is what cases.json holds")
        ->and(mb_substr_count($markdown, "of {$count} candidates"))
        ->toBe(1, "docs/field-types.md \"What this costs\" does not say \"of {$count} candidates\"");

    /*
     * ⚠️ Not vacuous: any OTHER number in either phrasing is a stale count the two assertions above
     * cannot see, because they only look for the right one.
     *
     * ⚠️ SCOPED TO THESE TWO PHRASINGS, not to "candidate" anywhere. A first version matched
     * `/(\d+) candidate/` and failed on §4's *"across 23 candidate names"* — a different
     * measurement, of named-capture scripts, correct as written. A documentation test that fails on
     * a true sentence teaches whoever hits it to loosen the test.
     */
    expect(preg_match_all('/(\d+) candidate constructs|of (\d+) candidates/', $markdown, $found, PREG_SET_ORDER))
        ->toBe(2, 'docs/field-types.md no longer quotes the candidate count in both places');

    foreach ($found as $match) {
        $written = (int) ($match[1] !== '' ? $match[1] : $match[2]);

        expect($written)->toBe($count, "docs/field-types.md quotes {$written} candidates; cases.json holds {$count}");
    }
});
