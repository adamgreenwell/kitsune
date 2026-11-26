<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Relations;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRelation;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Tenancy\Context;

/** Resolves authored references under the reading Site and the caller's entry access policy. */
final class EntryRelationResolver
{
    /**
     * @param  Closure(Entry): bool  $canRead  The caller's ordinary entry access policy, including type availability.
     * @return list<array{entry: Entry, group: string|null, link: int}>
     */
    public function resolve(Entry $source, FieldStorage $storage, bool $publishedOnly, Closure $canRead): array
    {
        return $this->resolveMany([$source], [$storage], $publishedOnly, $canRead)[(int) $source->getKeyForAuthorization()][(int) $storage->getRawOriginal($storage->getKeyName())] ?? [];
    }

    /**
     * @param  iterable<Entry>  $sources
     * @param  iterable<FieldStorage>  $storages
     * @param  Closure(Entry): bool  $canRead
     * @return array<int, array<int, list<array{entry: Entry, group: string|null, link: int}>>>
     */
    public function resolveMany(iterable $sources, iterable $storages, bool $publishedOnly, Closure $canRead): array
    {
        $context = app(Context::class);
        $orgId = $context->orgId();
        $siteId = $context->siteId();

        if ($orgId === null || $siteId === null) {
            return [];
        }

        $prototype = null;
        $sourceIds = [];
        foreach ($sources as $source) {
            $prototype ??= $source;
            $key = $source->getKeyForAuthorization();
            if ($source->exists && $key !== null && $source->getConnection() === $prototype->getConnection()) {
                $sourceIds[] = (int) $key;
            }
        }
        $storageIds = [];
        foreach ($storages as $storage) {
            if ($storage->exists && $prototype !== null && $storage->getConnection() === $prototype->getConnection()) {
                $storageIds[] = (int) $storage->getRawOriginal($storage->getKeyName());
            }
        }

        if ($prototype === null || $sourceIds === [] || $storageIds === []) {
            return [];
        }

        $entries = $prototype->newQuery()->where('entries.org_id', $orgId)
            ->when($publishedOnly, fn (Builder $query) => $query->published());
        $readableSources = (clone $entries)->whereKey($sourceIds)->get()->filter($canRead)->keyBy('id');

        if ($readableSources->isEmpty()) {
            return [];
        }

        $connection = $prototype->getConnectionName();
        $fields = (new Field)->setConnection($connection)->newQuery()->with('fieldStorage')
            ->whereIntegerInRaw('entry_type_id', $readableSources->pluck('entry_type_id')->unique()->all())
            ->whereIntegerInRaw('field_storage_id', $storageIds)->get();

        $definitions = [];
        foreach ($fields as $field) {
            $definition = $field->fieldStorage;
            if ($definition !== null && ($definition->org_id === null || $definition->org_id === $orgId)
                && $definition->strategy() === StorageStrategy::Relational) {
                $definitions[$field->entry_type_id][$definition->id] = $definition;
            }
        }

        $links = (new EntryRelation)->setConnection($connection)->newQuery()
            ->where('org_id', $orgId)->whereIntegerInRaw('source_entry_id', $readableSources->modelKeys())
            ->whereIntegerInRaw('field_storage_id', $storageIds)
            ->orderBy('ordering')->orderBy('id')->get()
            ->filter(fn (EntryRelation $link): bool => isset($definitions[$readableSources[$link->source_entry_id]->entry_type_id][$link->field_storage_id]));

        if ($links->isEmpty()) {
            return [];
        }

        $pins = $links->pluck('target_entry_id')->filter()->unique()->all();
        $groupIds = $links->pluck('target_group_id')->filter()->unique()->all();
        $pinned = $pins === [] ? collect() : (clone $entries)->whereKey($pins)->get()->filter($canRead)->keyBy('id');
        $members = $groupIds === [] ? collect() : $entries
            ->join('entry_translation_groups', function (JoinClause $join): void {
                $join->on('entry_translation_groups.org_id', '=', 'entries.org_id')
                    ->on('entry_translation_groups.uuid', '=', 'entries.translation_group');
            })
            ->where('entries.site_id', $siteId)
            ->whereIntegerInRaw('entry_translation_groups.id', $groupIds)
            ->get(['entries.*', 'entry_translation_groups.id as relation_group_id', 'entry_translation_groups.uuid as relation_group_uuid'])
            ->filter($canRead)->keyBy('relation_group_id');

        $resolved = [];
        foreach ($links as $link) {
            $entry = $link->target_group_id === null ? $pinned->get($link->target_entry_id) : $members->get($link->target_group_id);
            $definition = $definitions[$readableSources[$link->source_entry_id]->entry_type_id][$link->field_storage_id];
            $types = (array) ($definition->settings['targetTypes'] ?? []);

            if ($entry !== null && ($types === [] || in_array($entry->type_handle, $types, true))) {
                $resolved[$link->source_entry_id][$definition->id][] = [
                    'entry' => $entry,
                    'link' => (int) $link->id,
                    'group' => $link->target_group_id === null ? null : (string) $entry->getAttribute('relation_group_uuid'),
                ];
            }
        }

        return $resolved;
    }
}
