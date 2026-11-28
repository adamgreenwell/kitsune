<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Content;

use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Validator;
use Kitsune\Core\Blueprints\Declarations\FieldDeclaration;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Http\Api\EntryFieldContract;
use Kitsune\Core\Models\EntryType;

/**
 * Describe and validate the inline fields of a draft inside an established Site.
 *
 * @internal Candidate for the v1.2 extension audit.
 *
 * @phpstan-type FieldDescription array{type: string, cardinality: int, required: bool, inline: bool}
 */
final readonly class SiteDraftFields
{
    public function __construct(private SiteDraftAccess $access) {}

    /**
     * @param  list<FieldDeclaration>  $expected  Declared storage settings are compared without returning them.
     * @return array{fingerprint: string, fields: array<string, FieldDescription>, matches: bool}
     */
    public function describe(int $siteId, string $typeHandle, Authenticatable $actor, array $expected = []): array
    {
        $type = $this->access->type($this->access->site($siteId), $typeHandle, $actor);
        $contract = EntryFieldContract::forType($type);
        $definitions = $contract->definitions();
        $fields = array_map(self::describeField(...), $definitions);
        $matches = true;
        foreach ($expected as $declaration) {
            $field = $definitions[$declaration->handle] ?? null;
            if ($field === null || $field->storage->type !== $declaration->type
                || $field->cardinality() !== $declaration->cardinality
                || $field->isRequired() !== $declaration->isRequired
                || self::canonical(array_intersect_key($field->storage->settings ?? [], $declaration->settings)) !== self::canonical($declaration->settings)) {
                $matches = false;
                break;
            }
        }

        return ['fingerprint' => self::fingerprint($siteId, $type, $contract), 'fields' => $fields, 'matches' => $matches];
    }

    /**
     * An existing draft accepts a partial change. A new draft uses the ordinary required-field rules.
     *
     * @param  array<string, mixed>  $values
     * @return array{values: array<string, mixed>, errors: array<string, list<string>>}
     */
    public function validate(
        int $siteId,
        string $typeHandle,
        Authenticatable $actor,
        string $fingerprint,
        array $values,
        ?int $entryId = null,
    ): array {
        $type = $this->access->type($this->access->site($siteId), $typeHandle, $actor);
        $entry = $this->access->writeTarget($siteId, $type, $actor, $entryId);

        $contract = EntryFieldContract::forType($type);
        if (! hash_equals(self::fingerprint($siteId, $type, $contract), $fingerprint)) {
            throw new DomainException('The destination field schema changed. Review the fields again.');
        }

        $rules = $contract->rules($entry, partial: $entry !== null);
        $errors = [];
        foreach ($contract->definitions() as $handle => $definition) {
            if ($definition->storage->strategy() === StorageStrategy::Inline) {
                continue;
            }
            if (array_key_exists($handle, $values)) {
                $errors['values.'.$handle] = ['This field has a dedicated write path.'];
            }
            unset($rules['values.'.$handle], $rules['values.'.$handle.'.*'], $values[$handle]);
        }
        $validator = Validator::make(['values' => $values], $rules);
        $errors += $validator->errors()->getMessages();

        return $errors === [] ? ['values' => $validator->validated()['values'], 'errors' => []] : ['values' => [], 'errors' => $errors];
    }

    /** @return FieldDescription */
    private static function describeField(FieldConfig $field): array
    {
        return [
            'type' => $field->storage->type,
            'cardinality' => $field->cardinality(),
            'required' => $field->isRequired(),
            'inline' => $field->storage->strategy() === StorageStrategy::Inline,
        ];
    }

    /** @internal Used by the draft writer after resolving the current field graph. */
    public static function fingerprint(int $siteId, EntryType $type, EntryFieldContract $contract): string
    {
        $fields = array_map(static fn (FieldConfig $field): array => [
            ...self::describeField($field),
            'binding' => $field->field?->getKey(),
            'storage' => $field->storage->getKey(),
            'strategy' => $field->storage->strategy()->value,
            'column' => $field->storage->promotedColumn(),
            'settings' => $field->storage->settings ?? [],
        ], $contract->definitions());
        $witness = [$siteId, $type->getKey(), $type->org_id, $type->subject_field_id, $fields, $contract->schema(write: true)];

        return hash('sha256', "kitsune.site-draft-fields.v1\0".json_encode(self::canonical($witness), JSON_THROW_ON_ERROR));
    }

    private static function canonical(mixed $value): mixed
    {
        if (is_object($value)) {
            return (object) self::canonical(get_object_vars($value));
        }
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }
}
