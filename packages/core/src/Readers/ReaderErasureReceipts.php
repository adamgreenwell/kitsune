<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Kitsune\Core\Readers\Contracts\AnchorsReaderErasure;
use Kitsune\Core\Readers\Contracts\ReaderAccount;
use Kitsune\Core\Readers\Contracts\ReaderColumnClass;
use Kitsune\Core\Tenancy\Concerns\ReadsWrittenKeys;
use RuntimeException;

/** @internal Reader journals keep opaque account anchors and distinguish pending replay from a local erasure. */
final class ReaderErasureReceipts
{
    use ReadsWrittenKeys;

    public const ACCOUNT = 'account';

    public const SIGNUP = 'signup';

    public const GRANT = 'grant';

    public static function validAnchor(mixed $anchor): bool
    {
        return is_string($anchor) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $anchor) === 1;
    }

    public static function anchor((Model&ReaderAccount)|null $account): ?string
    {
        if (! $account instanceof AnchorsReaderErasure) {
            return null;
        }

        $column = self::column($account::class);
        $anchor = $column === null ? null : $account->getRawOriginal($column);

        return is_string($anchor) && self::validAnchor($anchor) ? $anchor : null;
    }

    public function readerFor(string $anchor, int $org, ReaderAccounts $accounts, ?Connection $expectedStore = null): int|string|null
    {
        $model = $accounts->model();
        if ($model === null || ! is_subclass_of($model, AnchorsReaderErasure::class) || ! self::validAnchor($anchor)) {
            throw new ReaderErasureReplayUnavailable('This host cannot resolve reader erasure anchors.');
        }

        $prototype = new $model;
        $column = self::column($model);
        if ($column === null) {
            throw new ReaderErasureReplayUnavailable('Reader erasure requires a classified host anchor.');
        }

        $connection = $prototype->getConnection();
        if ($expectedStore !== null && $connection !== $expectedStore) {
            throw new ReaderErasureReplayUnavailable('The configured reader store changed before erasure was verified.');
        }
        $keyName = $prototype->getKeyName();
        $matching = ReaderAccounts::mapped(static fn () => $connection->table($prototype->getTable())->useWritePdo()
            ->where('org_id', $org)->where($column, $anchor)->limit(2)->get([$keyName, 'org_id', $column]));
        if ($matching->isEmpty()) {
            return null;
        }
        $account = $matching->count() === 1 ? $matching->first() : null;
        if ($account === null || self::writtenKey($account->org_id ?? null) !== $org || ($account->{$column} ?? null) !== $anchor) {
            throw new ReaderErasureReplayUnavailable('The restored reader anchor is ambiguous.');
        }

        $key = $account->{$keyName} ?? null;
        if (! is_int($key) && ! is_string($key)) {
            throw new ReaderErasureReplayUnavailable('The restored reader key is unavailable.');
        }

        return $key;
    }

    /** @param list<string> $anchors */
    public function remember(Connection $connection, int $org, array $anchors, string $kind = self::ACCOUNT): void
    {
        foreach (array_chunk($anchors, 200) as $batch) {
            $rows = array_map(static fn (string $anchor): array => ['org_id' => $org, 'kind' => $kind, 'anchor' => $anchor], $batch);
            $connection->table('reader_erasure_receipts')->insertOrIgnore($rows);
            $stored = $connection->table('reader_erasure_receipts')->useWritePdo()->where('org_id', $org)->where('kind', $kind)
                ->whereIn('anchor', $batch)->orderBy('anchor')->lockForUpdate()->pluck('anchor')->all();
            sort($batch, SORT_STRING);
            if ($stored !== $batch) {
                throw new RuntimeException('The authenticated reader receipts could not be preserved.');
            }
        }
    }

    public function record(Connection $connection, int $org, string $anchor, string $kind = self::ACCOUNT): void
    {
        $this->assertCanRecord($connection, $anchor);

        $identity = ['org_id' => $org, 'kind' => $kind, 'anchor' => $anchor];
        $this->remember($connection, $org, [$anchor], $kind);
        $connection->table('reader_erasure_receipts')->where($identity)->whereNull('erased_at')->update(['erased_at' => now('UTC'), 'host_pending' => false]);
        if (! $this->completed($connection, $org, $anchor, $kind, lock: true)) {
            throw new RuntimeException('The reader erasure receipt could not be verified.');
        }
    }

    public function awaitHost(Connection $connection, int $org, string $anchor): void
    {
        $this->assertCanRecord($connection, $anchor);
        $this->remember($connection, $org, [$anchor]);
        $this->accountReceipt($connection, $org, $anchor)->update(['erased_at' => null, 'host_pending' => true]);
        if ($this->accountReceipt($connection, $org, $anchor)->where('host_pending', true)->whereNull('erased_at')->lockForUpdate()->first(['anchor']) === null) {
            throw new ReaderErasureReplayUnavailable('The pending host erasure could not be recorded.');
        }
    }

    public function finishHost(Connection $connection, int $org, string $anchor): bool
    {
        $this->accountReceipt($connection, $org, $anchor)->where('host_pending', true)
            ->update(['erased_at' => now('UTC'), 'host_pending' => false]);

        return $this->completed($connection, $org, $anchor);
    }

    private function accountReceipt(Connection $connection, int $org, string $anchor): Builder
    {
        return $connection->table('reader_erasure_receipts')->useWritePdo()
            ->where(['org_id' => $org, 'kind' => self::ACCOUNT, 'anchor' => $anchor]);
    }

    /** @param list<mixed> $anchors */
    public function recordErased(Connection $connection, int $org, array $anchors, string $kind, bool $requireComplete = true): void
    {
        foreach ($anchors as $anchor) {
            if (! is_string($anchor) || ! self::validAnchor($anchor)) {
                if ($requireComplete) {
                    throw new ReaderErasureReplayUnavailable('The erased record has no usable replay anchor.');
                }

                continue;
            }
            $this->record($connection, $org, $anchor, $kind);
        }
    }

    public function completed(Connection $connection, int $org, string $anchor, string $kind = self::ACCOUNT, bool $lock = false): bool
    {
        return $connection->table('reader_erasure_receipts')->useWritePdo()->where('org_id', $org)->where('kind', $kind)->where('anchor', $anchor)->whereNotNull('erased_at')->where('host_pending', false)->when($lock, static fn ($query) => $query->lockForUpdate())->first(['anchor']) !== null;
    }

    /** @param class-string<Model> $model */
    public function retainsAccountAnchor(Connection $connection, string $table, string $model, int $org, string $anchor): bool
    {
        if (! is_subclass_of($model, AnchorsReaderErasure::class) || ($column = self::column($model)) === null) {
            return true;
        }

        return $connection->table($table)->useWritePdo()
            ->where('org_id', $org)->where($column, $anchor)->exists();
    }

    private function assertCanRecord(Connection $connection, string $anchor): void
    {
        if (! self::validAnchor($anchor) || $connection->transactionLevel() === 0 || ! $connection->getPdo()->inTransaction()) {
            throw new RuntimeException('The reader erasure receipt cannot be recorded.');
        }
    }

    /** @param class-string<AnchorsReaderErasure> $model */
    private static function column(string $model): ?string
    {
        $column = $model::readerErasureAnchorColumn();
        if (preg_match('/\A[a-z][a-z0-9_]*\z/', $column) !== 1) {
            return null;
        }

        $spec = $model::readerColumnPrivacy()[$column] ?? null;

        return ($spec['class'] ?? null) === ReaderColumnClass::Operational && ! isset($spec['export_as']) ? $column : null;
    }
}
