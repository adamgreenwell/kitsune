<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Schema;

use Illuminate\Database\Connection;

/** Fresh column-name checks, without PostgreSQL's unused type/default/comment projection. @internal */
final class RequiredColumns
{
    /** @param list<string> $columns */
    public static function present(Connection $connection, string $table, array $columns): bool
    {
        // Other drivers retain the framework's column-only contract, including views.
        if ($connection->getDriverName() !== 'pgsql') {
            return $connection->getSchemaBuilder()->hasColumns($table, $columns);
        }

        return self::inspect($connection, [$table => $columns])[$table]['columns'];
    }

    /** One fresh metadata snapshot for a related set, never retained across calls or host callbacks.
     *
     * @param  array<string,list<string>>  $tables
     * @return array<string,array{table:bool,columns:bool}>
     */
    public static function inspect(Connection $connection, array $tables): array
    {
        if ($tables === []) {
            return [];
        }
        $schema = $connection->getSchemaBuilder();
        if ($connection->getDriverName() !== 'pgsql') {
            $result = [];
            foreach ($tables as $table => $columns) {
                $result[$table] = ['table' => $schema->hasTable($table), 'columns' => $schema->hasColumns($table, $columns)];
            }

            return $result;
        }
        $values = [];
        $bindings = [];
        $keys = array_keys($tables);
        foreach ($keys as $index => $table) {
            [$namespace, $name] = $schema->parseSchemaAndTable($table);
            $values[] = '(cast(? as integer), cast(? as text), cast(? as text))';
            array_push($bindings, $index, $namespace, $connection->getTablePrefix().$name);
        }
        // Match the framework's schema/prefix resolution, table kinds and case-insensitive column comparison.
        // Only the generated placeholder list is SQL; all identifiers remain bound values.
        $rows = $connection->selectFromWriteConnection(
            'select wanted.position, c.relkind, a.attname from (values '.implode(', ', $values).') '
            .'as wanted(position, schema_name, table_name) '
            .'left join pg_catalog.pg_namespace n on n.nspname = coalesce(wanted.schema_name, current_schema()) '
            .'left join pg_catalog.pg_class c on c.relnamespace = n.oid and c.relname = wanted.table_name '
            .'left join pg_catalog.pg_attribute a on a.attrelid = c.oid and a.attnum > 0 and not a.attisdropped '
            .'order by wanted.position, a.attnum',
            $bindings,
        );
        $result = [];
        $names = [];
        foreach ($keys as $table) {
            $result[$table] = ['table' => false, 'columns' => false];
            $names[$table] = [];
        }
        foreach ($rows as $row) {
            $table = $keys[(int) $row->position];
            $result[$table]['table'] = in_array($row->relkind, ['r', 'p'], true);
            if ($row->attname !== null) {
                $names[$table][] = strtolower($row->attname);
            }
        }
        foreach ($tables as $table => $columns) {
            $result[$table]['columns'] = array_all($columns, static fn (string $column): bool => in_array(strtolower($column), $names[$table]));
        }

        return $result;
    }
}
