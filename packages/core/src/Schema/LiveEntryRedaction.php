<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Schema;

use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRelation;
use Kitsune\Core\Models\EntryRevision;

/** @internal Redacts live content only while its exact revision and captured classification still apply. */
final class LiveEntryRedaction
{
    public function hasVersion(Entry $entry, EntryRevision $revision): bool
    {
        $captured = $revision->subject_schema;
        $current = RevisionSubject::capture($entry);
        if ($captured === null || $current === null || (int) $entry->entry_type_id !== (int) $revision->entry_type_id
            || (int) $entry->revisions()->latest('id')->value('id') !== $revision->id) {
            return false;
        }

        unset($captured['witness'], $current['witness']);

        return $captured === $current;
    }

    public function agrees(Entry $entry, EntryRevision $revision, string $strategy, string $key): bool
    {
        $historical = match ($strategy) {
            StorageStrategy::Inline->value => $revision->values[$key] ?? null,
            StorageStrategy::Promoted->value => $revision->getRawOriginal($key),
            StorageStrategy::Relational->value => $revision->relation_state[$key] ?? [],
            default => throw new RevisionErasureRefused('The live representation cannot be verified.'),
        };

        return $this->value($entry, $strategy, $key) === $historical;
    }

    public function redact(Entry $entry, string $strategy, string $key): bool
    {
        if (! $this->retainsValue($entry, $strategy, $key)) {
            return false;
        }

        $entry->withoutRevisions(function (Entry $entry) use ($strategy, $key): void {
            if ($strategy === StorageStrategy::Relational->value) {
                EntryRelation::query()->where('org_id', $entry->org_id)
                    ->where('source_entry_id', $entry->id)->where('field_storage_id', $key)->delete();

                return;
            }

            if ($strategy === StorageStrategy::Inline->value) {
                $entry->values = [...($entry->values ?? []), $key => null];
            } elseif ($strategy === StorageStrategy::Promoted->value && $key !== 'status' && in_array($key, EntryRevision::REDACTABLE_COLUMNS, true)) {
                $entry->setAttribute($key, null);
            } else {
                throw new RevisionErasureRefused('The live field needs an explicit erasure policy.');
            }

            $entry->save();
        });

        $entry->refresh();
        if ($this->retainsValue($entry, $strategy, $key)) {
            throw new RevisionErasureRefused('The live entry retained data after the erasure write.');
        }

        return true;
    }

    public function retainsValue(Entry $entry, string $strategy, string $key): bool
    {
        $value = $this->value($entry, $strategy, $key);

        return $strategy === StorageStrategy::Relational->value ? $value !== [] : $value !== null;
    }

    private function value(Entry $entry, string $strategy, string $key): mixed
    {
        return match ($strategy) {
            StorageStrategy::Inline->value => $entry->values[$key] ?? null,
            StorageStrategy::Promoted->value => $entry->getRawOriginal($key),
            StorageStrategy::Relational->value => $entry->relationState()[$key] ?? [],
            default => throw new RevisionErasureRefused('The live representation cannot be verified.'),
        };
    }
}
