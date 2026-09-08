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
use RuntimeException;
use stdClass;

/**
 * The escape hatch, and escape hatches get abused.
 *
 * Documented as "for machine-readable configuration, not content", because
 * otherwise it becomes the CMS equivalent of a `misc` column. Not indexable
 * and not queryable on purpose: if you want to query it, you wanted a real
 * field type.
 */
final class JsonType extends BaseFieldType
{
    /** Server-side, because a client-side cap is a suggestion. */
    public const MAX_BYTES = 65_536;

    public static function handle(): string
    {
        return 'json';
    }

    public static function label(): string
    {
        return 'JSON';
    }

    public static function icon(): string
    {
        return 'heroicon-o-code-bracket';
    }

    public function supportsCardinality(): bool
    {
        return false;
    }

    protected function castToStorage(mixed $input, FieldConfig $config): mixed
    {
        if ($input === null || $input === '') {
            return null;
        }

        $decoded = is_string($input) ? json_decode($input, true) : $input;

        if (is_string($input) && json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Value is not valid JSON: '.json_last_error_msg());
        }

        $encoded = json_encode($decoded);

        if ($encoded !== false && strlen($encoded) > self::MAX_BYTES) {
            throw new RuntimeException(
                'JSON value exceeds '.self::MAX_BYTES.' bytes. This field is for machine-readable '
                .'configuration, not content — a value this large probably wants a real field type.'
            );
        }

        // ⚠️ An empty object survives as an object. `json_decode('{}', true)`
        // gives `[]`, which re-encodes as a LIST — so `{}` changed shape on a
        // round trip through a field whose apiSchema() advertises an object.
        return $decoded === [] ? new stdClass : $decoded;
    }

    /** Same reason, on the way back out. */
    protected function castFromStorage(mixed $stored, FieldConfig $config): mixed
    {
        return $stored === [] ? new stdClass : $stored;
    }

    /**
     * ⚠️ Refuse an object whose keys are a 0-based sequence, at any depth.
     *
     * `{"0":"a","1":"b"}` is a valid JSON object that decodes to the PHP list
     * `['a','b']` and re-encodes as `["a","b"]`, so the value silently
     * changes shape on save. `Entry.values` casts to array, so there is
     * nowhere later to recover the distinction either.
     *
     * ⚠️ Walks the structure decoded WITHOUT assoc, and that is the only way
     * it can work: after `assoc: true` a nested `{"0":"a"}` and a nested
     * `["a"]` are the same PHP value, so the check could not tell an
     * ambiguous object from a perfectly ordinary array — and a first attempt
     * at this rejected legitimate nested lists for exactly that reason.
     *
     * Refused rather than accepted and quietly mangled. This field is for
     * machine-readable configuration (§4), where a key that is also its own
     * index is a naming accident rather than a requirement.
     */
    private function failOnAmbiguousObject(string $attribute, mixed $node, Closure $fail, string $path = ''): void
    {
        if ($node instanceof stdClass) {
            $properties = (array) $node;

            // ⚠️ A NESTED empty object cannot survive either, and the reason
            // it differs from the top level is worth stating: the field's
            // contract says the WHOLE value is an object, so `castFromStorage`
            // can restore a top-level `[]` to `{}` unambiguously. Nested,
            // there is no such contract — `{"config":{}}` decodes to
            // `['config' => []]` and re-encodes as `{"config":[]}` with
            // nothing able to tell it apart from a genuine empty array.
            if ($properties === [] && $path !== '') {
                $fail(
                    "The {$attribute} field has an empty object at [{$path}], which PHP cannot tell "
                    .'apart from an empty array once decoded — it would silently become `[]` on save. '
                    .'Omit the key, or give the object a property.'
                );

                return;
            }

            if ($properties !== [] && array_is_list($properties)) {
                $where = $path === '' ? '' : " at [{$path}]";

                $fail(
                    "The {$attribute} field has an object{$where} whose keys are a 0-based sequence, "
                    .'which PHP cannot tell apart from a list — it would silently become an array on '
                    .'save. Use keys that are not consecutive integers from zero.'
                );

                return;
            }

            foreach ($properties as $key => $value) {
                $this->failOnAmbiguousObject($attribute, $value, $fail, $path === '' ? (string) $key : $path.'.'.$key);
            }

            return;
        }

        // A genuine JSON array. Its ELEMENTS may still be ambiguous objects.
        if (is_array($node)) {
            foreach ($node as $key => $value) {
                $this->failOnAmbiguousObject($attribute, $value, $fail, $path === '' ? (string) $key : $path.'.'.$key);
            }
        }
    }

    /** @return array<string, mixed> */
    protected function scalarApiSchema(FieldConfig $config): array
    {
        return ['type' => 'object'];
    }

    /**
     * ⚠️ NOT Laravel's `json` rule, which requires a STRING.
     *
     * `apiSchema()` advertises an object and `toStorage()` explicitly accepts
     * an already-decoded array, so an API client following the published
     * schema was rejected by the field's own validation. Accepts either form
     * and reports which one failed.
     *
     * @return array<int, mixed>
     */
    protected function scalarValidationRules(FieldConfig $config): array
    {
        return [
            function (string $attribute, mixed $value, Closure $fail): void {
                // ⚠️ Parseable is not the same as valid here. apiSchema()
                // advertises an OBJECT, so `42` and `[1, 2]` are both valid
                // JSON and both wrong — a consumer reading the published
                // schema would assume key/value data and get a list.
                if (is_string($value)) {
                    // ⚠️ Decoded WITHOUT assoc, because `{}` and `[]` both
                    // become an empty PHP array with it — and `array_is_list([])`
                    // is true, so an empty object, which the schema explicitly
                    // allows, was rejected. As stdClass the two stay distinct.
                    $decoded = json_decode($value);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $fail("The {$attribute} field is not valid JSON: ".json_last_error_msg().'.');

                        return;
                    }

                    if (! $decoded instanceof stdClass) {
                        $fail("The {$attribute} field must be a JSON object, not a list or a scalar.");

                        return;
                    }

                    $this->failOnAmbiguousObject($attribute, $decoded, $fail);

                    return;
                }

                if (! is_array($value)) {
                    $fail("The {$attribute} field must be a JSON object or a JSON string.");

                    return;
                }

                // An empty array is the one ambiguous case in PHP, and `{}`
                // is the reading that matches the published schema.
                if ($value !== [] && array_is_list($value)) {
                    $fail("The {$attribute} field must be a JSON object, not a list.");

                    return;
                }

                // A PHP array carries no object/list distinction to lose, so
                // only the top-level list case is checkable here — it is
                // handled above.
            },
        ];
    }
}
