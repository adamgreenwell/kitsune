<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Content;

use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Kitsune\Core\Database\EntryWriteReads;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Http\Api\EntryFieldContract;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRelation;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Relations\EntryRelationResolver;

/** @internal Candidate for comparing the live draft and its ordered links with an approved plan. */
final readonly class SiteDraftSnapshot
{
    public function __construct(private SiteDraftAccess $access, private EntryRelationResolver $relations) {}

    /**
     * @param  list<string>|null  $relationHandles  Null includes all attached relation fields.
     * @return array{id:int, title:?string, slug:?string, status:string, values:?array<string,mixed>, revision:?int, relations:array<string,list<int|array{group:string}>>}
     */
    public function read(
        int $siteId,
        string $typeHandle,
        Authenticatable $actor,
        int $entryId,
        ?array $relationHandles = null,
    ): array {
        $current = $this->access->activeActor($actor);
        $site = $this->access->site($siteId);
        $connection = (new Entry)->getConnection();

        return TransactionRecovery::run($connection, fn (): array => app(EntryWriteReads::class)->run(
            $connection,
            function () use ($site, $typeHandle, $current, $entryId, $relationHandles): array {
                $type = $this->access->type($site, $typeHandle, $current);
                $entry = $this->access->writeTarget($site->id, $type, $current, $entryId, lock: true);
                if ($entry === null) {
                    throw new DomainException('The selected draft is unavailable.');
                }

                $storages = [];
                foreach (EntryFieldContract::forType($type)->definitions() as $handle => $field) {
                    if ($field->storage->strategy() === StorageStrategy::Relational) {
                        $storages[$handle] = $field->storage;
                    }
                }
                if ($relationHandles !== null) {
                    $selected = array_fill_keys($relationHandles, true);
                    if (array_diff_key($selected, $storages) !== []) {
                        throw new DomainException('The selected draft relation is unavailable.');
                    }
                    $storages = array_intersect_key($storages, $selected);
                }

                // Read the live draft under the writer's source lock. An old
                // revision can predate a retained draft or an unrecorded edit.
                return [
                    'id' => (int) $entry->getKey(), 'title' => $entry->title, 'slug' => $entry->slug,
                    'status' => $entry->status, 'values' => $entry->values,
                    'revision' => $this->access->revision($entry),
                    'relations' => $this->orderedRelations($entry, $site, $current, $storages),
                ];
            },
        ));
    }

    /**
     * @param  array<string,FieldStorage>  $storages
     * @return array<string,list<int|array{group:string}>>
     */
    private function orderedRelations(Entry $entry, Site $site, Authenticatable $actor, array $storages): array
    {
        $countByField = EntryWriteReads::query((new EntryRelation)->setConnection($entry->getConnectionName())->newQuery())
            ->where('org_id', $site->org_id)->where('source_entry_id', $entry->id)
            ->whereIn('field_storage_id', array_map(static fn (FieldStorage $storage): int => $storage->id, $storages))
            ->pluck('field_storage_id')->countBy();
        $resolved = $this->relations->resolveMany(
            [$entry], $storages, false, $this->access->relationReader($site, $actor),
        )[$entry->id] ?? [];
        $links = [];
        foreach ($storages as $handle => $storage) {
            $rows = $resolved[$storage->id] ?? [];
            // An incomplete digest would hide changes to an inaccessible relation.
            if (count($rows) !== ($countByField->get($storage->id) ?? 0)) {
                throw new DomainException('The selected draft relation is unavailable.');
            }

            $links[$handle] = array_map(
                static fn (array $row): int|array => $row['group'] === null ? $row['entry']->id : ['group' => $row['group']],
                $rows,
            );
        }

        return $links;
    }
}
