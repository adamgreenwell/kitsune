<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Fields\Types\NumberType;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
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

describe('slug uniqueness has to exclude the entry being edited', function (): void {
    beforeEach(function (): void {
        $this->org = Org::create(['name' => 'S', 'slug' => 'slug-org']);
        app(Context::class)->setOrg($this->org);
        $this->site = Site::create(['org_id' => $this->org->id, 'handle' => 's', 'slug' => 'slug-site', 'name' => 'S']);
        app(Context::class)->setSite($this->site);

        $this->type = EntryType::create(['org_id' => $this->org->id, 'handle' => 'page', 'name' => 'P', 'plural_name' => 'Ps']);
        $this->entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'About', 'slug' => 'about']);
    });

    afterEach(fn () => app(Context::class)->forget());

    /*
     * ⚠️ The rule passed null as the ignored id, so an update checked the row
     * against ITSELF: saving a page without touching its slug reported the
     * slug as already taken. The static EntryResource form had this right
     * because Filament hands it the record — a field type is handed a
     * FieldConfig, and there was nowhere in it to say which entry.
     */
    it('accepts an unchanged slug on the entry that already holds it', function (): void {
        $type = app(FieldTypeRegistry::class)->get('slug');
        $config = configFor('slug')->for($this->entry);

        expect(Validator::make(['f' => 'about'], ['f' => $type->validationRules($config)])->fails())
            ->toBeFalse();
    });

    it('still rejects a slug another entry holds', function (): void {
        Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Contact', 'slug' => 'contact']);

        $type = app(FieldTypeRegistry::class)->get('slug');
        $config = configFor('slug')->for($this->entry);

        expect(Validator::make(['f' => 'contact'], ['f' => $type->validationRules($config)])->fails())
            ->toBeTrue();
    });

    /*
     * ⚠️ The rule checked the SUBMITTED value while the database holds the
     * NORMALISED one. With `hello-world` taken, submitting `Hello World`
     * passed uniqueness and then violated the unique index — a 500 where the
     * user should have seen a validation message. Same reason soft-deleted
     * rows are included in the check: it has to match what the database will
     * actually enforce.
     */
    it('rejects input that normalises onto a slug already taken', function (): void {
        $type = app(FieldTypeRegistry::class)->get('slug');

        expect(Validator::make(['f' => 'About'], ['f' => $type->validationRules(configFor('slug'))])->fails())
            ->toBeTrue();
    });

    it('still accepts input that normalises onto the entry\'s own slug', function (): void {
        $type = app(FieldTypeRegistry::class)->get('slug');
        $config = configFor('slug')->for($this->entry);

        expect(Validator::make(['f' => 'About'], ['f' => $type->validationRules($config)])->fails())
            ->toBeFalse();
    });

    it('still rejects a taken slug when there is no record at all', function (): void {
        $type = app(FieldTypeRegistry::class)->get('slug');

        expect(Validator::make(['f' => 'about'], ['f' => $type->validationRules(configFor('slug'))])->fails())
            ->toBeTrue();
    });
});

it('refuses a JSON value over the cap as VALIDATION, not as an exception later', function (): void {
    // ⚠️ castToStorage() threw for this, which in a validate-then-save request
    // arrives after validation has passed — turning an ordinary mistake in a
    // field a user can type into into a 500 rather than a message.
    $big = ['blob' => str_repeat('x', 70000)];

    expect(validate('json', ['f' => $big])->fails())->toBeTrue()
        ->and(validate('json', ['f' => json_encode($big)])->fails())->toBeTrue()
        ->and(validate('json', ['f' => ['blob' => 'small']])->fails())->toBeFalse();
});

it('publishes multi-select choices, which validation already enforced', function (): void {
    // Element validation accepted only the configured keys while the schema
    // advertised every string — so a generated client could not discover the
    // options and would submit values the API then rejected.
    $config = configFor('multi_select', ['options' => ['1' => 'One', 'b' => 'B']]);
    $schema = app(FieldTypeRegistry::class)->get('multi_select')->apiSchema($config);

    expect($schema['items']['enum'])->toBe(['1', 'b'])
        ->and(app(FieldTypeRegistry::class)->get('multi_select')->apiSchema(configFor('multi_select'))['items'])
        ->toBe(['type' => 'string']);
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
describe('a datetime round trip keeps what the schema advertises', function (): void {
    /*
     * ⚠️ `toIso8601String()` emits whole seconds, so an accepted
     * `03:04:05.123456Z` was STORED as `03:04:05+00:00`. The published schema
     * says `date-time`, which permits a fraction, so the field advertised a
     * round trip it did not perform — and the value was silently different
     * from the one submitted.
     */
    it('preserves fractional seconds', function (): void {
        $type = app(FieldTypeRegistry::class)->get('datetime');
        $config = configFor('datetime');

        $stored = $type->toStorage('2026-01-05T03:04:05.123456Z', $config);

        expect($stored)->toBe('2026-01-05T03:04:05.123456+00:00')
            ->and($type->fromStorage($stored, $config)->format('u'))->toBe('123456');
    });

    it('pads a whole second to the same width', function (): void {
        // Fixed width, not "only when present". This projects to a VARCHAR
        // and compares as text, so mixing widths would order `05+00:00`
        // against `05.5+00:00` by punctuation rather than by time.
        $type = app(FieldTypeRegistry::class)->get('datetime');

        expect($type->toStorage('2026-01-05T03:04:05Z', configFor('datetime')))
            ->toBe('2026-01-05T03:04:05.000000+00:00');
    });

    it('stays inside the 32 characters the projection reserves', function (): void {
        $type = app(FieldTypeRegistry::class)->get('datetime');
        $stored = $type->toStorage('2026-12-31T23:59:59.999999+14:00', configFor('datetime'));

        expect(strlen((string) $stored))->toBeLessThanOrEqual(
            $type->projection(configFor('datetime'))->precision
        );
    });
});

it('enforces a configured step, rather than decorating a widget', function (): void {
    // ⚠️ `step` was configurable and unenforced, so it constrained a form
    // and nothing else — an API client submitted any value it liked. A
    // setting the server does not check is a suggestion, and this one reads
    // as a rule.
    expect(validate('number', ['f' => '0.3'], ['step' => 0.5])->fails())->toBeTrue()
        ->and(validate('number', ['f' => '1.5'], ['step' => 0.5])->fails())->toBeFalse()
        ->and(validate('number', ['f' => '0.3'], [])->fails())->toBeFalse();
});

it('offsets the step from a configured min', function (): void {
    // A step of 5 from a min of 2 permits 2, 7, 12 — not 5.
    expect(validate('number', ['f' => '7'], ['step' => 5, 'min' => 2])->fails())->toBeFalse()
        ->and(validate('number', ['f' => '5'], ['step' => 5, 'min' => 2])->fails())->toBeTrue();
});

it('rejects a non-string slug without reaching the normaliser', function (): void {
    // ⚠️ Laravel keeps evaluating rules after `string` fails, so an array
    // reached the uniqueness normaliser and castToStorage() attempted a
    // string cast — a PHP Error rather than a validation response, from
    // input that had already been rejected.
    // Built directly: the helper reads an array value as a multi-value
    // field, which is not the shape this guards.
    $type = app(FieldTypeRegistry::class)->get('slug');
    $v = Validator::make(['f' => ['not', 'a', 'string']], ['f' => $type->validationRules(configFor('slug'))]);

    expect(fn () => $v->fails())->not->toThrow(Throwable::class)
        ->and($v->fails())->toBeTrue();
});

it('publishes the text length and pattern it enforces', function (): void {
    // The inherited schema said {"type": "string"} while validation rejected
    // anything past 255 characters, so a generated client accepted payloads
    // the API refused.
    $type = app(FieldTypeRegistry::class)->get('text');

    expect($type->apiSchema(configFor('text', ['maxLength' => 40, 'pattern' => '^[a-z]+$'])))
        ->toBe(['type' => 'string', 'maxLength' => 40, 'pattern' => '^[a-z]+$']);
});

it('publishes the relation cardinality bound too', function (): void {
    // RelationType overrides apiSchema(), so it did not inherit the bound —
    // a generated client could submit three targets to a two-target relation
    // and be rejected by the API that advertised it.
    $type = app(FieldTypeRegistry::class)->get('relation');

    expect($type->apiSchema(configFor('relation', [], 2))['maxItems'])->toBe(2)
        ->and($type->apiSchema(configFor('relation', [], -1)))->not->toHaveKey('maxItems');
});

it('publishes the cardinality bound it already enforces', function (): void {
    // Validation enforces `max:{cardinality}`, so an unbounded array schema
    // let a generated client consider three elements valid on a field that
    // holds two — and the API rejected what its own schema allowed.
    $type = app(FieldTypeRegistry::class)->get('text');

    expect($type->apiSchema(configFor('text', [], 2))['maxItems'])->toBe(2)
        ->and($type->apiSchema(configFor('text', [], -1)))->not->toHaveKey('maxItems')
        ->and($type->apiSchema(configFor('text', [], 1)))->not->toHaveKey('maxItems');
});

describe('a multi-value scalar field stores an array of scalars', function (): void {
    it('converts each element rather than the array', function (string $handle, array $input, array $expected): void {
        $type = app(FieldTypeRegistry::class)->get($handle);

        expect($type->toStorage($input, configFor($handle, [], -1)))->toBe($expected);
    })->with([
        'text' => ['text', [1, 'two'], ['1', 'two']],
        'number' => ['number', ['1.5', '2'], [1.5, 2.0]],
        'date' => ['date', ['2026-01-05', '2026-02-06'], ['2026-01-05', '2026-02-06']],
        'datetime' => ['datetime', ['2026-01-05T03:04:05.000000+00:00'], ['2026-01-05T03:04:05.000000+00:00']],
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

/*
 * ⚠️ The cardinality fix stopped at conversion. Validation still applied
 * `string` / `numeric` / `date` to the OUTER array, so a multi-value field
 * rejected the array it was supposed to store and accepted a bare scalar
 * which `toStorage()` then wrapped into a singleton. Reported in review.
 */
describe('cardinality decides where the scalar rules land', function (): void {
    it('accepts the array and rejects a bad element', function (string $handle, array $ok, array $bad): void {
        expect(validate($handle, ['f' => $ok])->fails())->toBeFalse('valid array rejected')
            ->and(validate($handle, ['f' => $bad])->fails())->toBeTrue('invalid element accepted');
    })->with([
        'number' => ['number', [1, 2], [1, 'not a number']],
        'date' => ['date', ['2026-01-05'], ['2026-01-05', 'not a date']],
    ]);

    it('names the offending element rather than the whole field', function (): void {
        expect(validate('number', ['f' => [1, 'nope']])->errors()->keys())->toContain('f.1');
    });

    it('requires an array, not a scalar, once cardinality is many', function (): void {
        // It accepted a scalar and silently wrapped it, so the stored shape
        // did not match what the author submitted.
        $type = app(FieldTypeRegistry::class)->get('text');

        expect(Validator::make(['f' => 'just a string'], ['f' => $type->validationRules(configFor('text', [], -1))])->fails())
            ->toBeTrue();
    });

    it('publishes an ARRAY api schema for a multi-value field', function (): void {
        // Storage and API conversion both return an array, so advertising the
        // scalar shape would generate clients that submit the wrong thing.
        $type = app(FieldTypeRegistry::class)->get('text');

        expect($type->apiSchema(configFor('text', [], -1)))
            ->toBe(['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 255]])
            ->and($type->apiSchema(configFor('text')))->toBe(['type' => 'string', 'maxLength' => 255]);
    });
});

/*
 * The projection is a promise about the values a field admits, and nothing
 * was keeping it: DECIMAL(12,2) with no bounds accepted values the engine
 * then refused, and values it silently rounded.
 */
describe('a number stays inside the column it projects to', function (): void {
    it('rejects a value that would overflow the projection', function (): void {
        // PostgreSQL: `numeric field overflow`, and the column cannot be
        // created at all while such a row exists.
        expect(validate('number', ['f' => '10000000000'])->fails())->toBeTrue();
    });

    it('rejects more decimal places than the projection keeps', function (): void {
        // 1.234 through DECIMAL(12,2) rounds to 1.23, so two distinct stored
        // values compare equal through the index.
        expect(validate('number', ['f' => '1.234'])->fails())->toBeTrue();
    });

    it('accepts a value that fits', function (): void {
        expect(validate('number', ['f' => '99999999.99'])->fails())->toBeFalse();
    });

    it('widens with the configured precision', function (): void {
        expect(validate('number', ['f' => '10000000000'], ['precision' => 20, 'scale' => 2])->fails())->toBeFalse();
    });

    it('projects at the configured precision and scale', function (): void {
        $type = app(FieldTypeRegistry::class)->get('number');

        expect($type->projection(configFor('number', ['precision' => 14, 'scale' => 4]))->signature())
            ->toBe('decimal14_4');
    });

    it('caps precision at what a JSON number can actually distinguish', function (): void {
        // ⚠️ A decimal is stored as a JSON number, and JSON numbers are IEEE
        // doubles — about 15-17 significant digits. At precision 20,
        // 123456789012345678.12 and ...78.13 both become the same float
        // BEFORE reaching JSON, so validating and projecting at 20 would
        // advertise an exactness the storage cannot hold.
        $type = app(FieldTypeRegistry::class)->get('number');

        expect($type->projection(configFor('number', ['precision' => 20, 'scale' => 2]))->precision)
            ->toBe(NumberType::MAX_PRECISION);
    });

    it('will not let another org\'s in-range value overflow this column', function (): void {
        // The JSON-kind guard is not enough: a shared column reads its key
        // from every row, including rows whose org never indexed the field.
        // `10000000000` is a perfectly valid number and still out of range
        // for NUMERIC(12,2).
        //
        // ⚠️ And the range is what the column HOLDS, not a power of ten
        // beside it. `abs(v) < 10^10` admits 9999999999.999, which the
        // scale-2 cast rounds to 10000000000.00 and overflows anyway.
        $type = app(FieldTypeRegistry::class)->get('number');

        expect($type->projection(configFor('number'))->range())
            ->toBe(['min' => '-9999999999.99', 'max' => '9999999999.99'])
            ->and($type->projection(configFor('number', ['precision' => 15, 'scale' => 0]))->range())
            ->toBe(['min' => '-999999999999999', 'max' => '999999999999999']);
    });

    it('gives an integer projection the ASYMMETRIC range BIGINT actually has', function (): void {
        // A magnitude bound excluded -9223372036854775808, whose absolute
        // value equals the bound and which is a perfectly valid BIGINT.
        $type = app(FieldTypeRegistry::class)->get('number');

        expect($type->projection(configFor('number', ['format' => 'integer']))->range())
            ->toBe(['min' => '-9223372036854775808', 'max' => '9223372036854775807']);
    });
});

it('sizes a select projection from its widest option key', function (): void {
    // An 80-character key is accepted by Rule::in() and preserved by
    // toStorage(), then truncated through VARCHAR(64) — so two options
    // sharing a prefix compare equal, and SQLite disagrees about which rows
    // match because it does not enforce declared widths.
    $long = str_repeat('k', 80);
    $type = app(FieldTypeRegistry::class)->get('select');

    expect($type->projection(configFor('select', ['options' => [$long => 'Long']]))->precision)->toBe(80)
        ->and($type->projection(configFor('select', ['options' => ['a' => 'A']]))->precision)->toBe(64);
});

it('publishes numeric select options as the strings they are stored as', function (): void {
    // ⚠️ PHP casts a numeric-string array key to an integer, so an option
    // `"1"` reached array_keys() as int 1 and was published as
    // `{"type": "string", "enum": [1]}` — a schema NO JSON value satisfies,
    // since "1" has the right type and is not equal to 1. Validation accepted
    // the choice and storage kept the string, so the field worked while its
    // own published contract called every value invalid.
    $config = configFor('select', ['options' => ['1' => 'One', '2' => 'Two']]);
    $type = app(FieldTypeRegistry::class)->get('select');

    expect($type->apiSchema($config)['enum'])->toBe(['1', '2'])
        ->and(validate('select', ['f' => '1'], ['options' => ['1' => 'One']])->fails())->toBeFalse();
});

describe('a finite cardinality bounds the array', function (): void {
    it('rejects more values than the field holds', function (): void {
        // -1 is the explicit "unlimited"; a cardinality of 2 means two, and
        // accepting three stored a shape the configuration forbids.
        $type = app(FieldTypeRegistry::class)->get('text');

        expect(Validator::make(['f' => ['a', 'b', 'c']], ['f' => $type->validationRules(configFor('text', [], 2))])->fails())
            ->toBeTrue();
    });

    it('accepts exactly as many as it holds', function (): void {
        $type = app(FieldTypeRegistry::class)->get('text');

        expect(Validator::make(['f' => ['a', 'b']], ['f' => $type->validationRules(configFor('text', [], 2))])->fails())
            ->toBeFalse();
    });

    it('leaves -1 unlimited', function (): void {
        $type = app(FieldTypeRegistry::class)->get('text');

        expect(Validator::make(['f' => range(1, 50)], ['f' => $type->validationRules(configFor('text', [], -1))])->fails())
            ->toBeFalse();
    });
});

describe('a json field holds an OBJECT, which is what it advertises', function (): void {
    it('rejects a list, which is valid JSON and the wrong shape', function (string $value): void {
        // A consumer reading `{"type": "object"}` assumes key/value data.
        expect(validate('json', ['f' => $value])->fails())->toBeTrue();
    })->with([
        'json list' => ['[1,2]'],
        'json scalar' => ['42'],
        'json string' => ['"hello"'],
    ]);

    it('rejects a PHP list too, not only the encoded form', function (): void {
        expect(validate('json', ['f' => [1, 2]])->fails())->toBeTrue();
    });

    it('still accepts an object in either form', function (): void {
        expect(validate('json', ['f' => ['a' => 1]])->fails())->toBeFalse()
            ->and(validate('json', ['f' => '{"a":1}'])->fails())->toBeFalse();
    });
});

describe('json objects, including the empty one', function (): void {
    it('accepts an empty object, which the schema explicitly allows', function (): void {
        // `{}` and `[]` both decode to an empty PHP array with assoc, and
        // array_is_list([]) is true — so the object the schema allows was
        // rejected. Decoding without assoc keeps the two distinct.
        expect(validate('json', ['f' => '{}'])->fails())->toBeFalse()
            ->and(validate('json', ['f' => []])->fails())->toBeFalse();
    });

    it('still rejects an empty LIST in its encoded form', function (): void {
        expect(validate('json', ['f' => '[]'])->fails())->toBeTrue();
    });

    it('REFUSES an object whose keys are a 0-based sequence', function (): void {
        // ⚠️ This asserted the opposite one round ago, and the opposite was
        // wrong. `{"0":"a","1":"b"}` is a valid object that decodes to the PHP
        // list ['a','b'] and re-encodes as ["a","b"] — accepting it meant the
        // value silently changed shape on save, and `Entry.values` casts to
        // array so nothing downstream can recover the distinction.
        //
        // Refusing with a reason beats accepting and mangling.
        expect(validate('json', ['f' => '{"0":"a","1":"b"}'])->fails())->toBeTrue();
    });
});

describe('relation honours a finite cardinality', function (): void {
    it('rejects more targets than the field holds', function (): void {
        // Overriding validationRules() bypassed the bound the base class
        // applies, so a relation limited to one target accepted five.
        $type = app(FieldTypeRegistry::class)->get('relation');

        expect(Validator::make(['f' => [1, 2]], ['f' => $type->validationRules(configFor('relation', [], 1))])->fails())
            ->toBeTrue();
    });

    it('accepts up to the limit', function (): void {
        $type = app(FieldTypeRegistry::class)->get('relation');

        expect(Validator::make(['f' => [1, 2]], ['f' => $type->validationRules(configFor('relation', [], 2))])->fails())
            ->toBeFalse();
    });

    it('leaves -1 unlimited', function (): void {
        $type = app(FieldTypeRegistry::class)->get('relation');

        expect(Validator::make(['f' => range(1, 30)], ['f' => $type->validationRules(configFor('relation', [], -1))])->fails())
            ->toBeFalse();
    });

    it('does not bound multi_select, whose cardinality means nothing', function (): void {
        // supportsCardinality() is false there, so the storage row's value
        // says nothing about how many options may be chosen.
        $type = app(FieldTypeRegistry::class)->get('multi_select');

        expect(Validator::make(['f' => ['a', 'b', 'c']], ['f' => $type->validationRules(configFor('multi_select', [], 1))])->fails())
            ->toBeFalse();
    });
});

/*
 * ⚠️ Presentation used to override storage — "the per-type view wins" — which
 * let an UNLOCKED `fields.settings` change what a LOCKED
 * `field_storage.settings` had already committed to. That routes around
 * ADR-006's lock-on-data invariant, so the precedence gave way, not the ADR.
 */
describe('presentation cannot change what is stored', function (): void {
    it('reads a storage-affecting setting from STORAGE, not the field', function (): void {
        // A per-type maxLength of 400 against storage that already created
        // idx_summary__string255 accepted 400 characters and truncated them
        // in the index.
        $storage = new FieldStorage([
            'handle' => 'summary', 'type' => 'text', 'cardinality' => 1,
            'pii_class' => 'none', 'settings' => ['maxLength' => 255],
        ]);
        $field = new Field(['label' => 'Summary', 'settings' => ['maxLength' => 400]]);

        $config = new FieldConfig($storage, $field);

        expect($config->setting('maxLength'))->toBe(255)
            ->and(app(FieldTypeRegistry::class)->get('text')->projection($config)->precision)->toBe(255);
    });

    it('will not let a per-type format change the conversion', function (): void {
        // 1.5 would become 1 under stored decimals.
        $storage = new FieldStorage([
            'handle' => 'price', 'type' => 'number', 'cardinality' => 1,
            'pii_class' => 'none', 'settings' => ['format' => 'decimal'],
        ]);
        $field = new Field(['label' => 'Price', 'settings' => ['format' => 'integer']]);

        expect(app(FieldTypeRegistry::class)->get('number')->toStorage('1.5', new FieldConfig($storage, $field)))
            ->toBe(1.5);
    });

    it('still lets presentation win where it is asked to explicitly', function (): void {
        // The escape hatch exists so a display-only setting is a deliberate
        // decision at the call site rather than a blanket precedence rule.
        $storage = new FieldStorage([
            'handle' => 'summary', 'type' => 'text', 'cardinality' => 1,
            'pii_class' => 'none', 'settings' => ['placeholder' => 'from storage'],
        ]);
        $field = new Field(['label' => 'Summary', 'settings' => ['placeholder' => 'from the field']]);

        expect((new FieldConfig($storage, $field))->presentationSetting('placeholder'))->toBe('from the field');
    });
});

it('refuses a JSON object whose keys are a 0-based sequence', function (): void {
    // `{"0":"a","1":"b"}` is a valid object that decodes to the PHP list
    // ['a','b'] and re-encodes as ["a","b"] — the value changes shape on a
    // round trip, and Entry.values casts to array so nothing later can
    // recover the distinction.
    $v = validate('json', ['f' => '{"0":"a","1":"b"}']);

    expect($v->fails())->toBeTrue()
        ->and($v->errors()->first('f'))->toContain('0-based sequence');
});

it('still accepts an object with non-sequential numeric keys', function (): void {
    expect(validate('json', ['f' => '{"1":"a","5":"b"}'])->fails())->toBeFalse();
});

it('refuses an ambiguous object NESTED inside a valid one', function (): void {
    // toStorage() decodes recursively, so checking the outer object let
    // {"config":{"0":"a","1":"b"}} through and the child collapsed to
    // ["a","b"] on save — the same defect one level down, where it is harder
    // to notice.
    $v = validate('json', ['f' => '{"config":{"0":"a","1":"b"}}']);

    expect($v->fails())->toBeTrue()
        ->and($v->errors()->first('f'))->toContain('[config]');
});

it('still accepts a legitimate nested ARRAY', function (): void {
    // ⚠️ The distinction only survives before decoding: after `assoc: true` a
    // nested {"0":"a"} and a nested ["a"] are the same PHP value. A first
    // attempt at the recursion rejected ordinary lists for exactly that
    // reason, so the walk runs over the structure decoded WITHOUT assoc.
    expect(validate('json', ['f' => '{"tags":["a","b"],"nested":{"deep":{"x":1}}}'])->fails())->toBeFalse();
});

it('finds an ambiguous object inside an array', function (): void {
    expect(validate('json', ['f' => '{"items":[{"0":"a","1":"b"}]}'])->fails())->toBeTrue();
});

it('refuses a NESTED empty object, which cannot survive decoding', function (): void {
    /*
     * ⚠️ Different from the top level, and the reason is worth stating: the
     * field's contract says the WHOLE value is an object, so castFromStorage
     * restores a top-level `[]` to `{}` unambiguously. Nested there is no
     * such contract — `{"config":{}}` decodes to `['config' => []]` and
     * re-encodes as `{"config":[]}`, with nothing able to tell it from a
     * genuine empty array.
     */
    $v = validate('json', ['f' => '{"config":{}}']);

    expect($v->fails())->toBeTrue()
        ->and($v->errors()->first('f'))->toContain('[config]');
});

it('still accepts the TOP-LEVEL empty object', function (): void {
    // The asymmetry is deliberate, so a later tidy-up does not "fix" it.
    expect(validate('json', ['f' => '{}'])->fails())->toBeFalse();
});

describe('the same refusal applies to input that arrives already decoded', function (): void {
    /*
     * ⚠️ The string checks above covered ONE of the two ways a value reaches
     * this field. A request body Laravel parsed — the normal API path —
     * arrives as a PHP array, where `{"config":{}}` is already
     * `['config' => []]`. Nothing walked it, and castToStorage() preserved
     * it, so the identical value was rejected with a clear message when sent
     * as a string and silently stored as `{"config":[]}` when sent as JSON.
     */
    it('refuses a nested empty, whichever way the client sent it', function (): void {
        $v = validate('json', ['f' => ['config' => []]]);

        expect($v->fails())->toBeTrue()
            ->and($v->errors()->first('f'))->toContain('[config]');
    });

    it('refuses one buried several levels down', function (): void {
        expect(validate('json', ['f' => ['a' => ['b' => ['c' => []]]]])->fails())->toBeTrue();
    });

    it('still accepts the top-level empty, matching the string path', function (): void {
        expect(validate('json', ['f' => []])->fails())->toBeFalse();
    });

    it('leaves populated nested structures alone', function (): void {
        expect(validate('json', ['f' => ['list' => ['a', 'b'], 'map' => ['k' => 'v']]])->fails())
            ->toBeFalse();
    });
});
