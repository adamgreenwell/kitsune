<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Forms\Components\Repeater;
use Kitsune\Core\Fields\Control;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Fields\ValueDirection;
use Kitsune\Core\Filament\Schemas\FieldValueRenderer;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Context;

/**
 * Every field control carries the direction its `Control` says it should.
 *
 * ⚠️ THIS TEST IS THE GUARANTEE, and the class docblock in `FieldValueRenderer` says so
 * rather than claiming a structural impossibility it cannot deliver. Filament exposes
 * `extraInputAttributes()` through a trait with no interface, so the renderer discovers
 * the attachment point with `instanceof` — and no type system can prove that chain stayed
 * in step with the vocabulary. What can be proved is the outcome, for every case, which
 * is what this does.
 *
 * ⚠️ ITERATED OVER `Control::cases()`, NOT OVER A LIST. A hand-written list of controls is
 * the thing that goes stale when a thirteenth is added — the exact failure #39 shipped
 * twice, once missing two of three table columns. `cases()` cannot be short.
 */
beforeEach(function (): void {
    $this->org = Org::create(['name' => 'Golfdom', 'slug' => 'golfdom']);
    app(Context::class)->setOrg($this->org);
});

afterEach(fn () => app(Context::class)->forget());

/** A field storage of the type that names the given control, or null when none does. */
function storageNaming(Control $control, Org $org, int $cardinality = 1): ?FieldStorage
{
    foreach (app(FieldTypeRegistry::class)->all() as $type) {
        if ($type->control() !== $control) {
            continue;
        }

        return FieldStorage::create([
            'org_id' => $org->id,
            // ⚠️ Hyphens out: ADR-028 constrains field handles to snake_case, and
            // `Control::RichText->value` is `rich-text`. The guard refused the first
            // version of this helper, correctly.
            'handle' => 'probe_'.str_replace('-', '_', $control->value).'_'.$cardinality,
            'type' => $type::handle(),
            'pii_class' => 'none',
            'cardinality' => $type->supportsCardinality() ? $cardinality : 1,
        ]);
    }

    return null;
}

/** The `dir` this component carries, from wherever it carries it. */
function attachedDirection(mixed $component): ?string
{
    foreach (['getExtraInputAttributes', 'getExtraAttributes'] as $getter) {
        if (! method_exists($component, $getter)) {
            continue;
        }

        $attributes = $component->{$getter}();

        if (is_array($attributes) && isset($attributes['dir'])) {
            return (string) $attributes['dir'];
        }
    }

    return null;
}

it('gives every control the direction its vocabulary specifies', function (): void {
    foreach (Control::cases() as $control) {
        $storage = storageNaming($control, $this->org);

        // Every control in the vocabulary is claimed by a shipped type today. Asserted
        // rather than skipped: an unclaimed control would be a case this test silently
        // stopped covering.
        expect($storage)->not->toBeNull("no field type names {$control->value}");

        $component = FieldValueRenderer::formComponent(new FieldConfig($storage));
        $dir = attachedDirection($component);

        if ($control->direction() === ValueDirection::Auto) {
            expect($dir)->toBe('auto', "[{$control->value}] should carry dir=auto and carries ".var_export($dir, true));
        } else {
            // ⚠️ The other half, and the reason this is not "put dir=auto everywhere".
            // A `Neutral` value has no direction to resolve, and `PerBlock` would be made
            // WORSE by a wrapper attribute: it would resolve once from the first block and
            // impose that on the rest, while looking handled.
            expect($dir)->toBeNull("[{$control->value}] is {$control->direction()->value} and must carry no dir, but carries ".var_export($dir, true));
        }
    }
});

it('puts the direction on the INNER control of a multi-value field', function (): void {
    /*
     * ⚠️ A `Repeater` has no input hook, so direction attached to the repeater would land
     * on its wrapper and every row would inherit the first row's answer — the same defect
     * as `KeyValue`, but avoidable here because the inner control does have an input.
     *
     * So the assertion is that the repeater itself carries NO `dir` and its inner control
     * does. Asserting only "the form has dir somewhere" would pass on the broken version.
     */
    $storage = storageNaming(Control::Line, $this->org, cardinality: 3);

    expect($storage)->not->toBeNull();

    $component = FieldValueRenderer::formComponent(new FieldConfig($storage));

    expect($component)->toBeInstanceOf(Repeater::class)
        ->and(attachedDirection($component))->toBeNull('the repeater wrapper must not carry dir');

    $inner = $component->getSimpleField();

    expect($inner)->not->toBeNull('the repeater has no simple inner field')
        ->and(attachedDirection($inner))->toBe('auto');
});

it('does not nest the inner control state path inside the field path', function (): void {
    /*
     * ⚠️ MEASURED, and it is the failure that makes a multi-value field silently stop
     * saving. `Repeater::simple()` expects the inner control built at the repeater's own
     * path; handing it one made at `values.summary` nests state at
     * `values.summary.*.values.summary`, which nothing reads — so the field appears to
     * save and the value is gone on reload. No error anywhere.
     */
    $storage = storageNaming(Control::Line, $this->org, cardinality: 3);
    $component = FieldValueRenderer::formComponent(new FieldConfig($storage));

    expect($component->getName())->toBe('values.'.$storage->handle)
        ->and($component->getSimpleField()->getName())->not->toContain('values.');
});

it('lists a value only when its cell kind is listed', function (): void {
    foreach (Control::cases() as $control) {
        $storage = storageNaming($control, $this->org);
        $column = FieldValueRenderer::tableColumn(new FieldConfig($storage));

        if ($control->cell()->isListed()) {
            expect($column)->not->toBeNull("[{$control->value}] should be listed");

            $expected = $control->cell()->direction() === ValueDirection::Auto ? 'auto' : null;

            expect($column->getExtraAttributes()['dir'] ?? null)
                ->toBe($expected, "[{$control->value}] column direction");
        } else {
            // Null rather than a hidden column: a truncated document costs a query and
            // tells the reader nothing.
            expect($column)->toBeNull("[{$control->value}] should not be listed");
        }
    }
});

it('places a promoted value on its column and everything else in values', function (): void {
    /*
     * ⚠️ Getting the state path wrong does not error — it writes to a path nothing reads,
     * so the field appears to save and the value is gone on reload. `slug` is the only
     * promoted type among the twelve, so it is the whole of the positive case.
     */
    $slug = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'permalink', 'type' => 'slug',
        'pii_class' => 'none', 'cardinality' => 1,
    ]);
    $text = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'summary', 'type' => 'text',
        'pii_class' => 'none', 'cardinality' => 1,
    ]);

    expect(FieldValueRenderer::formComponent(new FieldConfig($slug))->getName())->toBe('slug')
        ->and(FieldValueRenderer::formComponent(new FieldConfig($text))->getName())->toBe('values.summary');
});
