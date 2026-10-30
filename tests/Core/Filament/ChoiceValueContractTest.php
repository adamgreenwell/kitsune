<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Filament\Schemas\FieldValueRenderer;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Livewire\Component;

beforeEach(function (): void {
    $org = Org::create(['name' => 'Choices', 'slug' => 'choices']);
    app(Context::class)->setOrg($org);
    $site = Site::create(['org_id' => $org->id, 'handle' => 'choices', 'slug' => 'choices', 'name' => 'Choices']);
    app(Context::class)->setSite($site);
    $this->choiceType = EntryType::create(['org_id' => $org->id, 'handle' => 'choice', 'name' => 'Choice', 'plural_name' => 'Choices']);
});

afterEach(fn () => app(Context::class)->forget());

function choiceConfig(string $kind, array $options, bool $required = false): FieldConfig
{
    $storage = FieldStorage::create([
        'handle' => 'choice_value', 'type' => $kind, 'pii_class' => 'none', 'cardinality' => 1,
        'settings' => ['options' => $options],
    ]);

    return new FieldConfig($storage, Field::create([
        'entry_type_id' => EntryType::where('handle', 'choice')->firstOrFail()->id,
        'field_storage_id' => $storage->id, 'label' => 'Choice value', 'is_required' => $required,
        'help_text' => 'Original field help.',
    ]));
}

/** A real Filament form, with the stored record available to its component callbacks. */
function choiceForm(FieldConfig $config, Entry $entry): array
{
    $host = new class extends Component implements HasActions, HasSchemas
    {
        use InteractsWithActions;
        use InteractsWithSchemas;

        public ?array $data = [];

        public function render(): string
        {
            return '<div></div>';
        }
    };

    $component = FieldValueRenderer::formComponent($config);
    $form = Schema::make($host)->statePath('data')->record($entry)->components([$component]);
    $form->fill($entry->attributesToArray());

    return [$host, $form, $component];
}

dataset('choice option maps', [
    'numeric' => [[1 => 'One', 2 => 'Two'], ['1', '2'], '2'],
    'zero based' => [[0 => 'Zero', 1 => 'One'], ['0', '1'], '1'],
    'text' => [['alpha' => 'Alpha', 'beta' => 'Beta'], ['alpha', 'beta'], 'beta'],
    'bare list retains its keys' => [['First', 'Second'], ['0', '1'], '1'],
    'a label is another key' => [[1 => '2', 2 => 'Two'], ['1', '2'], '2'],
]);

it('keeps rendered values, the schema and a form storage round trip on configured keys', function (string $kind, array $options, array $keys, string $key): void {
    $config = choiceConfig($kind, $options);
    $value = $kind === 'select' ? $key : [$key];
    $entry = Entry::create(['entry_type_id' => $this->choiceType->id, 'title' => 'Choice', 'slug' => 'choice', 'values' => ['choice_value' => $value]]);
    [$host, $form, $component] = choiceForm($config, $entry);
    expect($component)->toBeInstanceOf(Select::class);
    expect(array_map(strval(...), array_keys($component->getOptions())))->toBe($keys);
    $schema = app(FieldTypeRegistry::class)->get($kind)->apiSchema($config);
    expect($kind === 'select' ? $schema['enum'] : $schema['items']['enum'])->toBe($keys);
    $entry->update(['values' => $form->getState()['values']]);
    expect($entry->fresh()->values['choice_value'])->toBe($value);
    [, $reopened] = choiceForm($config, $entry->fresh());
    // Filament may dehydrate numeric option keys as integers; the model's actual
    // storage conversion must still publish and persist the string contract.
    $entry->update(['values' => $reopened->getState()['values']]);
    expect($entry->fresh()->values['choice_value'])->toBe($value);
})->with(['select', 'multi_select'])->with('choice option maps');

it('requires explicit reselection for a legacy label and preserves the original on refusal', function (string $kind, mixed $attempt): void {
    $config = choiceConfig($kind, [1 => 'One', 2 => 'Two']);
    $legacy = $kind === 'select' ? 'Two' : ['Two'];
    $entry = Entry::create(['entry_type_id' => $this->choiceType->id, 'title' => 'Legacy', 'slug' => 'legacy', 'values' => ['choice_value' => $legacy]]);
    [$host, $form, $component] = choiceForm($config, $entry);
    $help = $component->getChildSchema(Select::BELOW_CONTENT_SCHEMA_KEY)->getComponents()[0];
    expect((string) $help->getContent())->toContain('Two', 'select', 'Original field help.');
    $host->data['values']['choice_value'] = match ($attempt) {
        'unchanged' => $legacy,
        'invalid' => $kind === 'select' ? 'not-configured' : ['not-configured'],
        default => $kind === 'select' ? null : [],
    };
    try {
        $form->getState();
        test()->fail('An unresolved stored value must require explicit reselection.');
    } catch (ValidationException $exception) {
        expect(Arr::flatten($exception->errors()))
            ->toContain(__('kitsune::fields.choice.reselect'));
    }
    expect($entry->fresh()->values['choice_value'])->toBe($legacy);

    $host->data['values']['choice_value'] = $kind === 'select' ? '2' : ['2'];
    $entry->update(['values' => $form->getState()['values']]);
    expect($entry->fresh()->values['choice_value'])->toBe($kind === 'select' ? '2' : ['2']);
})->with(['select', 'multi_select'])->with(['unchanged', 'invalid', 'empty']);

it('keeps optional empty values valid and required or unavailable values refused', function (string $kind, bool $required): void {
    $config = choiceConfig($kind, [0 => 'Zero', 1 => 'One'], $required);
    $entry = Entry::create(['entry_type_id' => $this->choiceType->id, 'title' => 'Empty', 'slug' => 'empty']);
    [$host, $form] = choiceForm($config, $entry);
    $host->data['values']['choice_value'] = $kind === 'select' ? null : [];
    if ($required) {
        expect(fn () => $form->getState())->toThrow(ValidationException::class);
    } else {
        expect($form->getState()['values']['choice_value'])->toBe($kind === 'select' ? null : []);
    }
    $host->data['values']['choice_value'] = $kind === 'select' ? 'missing' : ['0', 'missing'];
    expect(fn () => $form->getState())->toThrow(ValidationException::class);
})->with(['select', 'multi_select'])->with([false, true]);

it('escapes untrusted legacy values in the reselection notice', function (string $kind): void {
    $config = choiceConfig($kind, [0 => 'Zero']);
    $value = '<script>alert(1)</script>';
    $entry = Entry::create(['entry_type_id' => $this->choiceType->id, 'title' => 'Untrusted', 'slug' => 'untrusted', 'values' => ['choice_value' => $kind === 'select' ? $value : [$value]]]);
    [, , $component] = choiceForm($config, $entry);
    $help = $component->getChildSchema(Select::BELOW_CONTENT_SCHEMA_KEY)->getComponents()[0];
    expect($help->toHtml())->toContain('&lt;script&gt;')->not->toContain('<script>');
})->with(['select', 'multi_select']);
