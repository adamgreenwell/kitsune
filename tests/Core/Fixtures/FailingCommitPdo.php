<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Illuminate\Database\Connection;
use PDO;
use Pdo\Sqlite;
use PDOException;

/**
 * A SQLite connection whose next COMMIT fails — before it applies, or after — as a busy database fails one.
 *
 * ⚠️ BOTH HALVES, BECAUSE THE CLIENT CANNOT TELL THEM APART. `before` rolls back and throws; `after` commits and throws
 * the same error, which is the ambiguous commit: the application sees a failure for a write that landed. Laravel treats
 * `database is locked` as a concurrency error and resets its level without rolling back
 * (`ManagesTransactions::handleCommitTransactionException`), which is the state custody must survive.
 */
class FailingCommitPdo extends Sqlite
{
    /** One-shot: 'before', 'after', or null. */
    public ?string $failNextCommit = null;

    /** Put one on this connection, reading the file it uses, with the pragmas Laravel's connector sets. */
    public static function installOn(Connection $connection, string $file): self
    {
        $pdo = new self('sqlite:'.$file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        $connection->setPdo($pdo);
        $connection->setReadPdo($pdo);

        return $pdo;
    }

    public function commit(): bool
    {
        $mode = $this->failNextCommit;
        $this->failNextCommit = null;

        if ($mode === 'before') {
            parent::rollBack();

            throw new PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
        }

        if ($mode === 'after') {
            parent::commit();

            throw new PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
        }

        return parent::commit();
    }
}
