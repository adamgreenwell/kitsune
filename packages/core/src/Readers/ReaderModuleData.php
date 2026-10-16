<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Entitlements\EntitlementAuthority;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Models\Entitlement;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Modules\ModuleManifest;
use Kitsune\Core\Modules\ModuleServiceProvider;
use Kitsune\Core\Readers\Contracts\ModuleReaderData;
use Kitsune\Core\Readers\Contracts\ModuleReaderErasureVerifier;
use Kitsune\Core\Tenancy\Context;
use PDO;
use PDOException;
use Throwable;

/**
 * Reader data held by every installed module, including disabled or stale runtime receipts.
 *
 * Providers are constructed only to ask for their privacy adapter: never registered or booted. No memo, so another
 * operation sees the installed receipts and code as they are now. Missing code refuses the complete operation; core
 * cannot know that an unavailable package held no reader data. Adapters use the entitlement connection, so erasure's
 * account-last transaction also owns every first-party module write.
 *
 * @internal First-party only; no public extension contract before v1.2.
 */
final class ReaderModuleData
{
    public function __construct(private readonly EntitlementAuthority $authority) {}

    /** @return array<string, array<string, mixed>> keyed by installed module package */
    public function export(#[\SensitiveParameter] int|string $reader): array
    {
        return EntitlementAuthority::mapped(EntitlementRefused::EXPORT, null, function () use ($reader): array {
            $orgId = $this->authority->org(EntitlementRefused::EXPORT);
            $this->authority->authorise(EntitlementRefused::EXPORT, null);
            $key = $this->authority->filedKey(EntitlementRefused::EXPORT, $reader);

            return self::mapped('export', function () use ($orgId, $key): array {
                $connection = (new Entitlement)->getConnection();
                $records = [];

                foreach ($this->stores($connection) as $handle => $store) {
                    $records[$handle] = $store->export($connection, $orgId, $key);
                }

                return $records;
            });
        });
    }

    /** @return array<string, int> erased parent-record counts, keyed by installed module package */
    public function erase(#[\SensitiveParameter] int|string $reader, #[\SensitiveParameter] ?Closure $afterErase = null): array
    {
        return EntitlementAuthority::mapped(EntitlementRefused::FORGET, null, function () use ($reader, $afterErase): array {
            $orgId = $this->authority->org(EntitlementRefused::FORGET);
            $this->authority->authorise(EntitlementRefused::FORGET, null);
            $key = $this->authority->filedKey(EntitlementRefused::FORGET, $reader);
            $callbackFailure = null;

            return self::mapped('erase', function () use ($orgId, $key, $afterErase, &$callbackFailure): array {
                $connection = (new Entitlement)->getConnection();

                return TransactionRecovery::run($connection, function () use ($connection, $orgId, $key, $afterErase, &$callbackFailure): array {
                    $native = $connection->getPdo();
                    $level = $connection->transactionLevel();
                    $counts = [];
                    $verifiers = [];
                    $authority = $this->authorityFrame($orgId);
                    $receipts = $this->receipts($connection, current: true);
                    $stores = $this->stores($connection, receipts: $receipts);

                    foreach ($stores as $handle => $store) {
                        $counts[$handle] = $store->erase($connection, $orgId, $key);
                        if ($store instanceof ModuleReaderErasureVerifier) {
                            $verifiers[] = $store->erasureVerification($connection, $orgId, $key);
                        }
                    }
                    try {
                        $afterErase?->__invoke();
                    } catch (Throwable $failure) {
                        // The account-last caller keeps its existing host exception behavior. Only this exact
                        // failure bypasses module redaction, after TransactionRecovery has rolled the writes back.
                        $callbackFailure = $failure;
                        throw $failure;
                    }
                    if (! $this->sameTransaction($connection, $native, $level)) {
                        throw new \RuntimeException('Reader erasure transaction changed.');
                    }
                    // Authority can invoke host/model code. Check it before the final raw module proofs.
                    if ($this->authorityFrame($orgId) !== $authority) {
                        throw new \RuntimeException('Reader erasure authority changed.');
                    }
                    foreach ($verifiers as $verify) {
                        $verify();
                    }
                    if ($this->receipts($connection, current: true) !== $receipts
                        || ! $this->sameTransaction($connection, $native, $level)
                        || app(Context::class)->orgId() !== $authority['org'] || app(Context::class)->siteId() !== $authority['site']) {
                        throw new \RuntimeException('Installed reader custody changed during erasure.');
                    }

                    return $counts;
                });
            }, callbackFailure: $callbackFailure);
        });
    }

    /**
     * @param  list<array<string,mixed>>|null  $receipts
     * @return array<string, ModuleReaderData>
     */
    private function stores(Connection $connection, bool $current = false, ?array $receipts = null): array
    {
        // Installation-wide module receipts are genuinely global. Read every switch/version from the primary: runtime
        // enablement does not remove personal data, and a lagging replica cannot make a store disappear from erasure.
        // Erasure also needs a current locking read: an enclosing InnoDB REPEATABLE READ snapshot can predate a newly
        // installed store even on the primary. Missing that receipt would leave its rows behind after account deletion.
        $stores = [];

        foreach ($receipts ?? $this->receipts($connection, $current) as $receipt) {
            $handle = $receipt['handle'] ?? null;
            if (! is_string($handle)) {
                throw new \RuntimeException('An installed module receipt is malformed.');
            }

            $manifest = ModuleDiscovery::read($handle);

            if (! $manifest instanceof ModuleManifest) {
                throw new \RuntimeException('An installed module provider is unavailable.');
            }

            $provider = new $manifest->provider(app());

            if (! $provider instanceof ModuleServiceProvider) {
                throw new \RuntimeException('An installed module provider is incompatible.');
            }

            $store = $provider->readerData();

            if ($store !== null) {
                $stores[$handle] = $store;
            }
        }

        return $stores;
    }

    /** Exact installed receipt graph, including disabled/stale stores with no declared privacy adapter.
     * @return list<array<string,mixed>>
     */
    private function receipts(Connection $connection, bool $current = false): array
    {
        return $connection->table('modules')->useWritePdo()->orderBy('handle')
            ->when($current, static fn (Builder $query): Builder => $query->lockForUpdate())->get()
            ->map(static fn (object $row): array => get_object_vars($row))->all();
    }

    /** @return array{org:int,site:?int,type:?string,id:?string} */
    private function authorityFrame(int $org): array
    {
        Permissions::forget();
        if ($this->authority->org(EntitlementRefused::FORGET) !== $org) {
            throw new \RuntimeException('Reader erasure Org changed.');
        }
        $this->authority->authorise(EntitlementRefused::FORGET, null);
        $actor = Permissions::currentUser();
        $key = $actor?->getAuthIdentifier();
        if ($actor !== null && ! is_int($key) && ! is_string($key)) {
            throw new \RuntimeException('Reader erasure actor changed.');
        }

        return ['org' => $org, 'site' => app(Context::class)->siteId(),
            'type' => $actor === null ? null : ($actor instanceof Model ? $actor->getMorphClass() : $actor::class),
            'id' => $actor === null ? null : (string) $key];
    }

    /** Fresh after callbacks, which can end or replace the database's transaction.
     * @phpstan-impure
     */
    private function sameTransaction(Connection $connection, PDO $native, int $level): bool
    {
        return $connection->getPdo() === $native && $connection->transactionLevel() === $level && $native->inTransaction();
    }

    /**
     * @template T
     *
     * @param  'export'|'erase'  $action
     * @param  Closure(): T  $body
     * @return T
     */
    private static function mapped(string $action, #[\SensitiveParameter] Closure $body, #[\SensitiveParameter] ?Throwable &$callbackFailure = null): mixed
    {
        try {
            return $body();
        } catch (ReaderModuleDataUnavailable $safe) {
            throw $safe;
        } catch (Throwable $failure) {
            if ($failure === $callbackFailure) {
                throw $failure;
            }

            throw new ReaderModuleDataUnavailable($action, $failure instanceof PDOException ? ReaderAccounts::sqlstate($failure) : null);
        }
    }
}
