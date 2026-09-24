<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Database;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Events\TransactionRolledBack;
use RuntimeException;
use Throwable;

/**
 * A transaction that fails never leaves the engine, Laravel's level or the transaction manager behind — ADR-042
 * decision 5.
 *
 * @internal
 *
 * ⚠️ THREE STATES LARAVEL LEAVES, EACH OF WHICH A LATER WRITE WOULD TRUST.
 *
 * - **A failed outermost COMMIT.** Laravel sets its level to 0 and, for a concurrency error such as SQLite's `database
 *   is locked`, neither rolls the engine back nor tells the transaction manager — so the connection stays inside a
 *   transaction every later BEGIN refuses, and the manager's stale record is staged by the next commit, running
 *   `afterCommit()` work registered for a write that never landed. Here the engine is rolled back (the connection is
 *   dropped if even that fails), the stale record is rolled back in the manager, and `TransactionRolledBack` fires.
 * - **A nested concurrency error.** Laravel decrements and throws `DeadlockException` without `ROLLBACK TO`, so a
 *   lock-wait timeout on MySQL or MariaDB leaves the savepoint's own writes in the host's transaction — committed with
 *   it if the host catches the exception. Here the call's own savepoint is rolled back.
 * - **A transaction the database already ended.** A MySQL deadlock, or a lock-wait timeout with
 *   `innodb_rollback_on_timeout`, ends the whole transaction under a nested call; Laravel's level stays where the host
 *   left it, and every later write in the host's closure would run in autocommit — committing rows before any byte they
 *   depend on has moved. A nested call here is refused unless the engine confirms, before and after its savepoint, that
 *   a transaction is open.
 *
 * ⚠️ WHAT IT CANNOT REACH, stated so it is not mistaken for more. Writes a host made EARLIER in a transaction the database
 * ended are committed children of the host's record, which Laravel's rollback never visits; and a SQLite automatic
 * rollback inside a nested write leaves Laravel's level one too high. Both can leave only the residue ADR-042 keeps — a
 * live file whose one copy is on the private disk — and never a file on the web under a trashed entry.
 */
final class TransactionRecovery
{
    /**
     * Run the work in a transaction on this connection, repairing what a failure leaves.
     *
     * @template TReturn
     *
     * @param  Closure(Connection): TReturn  $work
     * @return TReturn
     */
    public static function run(Connection $connection, Closure $work): mixed
    {
        $level = $connection->transactionLevel();

        if ($level > 0) {
            self::refuseEndedTransaction($connection);
        }

        try {
            return $connection->transaction(static function (Connection $inside) use ($work, $level): mixed {
                // After the savepoint too: MySQL reports its transaction status in the reply to each statement.
                if ($level > 0) {
                    self::refuseEndedTransaction($inside);
                }

                return $work($inside);
            });
        } catch (Throwable $failure) {
            if ($level === 0) {
                self::afterOutermost($connection);
            } elseif ($failure instanceof DeadlockException) {
                self::afterNested($connection, $level);
            }

            throw $failure;
        }
    }

    private static function refuseEndedTransaction(Connection $connection): void
    {
        if (! $connection->getPdo()->inTransaction()) {
            throw new RuntimeException(
                'Refusing this write: the database has already ended the transaction it would run inside — a deadlock or '
                .'a lock-wait timeout rolled it back — so it would commit on its own, before anything it depends on. '
                .'Retry the whole operation.'
            );
        }
    }

    private static function afterOutermost(Connection $connection): void
    {
        if ($connection->transactionLevel() !== 0) {
            return;
        }

        $manager = app('db.transactions');
        $pending = static fn (): bool => $manager->getPendingTransactions()->contains(
            static fn (DatabaseTransactionRecord $record): bool => $record->connection === $connection->getName(),
        );
        $stale = $pending();
        $repaired = false;

        try {
            if ($connection->getPdo()->inTransaction()) {
                $connection->getPdo()->rollBack();
                $repaired = true;
            }
        } catch (Throwable) {
            // Dropping the connection ends its transaction, and rolls the manager back to 0 on the way.
            $connection->disconnect();
            $repaired = true;
        }

        if ($stale && $pending()) {
            $manager->rollback($connection->getName(), 0);
            $repaired = true;
        }

        if ($repaired) {
            event(new TransactionRolledBack($connection));
        }
    }

    private static function afterNested(Connection $connection, int $level): void
    {
        if ($connection->transactionLevel() !== $level) {
            return;
        }

        try {
            $connection->getPdo()->exec($connection->getQueryGrammar()->compileSavepointRollBack('trans'.($level + 1)));
        } catch (Throwable) {
            // A deadlock has already ended the engine's transaction, savepoint and all.
        }

        event(new TransactionRolledBack($connection));
    }
}
