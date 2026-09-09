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
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Tenancy\Attributes\Unscoped;
use Kitsune\Core\Tenancy\Contracts\RefusesCascadingDeletes;
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
class Field extends Model implements RefusesCascadingDeletes, RequiresModelSave
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

        // ⚠️ Deleting is safe for the NOMINATION and not for the DATA, and this
        // comment used to claim it was safe outright.
        //
        // The foreign key nulls the nomination, which leaves the type in the
        // visible-hole state `withoutSubjectIdentifier()` reports rather than in
        // a silently wrong one — that part still holds. But the values do not go
        // anywhere: inline JSON keys and `entry_relations` rows survive against
        // the FieldStorage row, which is shared and stays.
        //
        // And they become UNREACHABLE. `Entry::redactField()` resolves storage
        // through `whereHas('fields')` on this entry type, so once the field row
        // is gone the lookup finds nothing, falls through to the inline path, and
        // reports 0 while a relation — possibly holding personal data — survives.
        // An erasure request would be answered successfully and truthfully
        // report that it reached nothing (ADR-020).
        // ⚠️ Kept alongside the CONTRACT, not instead of it.
        //
        // A `deleting` event is one path. `Field::query()->delete()`,
        // `deleteQuietly()` and anything inside `withoutEvents()` dispatch
        // straight past it — and this project has now found that shape seven
        // times. Implementing `RefusesCascadingDeletes` makes `ScopedBuilder`
        // run the same rule for every row a bulk delete would remove, under a
        // lock, so an importer or cleanup command cannot orphan the data either.
        //
        // The event still earns its place: it is what refuses an ordinary
        // `$field->delete()` before a transaction is opened.
        static::deleting(fn (self $field) => $field->guardCascade());
    }

    /**
     * Refuse to detach a field while its entry type still holds data for it.
     *
     * Refused rather than cascaded: deleting the values is an erasure and has to
     * be audited as one, and deleting them from here would be an unaudited bulk
     * removal — the same reason `EntryType::guardCascade()` refuses rather than
     * letting the database cascade.
     *
     * ⚠️ All THREE storage strategies. Checking `values` alone would let a
     * promoted or relational field be detached while holding content, which is
     * the "handled two of the three" omission this project has now hit three
     * times (the subject identifier, the erasure sweep, and revisions).
     *
     * ⚠️ Soft-deleted entries count. Their data still exists, and erasure has to
     * reach it — so a trashed entry holding a value is a reason to refuse.
     *
     * Named `guardCascade()` because that is the contract `ScopedBuilder` calls,
     * and public for the same reason. "Cascade" is right even though no foreign
     * key fires here: the data is not deleted, it is stranded — which is worse,
     * because a cascade at least leaves nothing behind to be missed.
     */
    public function guardCascade(): void
    {
        $storage = $this->fieldStorage;
        $type = $this->entryType;

        if ($storage === null || $type === null) {
            return;
        }

        $holding = Entry::withoutScopeBecause(
            'counting entries that hold data for a field before it is detached, to refuse rather than orphan',
            // Unhinted, like `EntryType::guardCascade()`: this receives an
            // ELOQUENT builder for a soft-deleting model, and `Builder` in this
            // file is the QUERY builder — the one `newEloquentBuilder()` takes.
            // Importing the Eloquent one to hint it here silently changed what
            // that docblock resolved to, which is the trap Pint set once before.
            function ($query) use ($storage, $type): int {
                // A type spans every site in its org, so the count must too.
                $query->withTrashed()->where('entry_type_id', $type->getKey());

                return match ($storage->strategy()) {
                    // A correlated subquery rather than materialising every id:
                    // a type can hold a hundred thousand entries, and this runs
                    // on the delete path.
                    StorageStrategy::Relational => $query
                        ->whereExists(
                            fn (Builder $sub) => $sub->from('entry_relations')
                                ->whereColumn('entry_relations.source_entry_id', 'entries.id')
                                ->where('entry_relations.field_storage_id', $storage->getKey()),
                        )
                        ->count(),
                    // The live promoted column. History is counted separately,
                    // below, for the reason stated there.
                    StorageStrategy::Promoted => $query
                        ->whereNotNull((string) $storage->promotedColumn())
                        ->count(),
                    // The live JSON key. History is counted separately, below.
                    StorageStrategy::Inline => $query
                        ->whereNotNull('values->'.$storage->handle)
                        ->count(),
                };
            },
        );

        // ⚠️ HISTORY is counted on its own terms, not through the entry's CURRENT
        // type — and nesting it under that predicate was a hole.
        //
        // `entries.entry_type_id` is mutable. An entry moved from type A to type B
        // keeps its A-era revisions, and those revisions are the only remaining
        // record of the A field's values. Asking "does any entry of type A have a
        // revision holding this?" finds none of them, because no entry is type A
        // any more — so deleting A's field was permitted, and `redactField()` then
        // resolves storage through the entry's current B schema and cannot see the
        // field at all. The historical values are stranded and uneraseable, which
        // is the precise failure ADR-020 exists to prevent.
        //
        // `entry_revisions.entry_type_id` records the schema each snapshot was
        // written against, so it answers the question directly and needs no join
        // to `entries` at all.
        $recorded = EntryRevision::query()
            ->where('entry_type_id', $type->getKey())
            ->where(match ($storage->strategy()) {
                StorageStrategy::Relational => fn ($revisions) => $revisions
                    ->whereNotNull('relation_state->'.$storage->getKey()),
                StorageStrategy::Promoted => fn ($revisions) => $revisions
                    ->whereNotNull((string) $storage->promotedColumn()),
                StorageStrategy::Inline => fn ($revisions) => $revisions
                    ->whereNotNull('values->'.$storage->handle),
            })
            ->count();

        if ($holding === 0 && $recorded === 0) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Field [%s] cannot be removed from [%s] while %d entr%s and %d revision%s still hold '
            .'data for it. The values would survive against the shared storage row and become '
            .'unreachable — `redactField()` resolves storage through this type\'s fields, so an '
            .'erasure request would report success having found nothing (ADR-020). Erase the field '
            .'first, which is audited, then remove it.',
            $storage->handle,
            $type->handle,
            $holding,
            $holding === 1 ? 'y' : 'ies',
            $recorded,
            $recorded === 1 ? '' : 's',
        ));
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
