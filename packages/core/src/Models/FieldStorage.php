<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Fields\Projection;
use Kitsune\Core\Tenancy\Attributes\Unscoped;
use RuntimeException;

/**
 * Drupal's FieldStorageConfig: defined once, reusable across entity types.
 *
 * @property int $id
 * @property int|null $org_id
 * @property string $handle
 * @property string $type
 * @property int $cardinality
 * @property bool $is_indexed
 * @property bool $is_locked
 * @property string|null $pii_class
 */
#[Unscoped]
class FieldStorage extends Model
{
    public const TABLE = 'field_storage';

    /** GDPR Article 9 special-category data is `sensitive` (ADR-020). */
    public const PII_CLASSES = ['none', 'personal', 'sensitive'];

    /**
     * Locked fields may not change shape; these attributes are shape.
     *
     * `settings` is NOT in this list and is guarded separately, because only
     * SOME settings are shape: a label or a help string is safe to edit on a
     * field holding data, while `format` or `maxLength` changes both the
     * conversion and the projection. The guard compares projections rather
     * than attribute names, so a new setting is covered without anyone
     * remembering to add it here.
     *
     * ⚠️ `handle` belongs here because it is the JSON KEY, not merely a
     * label: SchemaManager passes it as the extraction path, so renaming
     * `price` to `cost` leaves every stored value under `price` where
     * nothing reads it. The field appears to empty itself across the whole
     * table, and there is no error to notice.
     */
    public const SHAPE_ATTRIBUTES = ['type', 'cardinality', 'handle'];

    /**
     * Handles become SQL identifiers, and those have hard limits (ADR-028).
     *
     * PostgreSQL truncates at 63 bytes, MySQL rejects past 64. A truncated
     * identifier is a silent collision between two orgs' generated columns,
     * so the input is bounded rather than the output trimmed.
     *
     * 32 rather than 40: the column carries a projection signature and the
     * index adds `_site_idx`, so `idx_` + 32 + `__decimal12_2` + `_site_idx`
     * is 58 bytes — inside the limit with room for a module's longer logical
     * type. The assembled name is re-checked at index time regardless.
     */
    public const MAX_HANDLE_LENGTH = 32;

    /**
     * Lowercase snake_case, no doubled underscore.
     *
     * The ban on `__` is load-bearing: it is the separator in the generated
     * column name, and allowing it in a handle would make `idx_a__b__c`
     * ambiguous between handle `a` type `b__c` and handle `a__b` type `c`.
     */
    public const HANDLE_PATTERN = '/^[a-z][a-z0-9]*(_[a-z0-9]+)*$/';

    protected $table = self::TABLE;

    protected $guarded = [];

    /**
     * ⚠️ The same default the migration declares.
     *
     * Without it an unsaved row has `cardinality` NULL until the database
     * fills it in, so `isMultiValue()` — which asks whether it differs from 1
     * — answered TRUE for every new single-value field, and the domain guard
     * below saw 0. The model has to agree with the column, not wait for it.
     */
    protected $attributes = [
        'cardinality' => 1,
    ];

    protected $casts = [
        // ⚠️ A form posts `"1"`. Without this the strict comparisons that ask
        // whether cardinality differs from 1 answered TRUE for a
        // single-value field, so validation demanded an array, the schema
        // advertised one, and toStorage() wrapped the scalar in a singleton
        // — until the model was refreshed out of the database.
        'cardinality' => 'integer',
        'settings' => 'array',
        'is_indexed' => 'boolean',
        'is_locked' => 'boolean',
    ];

    protected static function booted(): void
    {
        // ADR-020, fail closed: an unclassified field does not save. In a
        // runtime schema engine the platform cannot know whether "Customer
        // Notes" holds personal data, and guessing from the field name fails
        // the moment a tenant labels it in German.
        static::saving(function (self $storage): void {
            if ($storage->pii_class === null) {
                throw new RuntimeException(
                    "Field [{$storage->handle}] has no pii_class. Classify it as one of: "
                    .implode(', ', self::PII_CLASSES).'. Without it a subject-access or '
                    .'erasure request is unanswerable, and nothing else can supply the answer.'
                );
            }

            if (! in_array($storage->pii_class, self::PII_CLASSES, true)) {
                throw new RuntimeException("Unknown pii_class [{$storage->pii_class}].");
            }

            $storage->guardHandle();
            $storage->guardCardinalitySupport();

            // ⚠️ The ORIGINAL lock state, and the flag cannot be cleared.
            //
            // Reading `$storage->is_locked` read the value being SAVED, so a
            // caller could set it false — alone or alongside a shape change —
            // and every guard below skipped itself. The model is fully mass
            // assignable, so that was one array key away from routing around
            // ADR-006 entirely, without an amendment.
            if ($storage->exists
                && (bool) $storage->getRawOriginal('is_locked')
                && ! $storage->is_locked) {
                throw new RuntimeException(
                    "Field [{$storage->handle}] is locked because entries hold data for it, and "
                    .'the lock cannot be cleared while that is true. It is not a preference — it '
                    .'is the record that data exists (ADR-006).'
                );
            }

            // ADR-006: storage locks the moment data exists. Shipping this
            // guard in v1 rather than later is the whole point of copying it.
            if ($storage->exists && (bool) $storage->getRawOriginal('is_locked')) {
                foreach (self::SHAPE_ATTRIBUTES as $attribute) {
                    if ($storage->isDirty($attribute)) {
                        throw new RuntimeException(
                            "Field [{$storage->handle}] is locked because entries hold data for it. "
                            ."[{$attribute}] cannot change. Create a new field, migrate the data, verify, "
                            .'then drop the old one — a silent shape change is how content gets destroyed.'
                        );
                    }
                }

                $storage->guardProjectionSettings();
            }
        });
    }

    public function isMultiValue(): bool
    {
        return $this->cardinality !== 1;
    }

    /** Cardinality > 1 cannot project to a scalar column (field-types.md §7). */
    public function isIndexable(): bool
    {
        return ! $this->isMultiValue();
    }

    /**
     * The generated column this field projects to, if it is indexed.
     *
     * ADR-028: named for the projection, not the owner. `entries` is one
     * table shared by every org, and two orgs may each define `price` — so
     * the projection has to be part of the identity, or one org's column
     * silently casts the other org's data to the wrong type.
     *
     * ⚠️ The signature, not the field type handle. The projection depends on
     * configuration too: `number` with `format: integer` projects to BIGINT
     * while its decimal sibling projects to DECIMAL(12,2), and two `text`
     * fields can want different widths. Naming after the handle alone was not
     * injective, and two orgs configuring `number` differently would have
     * collided on `idx_count__number` with incompatible column types.
     *
     * Two rows with the same handle AND signature generate a byte-identical
     * expression, so they share this column deliberately.
     */
    public function generatedColumnName(): string
    {
        return 'idx_'.$this->handle.'__'.$this->projection()->signature();
    }

    /** What this field projects to when indexed. */
    public function projection(): Projection
    {
        $projection = app(FieldTypeRegistry::class)->get($this->type)->projection(new FieldConfig($this));

        return $projection ?? throw new RuntimeException(
            "Field type [{$this->type}] projects to no scalar column, so [{$this->handle}] has none."
        );
    }

    /** ADR-021: the index leads with the scope key, and says so. */
    public function generatedIndexName(): string
    {
        return $this->generatedColumnName().'_site_idx';
    }

    /**
     * Settings that change the PROJECTION are shape too.
     *
     * ⚠️ The lock checked `type` and `cardinality` only, so switching a
     * number field from decimal to integer on a table full of data was
     * permitted — and it changes both the conversion (`1.5` becomes `1`) and
     * the generated column (DECIMAL to BIGINT). Existing fractional values
     * would then violate the field's own contract, and the same is true of
     * narrowing a text field's `maxLength`.
     *
     * Compared by projection rather than by naming the settings, so a field
     * type adding a projection-affecting setting is covered without anyone
     * remembering this method exists.
     */
    private function guardProjectionSettings(): void
    {
        if (! $this->isDirty('settings')) {
            return;
        }

        // A clone with the ORIGINAL attributes, rather than a fresh model:
        // `new self(...)` is unsaved, and naming a helper `exists()` on an
        // Eloquent model shadows the builder method Laravel forwards to —
        // which sent the suite into a loop rather than failing outright.
        $original = clone $this;
        // getRawOriginal, not getOriginal: the latter applies casts, so
        // `settings` comes back already decoded and setRawAttributes then
        // hands an array to the array cast's json_decode().
        $original->setRawAttributes($this->getRawOriginal(), true);

        $before = $original->projectionSignature();
        $after = $this->projectionSignature();

        if ($before !== $after) {
            throw new RuntimeException(
                "Field [{$this->handle}] is locked because entries hold data for it, and this "
                ."setting changes how it is stored — the projection would move from [{$before}] to "
                ."[{$after}]. Existing values would be coerced silently. Create a new field, "
                .'convert, verify, then drop the old one (ADR-006).'
            );
        }

        $this->guardNarrowedSettings($original);
    }

    /**
     * ⚠️ A locked field can be narrowed without moving its projection.
     *
     * A `multi_select` signs as `none` whatever its options are, and a
     * `select` swapping options of the same maximum width keeps `string64` —
     * so the comparison above permitted both. Existing entries could then
     * hold choices validation no longer accepts, and an unrelated edit to
     * such an entry started failing, on a field ADR-006 says is locked.
     *
     * WIDENING is safe and stays allowed: adding an option or another
     * permitted target type cannot invalidate a stored value. Narrowing and
     * reinterpreting are what this refuses, which is the same distinction the
     * projection guard draws, applied to what the field ACCEPTS rather than
     * to where it is stored.
     *
     * ⚠️ Walks the UNION of both sides, not just the keys that existed
     * before. A locked `number` could ADD `min: 0`, or a locked `text` a
     * `pattern`, and neither appeared in the original settings — so a loop
     * over `$before` never saw them, neither moves the projection, and both
     * narrow what the field accepts. An absent constraint is not the absence
     * of a setting: it means unrestricted, which is the widest value there
     * is.
     */
    private function guardNarrowedSettings(self $original): void
    {
        $before = (array) ($original->settings ?? []);
        $after = (array) ($this->settings ?? []);

        foreach (array_keys($before + $after) as $key) {
            $was = $before[$key] ?? null;
            $now = $after[$key] ?? null;

            if ($was === $now) {
                continue;
            }

            // Added where there was nothing. A constraint that did not exist
            // cannot have been satisfied by accident, so introducing one
            // narrows — an empty or absent value is the widest there is.
            if (! array_key_exists($key, $before) || $was === null || $was === []) {
                $this->refuseNarrowing((string) $key);
            }

            if (! is_array($was)) {
                // ⚠️ DIRECTION matters. Refusing every scalar difference
                // refused safe maintenance too: lowering a `min`, raising a
                // `max` or dropping either leaves every stored value valid.
                // Only a change that could invalidate one is narrowing.
                if ($this->scalarNarrows((string) $key, $was, $now)) {
                    $this->refuseNarrowing((string) $key);
                }

                continue;
            }

            // What the setting ACCEPTS: an option map is keyed by the stored
            // value and its labels are presentation, while a list of target
            // types is the values themselves.
            $accepted = array_is_list($was) ? $was : array_keys($was);
            $remaining = is_array($now) ? (array_is_list($now) ? $now : array_keys($now)) : [];

            if (array_diff($accepted, $remaining) !== []) {
                $this->refuseNarrowing((string) $key);
            }
        }
    }

    /**
     * Whether changing a scalar constraint could invalidate a stored value.
     *
     * Removal always widens — an absent bound is no bound. A lower floor or a
     * higher ceiling widens. Anything whose direction cannot be reasoned
     * about, such as a regular expression or a projection setting, is treated
     * as narrowing: guessing in the permissive direction is how content gets
     * quietly invalidated.
     */
    private function scalarNarrows(string $setting, mixed $was, mixed $now): bool
    {
        if ($now === null) {
            return false;
        }

        return match ($setting) {
            'min' => is_numeric($was) && is_numeric($now) && $now > $was,
            'max', 'maxLength' => is_numeric($was) && is_numeric($now) && $now < $was,
            default => true,
        };
    }

    private function refuseNarrowing(string $setting): void
    {
        throw new RuntimeException(
            "Field [{$this->handle}] is locked because entries hold data for it, so [{$setting}] "
            .'cannot be narrowed or reinterpreted — stored values would stop being valid, and the '
            .'next edit to an entry holding one would fail for no reason its author could see. '
            .'Adding to it is still allowed (ADR-006).'
        );
    }

    /** The projection's signature, or a marker when the type has none. */
    protected function projectionSignature(): string
    {
        $type = app(FieldTypeRegistry::class)->get($this->type);

        return $type->projection(new FieldConfig($this))?->signature() ?? 'none';
    }

    /**
     * ⚠️ `supportsCardinality()` was ADVISORY, and nothing read it on write.
     *
     * BaseFieldType branches on `cardinality !== 1` alone, so a type that
     * declares no support for multiple values converted, validated and
     * published as an array anyway once a storage row said so. A promoted
     * `slug` could produce an array for a column that is scalar in the
     * database — the flag described an intention rather than a rule.
     *
     * Refused at the row, because that is the one place every path goes
     * through: the builder UI, a seeder and a migration all save a
     * FieldStorage, and only some of them would go through a form.
     */
    private function guardCardinalitySupport(): void
    {
        $cardinality = (int) $this->cardinality;

        // ⚠️ The DOMAIN first. Only -1 (unlimited) and positive integers mean
        // anything; `0` and `-2` passed straight through, and BaseFieldType
        // reads anything other than 1 as multi-valued — so an invalid number
        // became an unlimited array with no maximum, silently.
        if ($cardinality !== -1 && $cardinality < 1) {
            throw new RuntimeException(sprintf(
                'Cardinality %d is not a value [%s] can hold. Use -1 for unlimited, or a positive '
                .'number of values.',
                $cardinality,
                $this->handle,
            ));
        }

        if ($cardinality === 1) {
            return;
        }

        if (app(FieldTypeRegistry::class)->get($this->type)->supportsCardinality()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Field type [%s] holds exactly one value, so [%s] cannot have cardinality %d. '
            .'A multi-value setting here would convert and publish an array for storage that '
            .'is scalar — including a promoted column, where the database disagrees outright.',
            $this->type,
            $this->handle,
            $cardinality,
        ));
    }

    private function guardHandle(): void
    {
        if (mb_strlen((string) $this->handle) > self::MAX_HANDLE_LENGTH) {
            throw new RuntimeException(sprintf(
                'Field handle [%s] exceeds %d characters. Handles become SQL identifiers and '
                .'PostgreSQL truncates those at 63 bytes — a truncated name is a silent collision '
                .'with another field, not an error (ADR-028).',
                $this->handle,
                self::MAX_HANDLE_LENGTH,
            ));
        }

        if (preg_match(self::HANDLE_PATTERN, (string) $this->handle) !== 1) {
            throw new RuntimeException(
                "Field handle [{$this->handle}] must be lowercase snake_case — a letter, then "
                .'letters, digits and single underscores. Doubled underscores are reserved as the '
                .'separator in generated column names (ADR-028).'
            );
        }
    }

    /** @return HasMany<Field, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(Field::class);
    }
}
