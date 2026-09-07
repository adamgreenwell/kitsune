<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kitsune\Core\Tenancy\Attributes\Unscoped;
use RuntimeException;

/**
 * Drupal's FieldConfig: per-type presentation. Never locked — presentation is
 * safe to edit even when storage is not.
 *
 * @property int $id
 * @property int $entry_type_id
 * @property int $field_storage_id
 * @property string $label
 * @property bool $is_required
 * @property string|null $help_text
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $default_value
 * @property int $ordering
 * @property string|null $group
 */
#[Unscoped]
class Field extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        // The other half of EntryType's ownership guard. Nominating is
        // checked there; MOVING a nominated field to another type would slip
        // past it entirely and leave the subject pointer crossing the type
        // boundary (ADR-020).
        static::saving(function (self $field): void {
            if (! $field->exists || ! $field->isDirty('entry_type_id')) {
                return;
            }

            $nominatedBy = EntryType::query()->where('subject_field_id', $field->getKey())->first();

            if ($nominatedBy !== null) {
                throw new RuntimeException(
                    "Field [{$field->getKey()}] identifies the data subject of [{$nominatedBy->handle}] "
                    .'and cannot be moved to another entry type. Clear the nomination first — moving it '
                    .'would leave that type answering subject-access requests with another type\'s field '
                    .'(ADR-020).'
                );
            }
        });

        // Deleting is safe: the foreign key nulls the nomination, which
        // leaves the type in the visible-hole state withoutSubjectIdentifier()
        // reports rather than in a silently wrong one.
    }

    protected $casts = [
        'settings' => 'array',
        'default_value' => 'array',
        'is_required' => 'boolean',
    ];

    /** @return BelongsTo<EntryType, $this> */
    public function entryType(): BelongsTo
    {
        return $this->belongsTo(EntryType::class);
    }

    /** @return BelongsTo<FieldStorage, $this> */
    public function fieldStorage(): BelongsTo
    {
        return $this->belongsTo(FieldStorage::class);
    }
}
