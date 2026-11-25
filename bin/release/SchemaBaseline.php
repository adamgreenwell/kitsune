<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Release;

use Illuminate\Database\Connection;

/** Check the historical in-place edits before Laravel skips the recorded originals. */
final class SchemaBaseline
{
    private const ORIGINALS = [
        '0001_01_01_000000_create_kitsune_tenancy_tables' => ['orgs', 'site_groups', 'sites'],
        '0001_01_01_000001_create_kitsune_schema_tables' => [
            'entry_types', 'field_storage', 'fields', 'entries', 'entry_relations', 'entry_revisions', 'entry_type_availability',
        ],
        '0001_01_01_000002_create_kitsune_audit_tables' => ['audit_log', 'erasure_log'],
    ];

    private const COLUMNS = [
        'sites' => ['slug', 'canonical_host', 'path_prefix'],
        'entry_types' => ['subject_field_id'],
        'entries' => ['promoted_by'],
        'entry_revisions' => ['entry_type_id', 'title', 'slug', 'published_at', 'relation_state', 'unsanitized_values'],
        'audit_log' => ['actor_type', 'actor_id', 'target_id'],
    ];

    private const INDEXES = [
        'sites' => [[['slug'], true], [['canonical_host', 'path_prefix'], true]],
        'entries' => [[['site_id', 'entry_type_id', 'updated_at'], false], [['site_id', 'updated_at'], false]],
        'entry_revisions' => [[['entry_id', 'id'], false]],
        'audit_log' => [[['org_id', 'actor_type', 'actor_id'], false]],
    ];

    /**
     * @param  list<string>  $recorded
     * @return list<string>
     */
    public static function refusals(Connection $connection, array $recorded): array
    {
        $schema = $connection->getSchemaBuilder();
        $ledger = array_intersect_key(array_count_values($recorded), self::ORIGINALS);
        $tables = array_merge(...array_values(self::ORIGINALS));

        if ($ledger === []) {
            foreach ($tables as $table) {
                if ($schema->hasTable($table)) {
                    return ["{$table} exists without the original Kitsune migration records"];
                }
            }

            return [];
        }

        if (count($ledger) !== count(self::ORIGINALS) || array_any($ledger, fn (int $count): bool => $count !== 1)) {
            return ['the original Kitsune migration records are incomplete or duplicated'];
        }

        $refusals = [];
        foreach ($tables as $table) {
            if (! $schema->hasTable($table)) {
                $refusals[] = "{$table} is missing although the original migrations are recorded";
            }
        }

        if ($refusals !== []) {
            return $refusals;
        }

        foreach (self::COLUMNS as $table => $required) {
            $columns = array_column($schema->getColumns($table), null, 'name');
            foreach ($required as $name) {
                if (! isset($columns[$name])) {
                    $refusals[] = "{$table}.{$name} is missing";

                    continue;
                }

                if ($table === 'entry_revisions' && $name === 'entry_type_id' && $columns[$name]['nullable']) {
                    $refusals[] = 'entry_revisions.entry_type_id is nullable';
                }

                if ($table === 'audit_log' && ! in_array($columns[$name]['type_name'], ['varchar', 'character varying'], true)) {
                    $refusals[] = "{$table}.{$name} is not a string column";
                }
            }
        }

        foreach (self::INDEXES as $table => $required) {
            $indexes = $schema->getIndexes($table);
            foreach ($required as [$columns, $unique]) {
                if (! array_any($indexes, fn (array $index): bool => $index['columns'] === $columns && $index['unique'] === $unique)) {
                    $refusals[] = "{$table} is missing the ".($unique ? 'unique ' : '').'index on '.implode(', ', $columns);
                }
            }
        }

        $revisionKeys = $schema->getForeignKeys('entry_revisions');
        $typeTable = $connection->getTablePrefix().'entry_types';
        if (! array_any($revisionKeys, fn (array $key): bool => $key['columns'] === ['entry_type_id']
            && $key['foreign_table'] === $typeTable && $key['foreign_columns'] === ['id'] && $key['on_delete'] === 'cascade')) {
            $refusals[] = 'entry_revisions.entry_type_id is missing its cascading foreign key';
        }

        return $refusals;
    }
}
