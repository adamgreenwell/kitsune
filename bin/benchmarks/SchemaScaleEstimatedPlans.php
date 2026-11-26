<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Benchmarks;

use PDO;
use RuntimeException;

/** Obtains estimated plans for original statement-and-binding pairs using the database optimizer. @internal */
final class SchemaScaleEstimatedPlans
{
    public int $occurrences = 0;

    /** @var array<string, string> */
    public array $digests = [];

    private readonly string $prefix;

    /** @var array<string, array{sql: string, bindings: array}> */
    private array $mariaDbLocks = [];

    public int $mariaDbLockPlans = 0;

    public function __construct(
        private readonly PDO $pdo,
        string $driver,
        private readonly bool $allowMariaDbLockPlans = false,
    ) {
        if ($allowMariaDbLockPlans && $driver !== 'mariadb') {
            throw new RuntimeException('Lock plan replay is only for MariaDB.');
        }
        $this->prefix = match ($driver) {
            'sqlite' => 'EXPLAIN QUERY PLAN ',
            'pgsql' => 'EXPLAIN (FORMAT JSON) ',
            'mysql', 'mariadb' => 'EXPLAIN FORMAT=JSON ',
        };
    }

    public function record(array $queries): void
    {
        foreach ($queries as $query) {
            if (! preg_match('/\A\s*select\b/i', $query['sql']) || ! array_is_list($query['bindings'])) {
                throw new RuntimeException('Only captured SELECT statements are planned.');
            }
            $this->occurrences++;
            $id = hash('sha256', json_encode([$query['sql'], $query['bindings']], JSON_THROW_ON_ERROR));
            if (isset($this->digests[$id]) || isset($this->mariaDbLocks[$id])) {
                continue;
            }
            if ($this->allowMariaDbLockPlans && preg_match('/\sfor update\s*\z/i', $query['sql']) === 1) {
                if (! self::isKnownWriteLock($query['sql'])) {
                    throw new RuntimeException('The captured locking SELECT is outside the benchmark allowlist.');
                }
                $this->mariaDbLocks[$id] = $query;

                continue;
            }
            $this->digests[$id] = $this->estimate($query);
        }
    }

    public function replayMariaDbLocks(): void
    {
        if ($this->mariaDbLocks === []) {
            return;
        }
        if ($this->pdo->inTransaction() || (int) $this->pdo->query('SELECT @@session.tx_read_only')->fetchColumn() !== 1) {
            throw new RuntimeException('Lock plan replay requires a read-only session without an active transaction.');
        }

        // MariaDB refuses to prepare EXPLAIN for a locking SELECT in READ ONLY mode.
        // Only the allowlisted estimates below run in the single READ WRITE transaction.
        $this->pdo->exec('SET TRANSACTION READ WRITE');
        $this->pdo->beginTransaction();
        try {
            foreach ($this->mariaDbLocks as $id => $query) {
                $this->digests[$id] = $this->estimate($query);
            }
        } finally {
            $this->pdo->rollBack();
        }
        if ($this->pdo->inTransaction() || (int) $this->pdo->query('SELECT @@session.tx_read_only')->fetchColumn() !== 1) {
            throw new RuntimeException('The session did not return to read-only after lock plan replay.');
        }
        $this->mariaDbLockPlans = count($this->mariaDbLocks);
        $this->mariaDbLocks = [];
    }

    private static function isKnownWriteLock(string $sql): bool
    {
        $shape = preg_replace('/(?<=\()[0-9]+(?:, [0-9]+)*(?=\))/', '[ids]', $sql);

        return in_array($shape, [
            'select * from `entries` where `entries`.`id` = ? for update',
            'select * from `entries` where `entries`.`id` in ([ids]) and (`entries`.`site_id` = ? or (`entries`.`site_id` is null and `entries`.`org_id` = ? and `entries`.`entry_type_id` in (?))) and `entries`.`deleted_at` is null for update',
            'select * from `entries` where `entries`.`id` in ([ids]) for update',
            'select * from `entries` where `entries`.`id` in ([ids]) order by `id` asc for update',
            'select * from `field_storage` where `field_storage`.`id` = ? limit 1 for update',
            'select * from `field_storage` where `field_storage`.`id` in ([ids]) order by `id` asc for update',
            'select * from `fields` where `entry_type_id` in (?) order by `id` asc for update',
            'select `entries`.`id` from `entries` where `id` = ? for update',
            'select `entry_type_id` from `entries` where `entries`.`id` in ([ids]) for update',
            'select `id`, `org_id` from `field_storage` where `field_storage`.`id` = ? limit 1 for update',
            'select `id`, `source_entry_id` from `entry_relations` where `id` = ? for update',
            'select `site_id`, `org_id`, `entry_type_id`, `deleted_at` from `entries` where `entries`.`id` = ? limit 1 for update',
        ], true);
    }

    private function estimate(array $query): string
    {
        $statement = $this->pdo->prepare($this->prefix.$query['sql']);
        foreach ($query['bindings'] as $position => $binding) {
            $type = match (true) {
                $binding === null => PDO::PARAM_NULL,
                is_int($binding) => PDO::PARAM_INT,
                is_float($binding), is_string($binding) => PDO::PARAM_STR,
                default => throw new RuntimeException('Unsupported SELECT binding.'),
            };
            $statement->bindValue($position + 1, $binding, $type);
        }
        $statement->execute();
        $plan = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($plan === []) {
            throw new RuntimeException('The database did not return an estimated plan.');
        }

        return hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));
    }
}
