<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields\Types;

use Closure;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\LogicalType;
use Kitsune\Core\Fields\Projection;

final class TextType extends BaseFieldType
{
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

    /** @return array<int, mixed> */
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

            $delimited = $this->delimited($pattern);

            // An uncompilable pattern is a refusal, not a pass. It means the
            // constraint cannot be checked, and letting the value through
            // would silently drop a rule the schema still advertises.
            if ($delimited === null || @preg_match($delimited, '') === false) {
                $fail("The {$attribute} field is constrained by a pattern that cannot be compiled.");

                return;
            }

            if (preg_match($delimited, $value) !== 1) {
                $fail("The {$attribute} field does not match the required format.");
            }
        };
    }

    /**
     * Wrap a JSON Schema pattern for PCRE, choosing a delimiter it does not
     * contain rather than escaping — escaping is where the already-escaped
     * cases go wrong.
     */
    private function delimited(string $pattern): ?string
    {
        foreach (['/', '#', '~', '%', '!'] as $delimiter) {
            if (! str_contains($pattern, $delimiter)) {
                return $delimiter.$pattern.$delimiter.'u';
            }
        }

        return null;
    }

    protected function scalarValidationRules(FieldConfig $config): array
    {
        $rules = [];
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
            'maxLength' => ['type' => 'integer', 'default' => 255, 'label' => 'Maximum length'],
            'pattern' => ['type' => 'string', 'nullable' => true, 'label' => 'Pattern (regex)'],
        ];
    }

    private function length(FieldConfig $config): int
    {
        return max(1, (int) $config->setting('maxLength', 255));
    }
}
