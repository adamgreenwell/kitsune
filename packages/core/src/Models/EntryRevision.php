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
 * @property string|null $title
 * @property string|null $slug
 */
#[Unscoped]
class EntryRevision extends Model
{
    /**
     * The entry columns a revision snapshots alongside `values`.
     *
     * A revision holding only `values` restores an entry with no title, which
     * is worse than having no revisions at all. These are also what erasure
     * has to sweep for a promoted field.
     */
    public const SNAPSHOT_ATTRIBUTES = ['title', 'slug', 'status', 'published_at'];

    protected $guarded = [];

    protected $casts = [
        'values' => 'array',
        'published_at' => 'datetime',
    ];

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
     *
     * Handles a promoted column as well as a key in `values`: a `slug` field
     * lives in its own column here too, and sweeping only the JSON left it
     * behind while reporting success.
     */
    public function redact(string $key, mixed $replacement = null): bool
    {
        if (in_array($key, self::SNAPSHOT_ATTRIBUTES, true)) {
            if ($this->getAttribute($key) === $replacement) {
                return false;
            }

            $this->setAttribute($key, $replacement);
            $this->save();

            return true;
        }

        $values = $this->values ?? [];

        if (! array_key_exists($key, $values)) {
            return false;
        }

        $values[$key] = $replacement;
        $this->values = $values;
        $this->save();

        return true;
    }

    /**
     * The state this revision holds, ready to write back onto an entry.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            ...array_combine(
                self::SNAPSHOT_ATTRIBUTES,
                array_map(fn (string $key): mixed => $this->getAttribute($key), self::SNAPSHOT_ATTRIBUTES),
            ),
            'values' => $this->values,
        ];
    }
}
