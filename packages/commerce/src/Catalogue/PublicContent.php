<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Catalogue;

use Illuminate\Support\Facades\Validator;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Fields\Types\JsonType;
use Kitsune\Core\Fields\Types\RelationType;
use Kitsune\Core\Fields\Types\RichTextType;
use Kitsune\Core\Fields\Types\SlugType;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Validation\ScopedUnique;

/** Current native content constraints, checked without one relation query per product or target. @internal */
final class PublicContent
{
    public function __construct(private readonly FieldTypeRegistry $types) {}

    /**
     * Relations are counted by the catalogue in two page-wide queries. Only the first-party relation implementation
     * has that special path; other types keep their own native rules, including any query a future custom rule needs.
     * A persisted slug already has the database's unique constraint, so repeating its write-only uniqueness query adds
     * no read admission guarantee. Its shape and every other native field constraint still run.
     *
     * @param  list<Field>  $fields
     * @param  array<int|string, array{count:int,invalid:int}>  $relations
     */
    public function ready(Entry $entry, array $fields, array $relations): bool
    {
        if ($entry->status !== 'published' || ! is_array($entry->values)) {
            return false;
        }

        $data = ['title' => $entry->title, 'slug' => $entry->slug];
        $rules = ['title' => ['required', 'string', 'max:255'], 'slug' => ['nullable', 'string', 'max:255']];

        foreach ($fields as $field) {
            $storage = $field->fieldStorage;

            if (! $storage instanceof FieldStorage || ! $this->types->has($storage->type)
                || ($storage->org_id !== null && (string) $storage->org_id !== (string) $entry->org_id)) {
                return false;
            }

            $type = $this->types->get($storage->type);
            $config = new FieldConfig($storage, $field, $entry);

            if ($type instanceof RelationType) {
                $counts = $relations[$storage->getKey()] ?? ['count' => 0, 'invalid' => 0];

                if ($counts['invalid'] !== 0 || ($config->isRequired() && $counts['count'] === 0)
                    || ($config->cardinality() > 0 && $counts['count'] > $config->cardinality())) {
                    return false;
                }

                continue;
            }

            // No first-party type has another relational strategy. An unknown implementation cannot inherit the
            // first-party relation's batch proof while silently dropping its own rules.
            if ($type->strategy() === StorageStrategy::Relational) {
                return false;
            }

            $handle = $type->promotedColumn() ?? $storage->handle;
            $key = $type->strategy() === StorageStrategy::Promoted ? $handle : 'values.'.$handle;
            $value = $type->strategy() === StorageStrategy::Promoted ? $entry->getAttribute($handle) : ($entry->values[$handle] ?? null);

            // JSON's native input rule distinguishes a JSON string's empty arrays from ambiguous decoded input.
            // This is already stored JSON, so retain that distinction instead of treating storage as a new submission.
            if ($type instanceof JsonType && $value !== null) {
                $value = json_encode($type->fromStorage($value, $config), JSON_THROW_ON_ERROR);
            }

            data_set($data, $key, $value);
            $rules[$key] = array_values(array_filter($type->validationRules($config),
                static fn (mixed $rule): bool => ! ($type instanceof SlugType && $rule instanceof ScopedUnique)));

            if (($elements = $type->elementValidationRules($config)) !== []) {
                $rules[$key.'.*'] = $elements;
            }
        }

        return Validator::make($data, $rules)->passes();
    }

    /**
     * Only Storefront's designated non-personal description is published; adapted arbitrary values stay private.
     *
     * @param  list<Field>  $fields
     */
    public function description(Entry $entry, array $fields): ?string
    {
        foreach ($fields as $field) {
            $storage = $field->fieldStorage;

            if (! $storage instanceof FieldStorage || $storage->handle !== 'product_description'
                || $storage->pii_class !== 'none' || $storage->cardinality !== 1
                || ! $this->types->get($storage->type) instanceof RichTextType) {
                continue;
            }

            $value = $entry->values['product_description'] ?? null;

            if (! is_string($value)) {
                return null;
            }

            // The public surface is plain text, never stored HTML. The sanitizer also removes forbidden elements'
            // text. Bound its input for this small excerpt rather than parsing an arbitrarily large rich document.
            $safe = (new RichTextType)->sanitize(mb_substr($value, 0, 8192));
            $text = html_entity_decode(strip_tags($safe), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim((string) preg_replace('/\s+/u', ' ', $text));

            return $text === '' ? null : mb_substr($text, 0, 240);
        }

        return null;
    }
}
