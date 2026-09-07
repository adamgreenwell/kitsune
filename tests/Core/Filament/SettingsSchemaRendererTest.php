<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Fields\Types\BaseFieldType;
use Kitsune\Core\Filament\Schemas\SettingsSchemaRenderer;

/*
 * The seam that lets a field type describe its settings as DATA while the
 * admin renders them (ADR-002). A type that built Filament components would
 * make kitsune/core depend on a panel, and the API and CLI would have nothing
 * to render — so this renderer is one consumer of that description, and a
 * headless client can be another.
 *
 * It also means adding a field type requires no admin code at all, which is
 * the property these tests exist to protect.
 */

it('renders a control for every setting each registered type declares', function (string $handle): void {
    $type = app(FieldTypeRegistry::class)->get($handle);

    // Fails closed on an unknown descriptor, so reaching the end at all is
    // the assertion: every descriptor in the registry has a renderer.
    expect(SettingsSchemaRenderer::for($type))->toHaveCount(count($type->settingsSchema()));
})->with(['text', 'textarea', 'rich_text', 'number', 'boolean', 'date', 'datetime', 'select', 'multi_select', 'relation', 'slug', 'json']);

it('maps each descriptor to the component that fits it', function (string $descriptorType, string $expected): void {
    $type = new class($descriptorType) extends BaseFieldType
    {
        public function __construct(private readonly string $descriptorType) {}

        public static function handle(): string
        {
            return 'probe';
        }

        public static function label(): string
        {
            return 'Probe';
        }

        public function settingsSchema(): array
        {
            return ['thing' => ['type' => $this->descriptorType, 'options' => ['a', 'b']]];
        }
    };

    expect(SettingsSchemaRenderer::for($type)[0])->toBeInstanceOf($expected);
})->with([
    'integer' => ['integer', TextInput::class],
    'number' => ['number', TextInput::class],
    'string' => ['string', TextInput::class],
    'boolean' => ['boolean', Toggle::class],
    'enum' => ['enum', Select::class],
    'multiSelect' => ['multiSelect', Select::class],
    'keyValue' => ['keyValue', KeyValue::class],
]);

it('fails closed on a descriptor it does not know', function (): void {
    // Skipping it silently would render a settings form missing a control,
    // and the field would then save with that setting absent — configuration
    // lost without an error.
    $type = new class extends BaseFieldType
    {
        public static function handle(): string
        {
            return 'probe';
        }

        public static function label(): string
        {
            return 'Probe';
        }

        public function settingsSchema(): array
        {
            return ['thing' => ['type' => 'holographic']];
        }
    };

    expect(fn () => SettingsSchemaRenderer::for($type))
        ->toThrow(RuntimeException::class, 'Unknown setting descriptor');
});

it('reports the declared defaults, which the form has to seed itself', function (): void {
    // ⚠️ The settings section is rebuilt reactively when the field type
    // changes, and a component inserted after the form was initialised never
    // applies its own default(). Two wrong answers came first: an empty
    // required control that could not be satisfied, and suppressing the
    // placeholder — which made the browser submit the FIRST option, so a
    // number field declared `decimal` saved as `integer` and truncated.
    $defaults = SettingsSchemaRenderer::defaultsFor(app(FieldTypeRegistry::class)->get('number'));

    expect($defaults['format'])->toBe('decimal')
        ->and($defaults['precision'])->toBe(12)
        ->and($defaults['scale'])->toBe(2)
        ->and($defaults['min'])->toBeNull();
});

it('writes settings under the state path the form uses', function (): void {
    // Prefixed so the storage record's settings can share one form with the
    // presentation record without colliding.
    $components = SettingsSchemaRenderer::for(app(FieldTypeRegistry::class)->get('text'), 'storage_settings');

    expect($components[0]->getName())->toBe('storage_settings.maxLength');
});

it('labels a bare option list without the type having to spell it out', function (): void {
    $format = collect(SettingsSchemaRenderer::for(app(FieldTypeRegistry::class)->get('number')))
        ->first(fn ($component): bool => $component->getName() === 'settings.format');

    expect($format)->not->toBeNull()
        ->and($format->getOptions())->toBe(['integer' => 'Integer', 'decimal' => 'Decimal']);
});
