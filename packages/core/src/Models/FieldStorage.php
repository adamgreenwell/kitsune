<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Fields\Projection;
use Kitsune\Core\Fields\StorageStrategy;
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

    protected $casts = [
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
            // Nomination first: when both apply — a nominated field given a
            // cardinality its type cannot hold — the more specific refusal is
            // the one the caller reads.
            $storage->guardNomination();
            $storage->guardOrgMove();
            $storage->guardCardinalitySupport();

            // ADR-006: storage locks the moment data exists. Shipping this
            // guard in v1 rather than later is the whole point of copying it.
            if ($storage->is_locked && $storage->exists) {
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

    /**
     * Where this field's values actually live.
     *
     * Callers dispatch on all THREE cases — relational rows in
     * `entry_relations`, a promoted column on `entries`, or a key in
     * `values`. Handling two of the three is how `slug` came to answer a
     * subject-access request with null.
     */
    public function strategy(): StorageStrategy
    {
        return app(FieldTypeRegistry::class)->get($this->type)->strategy();
    }

    /**
     * The real column this field writes to, for a promoted field.
     *
     * Never the handle: `field_storage` accepts any valid handle for a `slug`
     * field, so one called `public_slug` still writes `entries.slug`.
     */
    public function promotedColumn(): ?string
    {
        return app(FieldTypeRegistry::class)->get($this->type)->promotedColumn();
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
    }

    /** The projection's signature, or a marker when the type has none. */
    protected function projectionSignature(): string
    {
        $type = app(FieldTypeRegistry::class)->get($this->type);

        return $type->projection(new FieldConfig($this))?->signature() ?? 'none';
    }

    /**
     * A nominated field's storage is frozen in the ways that matter.
     *
     * ⚠️ The `Field` guard watches the FIELD row, so mutating the storage in
     * place walked past it entirely. An unlocked single-value relation could
     * have its `cardinality` changed to -1 — recreating the multi-subject
     * disclosure the nomination guard exists to prevent — and even a LOCKED
     * storage could have its `handle` or `org_id` changed, because neither is
     * a shape attribute: subject queries would then read the wrong key, or
     * the wrong org (ADR-020).
     *
     * Only while something nominates it, and only the attributes that change
     * what the field IS. Editing settings on a nominated field stays free
     * unless the lock or the projection guard says otherwise.
     */
    private function guardNomination(): void
    {
        if (! $this->exists) {
            return;
        }

        $frozen = array_filter(
            ['handle', 'org_id', 'cardinality', 'type'],
            fn (string $attribute): bool => $this->isDirty($attribute),
        );

        if ($frozen === []) {
            return;
        }

        $nominated = EntryType::query()
            ->whereIn('subject_field_id', Field::query()->where('field_storage_id', $this->getKey())->select('id'))
            ->first();

        if ($nominated !== null) {
            throw new RuntimeException(sprintf(
                'Field [%s] backs the data subject identifier of [%s], so [%s] cannot change. '
                .'Clear the nomination first — changing it here would leave that type answering '
                .'subject-access requests against a field nobody re-checked (ADR-020).',
                $this->handle,
                $nominated->handle,
                implode(', ', $frozen),
            ));
        }
    }

    /**
     * Storage cannot walk across the org boundary out from under a field.
     *
     * ⚠️ `guardNomination()` freezes `org_id` only while something NOMINATES
     * the storage. Attached but un-nominated, the same move recreated the
     * foreign-storage state that `Field::saving()` refuses — without ever
     * saving a field. `withoutSubjectIdentifier()` then filters the moved row
     * out and stops naming a type that still holds personal data, which is
     * the compliance report going quiet about a real hole (ADR-021).
     */
    private function guardOrgMove(): void
    {
        if (! $this->exists || ! $this->isDirty('org_id')) {
            return;
        }

        $field = Field::query()
            ->where('field_storage_id', $this->getKey())
            ->whereHas('entryType', function (Builder $query): void {
                if ($this->org_id === null) {
                    // Becoming global is safe: global storage is legitimately
                    // available to every org, including this field's.
                    $query->whereRaw('1 = 0');

                    return;
                }

                $query->where(function (Builder $inner): void {
                    $inner->whereNull('org_id')->orWhere('org_id', '!=', $this->org_id);
                });
            })
            ->with('entryType')
            ->first();

        if ($field !== null) {
            throw new RuntimeException(sprintf(
                'Field storage [%s] is attached to entry type [%s] and cannot move to another '
                .'organisation. Detach it first — moving it here would leave that type reading '
                .'another organisation\'s storage, and drop it from the holes report while it '
                .'still holds personal data (ADR-021).',
                $this->handle,
                $field->entryType->handle,
            ));
        }
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
        if ((int) $this->cardinality === 1) {
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
            $this->cardinality,
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
