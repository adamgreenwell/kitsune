<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/*
 * Rules a field type declares have to survive contact with Laravel's
 * validator. Asserting the array's SHAPE proves nothing — RelationType's
 * cross-org check looked correct in an array and validated nothing at all.
 */

beforeEach(function (): void {
    $this->registry = new FieldTypeRegistry;
});

function validate(string $handle, array $data, array $settings = []): Illuminate\Validation\Validator
{
    $type = app(FieldTypeRegistry::class)->get($handle);
    $config = configFor($handle, $settings, is_array($data['f'] ?? null) && $handle !== 'json' ? -1 : 1);

    $rules = ['f' => $type->validationRules($config)];

    if (($element = $type->elementValidationRules($config)) !== []) {
        $rules['f.*'] = $element;
    }

    return Validator::make($data, $rules);
}

describe('number', function (): void {
    it('rejects a fractional value for an integer-formatted field', function (): void {
        // 12.9 passed `numeric` and toStorage() truncated it to 12 — valid
        // input silently becoming different data.
        expect(validate('number', ['f' => '12.9'], ['format' => 'integer'])->fails())->toBeTrue();
    });

    it('accepts a whole number for an integer-formatted field', function (): void {
        expect(validate('number', ['f' => '12'], ['format' => 'integer'])->fails())->toBeFalse();
    });

    it('still accepts a fraction when the format is decimal', function (): void {
        expect(validate('number', ['f' => '12.9'], ['format' => 'decimal'])->fails())->toBeFalse();
    });
});

describe('relation, where the rule has to reach each id', function (): void {
    beforeEach(function (): void {
        $this->orgA = Org::create(['name' => 'A', 'slug' => 'val-a']);
        $this->orgB = Org::create(['name' => 'B', 'slug' => 'val-b']);

        app(Context::class)->setOrg($this->orgA);
        $this->site = Site::create(['org_id' => $this->orgA->id, 'handle' => 'a', 'slug' => 'val-a-main', 'name' => 'A']);
        app(Context::class)->setSite($this->site);

        $this->type = EntryType::create(['org_id' => $this->orgA->id, 'handle' => 'article', 'name' => 'A', 'plural_name' => 'As']);
        $this->mine = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Mine']);

        // Another org's entry, created outside this scope.
        $this->foreign = Entry::withoutScopeBecause('fixture: the attacker\'s own row', function () {
            return Entry::create([
                'org_id' => $this->orgB->id,
                'site_id' => null,
                'entry_type_id' => $this->type->id,
                'title' => 'Theirs',
            ]);
        });
    });

    afterEach(fn () => app(Context::class)->forget());

    it('accepts ids inside the current scope', function (): void {
        expect(validate('relation', ['f' => [$this->mine->id]])->fails())->toBeFalse();
    });

    it('REJECTS another org\'s id', function (): void {
        // The point of the rule. Under the old shape ScopedExists received
        // the whole array and compared the id column against it, so this
        // check never ran — and valid submissions failed instead.
        expect(validate('relation', ['f' => [$this->foreign->id]])->fails())->toBeTrue();
    });

    it('rejects a mixed array rather than passing on the valid one', function (): void {
        expect(validate('relation', ['f' => [$this->mine->id, $this->foreign->id]])->fails())->toBeTrue();
    });

    it('names the offending element, which a whole-array rule cannot', function (): void {
        $errors = validate('relation', ['f' => [$this->mine->id, $this->foreign->id]])->errors();

        expect($errors->keys())->toContain('f.1');
    });
});

/*
 * ⚠️ `supportsCardinality()` defaults to true, so every scalar type
 * ADVERTISED multi-value support and none of them implemented it: `text` cast
 * the array to the literal string "Array", `number` cast it to 1.0, and
 * `date` threw an unhandled Carbon exception. Handled once in the base class
 * now, because it is a property of the contract rather than of each type.
 */
describe('a multi-value scalar field stores an array of scalars', function (): void {
    it('converts each element rather than the array', function (string $handle, array $input, array $expected): void {
        $type = app(FieldTypeRegistry::class)->get($handle);

        expect($type->toStorage($input, configFor($handle, [], -1)))->toBe($expected);
    })->with([
        'text' => ['text', [1, 'two'], ['1', 'two']],
        'number' => ['number', ['1.5', '2'], [1.5, 2.0]],
        'date' => ['date', ['2026-01-05', '2026-02-06'], ['2026-01-05', '2026-02-06']],
        'datetime' => ['datetime', ['2026-01-05T03:04:05+00:00'], ['2026-01-05T03:04:05+00:00']],
        'textarea' => ['textarea', ['a', 'b'], ['a', 'b']],
    ]);

    it('still converts a single value when cardinality is 1', function (): void {
        expect(app(FieldTypeRegistry::class)->get('text')->toStorage(42, configFor('text')))->toBe('42');
    });

    it('round-trips an array back out', function (): void {
        $type = app(FieldTypeRegistry::class)->get('number');
        $config = configFor('number', [], -1);

        expect($type->fromStorage($type->toStorage(['1', '2'], $config), $config))->toBe([1.0, 2.0]);
    });

    it('leaves relation alone, since it manages its own array', function (): void {
        // Wrapping it would double-wrap: it is an array at every cardinality.
        expect(app(FieldTypeRegistry::class)->get('relation')->toStorage(['3', '4'], configFor('relation')))
            ->toBe([3, 4]);
    });
});

describe('multi-select', function (): void {
    it('rejects a value that is not one of the options', function (): void {
        // It checked only that the outer value was an array, so any string —
        // including an option since removed — went straight into storage.
        expect(validate('multi_select', ['f' => ['a', 'NOT_AN_OPTION']], ['options' => ['a' => 'A', 'b' => 'B']])->fails())
            ->toBeTrue();
    });

    it('accepts values that are options', function (): void {
        expect(validate('multi_select', ['f' => ['a', 'b']], ['options' => ['a' => 'A', 'b' => 'B']])->fails())
            ->toBeFalse();
    });
});

describe('json', function (): void {
    it('accepts a decoded object, which its own apiSchema advertises', function (): void {
        // Laravel's `json` rule requires a STRING, so an API client following
        // the published schema was rejected by the field's own validation.
        expect(validate('json', ['f' => ['a' => 1]])->fails())->toBeFalse();
    });

    it('still accepts a JSON string', function (): void {
        expect(validate('json', ['f' => '{"a":1}'])->fails())->toBeFalse();
    });

    it('rejects a string that is not JSON, and says why', function (): void {
        $v = validate('json', ['f' => '{not json']);

        expect($v->fails())->toBeTrue()
            ->and($v->errors()->first('f'))->toContain('not valid JSON');
    });
});
