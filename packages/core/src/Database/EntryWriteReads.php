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
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;

/**
 * Selected current witnesses for one atomic entry write, without changing ordinary model read scopes.
 *
 * An InnoDB locking outer query does not make its EXISTS/eager loads current. Each selected witness
 * opts in here; this frame supplies neither authority nor scope removal. It is request-scoped and
 * always unwound, so later reads cannot inherit the write's connection, locks or scope proof.
 *
 * @internal
 */
final class EntryWriteReads
{
    /** @var list<array{connection:Connection,org:int,site:int,level:int}> */
    private array $frames = [];

    /** @template T
     * @param  Closure():T  $work
     * @return T
     */
    public function run(Connection $connection, Closure $work): mixed
    {
        $context = app(Context::class);
        if ($connection->transactionLevel() < 1 || $context->orgId() === null || $context->siteId() === null) {
            throw new RuntimeException('Current entry writes require an open transaction and exact Org/Site context.');
        }
        $this->check($connection);
        $this->frames[] = ['connection' => $connection, 'org' => $context->orgId(), 'site' => $context->siteId(), 'level' => $connection->transactionLevel()];
        try {
            $result = $work();
            $this->check($connection);

            return $result;
        } finally {
            array_pop($this->frames);
        }
    }

    private function check(ConnectionInterface $connection): void
    {
        $frame = end($this->frames);
        if ($frame !== false && ($frame['connection'] !== $connection || $connection->transactionLevel() < $frame['level']
            || app(Context::class)->orgId() !== $frame['org'] || app(Context::class)->siteId() !== $frame['site'])) {
            throw new RuntimeException('The current entry write lost its connection, transaction or scope.');
        }
    }

    public static function active(): bool
    {
        return app(self::class)->frames !== [];
    }

    /** @template T of Builder<*>|QueryBuilder
     * @param  T  $query
     * @return T
     */
    public static function query(Builder|QueryBuilder $query): Builder|QueryBuilder
    {
        if (self::active()) {
            app(self::class)->check($query instanceof Builder ? $query->getQuery()->getConnection() : $query->getConnection());
            $query->useWritePdo()->lockForUpdate();
        }

        return $query;
    }

    /** @param Builder<*>|QueryBuilder $query */
    public static function exists(Builder|QueryBuilder $query): bool
    {
        return self::active() ? self::query($query)->limit(1)->first() !== null : $query->exists();
    }

    /** @param Builder<*>|QueryBuilder $query */
    public static function atLeast(Builder|QueryBuilder $query, int $count): bool
    {
        return self::active() ? self::query($query)->offset($count - 1)->limit(1)->first() !== null : $query->count() >= $count;
    }
}
