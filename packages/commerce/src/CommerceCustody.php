<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Refuse installation-wide teardown while any commerce-owned row remains.
 *
 * @internal
 *
 * ⚠️ THIS IS NOT A SCOPED BUSINESS READ. Uninstall removes the module for the installation, so a row in
 * another org, in the trash, or retained for an invoice must refuse it just as a live local row does. The
 * module owns tables whose canonical name starts with `commerce_`; inspect the current connection's
 * schemas rather than maintain a model list that the next migration could silently outgrow. Only an
 * existence flag is read, never a customer payload. A failed schema or row query propagates and refuses.
 *
 * ⚠️ DISABLE AND QUIESCE BEFORE UNINSTALLING. This check is not a fence against an already-running request
 * writing after it. The kernel refuses uninstall while enabled, but disabling does not unload a provider
 * in a process already serving a request. Stop writers and take a backup before destructive teardown.
 */
final class CommerceCustody
{
    public static function refuseKeptRows(Connection $connection): void
    {
        $schema = $connection->getSchemaBuilder();
        $connectionPrefix = $connection->getTablePrefix();
        $ownedPrefix = $connectionPrefix.'commerce_';
        $driver = $connection->getDriverName();
        $foldsTableNames = $driver === 'sqlite';

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // A case-sensitive server may host two installations whose prefixes differ only in case.
            // Its actual setting, not the driver name, decides whether those names belong to this one.
            $setting = $connection->selectFromWriteConnection('select @@lower_case_table_names as folding')[0]->folding;

            if (! in_array((string) $setting, ['0', '1', '2'], true)) {
                throw new RuntimeException('Commerce table-name semantics could not be determined.');
            }

            $foldsTableNames = (string) $setting !== '0';

            // Identifier folding is not Unicode lowercasing: measured on MariaDB, İ becomes i but ẞ
            // stays ẞ. PHP and SQL LOWER each disagree with it. Refuse ambiguity before teardown rather
            // than silently skip a retained table. ASCII prefixes have identical byte-length folding.
            if ($foldsTableNames && preg_match('/[^\x00-\x7F]/', $connectionPrefix) === 1) {
                throw new RuntimeException(
                    'Commerce cannot safely inspect a non-ASCII table prefix on a server that folds table names. '
                    .'Teardown refused; its data and receipt are retained.',
                );
            }
        }

        /*
         * PostgreSQL's schema builder reads configured search_path, defaulting to public. A role or the
         * session can have a different effective path: a normal query then reads rows this check would miss.
         * Ask the WRITE session for every visible schema, not all schemas in the database (which could belong
         * to a different installation). The vendor has no method that asks the session this question.
         */
        $schemas = $driver === 'pgsql'
            ? array_map(
                static fn (object $row): string => $row->name,
                $connection->selectFromWriteConnection('select unnest(current_schemas(false)) as name'),
            )
            : $schema->getCurrentSchemaListing();
        $tables = $schema->getTables($schemas);

        foreach ($tables as $table) {
            // SQLite folds ASCII names; MySQL/MariaDB fold them only under the server setting above.
            $nameToCompare = $foldsTableNames ? strtolower($table['name']) : $table['name'];
            $prefixToCompare = $foldsTableNames ? strtolower($ownedPrefix) : $ownedPrefix;

            if (! str_starts_with($nameToCompare, $prefixToCompare)) {
                continue;
            }

            // The builder adds its configured prefix itself, including after a schema qualifier.
            $name = substr($table['name'], strlen($connectionPrefix));
            $qualified = $table['schema'] === null ? $name : $table['schema'].'.'.$name;

            if ($connection->table($qualified)->useWritePdo()->exists()) {
                throw new RuntimeException(
                    'kitsune/commerce still holds data. Export and resolve its retained records before uninstalling; '
                    .'disabling commerce keeps its schema and data.',
                );
            }
        }
    }
}
