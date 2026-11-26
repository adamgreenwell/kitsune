<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Kitsune\Core\Http\Api\EntryFieldContract;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\FieldStorage;

it('uses the same configured choices, bounds and requiredness for validation and discovery', function (): void {
    $type = EntryType::create(['handle' => 'contract', 'name' => 'Contract', 'plural_name' => 'Contracts']);
    $topics = FieldStorage::create([
        'handle' => 'topics', 'type' => 'select', 'cardinality' => 1, 'pii_class' => 'none',
        'settings' => ['options' => ['golf' => 'Golf', 'baseball' => 'Baseball']],
    ]);
    $blurb = FieldStorage::create([
        'handle' => 'blurb', 'type' => 'text', 'cardinality' => 1, 'pii_class' => 'none',
        'settings' => ['maxLength' => 12],
    ]);

    $labels = FieldStorage::create([
        'handle' => 'labels', 'type' => 'text', 'cardinality' => 2, 'pii_class' => 'none',
    ]);
    $type->fields()->create(['field_storage_id' => $labels->getKey(), 'label' => 'Labels', 'is_required' => true]);

    $type->fields()->create(['field_storage_id' => $topics->getKey(), 'label' => 'Topics', 'is_required' => true]);
    $type->fields()->create(['field_storage_id' => $blurb->getKey(), 'label' => 'Blurb', 'is_required' => false]);

    $contract = EntryFieldContract::forType($type);
    $schema = $contract->schema(write: true);
    expect($schema['required'])->toBe(['labels', 'topics'])
        ->and($schema['additionalProperties'])->toBeFalse()
        ->and($schema['properties']->labels['minItems'])->toBe(1)
        ->and($schema['properties']->labels['maxItems'])->toBe(2)
        ->and($schema['properties']->topics['enum'])->toBe(['golf', 'baseball'])
        ->and($schema['properties']->blurb['anyOf'])->toBe([
            ['type' => 'string', 'maxLength' => 12], ['type' => 'null'],
        ]);

    $validate = static fn (array $fields) => Validator::make(['values' => $fields], $contract->rules());
    expect($validate(['topics' => 'golf', 'labels' => ['one'], 'blurb' => null])->passes())->toBeTrue()
        ->and($validate(['topics' => 'golf', 'labels' => []])->fails())->toBeTrue()
        ->and($validate(['topics' => 'golf', 'labels' => ['one', 'two', 'three']])->fails())->toBeTrue()
        ->and($validate(['topics' => 'hockey', 'labels' => ['one']])->fails())->toBeTrue()
        ->and($validate(['topics' => 'golf', 'labels' => ['one'], 'blurb' => str_repeat('x', 13)])->fails())->toBeTrue()
        ->and($validate(['topics' => 'golf', 'labels' => ['one'], 'unknown' => 'text'])->fails())->toBeTrue();
});

it('publishes fields of an empty type as a JSON object', function (): void {
    $type = EntryType::create(['handle' => 'empty-contract', 'name' => 'Empty', 'plural_name' => 'Empty']);
    $contract = EntryFieldContract::forType($type);

    expect(json_encode($contract->schema(), JSON_THROW_ON_ERROR))
        ->toBe('{"type":"object","properties":{},"additionalProperties":false}');
});
