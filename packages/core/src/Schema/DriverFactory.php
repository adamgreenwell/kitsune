<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Schema;

use Illuminate\Database\Connection;
use Kitsune\Core\Schema\Drivers\MySqlDriver;
use Kitsune\Core\Schema\Drivers\PostgresDriver;
use Kitsune\Core\Schema\Drivers\SqliteDriver;
use RuntimeException;

final class DriverFactory
{
    /**
     * Fail closed on an unknown engine.
     *
     * Falling back to a "probably compatible" driver is how a generated column
     * silently indexes nothing. Kitsune supports three engines and says so.
     */
    public static function for(Connection $connection): SchemaDriver
    {
        return match ($driver = $connection->getDriverName()) {
            'pgsql' => new PostgresDriver,
            'mysql', 'mariadb' => new MySqlDriver,
            'sqlite' => new SqliteDriver,
            default => throw new RuntimeException(
                "Kitsune has no schema driver for [{$driver}]. Supported: pgsql, mysql, sqlite."
            ),
        };
    }
}
