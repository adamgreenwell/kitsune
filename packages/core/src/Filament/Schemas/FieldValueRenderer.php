<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field as FormField;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Kitsune\Core\Fields\Cell;
use Kitsune\Core\Fields\Control;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\FieldTypeRegistry;

/**
 * Builds the control that edits a field value, and the cell that lists it.
 *
 * The panel half of ADR-029. A field type names a `Control`; this decides which
 * Filament component that is, and it is the ONE place a value's writing direction
 * is applied.
 *
 * ⚠️ THAT SINGULARITY IS THE WHOLE DESIGN, not tidiness. Issue #39 put `dir="auto"`
 * on the title input, then on the title column, then on two more title columns
 * found only in review — three passes, and short of complete twice, because every
 * screen decided for itself. Here no screen decides: `formComponent()` and
 * `tableColumn()` are the only constructors, they always route through
 * `withDirection()`, and `withDirection()` reads the answer from the vocabulary
 * rather than from an argument. There is no code path that builds a field control
 * and skips it.
 *
 * ⚠️ `SettingsSchemaRenderer` is its sibling and not its duplicate. That one renders
 * a field's CONFIGURATION form from `settingsSchema()` — how an operator sets a
 * field up. This renders the field's VALUE — what an editor types into it. Same
 * seam, different subject.
 */
final class FieldValueRenderer
{
    /**
     * The form control for one field.
     *
     * ⚠️ Multi-value handling wraps the control AFTER direction is applied to it, and
     * that order matters. `Repeater` exposes no input hook, so attaching direction to
     * the repeater would put it on a wrapper and lose per-value resolution — the inner
     * control is what holds text, so the inner control is what carries `dir`.
     */
    public static function formComponent(FieldConfig $config): Component
    {
        $control = self::controlFor($config);
        $inner = self::withDirection(self::buildControl($control, $config), $control);

        if (! $config->isMultiValue()) {
            return self::describe($inner, $config);
        }

        /*
         * ⚠️ `Repeater::simple()` takes the inner control built at the REPEATER'S OWN
         * state path, not at the field's. Handing it a control made with
         * `TextInput::make('values.summary')` nests the state at
         * `values.summary.*.values.summary` and the field silently stops saving —
         * measured, and the reason the inner control is rebuilt nameless here rather
         * than reusing the one above.
         */
        /*
         * ⚠️ `hiddenLabel()` on the inner control, or every row of the repeater is
         * labelled `Value` and the field's own label disappears — measured in the browser,
         * where `Keywords` was absent from the form and `Value` was present. `simple()`
         * promotes the inner control to the row, so the label that belongs to the FIELD
         * has to stay on the repeater.
         */
        $repeater = Repeater::make(self::statePath($config))
            ->simple(
                self::withDirection(self::buildControl($control, $config, named: false), $control)
                    ->hiddenLabel(),
            );

        if (($max = $config->cardinality()) > 1) {
            // Cardinality -1 means unbounded, so only a positive bound is applied.
            $repeater = $repeater->maxItems($max);
        }

        return self::describe($repeater, $config);
    }

    /**
     * The table column for one field, or null when the kind is not listed.
     *
     * Null rather than a hidden column: a `Cell::None` value has no useful one-line
     * form, and a column that truncates a document to forty characters costs a query
     * and tells the reader nothing.
     */
    public static function tableColumn(FieldConfig $config): ?Column
    {
        $cell = self::controlFor($config)->cell();

        if (! $cell->isListed()) {
            return null;
        }

        $column = self::buildCell($cell, $config)->label($config->label());

        // ⚠️ A table cell has only `extraAttributes()` — there is no input — so the
        // hook is not looked up here. Filament puts these on the element holding the
        // text, which is an inner `div.fi-ta-text-item` rather than the `<td>`: the
        // browser tests locate by text for exactly that reason.
        return $cell->direction()->needsAutoAttribute()
            ? $column->extraAttributes(['dir' => 'auto'])
            : $column;
    }

    /**
     * The control kind this field's type names.
     *
     * Resolved through the registry rather than a method on the model, matching how
     * `FieldStorage` already reaches `strategy()`, `projection()` and
     * `promotedColumn()` — one way in, so a module's type resolves the same way.
     */
    private static function controlFor(FieldConfig $config): Control
    {
        return app(FieldTypeRegistry::class)->get((string) $config->storage->type)->control();
    }

    /**
     * Applies the value's writing direction to the component that will hold it.
     *
     * ⚠️ THE CHOKEPOINT, and the only place a `dir` attribute is set on a field
     * control. Every path through `formComponent()` passes here, the direction comes
     * from the `Control` rather than from an argument, and `Control::direction()` is an
     * exhaustive `match` that PHPStan fails when a case is added. So a caller cannot
     * pass the wrong answer, and a new control kind cannot arrive without one.
     *
     * ⚠️ THE ATTACHMENT POINT IS ASKED OF THE COMPONENT, NOT DECLARED PER CONTROL.
     * Filament has no interface for `extraInputAttributes()` — only a trait, which
     * cannot be a type hint — and it is absent from `Toggle`, `KeyValue` and `Repeater`
     * (measured on v5.7.8), so calling it blindly is a fatal error on three of the
     * components built here. A hand-maintained control-to-mechanism table would encode
     * Filament's API surface in Kitsune and drift the moment a component gained or lost
     * the trait; an `instanceof` chain cannot disagree with the object in front of it.
     *
     * ⚠️ The chain is INLINE rather than behind a predicate, because a bool-returning
     * helper does not narrow the type and the only ways to make it compile would be a
     * PHPStan suppression or an inline `@var` — both forbidden here, and both of which
     * would trade a real check for a silenced one. `ValueDirectionRenderingTest` asserts
     * the outcome instead: for every control, the attribute lands where the vocabulary
     * says it should.
     *
     * ⚠️ `extraAttributes()` is the weaker fallback, and it is weaker in a specific way:
     * `dir` inherits down the DOM, so a wrapper resolves ONCE from the first strong
     * directional character inside it. A `KeyValue` editor holding an Arabic row and a
     * Latin row renders the second in the first's direction. Used only where there is no
     * input hook, so the alternative is no direction at all — recorded as a residual in
     * `docs/accessibility-inventory.md` rather than left to be discovered.
     */
    private static function withDirection(FormField $component, Control $control): FormField
    {
        if (! $control->needsAutoDirection()) {
            return $component;
        }

        if ($component instanceof TextInput
            || $component instanceof Textarea
            || $component instanceof RichEditor
            || $component instanceof DatePicker
            || $component instanceof DateTimePicker
            || $component instanceof Select) {
            return $component->extraInputAttributes(['dir' => 'auto']);
        }

        return $component->extraAttributes(['dir' => 'auto']);
    }

    /** The Filament component for a control kind, before description or direction. */
    private static function buildControl(Control $control, FieldConfig $config, bool $named = true): FormField
    {
        $path = $named ? self::statePath($config) : null;

        return match ($control) {
            Control::Line => TextInput::make($path ?? 'value')
                ->maxLength((int) $config->setting('maxLength', 255)),
            Control::Paragraph => Textarea::make($path ?? 'value')->rows(4),
            Control::RichText => RichEditor::make($path ?? 'value'),
            Control::Number => TextInput::make($path ?? 'value')->numeric(),
            Control::Toggle => Toggle::make($path ?? 'value'),
            Control::Date => DatePicker::make($path ?? 'value'),
            Control::DateTime => DateTimePicker::make($path ?? 'value'),
            Control::Choice => Select::make($path ?? 'value')->options(self::options($config)),
            Control::Choices => Select::make($path ?? 'value')
                ->multiple()
                ->options(self::options($config)),
            // ⚠️ Searchable rather than a plain list: a relation targets entries, and an
            // org's entry table is not a dropdown. The option source is deferred with the
            // rest of the relational leg — see the class docblock on EntryResource.
            Control::EntryPicker => Select::make($path ?? 'value')->searchable(),
            Control::KeyValue => KeyValue::make($path ?? 'value'),
        };
    }

    /** The table column for a cell kind, before label or direction. */
    private static function buildCell(Cell $cell, FieldConfig $config): Column
    {
        $path = self::statePath($config);

        return match ($cell) {
            Cell::Text => TextColumn::make($path),
            Cell::Numeric => TextColumn::make($path)->numeric(),
            Cell::Boolean => IconColumn::make($path)->boolean(),
            Cell::Timestamp => TextColumn::make($path)->dateTime(),
            Cell::Badge => TextColumn::make($path)->badge(),
            // `isListed()` is checked by the caller, so reaching this would be a bug
            // rather than a configuration. Stated to keep the match exhaustive.
            Cell::None => TextColumn::make($path),
        };
    }

    /**
     * Where this field's value lives in form state.
     *
     * ⚠️ Promoted fields are a real column and everything else is a key inside the
     * `values` JSON. Getting this wrong does not error — it writes to a path nothing
     * reads, so the field appears to save and the value is gone on reload.
     */
    private static function statePath(FieldConfig $config): string
    {
        return $config->storage->promotedColumn() ?? 'values.'.$config->handle();
    }

    /** Label, requiredness and help text, which are the field's rather than the type's. */
    private static function describe(FormField $component, FieldConfig $config): FormField
    {
        $component = $component->label($config->label())->required($config->isRequired());

        return ($help = $config->helpText()) !== null ? $component->helperText($help) : $component;
    }

    /**
     * Option keys and labels for a choice control.
     *
     * @return array<string, string>
     */
    private static function options(FieldConfig $config): array
    {
        $options = $config->setting('options', []);

        if (! is_array($options)) {
            return [];
        }

        $resolved = [];

        foreach ($options as $key => $label) {
            // Accepts both `['draft' => 'Draft']` and `['draft', 'published']`, because
            // an operator typing a bare list is the likelier mistake and refusing it
            // would leave a select with no options and no explanation.
            $resolved[is_int($key) ? (string) $label : (string) $key] = (string) $label;
        }

        return $resolved;
    }
}
