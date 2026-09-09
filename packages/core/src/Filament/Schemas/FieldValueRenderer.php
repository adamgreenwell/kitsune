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
use Kitsune\Core\Models\Entry;

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
     * Where relation state lives in form data, outside the model's attributes.
     *
     * A constant because the renderer writes it and the pages read it, and a literal in two
     * files is one rename away from a form that silently stops saving relations.
     */
    public const RELATION_STATE_PREFIX = 'relations';

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

        /*
         * ⚠️ A RELATION PICKER IS NEVER REPEATER-WRAPPED, and getting this wrong was a 500.
         *
         * `Select::multiple()` already carries a relation's multiplicity, and the picker's
         * state deliberately lives outside the model's attributes. Wrapping it made the
         * REPEATER the outer component — and the repeater is not `dehydrated(false)`, so the
         * whole `relations` key reached the entry as an attribute and the save died on
         * `no such column: relations`.
         *
         * Found by the browser suite: three revision tests started failing because the save
         * they depend on had stopped working. No unit test on the renderer could have seen
         * it, because the defect is in what the SAVE does with the component tree.
         */
        if ($control === Control::EntryPicker || ! $config->isMultiValue()) {
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
     * ⚠️ `extraAttributes()` is the weaker attachment, and it is weaker in a specific way:
     * `dir` inherits down the DOM, so a wrapper resolves ONCE from the first strong
     * directional character inside it. A `KeyValue` editor holding an Arabic row and a
     * Latin row renders the second in the first's direction. Used where there is no input
     * hook, so the alternative is no direction at all — recorded as a residual in
     * `docs/accessibility-inventory.md` rather than left to be discovered.
     *
     * ⚠️ A SELECT TAKES BOTH, because Filament renders two different controls under one
     * component. A plain select is a native `<select>` and honours the input attribute; a
     * `searchable()` or `multiple()` one is a combobox BUILT IN JAVASCRIPT
     * (`forms/dist/components/select.js`) whose button never sees a PHP attribute bag.
     * Measured on v5.7.8: the relation picker carried no `dir` ANYWHERE in its field while
     * every other value on the same form carried one, so an Arabic entry title laid out
     * left-to-right. The wrapper is server-rendered and `dir` inherits, so the combobox
     * resolves from it.
     *
     * Both are applied rather than branching on `isSearchable()` / `isMultiple()`: those are
     * runtime state that a later builder call can still change after this one runs, and a
     * branch that guesses wrong fails SILENTLY by omitting direction — the failure mode this
     * whole seam exists to make impossible.
     */
    private static function withDirection(FormField $component, Control $control): FormField
    {
        if (! $control->needsAutoDirection()) {
            return $component;
        }

        if ($component instanceof Select) {
            return $component
                ->extraInputAttributes(['dir' => 'auto'])
                ->extraAttributes(['dir' => 'auto']);
        }

        if ($component instanceof TextInput
            || $component instanceof Textarea
            || $component instanceof RichEditor
            || $component instanceof DatePicker
            || $component instanceof DateTimePicker) {
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
            Control::EntryPicker => self::entryPicker($path ?? 'value', $config),
            Control::KeyValue => KeyValue::make($path ?? 'value'),
        };
    }

    /**
     * A searchable picker over the entries this relation may target.
     *
     * ⚠️ SEARCH RESULTS, NOT A PRELOADED LIST. An org's entry table is not a dropdown —
     * preloading it makes opening a form O(total entries) in time and memory, which is the
     * same defect the public site lookup had and the same 1 vCPU / 1 GB floor (ADR-027) it
     * would spend. Filament asks for matches as the author types.
     *
     * ⚠️ IT NEVER REACHES THE MODEL, and that is the load-bearing part. Every other control
     * writes to `values.{handle}`; a relation must not, because the value-conversion
     * pipeline would then store an array of entry IDs in the JSON column — which is
     * precisely what ADR-015 forbids and the reason `entry_relations` exists: JSON cannot
     * answer "what points at me?" without a full scan, and cascade-on-delete becomes
     * application code that is eventually wrong.
     *
     * So the state lives under `relations.{handle}` and is `dehydrated(false)`. The pages
     * hydrate it from `Entry::relatedIdsForField()` and write it with
     * `syncFieldRelations()` after the entry itself is saved — a relation needs the source
     * entry to exist, so it cannot be part of the same attribute write.
     *
     * ⚠️ The target constraint MIRRORS the validation rule rather than restating it.
     * `RelationType::elementValidationRules()` already narrows to `type_handle IN
     * (targetTypes)` through `scopedExists`, with an empty list meaning "any type". A picker
     * that offered a different set would let an author choose something the save then
     * refuses.
     */
    private static function entryPicker(string $path, FieldConfig $config): Select
    {
        $targets = array_values(array_filter(
            (array) ($config->setting('targetTypes', []) ?: []),
            static fn (mixed $handle): bool => is_string($handle) && $handle !== '',
        ));

        $search = static function (string $search) use ($targets): array {
            /*
             * ⚠️ `whereLike(..., caseSensitive: false)` RATHER THAN `like`, because `LIKE` is
             * case-SENSITIVE on PostgreSQL and case-insensitive on SQLite and a default MySQL
             * collation. A plain `like` meant an author on Postgres could not find
             * "Course maintenance" by typing `course`, while the same interaction worked on
             * the other two engines — the driver-divergence class AGENTS.md invariant 5 is
             * about, found by review. Laravel emits `ILIKE` on Postgres and folds case
             * elsewhere, so one expression means one thing on all three.
             */
            $query = Entry::query()->whereLike('title', '%'.$search.'%', caseSensitive: false);

            if ($targets !== []) {
                $query->whereIn('type_handle', $targets);
            }

            // Bounded, because a search for "a" otherwise returns the whole table. The
            // author narrows; the control does not try to show everything.
            return $query->orderBy('title')->limit(50)->pluck('title', 'id')->all();
        };

        $picker = Select::make($path)
            ->searchable()
            ->getSearchResultsUsing($search)
            // ⚠️ Needed as well as the search, or a SAVED value renders as its bare id: the
            // options list is empty until the author types, so Filament has nothing to
            // resolve the current selection against.
            ->getOptionLabelUsing(static fn (mixed $value): ?string => Entry::query()->whereKey($value)->value('title'))
            ->dehydrated(false);

        if (! $config->isMultiValue()) {
            // Cardinality 1 is one target.
            return $picker;
        }

        /*
         * ⚠️ `getOptionLabelsUsing` — PLURAL — as well as the singular one, and the singular
         * alone is a 500. Filament validates a `multiple()` select's selected options and
         * refuses outright without it: "failed to validate the field's selected options
         * because it did not have an [options()] or [getOptionLabelsUsing()] configuration".
         * The singular hook is what renders a saved value; the plural one is what lets a
         * multi-select save at all.
         */
        $picker = $picker
            ->multiple()
            ->getOptionLabelsUsing(static fn (array $values): array => Entry::query()
                ->whereKey($values)
                ->pluck('title', 'id')
                ->all());

        /*
         * ⚠️ A FINITE CARDINALITY IS A BOUND THE FORM MUST STATE, and omitting it turned a
         * validation message into a stack trace. `multiple()` on its own is unbounded, so a
         * relation declared with cardinality 2 let an author pick a third target; the save
         * then reached `EntryRelation::guardCardinality()`, which throws. The author saw a
         * 500 rather than "you may select at most 2", and the bound was enforced only after
         * they had done the work.
         *
         * The repeater path above already does this, which is the tell — one control kind
         * honoured the bound and the other did not. Found by review, and it went unnoticed
         * because the seeded relation field is unlimited (-1), so nothing exercised a finite
         * one.
         *
         * -1 means unbounded, so only a positive bound is applied.
         */
        if (($max = $config->cardinality()) > 1) {
            $picker = $picker->maxItems($max);
        }

        return $picker;
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
        // ⚠️ A relation lives in `entry_relations`, not in `values` and not in a column, so
        // its state path is deliberately outside both. Writing it to `values.{handle}` would
        // have the conversion pipeline store an ID array in JSON — what ADR-015 forbids.
        if (self::controlFor($config) === Control::EntryPicker) {
            return self::RELATION_STATE_PREFIX.'.'.$config->handle();
        }

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
