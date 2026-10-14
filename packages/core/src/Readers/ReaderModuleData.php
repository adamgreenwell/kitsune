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
use Illuminate\Database\Query\Builder;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Entitlements\EntitlementAuthority;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Models\Entitlement;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Modules\ModuleManifest;
use Kitsune\Core\Modules\ModuleServiceProvider;
use Kitsune\Core\Readers\Contracts\ModuleReaderData;
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
    public function erase(#[\SensitiveParameter] int|string $reader): array
    {
        return EntitlementAuthority::mapped(EntitlementRefused::FORGET, null, function () use ($reader): array {
            $orgId = $this->authority->org(EntitlementRefused::FORGET);
            $this->authority->authorise(EntitlementRefused::FORGET, null);
            $key = $this->authority->filedKey(EntitlementRefused::FORGET, $reader);

            return self::mapped('erase', function () use ($orgId, $key): array {
                $connection = (new Entitlement)->getConnection();

                return TransactionRecovery::run($connection, function () use ($connection, $orgId, $key): array {
                    $counts = [];

                    foreach ($this->stores($connection, current: true) as $handle => $store) {
                        $counts[$handle] = $store->erase($connection, $orgId, $key);
                    }

                    return $counts;
                });
            });
        });
    }

    /** @return array<string, ModuleReaderData> */
    private function stores(Connection $connection, bool $current = false): array
    {
        // Installation-wide module receipts are genuinely global. Read every switch/version from the primary: runtime
        // enablement does not remove personal data, and a lagging replica cannot make a store disappear from erasure.
        // Erasure also needs a current locking read: an enclosing InnoDB REPEATABLE READ snapshot can predate a newly
        // installed store even on the primary. Missing that receipt would leave its rows behind after account deletion.
        $handles = $connection->table('modules')->useWritePdo()->orderBy('handle')
            ->when($current, static fn (Builder $query): Builder => $query->lockForUpdate())->pluck('handle');
        $stores = [];

        foreach ($handles as $handle) {
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

    /**
     * @template T
     *
     * @param  'export'|'erase'  $action
     * @param  Closure(): T  $body
     * @return T
     */
    private static function mapped(string $action, #[\SensitiveParameter] Closure $body): mixed
    {
        try {
            return $body();
        } catch (ReaderModuleDataUnavailable $safe) {
            throw $safe;
        } catch (PDOException $failure) {
            throw new ReaderModuleDataUnavailable($action, ReaderAccounts::sqlstate($failure));
        } catch (Throwable) {
            throw new ReaderModuleDataUnavailable($action);
        }
    }
}
