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

    /** Locked fields may not change shape; these attributes are shape. */
    public const SHAPE_ATTRIBUTES = ['type', 'cardinality'];

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

            // ADR-006: storage locks the moment data exists. Shipping this
            // guard in v1 rather than later is the whole point of copying it.
            if ($storage->is_locked && $storage->exists) {
                foreach (self::SHAPE_ATTRIBUTES as $attribute) {
                    if ($storage->isDirty($attribute)) {
                        throw new RuntimeException(
                            "Field [{$storage->handle}] is locked because entries hold data for it. "
                            ."[{$attribute}] cannot change. Create a new field, convert, verify, then "
                            .'drop the old one — silent type coercion is how content gets destroyed.'
                        );
                    }
                }
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
