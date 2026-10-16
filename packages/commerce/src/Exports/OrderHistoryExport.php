<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Exports;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Credentials\FreshCredentialAuthentication;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Tenancy\Concerns\ReadsWrittenKeys;
use Kitsune\Core\Tenancy\Context;
use Throwable;

/** Complete, audience-limited pages of installed historical custody, with no runtime/provider dependency. @internal */
final class OrderHistoryExport
{
    use ReadsWrittenKeys;

    public const MAX_ORDERS = 100;

    public function __construct(
        private readonly Context $context,
        private readonly OrderReaderData $orders,
        private readonly OrderExportRecords $records,
        private readonly FreshCredentialAuthentication $authentication,
    ) {}

    public function forOwner(string $format, int $limit = 100, ?string $cursor = null, bool $includeBilling = false): ExportPage
    {
        if ($this->context->siteId() === null) {
            throw new ExportRefused;
        }

        return $this->page($format, $limit, $cursor, $includeBilling, false);
    }

    public function forConsole(string $format, int $limit = 100, ?string $cursor = null, bool $includeBilling = false): ExportPage
    {
        if (! app()->runningInConsole()) {
            throw new ExportRefused;
        }

        return $this->page($format, $limit, $cursor, $includeBilling, true);
    }

    private function page(string $format, int $limit, ?string $cursor, bool $billing, bool $console): ExportPage
    {
        try {
            if ($limit < 1 || $limit > self::MAX_ORDERS) {
                throw new ExportRefused;
            }
            $formatter = new ExportFormat($format);
            $canonical = new ExportFormat('jsonl');
            $org = $this->context->orgId() ?? throw new ExportRefused;
            $site = $this->context->siteId();
            $resume = $cursor === null ? null : ExportCursor::decode($cursor, $org, $site, $billing);
            $connection = (new Order)->getConnection();

            return TransactionRecovery::run($connection, function () use ($connection, $org, $site, $resume, $billing, $console, $formatter, $canonical, $format, $limit): ExportPage {
                $pdo = $connection->getPdo();
                $level = $connection->transactionLevel();
                // SQLite has no row locks. This same-key no-op takes its writer reservation before custody is read.
                if ($connection->getDriverName() === 'sqlite') {
                    $connection->table('orgs')->where('id', $org)->update(['id' => $org]);
                }
                $orgRow = $connection->table('orgs')->useWritePdo()->where('id', $org)->whereNull('deleted_at')->lockForUpdate()->first();
                if ($orgRow === null || ! is_string($orgRow->slug) || $orgRow->slug === '') {
                    throw new ExportRefused;
                }
                $proof = ! $console || Permissions::currentUser() !== null ? $this->authentication->confirm(null, requirePassword: false) : null;
                if ($proof === null) {
                    $this->assertSystem();
                }
                $module = $connection->table('modules')->useWritePdo()->where('handle', 'kitsune/commerce')->lockForUpdate()->first();
                if ($module === null) {
                    throw new ExportRefused;
                }
                $selectedSite = $site === null ? null : $connection->table('sites')->useWritePdo()
                    ->where('org_id', $org)->where('id', $site)->lockForUpdate()->first();
                if ($site !== null && ($selectedSite === null || ! is_string($selectedSite->handle))) {
                    throw new ExportRefused;
                }
                // Current locking reads must not inherit an older host repeatable-read snapshot. Lock Sites
                // in bounded batches before touching Order locks; the Org lock serializes their writers.
                if ($site === null) {
                    $this->lockSites($connection, $org);
                }
                $hasSchema = $this->orders->schemaAvailable($connection);
                $after = $resume['after'] ?? 0;
                $highest = $resume === null && $hasSchema ? $this->query($connection, $org, $site)
                    ->orderByDesc('id')->lockForUpdate()->value('id') : null;
                $upper = $resume['upper'] ?? ($highest === null ? 0 : (self::writtenKey($highest) ?? throw new ExportRefused));
                if ($highest !== null && $upper < 1) {
                    throw new ExportRefused;
                }
                if ($after > $upper) {
                    throw new ExportRefused;
                }
                // Only the candidate IDs are loaded first, so Site locks precede every selected Order lock.
                $ids = $hasSchema ? $this->query($connection, $org, $site)->where('id', '>', $after)
                    ->where('id', '<=', $upper)->orderBy('id')->limit($limit + 1)->lockForUpdate()->get(['id', 'site_id'])->all() : [];
                $hasMore = count($ids) > $limit;
                $ids = array_slice($ids, 0, $limit);
                $siteIds = array_values(array_unique(array_map(static fn (object $row): int => (int) $row->site_id, $ids)));
                sort($siteIds);
                foreach ($siteIds as $id) {
                    if ($connection->table('sites')->useWritePdo()->where('org_id', $org)->where('id', $id)->lockForUpdate()->first() === null) {
                        throw new ExportRefused;
                    }
                }
                $generated = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z');
                $body = $formatter->header();
                $this->append($body, $formatter->encode($this->envelope('manifest', $orgRow->slug, $selectedSite?->handle,
                    ['profile' => $billing ? 'financial_with_billing' : 'financial', 'generated_at' => $generated, 'limit' => $limit, 'max_id' => $upper])));
                $customers = [];
                $fingerprints = [];
                foreach ($ids as $id) {
                    $key = self::writtenKey($id->id);
                    if ($key === null || $key < 1) {
                        throw new ExportRefused;
                    }
                    $row = $this->query($connection, $org, $site)->where('id', $key)->lockForUpdate()->first();
                    if ($row === null || (string) $row->site_id !== (string) $id->site_id) {
                        throw new ExportRefused;
                    }
                    $fingerprint = hash_init('sha256');
                    foreach ($this->records->forOrder($connection, $row, $orgRow->slug, $billing) as $record) {
                        $encoded = $formatter->encode($record);
                        hash_update($fingerprint, $canonical->encode($record));
                        if ($record['type'] === 'customer') {
                            $customer = $record['customer_reference'];
                            if (isset($customers[$customer])) {
                                continue;
                            }
                            $customers[$customer] = true;
                        }
                        $this->append($body, $encoded);
                    }
                    $fingerprints[$key] = hash_final($fingerprint);
                    $after = $key;
                }
                $next = $hasMore ? ExportCursor::encode($org, $site, $billing, $after, $upper) : null;
                $this->append($body, $formatter->encode($this->envelope('complete', $orgRow->slug, $selectedSite?->handle,
                    ['profile' => $billing ? 'financial_with_billing' : 'financial', 'generated_at' => $generated,
                        'count' => count($ids), 'has_more' => $hasMore, 'next_cursor' => $next, 'max_id' => $upper])));
                if ($proof !== null) {
                    $this->authentication->assertCurrent($proof);
                } else {
                    $this->assertSystem();
                }
                // Fresh host owner hydration is application code. It cannot change a previously checked
                // purchase, privacy representation or child graph behind a successful attachment.
                foreach ($ids as $id) {
                    $key = self::writtenKey($id->id) ?? throw new ExportRefused;
                    $row = $this->query($connection, $org, $site)->where('id', $key)->lockForUpdate()->first();
                    if ($row === null || (string) $row->site_id !== (string) $id->site_id) {
                        throw new ExportRefused;
                    }
                    $fingerprint = hash_init('sha256');
                    foreach ($this->records->forOrder($connection, $row, $orgRow->slug, $billing) as $record) {
                        hash_update($fingerprint, $canonical->encode($record));
                    }
                    if (hash_final($fingerprint) !== $fingerprints[$key]) {
                        throw new ExportRefused;
                    }
                }
                if (! $connection->table('modules')->useWritePdo()->where('id', $module->id)->where('handle', 'kitsune/commerce')->lockForUpdate()->exists()) {
                    throw new ExportRefused;
                }
                // This is deliberately after the last database/host authority callback.
                if ($this->context->orgId() !== $org || $this->context->siteId() !== $site
                    || $connection !== (new Order)->getConnection() || $connection->getPdo() !== $pdo
                    || $connection->transactionLevel() !== $level || ! $pdo->inTransaction()) {
                    throw new ExportRefused;
                }

                return new ExportPage($body, $format, $next, count($ids));
            });
        } catch (Throwable) {
            throw new ExportRefused;
        }
    }

    private function assertSystem(): void
    {
        if (! app()->runningInConsole() || Permissions::currentUser() !== null || app(ReaderGuard::class)->signedIn()) {
            throw new ExportRefused;
        }
    }

    private function lockSites(Connection $connection, int $org): void
    {
        $after = 0;
        do {
            $rows = $connection->table('sites')->useWritePdo()->where('org_id', $org)->where('id', '>', $after)
                ->orderBy('id')->limit(100)->lockForUpdate()->get(['id']);
            foreach ($rows as $row) {
                $after = self::writtenKey($row->id) ?? throw new ExportRefused;
            }
        } while ($rows->count() === 100);
    }

    private function query(Connection $connection, int $org, ?int $site): Builder
    {
        return $connection->table('commerce_orders')->useWritePdo()->where('org_id', $org)
            ->when($site !== null, static fn (Builder $query): Builder => $query->where('site_id', $site));
    }

    /** @param array<string,scalar|null> $data
     * @return array<string,mixed>
     */
    private function envelope(string $type, string $org, ?string $site, array $data): array
    {
        return ['schema' => 'kitsune.commerce.export', 'version' => 1, 'type' => $type, 'org' => $org, 'site' => $site,
            'order_reference' => null, 'customer_reference' => null, 'reference' => null, 'parent_reference' => null, 'data' => $data];
    }

    private function append(#[\SensitiveParameter] string &$body, #[\SensitiveParameter] string $line): void
    {
        if (strlen($body) + strlen($line) > ExportPage::MAX_BYTES) {
            throw new ExportRefused;
        }
        $body .= $line;
    }
}
