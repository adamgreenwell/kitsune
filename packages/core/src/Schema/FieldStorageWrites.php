<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Schema;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Collection;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use RuntimeException;

/** The storage definitions held by an already-authorized data write (ADR-006). @internal */
final class FieldStorageWrites
{
    /**
     * The entry rows, when they exist, are locked by the caller first. Fields and storage are then
     * read currently and in key order, on that same connection. These reads supply no authority.
     *
     * @param  list<int|string>  $types
     * @return array<int, Collection<int, Field>>
     */
    public static function lockTypes(Connection $connection, array $types): array
    {
        if ($connection->transactionLevel() < 1) {
            throw new RuntimeException('Field storage writes require an open transaction.');
        }

        if ($types === []) {
            return [];
        }

        $fields = (new Field)->setConnection($connection->getName())->newQuery()
            ->useWritePdo()->whereIn('entry_type_id', array_unique($types))->orderBy('id')->lockForUpdate()->get();
        $storages = (new FieldStorage)->setConnection($connection->getName())->newQuery()
            ->useWritePdo()->whereKey($fields->pluck('field_storage_id')->unique()->all())
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $byType = [];
        foreach ($fields as $field) {
            $field->setRelation('fieldStorage', $storages->get($field->field_storage_id));
            $byType[(int) $field->entry_type_id] ??= new Collection;
            $byType[(int) $field->entry_type_id]->push($field);
        }

        return $byType;
    }

    /**
     * @param  list<int|string>  $entries
     * @param  array<int, Collection<int, Field>>  $fields
     */
    public static function armEntries(Connection $connection, array $entries, array $fields): void
    {
        if ($entries === [] || $fields === []) {
            return;
        }

        // Keys come from the writer's frozen set, including rows just trashed by that same write.
        $rows = (new Entry)->setConnection($connection->getName())->newQueryWithoutScopes()
            ->useWritePdo()->whereKey($entries)->orderBy('id')->lockForUpdate()->get();
        foreach ($rows as $entry) {
            $entry->armFieldStorageForWrite($fields[(int) $entry->entry_type_id] ?? new Collection);
        }
    }

    public static function lockRelation(Connection $connection, ?int $storage): void
    {
        if ($connection->transactionLevel() < 1) {
            throw new RuntimeException('Field storage writes require an open transaction.');
        }
        if ($storage !== null) {
            (new FieldStorage)->setConnection($connection->getName())->newQuery()
                ->useWritePdo()->whereKey($storage)->lockForUpdate()->first();
        }
    }
}
