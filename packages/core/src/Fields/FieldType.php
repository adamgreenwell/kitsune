<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields;

use Kitsune\Core\Schema\SchemaDriver;

/**
 * The contract every field type answers.
 *
 * Most of the schema engine's difficulty is not the engine — it is that every
 * field type has to be right in FOUR places at once: storage, form, table and
 * API. Get the contract right and adding a type is filling in a form; get it
 * wrong and the engine becomes twelve special cases wearing a trenchcoat.
 *
 * A type that answers three of the four is not shippable. The most common
 * failure is one that edits beautifully and cannot be queried.
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
     * The LOGICAL type to project this field to, or null if not indexable.
     *
     * The driver renders the engine's spelling — MySQL rejects NUMERIC inside
     * CAST and demands DECIMAL, and a field type must not have to know that.
     * It takes a driver precisely so it never writes SQL itself.
     */
    public function generatedColumnType(SchemaDriver $driver): ?string;

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
     * The "configure this field" form, as a schema description.
     *
     * Deliberately data rather than Filament components: core stays
     * headless-capable (ADR-002), and the admin renders this rather than the
     * field type depending on a panel.
     *
     * @return array<string, mixed>
     */
    public function settingsSchema(): array;

    /** A suggested pii_class; the org confirms it, because only they know (ADR-020). */
    public function suggestedPiiClass(): string;
}
