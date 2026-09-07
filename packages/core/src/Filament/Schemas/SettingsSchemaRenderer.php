<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Str;
use Kitsune\Core\Fields\FieldType;
use RuntimeException;

/**
 * Turns a field type's `settingsSchema()` data into Filament components.
 *
 * This class is why `settingsSchema()` returns an array instead of Filament
 * components. ADR-002 keeps core headless-capable: a field type that built
 * `TextInput::make(...)` would make `kitsune/core` depend on a panel, and the
 * API and the CLI would have nothing to render. The description is data; the
 * admin is one renderer of it, and a headless client can be another.
 *
 * It fails closed on an unknown descriptor. Silently skipping one would
 * produce a settings form missing a control, and the field would then be
 * saved with that setting absent — configuration lost without an error.
 */
final class SettingsSchemaRenderer
{
    /**
     * @param  string  $statePath  where the values live, e.g. `settings`
     * @return array<int, mixed>
     */
    public static function for(FieldType $type, string $statePath = 'settings'): array
    {
        $components = [];

        foreach ($type->settingsSchema() as $key => $descriptor) {
            $components[] = self::component($statePath.'.'.$key, $key, $descriptor);
        }

        return $components;
    }

    /**
     * @param  array<string, mixed>  $descriptor
     */
    private static function component(string $path, string $key, array $descriptor): mixed
    {
        $label = $descriptor['label'] ?? Str::headline($key);
        $default = $descriptor['default'] ?? null;
        $nullable = (bool) ($descriptor['nullable'] ?? false);

        $component = match ($descriptor['type'] ?? null) {
            'integer' => TextInput::make($path)->integer(),
            'number' => TextInput::make($path)->numeric(),
            'string' => TextInput::make($path),
            'boolean' => Toggle::make($path),
            'enum' => Select::make($path)->options(
                self::options((array) ($descriptor['options'] ?? []))
            ),
            'multiSelect' => Select::make($path)->multiple()->options(
                self::options((array) ($descriptor['options'] ?? []))
            ),
            'keyValue' => KeyValue::make($path)
                ->keyLabel('Stored value')
                ->valueLabel('Shown to the author'),
            default => throw new RuntimeException(sprintf(
                'Unknown setting descriptor [%s] for [%s]. The renderer fails closed rather than '
                .'skipping it: a missing control saves the field with that setting absent, which '
                .'loses configuration without an error.',
                is_string($descriptor['type'] ?? null) ? $descriptor['type'] : get_debug_type($descriptor['type'] ?? null),
                $key,
            )),
        };

        $component = $component->label($label);

        if ($default !== null) {
            $component = $component->default($default);
        }

        if (! $nullable && ! in_array($descriptor['type'], ['keyValue', 'multiSelect', 'boolean'], true)) {
            $component = $component->required();
        }

        if (isset($descriptor['help'])) {
            $component = $component->helperText($descriptor['help']);
        }

        return $component;
    }

    /**
     * The declared defaults, as a state array to seed the form with.
     *
     * ⚠️ Needed because this section is rebuilt REACTIVELY when the field
     * type changes, and a component inserted after the form was initialised
     * never applies its own `default()`. Two wrong answers were tried first:
     * leaving it empty made a required setting unsatisfiable, and suppressing
     * the placeholder made the browser submit the FIRST option — which for
     * `number` meant a field declared `decimal` silently saving as `integer`
     * and truncating every value.
     *
     * Seeding the state is the version that is actually correct: the declared
     * default is what the form starts with, whatever the option order.
     *
     * @return array<string, mixed>
     */
    public static function defaultsFor(FieldType $type): array
    {
        $defaults = [];

        foreach ($type->settingsSchema() as $key => $descriptor) {
            $defaults[$key] = $descriptor['default'] ?? null;
        }

        return $defaults;
    }

    /**
     * A list becomes value => Headline; a map is already labelled.
     *
     * @param  array<int|string, string>  $options
     * @return array<string, string>
     */
    private static function options(array $options): array
    {
        if ($options === []) {
            return [];
        }

        return array_is_list($options)
            ? array_combine($options, array_map(Str::headline(...), $options))
            : $options;
    }
}
