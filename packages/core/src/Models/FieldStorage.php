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

    public function generatedColumnName(): string
    {
        return 'idx_'.$this->handle;
    }

    /** @return HasMany<Field, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(Field::class);
    }
}
