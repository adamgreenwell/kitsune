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
 * @property array<string, list<int>>|null $relation_state
 * @property int $entry_type_id
 * @property string|null $title
 * @property string|null $slug
 */
#[Unscoped]
class EntryRevision extends Model
{
    /**
     * The entry columns erasure may rewrite on a revision.
     *
     * ⚠️ A SUBSET of what is snapshotted, and separate on purpose. These are
     * the columns a promoted field can project to, so they are the only ones a
     * redaction has any business touching — `entry_type_id` is snapshotted and
     * must never be erasable, since blanking a discriminator turns a revision
     * into values with no schema.
     *
     * Keeping one list for both jobs is what allowed the last erasure defect:
     * `SNAPSHOT_ATTRIBUTES` was doubling as an oracle for which storage strategy
     * a field used, and an inline field handled `status` was erased as a column.
     *
     * @var list<string>
     */
    public const REDACTABLE_COLUMNS = ['title', 'slug', 'status', 'published_at'];

    /**
     * The entry columns a revision snapshots alongside `values`.
     *
     * A revision holding only `values` restores an entry with no title, which
     * is worse than having no revisions at all. These are also what erasure
     * has to sweep for a promoted field.
     *
     * ⚠️ `entry_type_id` is here because `values` MEAN NOTHING without it. The
     * column on `entries` is mutable and the model supports changing it, so a
     * revision recording values without the schema they were written against
     * cannot be restored safely — and a type change filed no version at all
     * while this list omitted it.
     *
     * @var list<string>
     */
    public const SNAPSHOT_ATTRIBUTES = [...self::REDACTABLE_COLUMNS, 'entry_type_id'];

    protected $guarded = [];

    protected $casts = [
        'values' => 'array',
        'relation_state' => 'array',
        'published_at' => 'datetime',
    ];

    /** @return BelongsTo<Entry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }

    /**
     * Erase a PROMOTED column on this revision.
     *
     * ⚠️ Separate from `redactValue()` on purpose, and it used to be one
     * method that guessed which it was by testing the key against
     * `SNAPSHOT_ATTRIBUTES`.
     *
     * That guess was unsound, because nothing reserves those names for
     * promoted fields: `FieldStorage::guardHandle()` checks length and
     * snake_case and no more, so an INLINE field may legitimately be handled
     * `status`, `title`, `slug` or `published_at`. Erasing one then wrote NULL
     * into the revision's promoted column instead of its `values` key — and on
     * `status`, which is NOT NULL, the write threw. The live entry had already
     * been erased by that point, so the result was an erasure that cleared the
     * current row, left every revision holding the personal data, and raised a
     * QueryException instead of returning a count. Retrying threw in the same
     * place, so the history could never be erased through this path at all.
     *
     * `Entry::redactStorage()` already knows the storage strategy; it dispatches
     * on it correctly and then handed a bare string to a method that guessed
     * again. The caller states the intent now, and `SNAPSHOT_ATTRIBUTES` is
     * back to naming what a revision snapshots rather than doubling as an
     * oracle for which strategy a field uses.
     *
     * Returns whether it changed anything. An erasure sweep that reports
     * success without saying how many rows it reached cannot be distinguished
     * from one that silently matched nothing — see `Entry::redactField()`.
     */
    public function redactColumn(string $column, mixed $replacement = null): bool
    {
        // Fail closed on a column a revision does not snapshot: Eloquent would
        // happily set an unknown attribute and then fail at the database, or
        // worse, succeed against a column erasure has no business writing.
        if (! in_array($column, self::REDACTABLE_COLUMNS, true)) {
            throw new RuntimeException(sprintf(
                'A revision has no erasable column [%s], so there is nothing here to redact. '
                .'Erasable columns are: %s. If this is an inline field, use redactValue().',
                $column,
                implode(', ', self::REDACTABLE_COLUMNS),
            ));
        }

        if ($this->getAttribute($column) === $replacement) {
            return false;
        }

        $this->setAttribute($column, $replacement);
        $this->save();

        return true;
    }

    /**
     * Erase an INLINE field's key inside this revision's `values`.
     *
     * Never touches a column, however the key is spelled — see the warning on
     * `redactColumn()` for what happened when one method decided between them
     * by inspecting the name.
     */
    public function redactValue(string $key, mixed $replacement = null): bool
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
