<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Http\Api;

use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\FieldStorage;
use stdClass;

/** @internal Field definitions consumed by the generated content API. */
final readonly class EntryFieldContract
{
    /** @param list<FieldConfig> $fields */
    private function __construct(private FieldTypeRegistry $registry, private array $fields) {}

    public static function forType(EntryType $entryType): self
    {
        $fields = [];

        $entryType->loadMissing('fields.fieldStorage');
        foreach ($entryType->fields->sortBy([['ordering', 'asc'], ['id', 'asc']]) as $field) {
            $storage = $field->fieldStorage;

            if ($storage !== null && ($storage->org_id === null || $storage->org_id === $entryType->org_id)) {
                $fields[] = new FieldConfig($storage, $field);
            }
        }

        return new self(app(FieldTypeRegistry::class), $fields);
    }

    /** @return list<FieldStorage> */
    public function relationStorage(): array
    {
        $relations = [];
        foreach ($this->fields as $config) {
            if ($config->storage->strategy() === StorageStrategy::Relational) {
                $relations[] = $config->storage;
            }
        }

        return $relations;
    }

    /** @param array<int, list<array{entry: Entry, group: string|null}>> $relations */
    public function values(Entry $entry, array $relations): stdClass
    {
        $values = [];

        foreach ($this->fields as $config) {
            $storage = $config->storage;
            $value = match ($storage->strategy()) {
                StorageStrategy::Inline => $entry->values[$config->handle()] ?? null,
                StorageStrategy::Promoted => $entry->getAttribute((string) $storage->promotedColumn()),
                StorageStrategy::Relational => array_map(
                    fn (array $link): int|array => $link['group'] === null
                        ? $link['entry']->id
                        : ['group' => $link['group'], 'entry' => $link['entry']->id],
                    $relations[$storage->id] ?? [],
                ),
            };
            $values[$config->handle()] = $this->registry->get($storage->type)->toApi($value, $config);
        }

        return (object) $values;
    }

    /** @return array{type: 'object', properties: stdClass, additionalProperties: false, required?: list<string>} */
    public function schema(bool $write = false, bool $partial = false): array
    {
        $properties = [];
        $required = [];

        foreach ($this->fields as $config) {
            $handle = $config->handle();
            $fragment = $this->registry->get($config->storage->type)->apiSchema($config);

            if ($config->storage->strategy() === StorageStrategy::Relational) {
                $targets = [
                    ['type' => 'integer', 'minimum' => 1],
                    [
                        'type' => 'object',
                        'properties' => [
                            'group' => ['type' => 'string', 'format' => 'uuid'],
                            'entry' => ['type' => 'integer', 'minimum' => 1],
                        ],
                        'required' => ['group', 'entry'],
                        'additionalProperties' => false,
                    ],
                ];
                if ($write) {
                    $targets[] = [
                        'type' => 'object',
                        'properties' => [
                            'entry' => ['type' => 'integer', 'minimum' => 1],
                            'mode' => ['enum' => ['follow', 'pin']],
                        ],
                        'required' => ['entry'],
                        'additionalProperties' => false,
                    ];
                }
                $fragment['items'] = ['oneOf' => $targets];
            }

            if ($write && $config->isRequired()) {
                if (! $partial) {
                    $required[] = $handle;
                }
                if (($fragment['type'] ?? null) === 'array') {
                    // Laravel's required rule rejects an empty array.
                    $fragment['minItems'] = max(1, (int) ($fragment['minItems'] ?? 0));
                } elseif (($fragment['type'] ?? null) === 'string') {
                    $fragment['minLength'] = max(1, (int) ($fragment['minLength'] ?? 0));
                } elseif (($fragment['type'] ?? null) === 'object') {
                    $fragment['minProperties'] = max(1, (int) ($fragment['minProperties'] ?? 0));
                }
            } elseif ($write || $config->storage->strategy() !== StorageStrategy::Relational) {
                $fragment = ['anyOf' => [$fragment, ['type' => 'null']]];
            }

            $properties[$handle] = ['title' => $config->label(), ...$fragment];
        }

        $schema = ['type' => 'object', 'properties' => (object) $properties, 'additionalProperties' => false];

        return $required === [] ? $schema : [...$schema, 'required' => $required];
    }

    /**
     * When updating, records are supplied to the existing field rules so
     * checks for uniqueness can exclude the record being edited.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(?Entry $record = null, bool $partial = false): array
    {
        $handles = array_map(static fn (FieldConfig $config): string => $config->handle(), $this->fields);
        $rules = ['values' => [$partial ? 'sometimes' : 'present', ...($handles === [] ? ['array', 'size:0'] : ['array:'.implode(',', $handles)])]];

        foreach ($this->fields as $definition) {
            $config = $definition->for($record);
            $fieldType = $this->registry->get($config->storage->type);
            $handle = 'values.'.$config->handle();

            $rules[$handle] = [...($partial ? ['sometimes'] : []), ...$fieldType->validationRules($config)];
            $elements = $config->storage->strategy() === StorageStrategy::Relational
                ? ['required', self::relationInput(...)] : $fieldType->elementValidationRules($config);

            if ($elements !== []) {
                $rules[$handle.'.*'] = $elements;
            }
        }

        return $rules;
    }

    /** @return array{entry: int, pin?: bool, follow?: bool}|array{group: string}|null */
    public static function relationChoice(mixed $value): ?array
    {
        if (is_int($value) && $value > 0) {
            return ['entry' => $value, 'pin' => true];
        }

        if (! is_array($value) || ! isset($value['entry']) || ! is_int($value['entry']) || $value['entry'] < 1) {
            return null;
        }

        if (count($value) === 2 && isset($value['group']) && Str::isUuid($value['group'])) {
            return ['group' => $value['group']];
        }

        if (array_diff(array_keys($value), ['entry', 'mode']) !== []) {
            return null;
        }

        if (! array_key_exists('mode', $value)) {
            return ['entry' => $value['entry']];
        }

        return match ($value['mode']) {
            'pin' => ['entry' => $value['entry'], 'pin' => true],
            'follow' => ['entry' => $value['entry'], 'follow' => true],
            default => null,
        };
    }

    private static function relationInput(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::relationChoice($value) === null) {
            $fail('Choose an entry ID, an entry with a relation mode, or an existing group link.');
        }
    }

    /** @return array<string, mixed> */
    public function writePayload(bool $partial, bool $canPublish): array
    {
        $fields = $this->schema(write: true, partial: $partial);
        $statuses = $canPublish || $partial ? Entry::STATUSES : ['draft', 'archived'];
        $properties = [
            'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'pattern' => '\S'],
            'status' => ['type' => 'string', 'enum' => $statuses,
                'description' => 'Changing to published requires the publish ability.'],
            'values' => $fields,
        ];

        if (! $this->hasPromotedSlug()) {
            $properties['slug'] = ['anyOf' => [
                ['type' => 'string', 'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$', 'maxLength' => 255],
                ['type' => 'null'],
            ]];
        }

        $payload = ['type' => 'object', 'properties' => (object) $properties, 'additionalProperties' => false];
        if (! $partial) {
            $payload['required'] = isset($fields['required']) ? ['title', 'values'] : ['title'];
        }

        return $payload;
    }

    /**
     * Partition values already validated against this contract for the model and relation writer.
     *
     * @param  array<string, mixed>  $changes
     * @return array{array<string, mixed>, array<string, list<array{entry: int, pin?: bool, follow?: bool}|array{group: string}>>}
     */
    public function writeChanges(Entry $entry, array $changes): array
    {
        $definitions = $this->definitions();
        $attributes = array_intersect_key($changes, array_flip(['title', 'status', 'slug']));
        $inline = $entry->values ?? [];
        $relations = [];

        foreach ($changes['values'] ?? [] as $handle => $value) {
            $config = $definitions[$handle];
            $storage = $config->storage;
            match ($storage->strategy()) {
                StorageStrategy::Inline => $inline[$handle] = $value,
                StorageStrategy::Promoted => $attributes[(string) $storage->promotedColumn()] = $value,
                StorageStrategy::Relational => $relations[$handle] = array_map(
                    fn (mixed $choice): array => self::relationChoice($choice)
                        ?? throw ValidationException::withMessages(['values.'.$handle => 'Choose an available relation.']),
                    $value ?? [],
                ),
            };
        }
        if ($inline !== ($entry->values ?? [])) {
            $attributes['values'] = $inline;
        }

        return [$attributes, $relations];
    }

    public function hasPromotedSlug(): bool
    {
        foreach ($this->fields as $config) {
            if ($config->storage->promotedColumn() === 'slug') {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, FieldConfig> */
    public function definitions(): array
    {
        $definitions = [];
        foreach ($this->fields as $config) {
            $definitions[$config->handle()] = $config;
        }

        return $definitions;
    }
}
