<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Payments\FinancialState;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Core\Models\Site;

/** Real original offer/allocation plus normalized authenticated-provider witnesses; no provider calls. */
final class FinancialAccessFixture
{
    /** @param list<array{days:?int,quantity:int,name?:string,unknown?:bool}> $offers @param array<string,mixed> $payment */
    public static function purchase(Site $site, ?string $reader, array $offers = [['days' => 30, 'quantity' => 1]], array $payment = []): object
    {
        $attempt = PaidOrderFixture::attempt($site, null, $offers[0]['quantity']);
        $order = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
        if ($reader !== null) {
            $owner = CartOwner::readerKey($reader);
            DB::table('commerce_orders')->where('id', $order->id)->update(['reader_id' => $reader, 'owner_key' => $owner,
                'active_cart_key' => hash('sha256', $owner."\0".$order->cart_ref."\0".$order->cart_version)]);
        }
        $base = (array) DB::table('commerce_order_lines')->where('order_id', $order->id)->sole();
        $first = $base['id'];
        unset($base['id']);
        $quantity = 0;
        foreach ($offers as $index => $offer) {
            $quantity += $offer['quantity'];
            $terms = $offer['days'] === null ? ['version' => 1, 'kind' => 'permanent'] : ['version' => 1, 'kind' => 'fixed', 'days' => $offer['days']];
            if ($offer['unknown'] ?? false) {
                $terms = null;
            }
            $line = [...$base, 'quantity' => $offer['quantity'], 'net_minor' => (string) (2000 * $offer['quantity']),
                'tax_minor' => (string) (100 * $offer['quantity']), 'gross_minor' => (string) (2100 * $offer['quantity']),
                'entitlement_name' => $offer['name'] ?? 'course.purchased', 'offer_terms' => $terms === null ? null : json_encode($terms, JSON_THROW_ON_ERROR)];
            $index === 0 ? DB::table('commerce_order_lines')->where('id', $first)->update($line) : DB::table('commerce_order_lines')->insert($line);
        }
        DB::table('commerce_orders')->where('id', $order->id)->update(['quoted_item_net_minor' => (string) (2000 * $quantity),
            'quoted_tax_minor' => (string) (100 * $quantity), 'quoted_total_minor' => (string) (2100 * $quantity)]);
        $attempt = PaidOrderFixture::refresh($attempt);
        $session = PaidOrderFixture::session($attempt);
        $session['payment'] = array_replace($session['payment'], $payment);
        app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm(DB::connection(),
            DB::table('commerce_orders')->where('id', $order->id)->sole(), $attempt, $session));

        return DB::table('commerce_orders')->where('id', $order->id)->sole();
    }

    /** @return array<string,mixed> */
    public static function refund(object $order, string $reference, string $status, string $id = 're_AccessAdjustment'): array
    {
        $row = DB::table(FinancialState::REFUNDS)->where('order_id', $order->id)->where('reference', $reference)->sole();
        $request = json_decode($row->request_payload, true, flags: JSON_THROW_ON_ERROR);
        $receipt = DB::table(PaidOrders::RECEIPTS)->where('order_id', $order->id)->sole();

        return ['id' => $id, 'chargeId' => $receipt->charge_id, 'paymentIntent' => $receipt->payment_intent_id,
            'currency' => $receipt->currency, 'amount' => $request['amount'], 'status' => $status,
            'created' => 1792065600, 'metadata' => $request['metadata']];
    }

    /** @return array<string,mixed> */
    public static function dispute(object $order, string $status, string $id = 'du_AccessAdjustment'): array
    {
        $receipt = DB::table(PaidOrders::RECEIPTS)->where('order_id', $order->id)->sole();

        return ['id' => $id, 'chargeId' => $receipt->charge_id, 'paymentIntent' => $receipt->payment_intent_id,
            'currency' => $receipt->currency, 'amount' => (string) $receipt->amount_minor,
            'status' => $status, 'created' => 1792065600, 'test' => true];
    }

    /** @param list<array<string,mixed>> $refunds @param list<array<string,mixed>> $disputes */
    public static function observe(object $order, array $refunds = [], array $disputes = []): void
    {
        $receipt = DB::table(PaidOrders::RECEIPTS)->where('order_id', $order->id)->sole();
        $total = '0';
        foreach ($refunds as $refund) {
            if (! in_array($refund['status'], ['failed', 'canceled'], true)) {
                $total = (string) ((int) $total + (int) $refund['amount']);
            }
        }
        $graph = ['charge' => ['id' => $receipt->charge_id, 'paymentIntent' => $receipt->payment_intent_id,
            'currency' => $receipt->currency, 'amount' => (string) $receipt->amount_minor, 'captured' => (string) $receipt->amount_minor,
            'refunded' => $total, 'disputed' => $disputes !== [], 'paid' => true, 'capturedFully' => true,
            'status' => 'succeeded', 'test' => true], 'refunds' => $refunds, 'disputes' => $disputes];
        app(OrderLocks::class)->run(fn () => app(FinancialState::class)->observe(DB::connection(), $order, $graph));
    }
}
