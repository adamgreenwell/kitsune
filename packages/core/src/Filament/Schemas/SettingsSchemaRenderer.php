<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Schemas;

use Closure;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Str;
use Kitsune\Core\Fields\FieldType;
use Kitsune\Core\Fields\Pattern;
use Kitsune\Core\Models\EntryType;
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
                self::choices($descriptor, $key)
            ),
            'multiSelect' => Select::make($path)->multiple()->options(
                self::choices($descriptor, $key)
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

        // ⚠️ A declared FORMAT is a constraint, so it is enforced here as well
        // as published.
        //
        // A `regex` setting that cannot compile made the field unusable rather
        // than merely misconfigured: `TextType::patternRule()` refuses every
        // value when the pattern will not compile — correctly, since an
        // uncheckable constraint must not pass — so nothing could be stored in
        // the field until an author went back and repaired its settings. The
        // rule failing closed was right; accepting the setting was not.
        //
        // `FieldStorage` enforces the same declaration on save, because a form
        // is one door (invariant 6's lesson, applied to settings).
        if (($descriptor['format'] ?? null) === 'regex') {
            $component = $component->rule(
                static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && $value !== '' && ! Pattern::compiles($value)) {
                        $fail('That pattern cannot be compiled, so nothing could ever be saved in this field.');
                    }
                },
            );
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
    /**
     * A descriptor's choices, static or resolved at render time.
     *
     * ⚠️ `optionsFrom` exists because some option lists are not knowable when
     * a field type declares its settings — a relation's permitted targets are
     * the ORG's entry types. Rendering that control from an absent `options`
     * key produced an empty list, and since an empty `targetTypes` means
     * unrestricted, the constraint could not be configured at all.
     *
     * A NAME rather than a closure, because `settingsSchema()` returns data so
     * that core stays usable headless (ADR-002). A closure would tie the
     * declaration to Filament.
     *
     * @param  array<string, mixed>  $descriptor
     * @return array<string, string>
     */
    private static function choices(array $descriptor, string $key): array
    {
        $source = $descriptor['optionsFrom'] ?? null;

        if ($source === null) {
            return self::options((array) ($descriptor['options'] ?? []));
        }

        return match ($source) {
            // Visible to this org, which includes the global system types a
            // relation may legitimately point at.
            'entryTypes' => EntryType::query()
                ->availableToCurrentOrg()
                ->orderBy('name')
                ->pluck('name', 'handle')
                ->all(),
            default => throw new RuntimeException(sprintf(
                'Unknown options source [%s] for [%s]. Fails closed rather than rendering an empty '
                .'list: an empty choice list reads as "no constraint available" and silently '
                .'prevents the setting being configured.',
                is_string($source) ? $source : get_debug_type($source),
                $key,
            )),
        };
    }

    /**
     * @param  array<int|string, mixed>  $options
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
