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
