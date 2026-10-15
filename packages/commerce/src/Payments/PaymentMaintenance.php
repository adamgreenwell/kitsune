<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Payments;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Tenancy\Context;
use Throwable;

/** Bounded operator catch-up and refund recovery; execution is explicit, with no required scheduler. @internal */
final class PaymentMaintenance
{
    public function __construct(private readonly Context $context) {}

    /** @return array{processed:int,blocked:int} */
    public function run(int $limit = 100, int $seconds = 30, bool $refreshSuccessful = false): array
    {
        if ($limit < 1 || $limit > 1000 || $seconds < 1 || $seconds > 300 || (new Order)->getConnection()->transactionLevel() !== 0) {
            throw new CheckoutRefused;
        }
        $org = $this->context->orgId() ?? throw new CheckoutRefused;
        $before = $this->context->site();
        $beforeOrg = $this->context->org();
        $deadline = hrtime(true) + $seconds * 1000000000;
        $counts = ['processed' => 0, 'blocked' => 0];
        try {
            $connection = (new Order)->getConnection();
            $this->ready($connection);
            $sites = Site::query()->useWritePdo()->where('org_id', $org)->orderBy('id')->get()->keyBy('id');
            $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
            $work = [];
            // Existing #198 completed receipts need fresh payment proof even when all their inbox events are processed.
            $attempts = $connection->table(CheckoutAttempts::TABLE.' as a')->useWritePdo()->where('a.org_id', $org)
                ->whereIn('a.site_id', $sites->keys()->all())->where('a.state', 'completed')
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from(PaidOrders::RECEIPTS.' as p')->whereColumn('p.order_id', 'a.order_id'))
                ->where(fn ($query) => $query->whereNull('a.lease_until')->orWhere('a.lease_until', '<=', $now))
                ->orderBy('a.confirmation_replay_count')->orderBy('a.id')->limit($limit)
                ->get(['a.id', 'a.site_id', 'a.order_id', 'a.reference', 'a.session_id', 'a.confirmation_replay_count']);
            foreach ($attempts as $attempt) {
                $work[] = ['kind' => 'confirmation', 'count' => self::counter($attempt->confirmation_replay_count),
                    'id' => (int) $attempt->id, 'row' => $attempt];
            }
            $query = $connection->table(PaidOrders::ATTEMPTS.' as a')->useWritePdo()->where('a.org_id', $org)
                ->whereIn('a.site_id', $sites->keys()->all())
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from(PaidOrders::ATTEMPTS.' as newer')
                    ->whereColumn('newer.obligation_id', 'a.obligation_id')->whereColumn('newer.id', '>', 'a.id'))
                ->where(fn ($q) => $q->whereNull('a.lease_until')->orWhere('a.lease_until', '<=', $now));
            if (! $refreshSuccessful) {
                $query->where('a.state', '!=', 'succeeded');
            }
            foreach ($query->orderBy('a.replay_count')->orderBy('a.id')->limit($limit)->get(['a.id', 'a.site_id', 'a.order_id', 'a.reference', 'a.replay_count']) as $attempt) {
                $work[] = ['kind' => 'refund', 'count' => self::counter($attempt->replay_count), 'id' => (int) $attempt->id, 'row' => $attempt];
            }
            if (app(AccessFulfillment::class)->schemaAvailable($connection)) {
                foreach ($connection->table(AccessFulfillment::WORK.' as a')->useWritePdo()->where('a.org_id', $org)
                    ->whereIn('a.site_id', $sites->keys()->all())->whereIn('a.state', ['initializing', 'pending', 'held'])
                    ->orderBy('a.replay_count')->orderBy('a.id')->limit($limit)->get(['a.id', 'a.site_id', 'a.order_id', 'a.replay_count']) as $access) {
                    $work[] = ['kind' => 'access', 'count' => self::counter($access->replay_count), 'id' => (int) $access->id, 'row' => $access];
                }
            }
            usort($work, static fn (array $left, array $right): int => $left['count'] <=> $right['count']
                ?: $left['id'] <=> $right['id'] ?: strcmp($left['kind'], $right['kind']));
            foreach ($work as $candidate) {
                if (hrtime(true) >= $deadline || array_sum($counts) >= $limit) {
                    break;
                }
                try {
                    $attempt = $candidate['row'];
                    $this->context->setSite($sites->get($attempt->site_id) ?? throw new CheckoutRefused);
                    $order = $this->claim($connection, $org, $candidate, $refreshSuccessful);
                    if ($order === null) {
                        continue;
                    }
                    if ($candidate['kind'] === 'confirmation') {
                        app(CheckoutReconciler::class)->reconcile($order->reference, $attempt->reference, $attempt->session_id ?? '');
                        $counts['processed']++;
                    } elseif ($candidate['kind'] === 'refund') {
                        $result = app(RefundRecovery::class)->reconcile($order->reference, $attempt->reference);
                        $counts[in_array($result['state'], ['held', 'unknown', 'failed', 'canceled', 'requires_action'], true) ? 'blocked' : 'processed']++;
                    } else {
                        $result = app(AccessFulfillment::class)->reconcile($order->reference);
                        $counts[$result['state'] === 'processed' ? 'processed' : 'blocked']++;
                    }
                } catch (Throwable) {
                    $counts['blocked']++;
                }
            }

            return $counts;
        } finally {
            $this->context->forget();
            $before === null ? $this->context->setOrg($beforeOrg) : $this->context->setSite($before);
        }
    }

    /** Selection is durable before credential/provider admission. RefundRecovery additionally counts its provider
     * claims; blocked old credentials therefore cannot monopolize either work type. Active leases are checked both
     * during selection and under the Org lock, so counting never changes an in-flight reconciliation graph.
     *
     * @param  array{kind:string,count:int,id:int,row:object}  $candidate
     */
    private function claim(Connection $connection, int $org, array $candidate, bool $refreshSuccessful): ?object
    {
        return app(OrderLocks::class)->run(function () use ($connection, $org, $candidate, $refreshSuccessful): ?object {
            $this->ready($connection);
            $site = $this->context->siteId() ?? throw new CheckoutRefused;
            $table = match ($candidate['kind']) {
                'confirmation' => CheckoutAttempts::TABLE,
                'refund' => PaidOrders::ATTEMPTS,
                'access' => AccessFulfillment::WORK,
                default => throw new CheckoutRefused,
            };
            $counter = $candidate['kind'] === 'confirmation' ? 'confirmation_replay_count' : 'replay_count';
            $row = $connection->table($table)->useWritePdo()->where('id', $candidate['id'])->where('org_id', $org)
                ->where('site_id', $site)->lockForUpdate()->first();
            if ($row === null || self::counter($row->{$counter}) !== $candidate['count']
                || ($candidate['kind'] !== 'access' && $row->lease_until !== null && CheckoutAttempts::instant($row->lease_until)->greaterThan(CarbonImmutable::now('UTC')))) {
                return null;
            }
            if ($candidate['kind'] === 'confirmation') {
                if ($row->state !== 'completed' || $connection->table(PaidOrders::RECEIPTS)->useWritePdo()->where('order_id', $row->order_id)->exists()) {
                    return null;
                }
            } elseif ($candidate['kind'] === 'access') {
                if (! in_array($row->state, ['initializing', 'pending', 'held'], true)) {
                    return null;
                }
            } elseif ((! $refreshSuccessful && $row->state === 'succeeded')
                || $connection->table(PaidOrders::ATTEMPTS)->useWritePdo()->where('obligation_id', $row->obligation_id)->where('id', '>', $row->id)->exists()) {
                return null;
            }
            $order = $connection->table('commerce_orders')->useWritePdo()->where('id', $row->order_id)
                ->where('org_id', $org)->where('site_id', $site)->lockForUpdate()->first() ?? throw new CheckoutRefused;
            $count = self::counter($row->{$counter});
            if ($count >= 2147483647 || $connection->table($table)->where('id', $row->id)->where('org_id', $org)
                ->where('site_id', $site)->where($counter, $count)->update([$counter => $count + 1]) !== 1) {
                throw new CheckoutRefused;
            }

            return $order;
        });
    }

    private function ready(Connection $connection): void
    {
        $module = $connection->table('modules')->useWritePdo()->where('handle', 'kitsune/commerce')->where('is_enabled', true)->first();
        if ($this->context->orgId() === null || $module === null || $module->version !== ModuleDiscovery::version('kitsune/commerce')
            || ! $connection->getSchemaBuilder()->hasColumn(CheckoutAttempts::TABLE, 'confirmation_replay_count')) {
            throw new CheckoutRefused('setup');
        }
    }

    private static function counter(mixed $value): int
    {
        if ((! is_int($value) && ! is_string($value)) || preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', (string) $value) !== 1
            || (int) $value > 2147483647) {
            throw new CheckoutRefused;
        }

        return (int) $value;
    }

    /** The FPM response already finished. One newly owed refund can progress after its checkout receipt commits. */
    public function afterEvent(int $id): void
    {
        $org = $this->context->orgId() ?? throw new CheckoutRefused;
        $beforeOrg = $this->context->org();
        $beforeSite = $this->context->site();
        try {
            $connection = (new Order)->getConnection();
            $event = $connection->table(StripeEventInbox::TABLE)->useWritePdo()->where('org_id', $org)->where('id', $id)->where('state', 'processed')
                ->first(['site_id', 'order_id', 'order_reference', 'refund_reference']);
            if ($event === null || $event->refund_reference !== null) {
                return;
            }
            $site = Site::query()->useWritePdo()->whereKey($event->site_id)->where('org_id', $org)->first() ?? throw new CheckoutRefused;
            $this->context->setSite($site);
            try {
                app(AccessFulfillment::class)->reconcile($event->order_reference);
            } catch (Throwable) {
                // Durable independent access work remains available to bounded maintenance and owner retry.
            }
            $attempt = $connection->table(PaidOrders::ATTEMPTS)->useWritePdo()->where('org_id', $org)->where('site_id', $event->site_id)
                ->where('order_id', $event->order_id)->orderByDesc('id')->first();
            if ($attempt !== null && in_array($attempt->state, ['owed', 'unknown'], true)) {
                app(RefundRecovery::class)->reconcile($event->order_reference, $attempt->reference);
            }
        } finally {
            $this->context->forget();
            $beforeSite !== null ? $this->context->setSite($beforeSite) : $this->context->setOrg($beforeOrg);
        }
    }
}
