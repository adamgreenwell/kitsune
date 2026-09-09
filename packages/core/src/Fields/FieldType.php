<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields;

/**
 * The contract every field type answers.
 *
 * Most of the schema engine's difficulty is not the engine — it is that every
 * field type has to be right in several places at once: storage, UI, API and
 * validation. Get the contract right and adding a type is filling in a form; get
 * it wrong and the engine becomes twelve special cases wearing a trenchcoat.
 *
 * ⚠️ This used to say "a type that answers three of the four is not shippable"
 * while all twelve shipped types answered exactly three — there was no UI method
 * at all until `control()`. An aspiration phrased as an invariant is worse than
 * either, because it makes a real gap look like a rule already held. The UI face
 * is now one method returning a KIND of control, never a component (ADR-029).
 *
 * The failure it warned about is real in its accurate form: one that edits
 * beautifully and cannot be queried, because storage is the face with no visible
 * symptom when it is wrong.
 */
interface FieldType
{
    // ── Identity ──────────────────────────────────────────────────────────

    public static function handle(): string;

    public static function label(): string;

    public static function icon(): string;

    // ── Storage ───────────────────────────────────────────────────────────

    public function strategy(): StorageStrategy;

    public function isIndexable(): bool;

    public function supportsCardinality(): bool;

    /**
     * What this field projects to when indexed, or null if it cannot be.
     *
     * A description, not SQL, and no driver is passed: the field type says
     * `decimal` and the driver decides that MySQL wants DECIMAL as a column,
     * DECIMAL inside CAST and rejects NUMERIC there, while an integer column
     * is BIGINT filled by a SIGNED cast. Handing field types a driver was the
     * wrong seam — they still had to know a rendered type serves two
     * grammars, and the driver had no way to guard the expression by JSON
     * type (ADR-028 amendment).
     */
    public function projection(FieldConfig $config): ?Projection;

    /**
     * The real column a `Promoted` field writes to, or null for the rest.
     *
     * ADR-015 promotes title, slug, status and published_at because every
     * list view, URL resolution and status filter touches them, so a promoted
     * type's data is NOT in `values` — which anything reasoning about where a
     * field's data lives has to know. The storage lock got exactly that
     * wrong and left a slug field unlocked while holding content.
     *
     * ⚠️ NOT the handle. `field_storage` accepts any valid handle for a
     * `slug` field, so a field called `public_slug` still stores its value in
     * `entries.slug` — and code that assumed handle-is-column read a column
     * that does not exist.
     */
    public function promotedColumn(): ?string;

    public function toStorage(mixed $input, FieldConfig $config): mixed;

    public function fromStorage(mixed $stored, FieldConfig $config): mixed;

    // ── API ───────────────────────────────────────────────────────────────

    public function toApi(mixed $stored, FieldConfig $config): mixed;

    public function fromApi(mixed $input, FieldConfig $config): mixed;

    /** @return array<string, mixed> JSON Schema fragment */
    public function apiSchema(FieldConfig $config): array;

    // ── Validation and configuration ──────────────────────────────────────

    /**
     * Rules for this field.
     *
     * ⚠️ NEVER returns Laravel's `unique` or `exists`. Those do not go through
     * Eloquent, so they ignore global scopes and leak across orgs. Return
     * Kitsune's scoped equivalents instead. The plugin validation CLI fails
     * the build on this.
     *
     * @return array<int, mixed>
     */
    public function validationRules(FieldConfig $config): array;

    /**
     * Rules for EACH element of a multi-value field, applied at `handle.*`.
     *
     * Laravel needs a separate attribute for element rules. A rule placed in
     * the field's own list is handed the whole array, so a per-element check
     * never runs — which is how RelationType's cross-org `scopedExists` came
     * to validate nothing at all while looking correct.
     *
     * @return array<int, mixed>
     */
    public function elementValidationRules(FieldConfig $config): array;

    /**
     * The "configure this field" form, as a schema description.
     *
     * Deliberately data rather than Filament components: a field type describes a
     * control and the panel builds it (ADR-029). Not because core avoids depending
     * on a panel — it hard-requires one (ADR-008) — but because one renderer
     * reading a closed vocabulary is the only shape in which a cross-cutting
     * concern cannot be forgotten by the thirteenth type.
     *
     * @return array<string, mixed>
     */
    public function settingsSchema(): array;

    /**
     * Why this combination of settings is unusable, or null if it is fine.
     *
     * ⚠️ Separate from `settingsSchema()` because the interesting constraints
     * are not per-setting. A minimum above a maximum leaves no value that can
     * satisfy the field, and neither control is individually wrong — so no
     * per-descriptor rule can see it.
     *
     * Data in, reason out: no Filament, no exceptions, so the builder can render
     * the message and the model can throw it — one description, two consumers
     * (ADR-029).
     *
     * The bar is "unusable", not "unwise". A field nothing can ever be stored in
     * is a defect; an oddly narrow one is the author's business.
     *
     * @param  array<string, mixed>  $settings
     */
    public function validateSettings(array $settings): ?string;

    /**
     * Whether `toStorage()` loses information a revision should keep.
     *
     * ⚠️ Asked of the TYPE rather than decided by the model, for the reason
     * `validateSettings()` is: a new type that converts lossily declares it here and
     * the revision recorder needs no change. The alternative — the model testing for
     * `rich_text` by name — puts a field-type concern in every layer that touches a
     * value, which is what ADR-002 and ADR-006 separate.
     *
     * True for `rich_text`, whose conversion strips markup: field-types.md §6 requires
     * the pre-sanitization original be kept in the revision record so an author can
     * see what was removed. False for the types whose conversion is a cast — keeping
     * `'5'` beside `5` is noise, and noise in a store erasure has to sweep is worse
     * than noise.
     */
    public function retainsOriginal(): bool;

    /** A suggested pii_class; the org confirms it, because only they know (ADR-020). */
    public function suggestedPiiClass(): string;
}
