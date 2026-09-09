<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields;

/**
 * What KIND of control edits a value — a kind, never a Filament component.
 *
 * This is the whole of what a field type says about its UI (ADR-029). It answers
 * one question; the cell, the writing direction and whether the value is listable
 * are all DERIVED from the answer by the methods below.
 *
 * ⚠️ ONE QUESTION, NOT FOUR, and that is the design rather than brevity. Every
 * additional thing a field type could declare is another thing twelve types must
 * get right and a thirteenth can get wrong. Issue #39 is the evidence: `dir="auto"`
 * was correct in `EntryResource` and absent from two other table definitions,
 * because reach depended on somebody enumerating call sites. Here a field type
 * cannot mention direction at all, so it cannot omit it.
 *
 * ⚠️ A CLOSED SET, matched exhaustively in `Kitsune\Core\Filament`. Adding a case
 * makes PHPStan fail every unhandled `match` — the renderer, `direction()` and
 * `cell()` — so a new control kind cannot ship until someone has decided what it
 * looks like, how it lists, and which way its text runs. That is the same
 * guarantee `LogicalType` gives the three storage drivers, and it was added there
 * because `integer` and `boolean` were silently unindexable on MySQL.
 *
 * ⚠️ It takes no `FieldConfig`. The control KIND is a property of the type; what
 * varies with configuration — a number's precision, a select's options, a
 * relation's target types, a field's cardinality — is read by the renderer from
 * the config it already holds. `projection()` takes one because a projection
 * genuinely changes with configuration; this does not, and a parameter nothing
 * uses invites a type to branch on the wrong thing.
 */
enum Control: string
{
    /** One line of text somebody typed. `text`, and `slug`. */
    case Line = 'line';

    /** Several lines of plain text. `textarea`. */
    case Paragraph = 'paragraph';

    /** Sanitised HTML with its own internal structure. `rich_text`. */
    case RichText = 'rich-text';

    /** A number, entered as digits. `number`. */
    case Number = 'number';

    /** A yes or no. `boolean`. */
    case Toggle = 'toggle';

    /** A calendar date. `date`. */
    case Date = 'date';

    /** A date and a time, stored UTC. `datetime`. */
    case DateTime = 'datetime';

    /** One option from a constrained set. `select`. */
    case Choice = 'choice';

    /** Several options from a constrained set. `multi_select`. */
    case Choices = 'choices';

    /** One or more other entries, chosen by searching. `relation`. */
    case EntryPicker = 'entry-picker';

    /** Arbitrary keys and values. `json`, the escape hatch. */
    case KeyValue = 'key-value';

    /**
     * How this control's writing direction is decided.
     *
     * ⚠️ EXHAUSTIVE `match`, NO `default`. A default arm is how a new case inherits
     * an answer nobody chose for it, and the answer it would inherit here is a
     * layout bug that reads as working software.
     */
    public function direction(): ValueDirection
    {
        return match ($this) {
            // Text a person wrote, in whatever script they wrote it in.
            self::Line, self::Paragraph => ValueDirection::Auto,

            // ⚠️ `Choice` and `Choices` are AUTO, which is easy to get wrong. The
            // control shows option LABELS, and a label is authored text — an org may
            // label its statuses in Arabic. Treating a select as chrome because its
            // values are constrained is how a translated label lays out backwards.
            self::Choice, self::Choices => ValueDirection::Auto,

            // ⚠️ So is the entry picker: it shows entry TITLES, which is exactly the
            // content the seeded `صيانة الملاعب في الأسبوع السابع` row exists to test.
            self::EntryPicker => ValueDirection::Auto,

            // ⚠️ And so is the key-value editor, on both halves. A JSON escape hatch
            // holds whatever an author put in it, and the KEY is as likely to be
            // non-Latin as the value.
            self::KeyValue => ValueDirection::Auto,

            // Per block, because one document may hold both directions — which is the
            // case ADR-018 rule 2 exists for, not an exotic one.
            self::RichText => ValueDirection::PerBlock,

            // Rendered from data whose glyphs the app chooses, not the author.
            self::Number, self::Toggle, self::Date, self::DateTime => ValueDirection::Neutral,
        };
    }

    /** What kind of cell lists a value edited by this control. */
    public function cell(): Cell
    {
        return match ($this) {
            self::Line, self::Paragraph, self::EntryPicker => Cell::Text,
            self::Number => Cell::Numeric,
            self::Toggle => Cell::Boolean,
            self::Date, self::DateTime => Cell::Timestamp,
            self::Choice, self::Choices => Cell::Badge,
            // No useful one-line form: a truncated document costs a query and tells
            // the reader nothing.
            self::RichText, self::KeyValue => Cell::None,
        };
    }

    /**
     * Whether the renderer must put `dir="auto"` on the input.
     *
     * A convenience over `direction()`, because the renderer asks this far more often
     * than it asks which of the three answers applies.
     */
    public function needsAutoDirection(): bool
    {
        return $this->direction()->needsAutoAttribute();
    }
}
