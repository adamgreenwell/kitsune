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
use Kitsune\Core\Fields\Control;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Fields\Pattern;
use Kitsune\Core\Fields\Types\BaseFieldType;
use Kitsune\Core\Fields\Types\TextType;
use Kitsune\Core\Filament\Schemas\SettingsSchemaRenderer;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

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

        // ⚠️ Required because `control()` has no default on `BaseFieldType`,
        // deliberately: a field type that inherits a control kind inherits a
        // text direction nobody chose for it (ADR-029). A double is a field
        // type and answers like one.
        public function control(): Control
        {
            return Control::Line;
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

        // ⚠️ Required because `control()` has no default on `BaseFieldType`,
        // deliberately: a field type that inherits a control kind inherits a
        // text direction nobody chose for it (ADR-029). A double is a field
        // type and answers like one.
        public function control(): Control
        {
            return Control::Line;
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

describe('a descriptor whose choices are not knowable at declaration time', function (): void {
    /*
     * ⚠️ `relation`'s permitted targets are the ORG's entry types, which core
     * cannot know when a field type declares its settings. The descriptor had
     * no `options` key, so the control rendered with an EMPTY list — and since
     * an empty `targetTypes` means unrestricted, the documented constraint
     * could not be configured through the builder at all. An empty choice list
     * reads as "no constraint available" rather than as a bug.
     */
    beforeEach(function (): void {
        $this->org = Org::create(['name' => 'R', 'slug' => 'renderer-org']);
        app(Context::class)->setOrg($this->org);
        $this->site = Site::create(['org_id' => $this->org->id, 'handle' => 's', 'slug' => 'renderer-s', 'name' => 'S']);
        app(Context::class)->setSite($this->site);
    });

    afterEach(fn () => app(Context::class)->forget());

    it('populates relation target types from the org\'s own types', function (): void {
        EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
        EntryType::create(['org_id' => $this->org->id, 'handle' => 'person', 'name' => 'Person', 'plural_name' => 'People']);

        $components = SettingsSchemaRenderer::for(
            app(FieldTypeRegistry::class)->get('relation'),
            'storage_settings',
        );

        $select = collect($components)->first(
            fn ($component): bool => str_ends_with($component->getName(), 'targetTypes'),
        );

        expect($select)->toBeInstanceOf(Select::class)
            ->and($select->getOptions())->toBe(['article' => 'Article', 'person' => 'Person']);
    });

    it('includes a GLOBAL type, which a relation may legitimately point at', function (): void {
        EntryType::create(['org_id' => null, 'handle' => 'system_media', 'name' => 'Media', 'plural_name' => 'Media']);

        $components = SettingsSchemaRenderer::for(
            app(FieldTypeRegistry::class)->get('relation'),
            'storage_settings',
        );

        $select = collect($components)->first(
            fn ($component): bool => str_ends_with($component->getName(), 'targetTypes'),
        );

        expect($select->getOptions())->toHaveKey('system_media');
    });

    it('leaves out ANOTHER org\'s types', function (): void {
        $rival = Org::create(['name' => 'V', 'slug' => 'renderer-rival']);
        EntryType::create(['org_id' => $rival->id, 'handle' => 'theirs', 'name' => 'Theirs', 'plural_name' => 'Theirs']);

        $components = SettingsSchemaRenderer::for(
            app(FieldTypeRegistry::class)->get('relation'),
            'storage_settings',
        );

        $select = collect($components)->first(
            fn ($component): bool => str_ends_with($component->getName(), 'targetTypes'),
        );

        expect($select->getOptions())->not->toHaveKey('theirs');
    });

    it('fails closed on an unknown options source', function (): void {
        // Same posture as an unknown descriptor type: rendering an empty list
        // would silently prevent the setting being configured.
        $type = new class extends BaseFieldType
        {
            public static function handle(): string
            {
                return 'bad_source';
            }

            // ⚠️ Required because `control()` has no default on `BaseFieldType`,
            // deliberately: a field type that inherits a control kind inherits a
            // text direction nobody chose for it (ADR-029). A double is a field
            // type and answers like one.
            public function control(): Control
            {
                return Control::Line;
            }

            public static function label(): string
            {
                return 'Bad source';
            }

            public function settingsSchema(): array
            {
                return ['thing' => ['type' => 'multiSelect', 'label' => 'Thing', 'optionsFrom' => 'nowhere']];
            }
        };

        expect(fn () => SettingsSchemaRenderer::for($type, 'storage_settings'))
            ->toThrow(RuntimeException::class, 'Unknown options source');
    });
});

it('applies a published maxLength to the rendered input', function (): void {
    /*
     * ⚠️ THE ONE PUBLISHED KEY THIS RENDERER IGNORED (issue #48). `TextType` declares
     * `maxLength => Pattern::MAX_LENGTH` on its `pattern` descriptor with the comment
     * "published because it is enforced", and the form imposed no limit — so an author
     * discovered the bound only when the save was refused.
     */
    $components = SettingsSchemaRenderer::for(new TextType);
    $pattern = null;

    foreach ($components as $component) {
        if (str_ends_with((string) $component->getName(), 'pattern')) {
            $pattern = $component;
        }
    }

    expect($pattern)->not->toBeNull('the pattern descriptor rendered no component')
        ->and($pattern->getMaxLength())->toBe(Pattern::MAX_LENGTH);
});

it('still refuses an over-long pattern on the server', function (): void {
    /*
     * ⚠️ THE HALF THAT MATTERS, asserted alongside the attribute so nobody later
     * "simplifies" by keeping only one. `maxlength` is an HTML attribute a client can
     * ignore (invariant 6), so it is a courtesy to the author and never the enforcement.
     * The server refuses through `Pattern::lengthRefusal()`, consulted by
     * `unpublishable()`, `delimit()` and `validateSettings()`.
     */
    expect((new TextType)->validateSettings(['pattern' => str_repeat('a', Pattern::MAX_LENGTH + 1)]))
        ->toContain('the limit is '.Pattern::MAX_LENGTH);
});

it('refuses a maxLength on a descriptor that cannot express one', function (): void {
    /*
     * ⚠️ Fails closed, matching this class's posture on an unknown descriptor type.
     * Silently dropping the key is how a published constraint becomes a lie — which is
     * the defect this whole change fixes, so accepting it here would reintroduce it one
     * level down.
     */
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

        public function control(): Control
        {
            return Control::Line;
        }

        public function settingsSchema(): array
        {
            return ['enabled' => ['type' => 'boolean', 'default' => false, 'maxLength' => 10]];
        }
    };

    expect(fn () => SettingsSchemaRenderer::for($type))
        ->toThrow(RuntimeException::class, 'cannot express a string length');
});

it('refuses a maxLength on a NUMERIC descriptor, which renders the same component as a string', function (): void {
    /*
     * ⚠️ THE CASE AN `instanceof` CHECK CANNOT SEE, and the reason this guard asks the
     * descriptor rather than the component. `integer` and `number` both render a
     * `TextInput`, exactly as `string` does — so a class check accepts them and applies
     * `maxLength()` to a numeric control, where a browser ignores the `maxlength` attribute
     * and numeric validation reads a maximum as a VALUE bound rather than a digit count.
     *
     * The form would then impose a DIFFERENT constraint from the published one, which is
     * worse than imposing none: nothing on screen reveals it and the type that published it
     * cannot tell either. Found by review on #48.
     */
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

        public function control(): Control
        {
            return Control::Line;
        }

        public function settingsSchema(): array
        {
            return ['digits' => ['type' => 'integer', 'default' => 1, 'maxLength' => 4]];
        }
    };

    expect(fn () => SettingsSchemaRenderer::for($type))
        ->toThrow(RuntimeException::class, 'cannot express a string length');
});
