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
use Illuminate\Database\Query\Builder;
use Kitsune\Core\Tenancy\Attributes\Unscoped;
use Kitsune\Core\Tenancy\Contracts\RequiresModelSave;
use Kitsune\Core\Tenancy\ScopedBuilder;
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
class Field extends Model implements RequiresModelSave
{
    protected $guarded = [];

    protected static function booted(): void
    {
        // The other half of EntryType's ownership guard. Nominating is
        // checked there; MOVING a nominated field to another type would slip
        // past it entirely and leave the subject pointer crossing the type
        // boundary (ADR-020).
        static::saving(function (self $field): void {
            // ⚠️ field_storage_id as well as entry_type_id. Watching only the
            // type let a nominated field be swapped onto DIFFERENT storage
            // without rerunning either check — onto a multi-select, which
            // makes the holes report call the type answerable while subject
            // queries silently miss, or onto another org's storage, since
            // FieldStorage is #[Unscoped].
            if (! $field->exists || ! $field->isDirty(['entry_type_id', 'field_storage_id'])) {
                return;
            }

            $nominatedBy = EntryType::query()->where('subject_field_id', $field->getKey())->first();

            if ($nominatedBy !== null) {
                throw new RuntimeException(
                    "Field [{$field->getKey()}] identifies the data subject of [{$nominatedBy->handle}] "
                    .'and cannot be moved to another entry type, or repointed at different storage. '
                    .'Clear the nomination first — either change would leave that type answering '
                    .'subject-access requests against a field nobody checked (ADR-020).'
                );
            }
        });

        // ⚠️ Ownership on EVERY save, creation included. The guard above
        // returns early for a new row, so `Field::create()` accepted ANOTHER
        // ORG'S storage id outright — FieldStorage is #[Unscoped], so nothing
        // else stopped it either.
        //
        // Not merely untidy: attaching a rival's storage row to your own
        // un-nominated type makes the holes report answer a question about
        // THEIR schema — whether they classified that field personal or
        // sensitive — and poisons your own compliance result at the same time
        // (ADR-021: org isolation has no framework safety net).
        //
        // Registered AFTER the nomination guard so that when both apply — a
        // nominated field swapped onto a rival's storage — the more specific
        // refusal is the one the caller reads.
        static::saving(fn (self $field) => $field->guardStorageOwnership());

        // Deleting is safe: the foreign key nulls the nomination, which
        // leaves the type in the visible-hole state withoutSubjectIdentifier()
        // reports rather than in a silently wrong one.
    }

    protected $casts = [
        'settings' => 'array',
        'default_value' => 'array',
        'is_required' => 'boolean',
    ];

    /**
     * Storage must belong to this field's entry type, or be global.
     *
     * A global type takes global storage only: org_id NULL means available to
     * every org, so one customer's definition would otherwise decide every
     * org's behaviour — a wider blast radius than the cross-org case, not a
     * narrower one.
     */
    private function guardStorageOwnership(): void
    {
        if (! $this->isDirty(['entry_type_id', 'field_storage_id']) && $this->exists) {
            return;
        }

        $storage = FieldStorage::query()->whereKey($this->field_storage_id)->first();
        $type = EntryType::query()->whereKey($this->entry_type_id)->first();

        if ($storage === null || $type === null) {
            // A missing row is the foreign key's job to report, not this
            // guard's — and reporting it here would mask that error.
            return;
        }

        if ($storage->org_id !== null && $storage->org_id !== $type->org_id) {
            throw new RuntimeException(
                "Field storage [{$storage->handle}] belongs to another organisation and cannot be "
                ."attached to entry type [{$type->handle}]. Storage is shared only when it is "
                .'global; borrowing a row across the boundary would expose how that organisation '
                .'classified it (ADR-021).'
            );
        }
    }

    /**
     * ⚠️ Columns whose guards can only run per row.
     *
     * Repointing a field at different storage is checked against the storage's
     * ORG and, when the field is nominated, against its shape. A bulk update
     * ran neither, so it could point a nominated field at multi-valued or
     * another org's storage.
     *
     * @return array<string, string>
     */
    public static function columnsRequiringModelSave(): array
    {
        return [
            'field_storage_id' => 'the storage must belong to this field\'s org and, when nominated, must name one person.',
            'entry_type_id' => 'moving a nominated field between types leaves the subject pointer crossing the boundary.',
        ];
    }

    /**
     * ⚠️ #[Unscoped] and still needs a builder: its guards are per row.
     *
     * @param  Builder  $query
     * @return ScopedBuilder<$this>
     */
    public function newEloquentBuilder($query): ScopedBuilder
    {
        return new ScopedBuilder($query, $this);
    }

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
