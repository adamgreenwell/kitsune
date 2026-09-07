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
    $config = configFor($handle, $settings, isset($data['f']) && is_array($data['f']) ? -1 : 1);

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
