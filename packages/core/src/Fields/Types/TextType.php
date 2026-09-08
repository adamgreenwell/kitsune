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
use Kitsune\Core\Fields\Pattern;
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
            // The constraint on this setting lives in `validateSettings()`
            // rather than in a descriptor key: it has to reject a pattern that
            // cannot compile AND one that cannot be published, and a per-setting
            // `format` marker could express neither well. See there.
            'pattern' => [
                'type' => 'string',
                'nullable' => true,
                'label' => 'Pattern (regex)',
                'help' => 'Without delimiters, e.g. ^[A-Z]{2}-\\d+$. Must compile, and must be valid '
                    .'in the JSON Schema dialect, because it is published to API consumers.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function validateSettings(array $settings): ?string
    {
        $pattern = $settings['pattern'] ?? null;

        if (! is_string($pattern) || $pattern === '') {
            return null;
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

    private function length(FieldConfig $config): int
    {
        return max(1, (int) $config->setting('maxLength', 255));
    }
}
