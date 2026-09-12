<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields\Types;

use Closure;
use Kitsune\Core\Fields\Control;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\LogicalType;
use Kitsune\Core\Fields\Pattern;
use Kitsune\Core\Fields\Projection;

final class TextType extends BaseFieldType
{
    /**
     * The longest a text field's value may be configured to be.
     *
     * ⚠️ A CEILING BECAUSE THE PATTERN SCREEN'S BOUND DEPENDS ON ONE. `Pattern` permits two adjacent
     * variable-width atoms — quadratic rather than exponential — on the strength of the value being
     * bounded, and nothing bounded it. See `validateSettings()` for the measurement.
     */
    public const MAX_CONFIGURABLE_LENGTH = 5000;

    public static function handle(): string
    {
        return 'text';
    }

    public static function label(): string
    {
        return 'Text';
    }

    public static function icon(): string
    {
        return 'heroicon-o-bars-3-bottom-left';
    }

    /**
     * One line of text. `slug` answers the same, and both are `Auto`: a title or a
     * handle is authored text and may be in any script.
     */
    public function control(): Control
    {
        return Control::Line;
    }

    public function isIndexable(): bool
    {
        return true;
    }

    public function projection(FieldConfig $config): Projection
    {
        // ⚠️ The configured width, not a constant 255. A field validated to
        // accept 1,000 characters and projected through VARCHAR(255) is
        // silently truncated in the index, so two distinct values compare
        // equal and an exact filter returns the wrong rows — and SQLite,
        // which does not enforce declared widths, disagrees with the other
        // two engines about which rows those are.
        return new Projection(LogicalType::String, $this->length($config));
    }

    protected function castToStorage(mixed $input, FieldConfig $config): mixed
    {
        return $input === null ? null : (string) $input;
    }

    /**
     * ⚠️ The constraints are PUBLISHED, not only enforced.
     *
     * The inherited schema said `{"type": "string"}` while validation
     * rejected anything past 255 characters by default, so a generated client
     * accepted payloads the API refused — and the same gap applied to a
     * configured length or pattern.
     *
     * @return array<string, mixed>
     */
    protected function scalarApiSchema(FieldConfig $config): array
    {
        $schema = ['type' => 'string', 'maxLength' => $this->length($config)];

        if (($pattern = $config->setting('pattern')) !== null) {
            // Published verbatim, because the CANONICAL form is JSON Schema's:
            // undelimited, unanchored unless the author anchors it. See
            // scalarValidationRules() for the other half.
            $schema['pattern'] = (string) $pattern;
        }

        return $schema;
    }

    /**
     * ⚠️ One string cannot serve both grammars, and this shipped as if it
     * could.
     *
     * JSON Schema wants an UNDELIMITED pattern; `preg_match()` requires
     * delimiters. So `^[a-z]+$` — the form the published schema needs, and the
     * form my own test used — is not a valid PCRE, and Laravel's `regex:` rule
     * handed it straight to `preg_match`. A server-valid `/^[a-z]+$/` publishes
     * literal slashes, which in JSON Schema match literal slashes. Either way
     * one consumer was wrong, and the test asserted only the published half.
     *
     * The canonical form is JSON Schema's. It is published as authored and
     * delimited here.
     *
     * Not Laravel's `regex:` rule either: that splits its parameters on
     * commas, so a perfectly ordinary `{2,4}` quantifier would arrive as two
     * broken halves.
     */
    private function patternRule(string $pattern): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($pattern): void {
            if (! is_string($value)) {
                return;
            }

            $delimited = Pattern::delimit($pattern);

            // An uncompilable pattern is a refusal, not a pass. It means the
            // constraint cannot be checked, and letting the value through
            // would silently drop a rule the schema still advertises.
            if ($delimited === null || ! Pattern::compiles($pattern)) {
                $fail("The {$attribute} field is constrained by a pattern that cannot be compiled.");

                return;
            }

            if (preg_match($delimited, $value) !== 1) {
                $fail("The {$attribute} field does not match the required format.");
            }
        };
    }

    /** @return array<int, mixed> */
    protected function scalarValidationRules(FieldConfig $config): array
    {
        $rules = [];

        /*
         * ⚠️ `bail` SO THE LENGTH RULE SHORT-CIRCUITS THE PATTERN, which review found was missing and
         * which is the half the configuration ceiling does not cover. `MAX_CONFIGURABLE_LENGTH` bounds
         * what an ORG MAY CONFIGURE; it says nothing about the untrusted value a request submits, and
         * without `bail` Laravel runs every rule — so a 100,000-character value was handed to the regex
         * even though `max` had already failed on it. Measured: the closure ran.
         *
         * The pattern screen permits shapes whose cost grows with the SQUARE of the value length on the
         * strength of that length being bounded, so evaluating one on a value already known to exceed
         * the bound is the exact case the bound exists to prevent.
         *
         * ⚠️ AND IT STILL RUNS WHEN THE LENGTH PASSES, which is the point of putting `bail` first rather
         * than dropping the rule: a value inside the ceiling is checked against the pattern as before.
         */
        $rules[] = 'bail';
        $rules[] = 'string';
        $rules[] = 'max:'.$this->length($config);

        if (($pattern = $config->setting('pattern')) !== null) {
            $rules[] = $this->patternRule((string) $pattern);
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    public function settingsSchema(): array
    {
        return [
            'maxLength' => [
                'type' => 'integer',
                'default' => 255,
                // Published because it is enforced — see `validateSettings()` for the measurement that
                // places it, and invariant 14 for why it cannot be enforced silently.
                'maximum' => self::MAX_CONFIGURABLE_LENGTH,
                'label' => 'Maximum length',
            ],
            // The constraint on this setting lives in `validateSettings()`
            // rather than in a descriptor key: it has to reject a pattern that
            // cannot compile AND one that cannot be published, and a per-setting
            // `format` marker could express neither well. See there.
            'pattern' => [
                'type' => 'string',
                'nullable' => true,
                // Published because it is enforced — `Pattern::unpublishable()` refuses a
                // longer one, and invariant 14 is that a constraint which is enforced and
                // not published is a constraint a consumer gets wrong.
                'maxLength' => Pattern::MAX_LENGTH,
                'label' => 'Pattern (regex)',
                'help' => 'Without delimiters, e.g. ^[A-Z]{2}-[0-9]+$. Must compile, and must mean the '
                    .'same thing in the JSON Schema dialect, because it is published to API '
                    .'consumers verbatim — so [0-9] rather than \\d, and \\p{Script=Arabic} rather '
                    .'than \\p{Arabic}.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function validateSettings(array $settings): ?string
    {
        /*
         * ⚠️ THE LENGTH CEILING IS WHAT MAKES THE PATTERN SCREEN'S BOUND REAL, which review found by
         * reading a claim of mine and checking it. `Pattern` permits TWO adjacent variable-width atoms
         * because the cost is quadratic in the value's length rather than exponential, and the docblock
         * saying so added "which `TextType` bounds by its configured `maxLength`". It does not: this
         * setting had no upper bound at all, so `^a*a*b$` — accepted, and a shape real patterns are made
         * of — costs whatever an org configures. Measured on Node 22.23.2 against a failing subject:
         *
         *   1,000  1 ms     10,000  144 ms     65,535  6.2 SECONDS
         *   5,000 36 ms     20,000  579 ms    100,000 14.4 SECONDS
         *
         * 5,000 keeps the worst adversarial case — a quadratic pattern, a maximal value, and a subject
         * that fails at the end — at 36 ms here and inside half a second on ADR-027's 1 vCPU floor. It
         * is generous for a `Control::Line` field: a URL, a name, a title. Long content is `textarea`
         * and `rich_text`, neither of which takes a pattern, so neither is affected.
         *
         * ⚠️ ENFORCED HERE AND PUBLISHED IN `settingsSchema()`, because invariant 14 is that a
         * constraint which is enforced and not published is one a consumer gets wrong.
         */
        $maxLength = $settings['maxLength'] ?? null;

        /*
         * ⚠️ A VALUE THAT IS NOT A WHOLE NUMBER IS REFUSED OUTRIGHT, because the ceiling below was
         * checked with `is_numeric()` and SPENT with `(int)`, and those two disagree. Review found the
         * gap: `is_numeric('100000x')` is false, so the guard stood aside — and `length()` casts the
         * same string to 100000, so `max:` and an accepted quadratic pattern then ran on
         * 100,000-character values. The 5,000-character bound the pattern rules rest on was defeated by
         * a trailing letter.
         *
         * Settings reach this from `FieldStorage` and from a module, not only from the Filament numeric
         * control, so "the UI would not send that" is not a guard.
         *
         * ⚠️ THE TEST IS THE ONE THE CAST MAKES, which is the point: a value is acceptable exactly when
         * casting it loses nothing. `'100'` passes because `(string) (int) '100'` is `'100'`; `'100x'`,
         * `'1e3'` and `10.5` do not, and each would have been spent as a different number than it reads
         * as.
         */
        if ($maxLength !== null && (! is_numeric($maxLength) || (string) (int) $maxLength !== trim((string) $maxLength))) {
            return sprintf(
                'maxLength must be a whole number, and this is [%s]. It would be read as %s when the '
                .'length rule is built, which is not what it says — and the pattern rules rest on that '
                .'number bounding the value they run against.',
                is_scalar($maxLength) ? (string) $maxLength : gettype($maxLength),
                number_format((int) $maxLength),
            );
        }

        if (is_numeric($maxLength) && (int) $maxLength > self::MAX_CONFIGURABLE_LENGTH) {
            return sprintf(
                'A text field is limited to %s characters, and this asks for %s. The pattern screen '
                .'permits shapes whose cost grows with the SQUARE of the value length — `^a*a*b$` is '
                .'one, and measured, it takes ECMAScript 6.2 seconds at 65,535 characters against a '
                .'value that fails at the end. Use a textarea or rich text for longer content; neither '
                .'takes a pattern.',
                number_format(self::MAX_CONFIGURABLE_LENGTH),
                number_format((int) $maxLength),
            );
        }

        $pattern = $settings['pattern'] ?? null;

        if (! is_string($pattern) || $pattern === '') {
            return null;
        }

        // ⚠️ LENGTH FIRST, because `compiles()` now refuses an over-long pattern too —
        // `Pattern::delimit()` bounds itself — and "that pattern cannot be compiled" is
        // the wrong explanation for one that is merely too long. The author needs to be
        // told the limit, not sent looking for a syntax error that is not there.
        if (($tooLong = Pattern::lengthRefusal($pattern)) !== null) {
            return sprintf('That pattern is %s.', $tooLong);
        }

        // Unusable server-side: `patternRule()` refuses every value when the
        // pattern will not compile, so accepting it leaves a field nothing can
        // be stored in.
        if (! Pattern::compiles($pattern)) {
            return 'That pattern cannot be compiled, so every value for this field would be refused.';
        }

        // Unusable for a CONSUMER: the same string is published verbatim as a
        // JSON Schema pattern, and JSON Schema's dialect is ECMAScript.
        if (($unpublishable = Pattern::unpublishable($pattern)) !== null) {
            return sprintf(
                'That pattern uses %s, which PCRE understands and the JSON Schema dialect does not. '
                .'This field publishes its pattern to API consumers verbatim, so they would be '
                .'handed a constraint they cannot compile. Rewrite it without that construct.',
                $unpublishable,
            );
        }

        return null;
    }

    /**
     * A multi-value text field's item bound, narrowed when its pattern costs quadratic work.
     *
     * ⚠️ THE CEILING WAS PER ELEMENT AND THE FIELD PUBLISHES AN ARRAY, which review found.
     * `MAX_CONFIGURABLE_LENGTH` bounds one value on the strength of the quadratic allowance being
     * quadratic in ONE length; a cardinality of `-1` published no `maxItems` at all and applied the
     * pattern to every item. Measured on Node 22.23.2, an accepted `^a*a*b$` against all-`a` values
     * that fail:
     *
     *   items:        10        25        50       100       200
     *   255 chars    1.3 ms    2.4 ms    4.8 ms    9.7 ms   19.5 ms
     *   1,000       14.4      36.5      73.2     145.4     290.6
     *   5,000      359.6     900.4   1,802.5   3,595.8   7,262.8
     *
     * Linear in the item count and quadratic in the length, so the work is `items × length²` and the
     * bound that keeps it where the single-element ceiling put it is `(MAX / length)²`: **384** items at
     * the 255-character default, 25 at 1,000, and **1** at the 5,000-character ceiling. A field
     * configured for maximal values and a quadratic pattern really does get one element — that pair is
     * the worst case the ceiling exists for, and it is the author's own configuration.
     *
     * ⚠️ ONLY WHEN THE PATTERN CLAIMS THE ALLOWANCE. A run of one variable-width atom is linear, so a
     * hundred maximal values against `^[a-z]+$` is half a million character tests and needs no bound at
     * all. `Pattern::costsQuadraticPerValue()` is the same question the run rule already answers, asked
     * from outside.
     *
     * ⚠️ AND IT NARROWS RATHER THAN REPLACES: a declared cardinality of 2 still means two.
     */
    public function maxItems(FieldConfig $config): ?int
    {
        $declared = parent::maxItems($config);

        if (! $config->isMultiValue()) {
            return null;
        }

        $pattern = $config->setting('pattern');

        if (! is_string($pattern) || $pattern === '' || ! Pattern::costsQuadraticPerValue($pattern)) {
            return $declared;
        }

        $budget = max(1, (int) ((self::MAX_CONFIGURABLE_LENGTH / $this->length($config)) ** 2));

        return $declared === null ? $budget : min($declared, $budget);
    }

    private function length(FieldConfig $config): int
    {
        return max(1, (int) $config->setting('maxLength', 255));
    }
}
