<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Inventory;

use Carbon\CarbonImmutable;
use Filament\Models\Contracts\HasTenants;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Kitsune\Commerce\Catalogue\ProductType;
use Kitsune\Commerce\Models\Listing;
use Kitsune\Commerce\Models\Sellable;
use Kitsune\Commerce\Models\StockMovement;
use Kitsune\Commerce\Money\MinorUnits;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Credentials\FreshCredentialAuthentication;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Tenancy\Context;
use Throwable;

/** Org-first, compare-and-set stock changes and immutable reasons; no provider I/O. @internal */
final class StockWriter
{
    public const TABLE = 'commerce_stock_movements';

    public function __construct(private readonly Context $context, private readonly OrderLocks $locks,
        private readonly Auditor $auditor) {}

    /** @return array<string,mixed> */
    public function adjust(string $sellableRef, ?string $expectedStock, ?string $newStock, string $reason): array
    {
        return $this->safe(function () use ($sellableRef, $expectedStock, $newStock, $reason): array {
            $key = Sellable::catalogueKey($sellableRef);
            $expectedStock = self::balance($expectedStock);
            $newStock = self::balance($newStock);
            $reason = self::reason($reason);
            if ($expectedStock === $newStock) {
                throw new StockRefused;
            }

            return $this->locks->run(function () use ($key, $expectedStock, $newStock, $reason): array {
                $connection = (new Sellable)->getConnection();
                $identity = $this->authority($connection, $key);
                $row = $this->sellable($connection, $key);
                if (self::balance($row->stock) !== $expectedStock) {
                    throw new StockRefused;
                }
                $movement = $this->movement($row, 'manual', $newStock, null, $reason, $identity);
                $this->write($connection, $row, $movement);
                if ($identity !== $this->authority($connection, $key)) {
                    throw new StockRefused;
                }

                return self::projection((object) $movement);
            });
        });
    }

    public function canAdjust(string $sellableRef): bool
    {
        try {
            $key = Sellable::catalogueKey($sellableRef);

            return $this->locks->run(function () use ($key): bool {
                $connection = (new Sellable)->getConnection();
                $this->authority($connection, $key);
                $this->sellable($connection, $key);

                return true;
            });
        } catch (Throwable) {
            return false;
        }
    }

    public function canAccess(): bool
    {
        try {
            return $this->locks->run(function (): bool {
                $this->authority((new Sellable)->getConnection());

                return true;
            });
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{reference:string,sku:string,stock:string|null} */
    public function current(string $sellableRef): array
    {
        return $this->safe(function () use ($sellableRef): array {
            $key = Sellable::catalogueKey($sellableRef);

            return $this->locks->run(function () use ($key): array {
                $connection = (new Sellable)->getConnection();
                $this->authority($connection, $key);
                $row = $this->sellable($connection, $key);

                return self::productProjection($row);
            });
        });
    }

    /** @return list<array{reference:string,sku:string,stock:string|null}> */
    public function products(int $offset = 0, int $limit = 50): array
    {
        return $this->safe(function () use ($offset, $limit): array {
            if ($offset < 0 || $offset > 1000000 || $limit < 1 || $limit > 100) {
                throw new StockRefused;
            }

            return $this->locks->run(function () use ($offset, $limit): array {
                $connection = (new Sellable)->getConnection();
                $identity = $this->authority($connection);
                $query = Sellable::query()->useWritePdo()->orderBy('id');
                if (! Permissions::isOwner(Permissions::currentUser() ?? throw new StockRefused)) {
                    $type = ProductType::current(true) ?? throw new StockRefused;
                    $org = $this->context->orgId();
                    $site = $this->context->siteId();
                    // Listing::entry() binds an instance's scope stamps; an unsaved relation prototype has none.
                    $query->whereExists(static fn (Builder $listing) => $listing->selectRaw('1')->from('commerce_listings')
                        ->join('entries', 'entries.id', '=', 'commerce_listings.entry_id')
                        ->whereColumn('commerce_listings.sellable_id', 'commerce_sellables.id')
                        ->where('commerce_listings.org_id', $org)->where('commerce_listings.site_id', $site)
                        ->where('entries.org_id', $org)->where('entries.site_id', $site)->where('entries.entry_type_id', $type->getKey())
                        ->whereNull('entries.deleted_at'));
                }
                $products = [];
                foreach ($query->offset($offset)->limit($limit)->lockForUpdate()->get() as $row) {
                    $this->authority($connection, (int) $row->getKey());
                    $raw = $this->sellable($connection, (int) $row->getKey());
                    $products[] = self::productProjection($raw);
                }
                if ($identity !== $this->authority($connection)) {
                    throw new StockRefused;
                }

                return $products;
            });
        });
    }

    /** Shared-stock history is available only through a product the current staff member may manage.
     * @return list<array<string,mixed>>
     */
    public function history(string $sellableRef, int $limit = 50): array
    {
        return $this->safe(function () use ($sellableRef, $limit): array {
            $key = Sellable::catalogueKey($sellableRef);
            if ($limit < 1 || $limit > 100) {
                throw new StockRefused;
            }

            return $this->locks->run(function () use ($key, $limit): array {
                $connection = (new Sellable)->getConnection();
                $identity = $this->authority($connection, $key);
                $this->sellable($connection, $key);
                $rows = $connection->table(self::TABLE)->useWritePdo()->where('org_id', $this->context->orgId())
                    ->where('sellable_ref', $key)->orderByDesc('id')->limit($limit)->lockForUpdate()->get();
                $result = [];
                foreach ($rows as $row) {
                    $this->validateMovement($connection, $row);
                    $result[] = self::projection($row);
                }
                if ($identity !== $this->authority($connection, $key)) {
                    throw new StockRefused;
                }

                return $result;
            });
        });
    }

    /** Owners alone may browse retained history without any live product or listing. */
    public function canBrowseHistory(): bool
    {
        try {
            return $this->locks->run(function (): bool {
                $this->runtime((new StockMovement)->getConnection());
                app(FreshCredentialAuthentication::class)->confirm(null, requirePassword: false);

                return true;
            });
        } catch (Throwable) {
            return false;
        }
    }

    /** Exact historical identifiers, inclusive UTC calendar dates; no LIKE wildcards or inferred live relationship.
     * @param  array<string,mixed>  $filters
     * @return array<string,string>
     */
    public static function historyFilters(array $filters): array
    {
        if (array_diff(array_keys($filters), ['sku', 'product', 'from', 'to']) !== []) {
            throw new StockRefused;
        }
        $result = [];
        foreach ($filters as $key => $value) {
            if (! is_string($value)) {
                throw new StockRefused;
            }
            if ($value === '') {
                continue;
            }
            $result[$key] = match ($key) {
                'sku' => Sellable::skuValue(strtoupper(trim($value))),
                'product' => (string) Sellable::catalogueKey($value),
                default => self::historyDate($value),
            };
        }
        if (isset($result['from'], $result['to']) && $result['from'] > $result['to']) {
            throw new StockRefused;
        }

        return $result;
    }

    /** Bounded owner-only projection. Historical references are labels, never joins into live catalogue or staff rows.
     * @param  array<string,mixed>  $filters
     * @return list<array<string,mixed>>
     */
    public function retainedHistory(array $filters = [], int $offset = 0, int $limit = 26): array
    {
        return $this->safe(function () use ($filters, $offset, $limit): array {
            $filters = self::historyFilters($filters);
            if ($offset < 0 || $offset > 249975 || $limit < 1 || $limit > 26) {
                throw new StockRefused;
            }

            return $this->locks->run(function () use ($filters, $offset, $limit): array {
                $connection = (new StockMovement)->getConnection();
                $this->runtime($connection);
                $authentication = app(FreshCredentialAuthentication::class);
                $proof = $authentication->confirm(null, requirePassword: false);
                $query = $connection->table(self::TABLE)->useWritePdo()->where('org_id', $this->context->orgId());
                foreach (['sku' => 'sku', 'product' => 'sellable_ref'] as $filter => $column) {
                    if (isset($filters[$filter])) {
                        $query->where($column, $filters[$filter]);
                    }
                }
                if (isset($filters['from'])) {
                    $query->where('created_at', '>=', $filters['from'].' 00:00:00');
                }
                if (isset($filters['to'])) {
                    $query->where('created_at', '<=', $filters['to'].' 23:59:59');
                }
                $rows = $query->orderByDesc('created_at')->orderByDesc('id')->offset($offset)->limit($limit)->lockForUpdate()->get();
                $result = [];
                foreach ($rows as $row) {
                    $this->validateMovement($connection, $row);
                    $result[] = [...self::projection($row), 'sku' => $row->sku, 'productReference' => (string) $row->sellable_ref,
                        'orderReference' => $row->order_reference, 'lineReference' => $row->line_reference === null ? null : (string) $row->line_reference];
                }
                $this->runtime($connection);
                $authentication->assertCurrent($proof);

                return $result;
            });
        });
    }

    private static function historyDate(string $value): string
    {
        if (preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}\z/D', $value) !== 1) {
            throw new StockRefused;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new StockRefused;
        }

        return $value;
    }

    /** Existing OrderLocks transaction owns the authoritative allocation. Repeating one line is a checked no-op. */
    public function recordSale(Connection $connection, int $org, int $site, object $sellable, int $awarded,
        string $orderRef, string $lineRef, bool $free = false): void
    {
        $this->safe(function () use ($connection, $org, $site, $sellable, $awarded, $orderRef, $lineRef, $free): void {
            if ($connection !== (new Sellable)->getConnection() || $connection->transactionLevel() < 1
                || ! $connection->getPdo()->inTransaction() || $this->context->orgId() !== $org || $this->context->siteId() !== $site
                || $awarded < 1 || $awarded > 99 || preg_match('/\A[a-f0-9]{32}\z/D', $orderRef) !== 1) {
                throw new StockRefused;
            }
            $key = Sellable::catalogueKey($sellable->id ?? null);
            $line = Sellable::catalogueKey($lineRef);
            TransactionRecovery::run($connection, function () use ($connection, $org, $site, $sellable, $key, $line, $awarded, $orderRef, $free): void {
                $this->runtime($connection);
                $parent = $connection->table('commerce_orders')->useWritePdo()->where('org_id', $org)->where('site_id', $site)
                    ->where('reference', $orderRef)->lockForUpdate()->first();
                $child = $connection->table('commerce_order_lines')->useWritePdo()->where('id', $line)->lockForUpdate()->first();
                if ($parent === null || $child === null || (string) $child->org_id !== (string) $org || (string) $child->site_id !== (string) $site
                    || (string) $child->order_id !== (string) $parent->id || (string) $child->sellable_ref !== (string) $key || $awarded > (int) $child->quantity) {
                    throw new StockRefused;
                }
                if ($free && (MinorUnits::integer($parent->quoted_total_minor) !== '0' || ! in_array($parent->state, ['prepared', 'completed_free'], true))) {
                    throw new StockRefused;
                }
                $previous = $connection->table(self::TABLE)->useWritePdo()->where('org_id', $org)
                    ->where('order_reference', $orderRef)->where('line_reference', $line)->lockForUpdate()->first();
                if ($previous !== null) {
                    $this->validateMovement($connection, $previous);
                    if ((string) $previous->sellable_ref !== (string) $key || (string) $previous->site_id !== (string) $site
                        || (int) $previous->quantity !== $awarded || $previous->kind !== ($free ? 'free_order' : 'sale')) {
                        throw new StockRefused;
                    }

                    return;
                }
                $row = $this->sellable($connection, $key);
                foreach (['id', 'org_id', 'sku', 'currency', 'stock'] as $column) {
                    if (! property_exists($sellable, $column) || ($row->{$column} === null) !== ($sellable->{$column} === null)
                        || ($row->{$column} !== null && (string) $row->{$column} !== (string) $sellable->{$column})) {
                        throw new StockRefused;
                    }
                }
                $before = self::balance($row->stock);
                $after = $before === null ? null : self::subtract($before, $awarded);
                $movement = [...$this->movement($row, $free ? 'free_order' : 'sale', $after, $awarded, $free ? 'Free order stock allocation.' : 'Paid order stock allocation.', null),
                    'order_reference' => $orderRef, 'line_reference' => $line];
                $frozen = (array) $child;
                $frozenParent = (array) $parent;
                $this->write($connection, $row, $movement);
                $current = $connection->table('commerce_order_lines')->useWritePdo()->where('id', $line)->lockForUpdate()->first();
                $currentParent = $connection->table('commerce_orders')->useWritePdo()->where('id', $parent->id)->lockForUpdate()->first();
                if ($current === null || (array) $current !== $frozen || $currentParent === null || (array) $currentParent !== $frozenParent
                    || $this->context->orgId() !== $org || $this->context->siteId() !== $site) {
                    throw new StockRefused;
                }
                $this->runtime($connection);
            });
        });
    }

    /** Fresh role resolution matters after an audit listener changes membership or permissions.
     * @return array{type:string,id:string}
     */
    private function authority(Connection $connection, ?int $sellable = null): array
    {
        $this->runtime($connection);
        Permissions::forget();
        $actor = Permissions::currentUser();
        $id = $actor?->getAuthIdentifier();
        if ($actor === null || (! is_int($id) && ! is_string($id)) || (string) $id === '' || strlen((string) $id) > 255) {
            throw new StockRefused;
        }
        $site = $this->context->site() ?? throw new StockRefused;
        if ($actor instanceof HasTenants && ! $actor->canAccessTenant($site)) {
            throw new StockRefused;
        }
        if (! Permissions::isOwner($actor)) {
            $type = ProductType::current(true);
            if ($type === null || ! Permissions::allows($actor, Permissions::forEntryType($type->handle, 'view'))
                || ! Permissions::allows($actor, Permissions::forEntryType($type->handle, 'update'))) {
                throw new StockRefused;
            }
            if ($sellable !== null) {
                $listing = Listing::query()->useWritePdo()->where('sellable_id', $sellable)->lockForUpdate()->first();
                $entry = $listing?->entry()->useWritePdo()->lockForUpdate()->first();
                if ($entry === null || (string) $entry->entry_type_id !== (string) $type->getKey()) {
                    throw new StockRefused;
                }
            }
        }
        $class = $actor instanceof Model ? $actor->getMorphClass() : $actor::class;
        if (strlen($class) > 255) {
            throw new StockRefused;
        }

        return ['type' => $class, 'id' => (string) $id];
    }

    private function runtime(Connection $connection): void
    {
        $org = $this->context->orgId() ?? throw new StockRefused;
        $site = $this->context->siteId() ?? throw new StockRefused;
        $parent = $connection->table('sites')->useWritePdo()->where('org_id', $org)->where('id', $site)->lockForUpdate()->first();
        $module = $connection->table('modules')->useWritePdo()->where('handle', 'kitsune/commerce')->lockForUpdate()->first();
        if ($parent === null || $module === null || ! $module->is_enabled || $module->version !== ModuleDiscovery::version('kitsune/commerce')) {
            throw new StockRefused;
        }
    }

    private function sellable(Connection $connection, int $key): object
    {
        $row = $connection->table('commerce_sellables')->useWritePdo()->where('org_id', $this->context->orgId())
            ->where('id', $key)->lockForUpdate()->first() ?? throw new StockRefused;
        Sellable::skuValue($row->sku);
        MinorUnits::integer($row->price_minor);
        self::balance($row->stock);
        $org = $connection->table('orgs')->useWritePdo()->where('id', $this->context->orgId())->whereNull('deleted_at')->lockForUpdate()->first();
        if ($org === null || $org->currency !== $row->currency) {
            throw new StockRefused;
        }

        return $row;
    }

    /** @param array{type:string,id:string}|null $actor
     * @return array<string,mixed>
     */
    private function movement(object $row, string $kind, ?string $after, ?int $quantity, string $reason, ?array $actor): array
    {
        return ['org_id' => $this->context->orgId(), 'site_id' => $this->context->siteId(), 'sellable_ref' => $row->id,
            'sku' => $row->sku, 'reference' => bin2hex(random_bytes(16)), 'kind' => $kind,
            'before_stock' => self::balance($row->stock), 'after_stock' => $after, 'quantity' => $quantity, 'reason' => $reason,
            'actor_type' => $actor['type'] ?? null, 'actor_id' => $actor['id'] ?? null,
            'order_reference' => null, 'line_reference' => null, 'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s')];
    }

    /** @param array<string,mixed> $movement */
    private function write(Connection $connection, object $row, array $movement): void
    {
        if ($movement['before_stock'] !== $movement['after_stock'] && $connection->table('commerce_sellables')
            ->where('org_id', $row->org_id)->where('id', $row->id)->where('stock', $row->stock)
            ->update(['stock' => $movement['after_stock'], 'updated_at' => $movement['created_at']]) !== 1) {
            throw new StockRefused;
        }
        $id = $connection->table(self::TABLE)->insertGetId($movement);
        $model = (new StockMovement)->newFromBuilder([...$movement, 'id' => $id]);
        $action = $movement['kind'] === 'manual' ? 'commerce.stock_adjusted' : 'commerce.stock_allocated';
        $actor = Permissions::currentUser();
        $actorType = $actor instanceof Model ? $actor->getMorphClass() : ($actor === null ? null : $actor::class);
        $actorId = $actor?->getAuthIdentifier();
        $actorId = is_int($actorId) || is_string($actorId) ? (string) $actorId : null;
        $audit = $this->auditor->recordOrFail($action, $model);
        $storedAudit = $connection->table('audit_log')->useWritePdo()->where('id', $audit->getKey())->lockForUpdate()->first();
        if (! $audit->exists || $storedAudit === null || (string) $storedAudit->org_id !== (string) $movement['org_id']
            || (string) $storedAudit->site_id !== (string) $movement['site_id'] || $storedAudit->action !== $action
            || $storedAudit->target_type !== $model->getMorphClass() || (string) $storedAudit->target_id !== (string) $id
            || $storedAudit->actor_type !== $actorType || $storedAudit->actor_id !== $actorId) {
            throw new StockRefused;
        }
        $saved = $connection->table(self::TABLE)->useWritePdo()->where('id', $id)->lockForUpdate()->first() ?? throw new StockRefused;
        foreach ($movement as $column => $value) {
            if (($saved->{$column} === null) !== ($value === null) || ($value !== null && (string) $saved->{$column} !== (string) $value)) {
                throw new StockRefused;
            }
        }
        $this->validateMovement($connection, $saved);
        $current = $this->sellable($connection, (int) $row->id);
        foreach ((array) $row as $column => $value) {
            $expected = match ($column) {
                'stock' => $movement['after_stock'],
                'updated_at' => $movement['before_stock'] === $movement['after_stock'] ? $value : $movement['created_at'],
                default => $value,
            };
            if (($current->{$column} === null) !== ($expected === null) || ($expected !== null && (string) $current->{$column} !== (string) $expected)) {
                throw new StockRefused;
            }
        }
    }

    private function validateMovement(Connection $connection, object $row): void
    {
        $site = $connection->table('sites')->useWritePdo()->where('id', $row->site_id)->where('org_id', $row->org_id)->first();
        if ((string) $row->org_id !== (string) $this->context->orgId() || $site === null
            || preg_match('/\A[a-f0-9]{32}\z/D', $row->reference) !== 1 || ! in_array($row->kind, ['manual', 'sale', 'free_order'], true)) {
            throw new StockRefused;
        }
        Sellable::catalogueKey($row->sellable_ref);
        Sellable::skuValue($row->sku);
        self::reason($row->reason);
        $before = self::balance($row->before_stock);
        $after = self::balance($row->after_stock);
        if ($row->kind === 'manual') {
            if ($before === $after || $row->quantity !== null || $row->order_reference !== null || $row->line_reference !== null
                || ! is_string($row->actor_type) || $row->actor_type === '' || ! is_string($row->actor_id) || $row->actor_id === '') {
                throw new StockRefused;
            }
        } elseif ((int) $row->quantity < 1 || (int) $row->quantity > 99 || ! is_string($row->order_reference)
            || preg_match('/\A[a-f0-9]{32}\z/D', $row->order_reference) !== 1 || $row->line_reference === null
            || ($before === null ? $after !== null : $after !== self::subtract($before, (int) $row->quantity))) {
            throw new StockRefused;
        }
        if (! is_string($row->created_at) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $row->created_at) !== 1) {
            throw new StockRefused;
        }
    }

    /** @return array<string,mixed> */
    private static function projection(object $row): array
    {
        return ['reference' => $row->reference, 'kind' => $row->kind, 'beforeStock' => self::balance($row->before_stock),
            'afterStock' => self::balance($row->after_stock), 'quantity' => $row->quantity === null ? null : (int) $row->quantity,
            'reason' => $row->reason, 'actorId' => $row->actor_id, 'createdAt' => str_replace(' ', 'T', $row->created_at).'Z'];
    }

    /** @return array{reference:string,sku:string,stock:string|null} */
    private static function productProjection(object $row): array
    {
        return ['reference' => (string) Sellable::catalogueKey($row->id), 'sku' => Sellable::skuValue($row->sku), 'stock' => self::balance($row->stock)];
    }

    private static function balance(mixed $stock): ?string
    {
        return $stock === null ? null : MinorUnits::integer($stock);
    }

    private static function reason(string $reason): string
    {
        if (strlen($reason) > 2000 || ! mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason) > 500
            || preg_match('/\S/u', $reason) !== 1 || preg_match('/[\p{Cc}\x{2028}\x{2029}]/u', $reason) === 1) {
            throw new StockRefused;
        }

        return $reason;
    }

    private static function subtract(string $stock, int $quantity): string
    {
        if (strlen($stock) < strlen((string) $quantity) || (strlen($stock) === strlen((string) $quantity) && strcmp($stock, (string) $quantity) < 0)) {
            throw new StockRefused;
        }
        $digits = strrev($stock);
        $right = strrev((string) $quantity);
        $borrow = 0;
        $result = '';
        for ($i = 0; $i < strlen($digits); $i++) {
            $next = (int) $digits[$i] - (int) ($right[$i] ?? '0') - $borrow;
            $borrow = $next < 0 ? 1 : 0;
            $result = (string) ($next < 0 ? $next + 10 : $next).$result;
        }

        return MinorUnits::integer(ltrim($result, '0') ?: '0');
    }

    /** @template T
     * @param  \Closure():T  $work
     * @return T
     */
    private function safe(\Closure $work): mixed
    {
        try {
            return $work();
        } catch (Throwable) {
            throw new StockRefused;
        }
    }
}
