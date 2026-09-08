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
 * Field-level redactable, never an immutable blob (ADR-020).
 *
 * Erasure must reach revision history: article revision 4 still contains the
 * name just erased. Immutable revisions would make the right to erasure
 * unimplementable, which is why they were rejected.
 *
 * Unscoped because it is reached only through its Entry, which is scoped.
 *
 * @property int $id
 * @property int $entry_id
 * @property array<string, mixed>|null $values
 * @property string $status
 */
#[Unscoped]
class EntryRevision extends Model
{
    protected $guarded = [];

    protected $casts = ['values' => 'array'];

    /** @return BelongsTo<Entry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }

    /**
     * Replace a value in place rather than deleting the record.
     *
     * Returns whether it changed anything. An erasure sweep that reports
     * success without saying how many rows it reached cannot be distinguished
     * from one that silently matched nothing — see `Entry::redactField()`.
     */
    public function redact(string $key, mixed $replacement = null): bool
    {
        $values = $this->values ?? [];

        if (! array_key_exists($key, $values)) {
            return false;
        }

        $values[$key] = $replacement;
        $this->values = $values;
        $this->save();

        return true;
    }
}
