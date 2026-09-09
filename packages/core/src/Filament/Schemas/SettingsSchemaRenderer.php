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
use Kitsune\Core\Models\EntryType;
use RuntimeException;

/**
 * Turns a field type's `settingsSchema()` data into Filament components.
 *
 * This class is why `settingsSchema()` returns an array instead of Filament
 * components: a field type describes a control and the panel builds it (ADR-029).
 *
 * ⚠️ THIS DOCBLOCK USED TO GIVE A REASON THAT WAS FALSE, and it was cited from
 * seven other places. It said ADR-002 kept core headless-capable so a type
 * building `TextInput::make(...)` "would make kitsune/core depend on a panel" —
 * but core hard-requires `filament/filament ^5.4` (ADR-008), so it already does,
 * and ADR-002 is a five-line delivery decision that says nothing about return
 * types. The rule was right and had never been decided; ADR-029 decides it.
 *
 * The reason that actually holds is EXHAUSTIVENESS. Because every control passes
 * through this renderer, a cross-cutting presentation concern has exactly one
 * place to live — and a thirteenth field type cannot omit it, because it never
 * gets to decide. Twelve types each returning a finished component is twelve
 * chances to forget, which is the reach failure `dir="auto"` already hit twice.
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
     * Applies a descriptor's `maxLength`, and refuses to drop one it cannot apply.
     *
     * ⚠️ IT WAS THE ONE PUBLISHED KEY THIS RENDERER IGNORED (issue #48). `TextType`
     * declares `maxLength => Pattern::MAX_LENGTH` on its `pattern` descriptor with the
     * comment "published because it is enforced", and the form imposed no limit at all —
     * so an author discovered the bound only when the save was refused.
     *
     * ⚠️ Established by ENUMERATION, not by fixing what was reported. Every key the twelve
     * types publish was compared against every key this method consumes: `default`, `help`,
     * `label`, `nullable`, `options`, `optionsFrom` and `type` are all read, and `maxLength`
     * was the only one that was not. The issue also asked about `min`, `max` and `step` on
     * `number` — those are descriptor NAMES rather than descriptor keys, so there is no
     * second instance.
     *
     * ⚠️ AND IT IS NOT THE ENFORCEMENT. The server refuses an over-long pattern —
     * `Pattern::lengthRefusal()`, consulted by `unpublishable()`, `delimit()` and
     * `validateSettings()` — and a `maxlength` attribute is something a client can ignore
     * (invariant 6). This is a courtesy to the author, and the test asserts both halves so
     * nobody later "simplifies" by keeping only one.
     *
     * ⚠️ FAILS CLOSED on a descriptor that cannot express a length, matching this class's
     * existing posture on an unknown descriptor type: silently dropping the key is how a
     * published constraint becomes a lie, which is the defect being fixed.
     *
     * ⚠️ GATED ON THE DECLARED TYPE, NOT ON THE COMPONENT CLASS, and the difference is not
     * cosmetic. `integer` and `number` descriptors also render a `TextInput`, so an
     * `instanceof` check accepted them and applied `maxLength()` to a numeric control —
     * where a browser ignores the `maxlength` attribute outright and numeric validation
     * reads a maximum as a VALUE bound rather than a digit count. The form then imposed a
     * DIFFERENT constraint from the one published, which is a worse failure than imposing
     * none: the author cannot see it, and neither can the type that published it.
     *
     * `string` is required positively rather than numeric types being excluded, so a
     * descriptor type added later cannot inherit a length silently by not being on a
     * denylist.
     *
     * @param  array<string, mixed>  $descriptor
     */
    private static function withLength(mixed $component, string $key, array $descriptor): mixed
    {
        if (! isset($descriptor['maxLength'])) {
            return $component;
        }

        if (($descriptor['type'] ?? null) !== 'string' || ! $component instanceof TextInput) {
            throw new RuntimeException(sprintf(
                'Setting [%s] declares maxLength on a [%s] descriptor, which cannot express a '
                .'string length. The renderer fails closed rather than dropping it or applying '
                .'something else: a published constraint the form does not apply is a constraint '
                .'the author only meets by accident, and one the form applies DIFFERENTLY is one '
                .'nobody can see is wrong.',
                $key,
                is_string($descriptor['type'] ?? null) ? $descriptor['type'] : get_debug_type($descriptor['type'] ?? null),
            ));
        }

        return $component->maxLength((int) $descriptor['maxLength']);
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

        return self::withLength($component, $key, $descriptor);
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
     * A NAME rather than a closure, because `settingsSchema()` returns data: a
     * field type describes, the panel builds (ADR-029). A closure would put a
     * Filament callable in the description and be callable from nowhere else.
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
