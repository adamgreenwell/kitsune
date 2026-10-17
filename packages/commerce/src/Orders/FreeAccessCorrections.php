<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Orders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Payments\AccessAdjustments;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Payments\PaidConfirmationAudit;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Commerce\Privacy\PrivacyState;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Tenancy\Context;

/** Append-only corrections to exact awarded free units. No payment, refund, new term or stock mutation. @internal */
final class FreeAccessCorrections
{
    public const WORK = 'commerce_free_access_work';

    public const TABLE = 'commerce_free_access_corrections';

    public const MAX = 1000;

    public function __construct(private readonly Context $context, private readonly OrderLocks $locks, private readonly Auditor $auditor) {}

    public function prepare(Connection $connection, object $order): void
    {
        $this->inside($connection, $order);
        if ($order->state !== 'completed_free' || $connection->table(self::WORK)->useWritePdo()->where('order_id', $order->id)->exists()) {
            throw new OrderRefused;
        }
        $connection->table(self::WORK)->insert(['org_id' => $order->org_id, 'site_id' => $order->site_id, 'order_id' => $order->id,
            'revision' => 0, 'head_hash' => $this->genesis($order)]);
    }

    /** @param array<int|string,mixed> $units
     * @return string The immutable correction reference; repeating one request never applies it twice.
     */
    public function changeAsOwner(string $reference, array $units, string $action, int $revision, string $reason, string $requestKey, bool $confirmed): string
    {
        if (! $confirmed || ! in_array($action, ['remove', 'restore'], true) || $revision < 0 || $revision >= self::MAX
            || preg_match('/\A[a-f0-9]{32}\z/D', $reference) !== 1 || preg_match('/\A[a-f0-9]{32}\z/D', $requestKey) !== 1
            || $reason !== trim($reason) || $reason === '' || ! mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason) > 500
            || preg_match('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]/u', $reason) === 1) {
            throw new OrderRefused;
        }
        $selection = self::selection($units);

        return $this->locks->run(function () use ($reference, $selection, $action, $revision, $reason, $requestKey): string {
            $connection = (new Order)->getConnection();
            $actor = $this->authority($connection);
            $order = $connection->table('commerce_orders')->useWritePdo()->where('org_id', $this->context->orgId())
                ->where('site_id', $this->context->siteId())->where('reference', $reference)->lockForUpdate()->first() ?? throw new OrderRefused;
            $this->inside($connection, $order);
            app(PrivacyState::class)->assertAccessAllowed($connection, $order);
            $plan = $this->plan($connection, $order, true);
            foreach ($plan['history'] as $old) {
                if ($old->request_key === $requestKey) {
                    if ($old->action !== $action || $old->reason !== $reason || (int) $old->revision !== $revision + 1
                        || self::selection(json_decode($old->selection, true, flags: JSON_THROW_ON_ERROR)) !== $selection) {
                        throw new OrderRefused('stale');
                    }

                    return $old->reference;
                }
            }
            if ($plan['revision'] !== $revision) {
                throw new OrderRefused('stale');
            }
            foreach ($selection as $line => $selected) {
                foreach ($selected as $unit) {
                    $removed = in_array($unit, $plan['selected_units'][$line] ?? [], true);
                    if ($unit > ($plan['awarded'][$line] ?? 0) || ($action === 'remove' ? $removed : ! $removed)) {
                        throw new OrderRefused('stale');
                    }
                }
            }
            $row = ['org_id' => $order->org_id, 'site_id' => $order->site_id, 'order_id' => $order->id,
                'reference' => bin2hex(random_bytes(16)), 'request_key' => $requestKey, 'revision' => $revision + 1,
                'action' => $action, 'selection' => json_encode($selection, JSON_THROW_ON_ERROR), 'reason' => $reason,
                'previous_hash' => $plan['head_hash'], 'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s')];
            $row['record_hash'] = $this->recordHash((object) $row);
            $connection->table(self::TABLE)->insert($row);
            if ($connection->table(self::WORK)->where('id', $plan['work']->id)->where('org_id', $order->org_id)->where('site_id', $order->site_id)
                ->where('order_id', $order->id)->where('revision', $revision)->where('head_hash', $plan['head_hash'])
                ->update(['revision' => $revision + 1, 'head_hash' => $row['record_hash']]) !== 1) {
                throw new OrderRefused;
            }
            $before = app(OrderReaderData::class)->record($connection, $order);
            $inventory = $this->inventory($connection, $order);
            $graph = app(PaidConfirmationAudit::class)->witness($connection, $order);
            $model = (new Order)->newFromBuilder(get_object_vars($order));
            $auditAction = 'commerce.free_access_'.$action;
            $audit = $this->auditor->recordOrFail($auditAction, $model);
            $stored = $audit->exists ? $connection->table('audit_log')->useWritePdo()->where('id', $audit->getKey())->lockForUpdate()->first() : null;
            if ($stored === null || (string) $stored->org_id !== (string) $order->org_id || (string) $stored->site_id !== (string) $order->site_id
                || $stored->action !== $auditAction || $stored->target_type !== $model->getMorphClass() || (string) $stored->target_id !== (string) $order->id
                || $stored->actor_type !== $actor['type'] || $stored->actor_id !== $actor['id'] || $actor !== $this->authority($connection)
                || $graph !== app(PaidConfirmationAudit::class)->witness($connection, $order)
                || CheckoutAttempts::digest($before) !== CheckoutAttempts::digest(app(OrderReaderData::class)->record($connection, $order))) {
                throw new OrderRefused;
            }

            $finalAudits = $connection->table('audit_log')->useWritePdo()->where('id', $audit->getKey())->lockForUpdate()->get();
            if ($finalAudits->count() !== 1 || get_object_vars($finalAudits->sole()) !== get_object_vars($stored)) {
                throw new OrderRefused;
            }

            // Entirely local: source removal/restoration and its checked correction commit atomically.
            app(AccessAdjustments::class)->reconcile($reference);
            app(AccessFulfillment::class)->reconcile($reference);
            app(AccessAdjustments::class)->reconcile($reference);
            if ($actor !== $this->authority($connection) || $this->plan($connection, $order, true)['head_hash'] !== $row['record_hash']
                || $inventory !== $this->inventory($connection, $order)) {
                throw new OrderRefused;
            }
            $lastAudits = $connection->table('audit_log')->useWritePdo()->where('id', $audit->getKey())->lockForUpdate()->get();
            if ($lastAudits->count() !== 1 || get_object_vars($lastAudits->sole()) !== get_object_vars($stored)) {
                throw new OrderRefused;
            }

            return $row['reference'];
        });
    }

    /** Checked raw chain is also used by cold exports and privacy. No provider or entitlement write.
     * @return array<string,mixed>
     */
    public function plan(Connection $connection, object $order, bool $lock = false): array
    {
        app(FreeOrders::class)->receipt($connection, $order, $lock) ?? throw new OrderRefused;
        $parents = $connection->table(self::WORK)->useWritePdo()->where('order_id', $order->id)->limit(2)
            ->when($lock, fn ($q) => $q->lockForUpdate())->get();
        if ($parents->count() !== 1) {
            throw new OrderRefused;
        }
        $work = $parents->sole();
        $this->child($work, $order);
        $revision = AccessFulfillment::counter($work->revision, self::MAX);
        $history = $connection->table(self::TABLE)->useWritePdo()->where('order_id', $order->id)->orderBy('revision')->limit(self::MAX + 1)
            ->when($lock, fn ($q) => $q->lockForUpdate())->get()->all();
        if (count($history) !== $revision) {
            throw new OrderRefused;
        }
        $allocations = $connection->table(PaidOrders::ALLOCATIONS)->useWritePdo()->where('order_id', $order->id)->orderBy('line_id')->get();
        $awarded = [];
        foreach ($allocations as $allocation) {
            $awarded[(int) $allocation->line_id] = (int) $allocation->awarded;
        }
        $head = $this->genesis($order);
        $removed = [];
        foreach ($history as $index => $row) {
            $this->child($row, $order);
            if ((int) $row->revision !== $index + 1 || $row->previous_hash !== $head || $row->record_hash !== $this->recordHash($row)
                || ! in_array($row->action, ['remove', 'restore'], true)) {
                throw new OrderRefused;
            }
            $selection = self::selection(json_decode($row->selection, true, flags: JSON_THROW_ON_ERROR));
            foreach ($selection as $line => $units) {
                foreach ($units as $unit) {
                    $wasRemoved = isset($removed[$line][$unit]);
                    if ($unit > ($awarded[$line] ?? 0) || ($row->action === 'remove' ? $wasRemoved : ! $wasRemoved)) {
                        throw new OrderRefused;
                    }
                    if ($row->action === 'remove') {
                        $removed[$line][$unit] = true;
                    } else {
                        unset($removed[$line][$unit]);
                    }
                }
            }
            $head = $row->record_hash;
            CheckoutAttempts::instant($row->created_at);
        }
        if ($work->head_hash !== $head) {
            throw new OrderRefused;
        }
        $counts = $selected = [];
        foreach ($removed as $line => $units) {
            $counts[$line] = count($units);
            $selected[$line] = array_keys($units);
            sort($selected[$line], SORT_NUMERIC);
        }
        ksort($counts, SORT_NUMERIC);

        return ['revision' => $revision, 'head_hash' => $head, 'removed_units' => $counts, 'selected_units' => $selected,
            'awarded' => $awarded, 'history' => $history, 'work' => $work];
    }

    /** @return array<string,mixed> */
    public function projection(Connection $connection, object $order, bool $lock = false): array
    {
        if ($order->state !== 'completed_free') {
            return [];
        }
        $plan = $this->plan($connection, $order, $lock);

        return ['free_access_corrections' => ['revision' => $plan['revision'], 'removed_units' => $plan['removed_units'], 'selected_units' => $plan['selected_units'],
            'history' => array_map(static fn (object $row): array => ['reference' => $row->reference, 'action' => $row->action,
                'created_at' => CheckoutAttempts::instant($row->created_at)->format('Y-m-d\TH:i:s\Z')], $plan['history'])]];
    }

    /** @param array<int|string,mixed> $value
     * @return array<int,list<int>>
     */
    private static function selection(array $value): array
    {
        if ($value === [] || count($value) > 20) {
            throw new OrderRefused;
        }
        $result = [];
        foreach ($value as $line => $units) {
            if (preg_match('/\A[1-9][0-9]{0,18}\z/D', (string) $line) !== 1 || (int) $line < 1
                || ! is_array($units) || ! array_is_list($units) || $units === [] || count($units) > 99) {
                throw new OrderRefused;
            }
            $selected = [];
            foreach ($units as $unit) {
                if ((! is_int($unit) && ! is_string($unit)) || preg_match('/\A[1-9][0-9]?\z/D', (string) $unit) !== 1 || isset($selected[(int) $unit])) {
                    throw new OrderRefused;
                }
                $selected[(int) $unit] = true;
            }
            $result[(int) $line] = array_keys($selected);
            sort($result[(int) $line], SORT_NUMERIC);
        }
        ksort($result, SORT_NUMERIC);

        return $result;
    }

    private function recordHash(object $row): string
    {
        $values = [];
        foreach (['org_id', 'site_id', 'order_id', 'reference', 'request_key', 'revision', 'action', 'reason', 'previous_hash', 'created_at'] as $key) {
            $values[$key] = (string) $row->{$key};
        }
        $values['selection'] = self::selection(json_decode($row->selection, true, flags: JSON_THROW_ON_ERROR));

        return CheckoutAttempts::digest($values);
    }

    /** Corrections never change shared stock, including from a host entitlement-audit callback. */
    private function inventory(Connection $connection, object $order): string
    {
        $ids = $connection->table('commerce_order_lines')->useWritePdo()->where('org_id', $order->org_id)->where('site_id', $order->site_id)
            ->where('order_id', $order->id)->pluck('sellable_ref')->all();
        $products = $connection->table('commerce_sellables')->useWritePdo()->where('org_id', $order->org_id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        $movements = $connection->table('commerce_stock_movements')->useWritePdo()->where('org_id', $order->org_id)->where('order_reference', $order->reference)->orderBy('id')->lockForUpdate()->get();

        return CheckoutAttempts::digest(['products' => $products->map(get_object_vars(...))->all(), 'movements' => $movements->map(get_object_vars(...))->all()]);
    }

    private function genesis(object $order): string
    {
        return CheckoutAttempts::digest(['free_access' => 1, 'org' => (string) $order->org_id, 'site' => (string) $order->site_id,
            'order' => (string) $order->id, 'reference' => $order->reference]);
    }

    private function child(object $row, object $order): void
    {
        if ($this->context->orgId() !== (int) $order->org_id || (string) $row->org_id !== (string) $order->org_id
            || (string) $row->site_id !== (string) $order->site_id || (string) $row->order_id !== (string) $order->id) {
            throw new OrderRefused;
        }
    }

    private function inside(Connection $connection, object $order): void
    {
        if ($connection !== (new Order)->getConnection() || $connection->transactionLevel() < 1 || ! $connection->getPdo()->inTransaction()
            || $this->context->orgId() !== (int) $order->org_id || $this->context->siteId() !== (int) $order->site_id) {
            throw new OrderRefused;
        }
    }

    /** Fresh authority is checked again after host audit code, including actual membership and stored actor.
     * @return array<string,mixed> */
    private function authority(Connection $connection): array
    {
        Permissions::forget();
        $actor = Permissions::currentUser();
        if (! $actor instanceof Model || ! Permissions::isOwner($actor) || $actor->getConnection() !== $connection) {
            throw new OrderRefused;
        }
        $row = $connection->table($actor->getTable())->useWritePdo()->where($actor->getKeyName(), $actor->getKey())->lockForUpdate()->first()
            ?? throw new OrderRefused;

        return ['type' => $actor->getMorphClass(), 'id' => (string) $actor->getAuthIdentifier(), 'row' => get_object_vars($row),
            'org' => $this->context->orgId(), 'site' => $this->context->siteId(), 'permissions' => Permissions::held($actor)];
    }
}
