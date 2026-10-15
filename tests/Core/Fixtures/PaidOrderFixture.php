<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Catalogue\CatalogueWriter;
use Kitsune\Commerce\Models\Sellable;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Payments\CheckoutReview;
use Kitsune\Commerce\Payments\StripeAmounts;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/** Real catalogue endpoints and a frozen exact order/Session proof, without external I/O. */
final class PaidOrderFixture
{
    public static function attempt(Site $site, ?string $stock = '2', int $quantity = 3, ?Sellable $sellable = null): object
    {
        app(Context::class)->setSite($site);
        StripeInboxFixture::enable();
        $currency = DB::table('orgs')->where('id', $site->org_id)->value('currency');
        $type = EntryType::query()->where('handle', 'paid-product')->first() ?? EntryType::create(['handle' => 'paid-product',
            'name' => 'Paid product', 'plural_name' => 'Paid products']);
        $entry = Entry::create(['entry_type_id' => $type->getKey(), 'slug' => 'paid-'.bin2hex(random_bytes(6)), 'title' => 'A paid product']);
        $writer = app(CatalogueWriter::class);
        $sellable ??= $writer->createSellable('PAID-'.strtoupper(bin2hex(random_bytes(5))), '2000', $stock, $currency);
        $listing = $writer->createListing($sellable->getKey(), $entry->getKey(), true);
        $attempt = StripeInboxFixture::attempt($site, currency: $currency);
        DB::table('commerce_order_lines')->where('order_id', $attempt->order_id)->update(['sellable_ref' => $sellable->getKey(),
            'listing_ref' => $listing->getKey(), 'entry_ref' => $entry->getKey(), 'sku' => $sellable->sku, 'quantity' => $quantity,
            'net_minor' => (string) (2000 * $quantity), 'tax_minor' => (string) (100 * $quantity), 'gross_minor' => (string) (2100 * $quantity)]);
        DB::table('commerce_orders')->where('id', $attempt->order_id)->update(['quoted_item_net_minor' => (string) (2000 * $quantity),
            'quoted_tax_minor' => (string) (100 * $quantity), 'quoted_total_minor' => (string) (2100 * $quantity)]);

        return self::refresh($attempt);
    }

    public static function refresh(object $attempt): object
    {
        $order = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
        $old = CheckoutAttempts::request($attempt);
        $old['line_items'] = [array_replace_recursive($old['line_items'][0], ['price_data' => ['unit_amount' => StripeAmounts::toWire($order->quoted_total_minor, $order->currency)]])];
        DB::table(CheckoutAttempts::TABLE)->where('id', $attempt->id)->update(['request_payload' => json_encode($old, JSON_THROW_ON_ERROR), 'request_hash' => CheckoutAttempts::digest($old)]);
        $record = app(OrderReaderData::class)->record(DB::connection(), $order);
        $payload = CheckoutAttempts::payload($record, $attempt->reference, CheckoutAttempts::instant($attempt->expires_at)->getTimestamp(), 'https://paid.example.test');
        DB::table(CheckoutAttempts::TABLE)->where('id', $attempt->id)->update(['request_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'request_hash' => CheckoutAttempts::digest($payload), 'order_hash' => CheckoutReview::orderHash($record)]);

        return DB::table(CheckoutAttempts::TABLE)->where('id', $attempt->id)->sole();
    }

    /** @return array<string,mixed> */
    public static function session(object $attempt): array
    {
        $payload = CheckoutAttempts::request($attempt);
        $order = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
        $amount = StripeAmounts::toWire($order->quoted_total_minor, $order->currency);

        return ['id' => $attempt->session_id, 'test' => true, 'currency' => $order->currency, 'amountTotal' => $amount,
            'clientReference' => $order->reference, 'attemptReference' => $attempt->reference, 'orderReference' => $order->reference,
            'expiresAt' => $payload['expires_at'], 'paymentStatus' => 'paid', 'status' => 'complete', 'url' => null,
            'paymentIntent' => 'pi_'.$attempt->reference, 'payment' => ['intentId' => 'pi_'.$attempt->reference,
                'chargeId' => 'ch_'.$attempt->reference, 'currency' => $order->currency, 'amount' => $amount, 'received' => $amount,
                'captured' => $amount, 'refunded' => '0', 'disputed' => false, 'test' => true]];
    }

    /** @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public static function expanded(array $session): array
    {
        if (($session['payment_status'] ?? null) !== 'paid') {
            return $session;
        }
        $intent = is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : 'pi_PaidFixture';
        $amount = $session['amount_total'];
        $currency = $session['currency'];
        $session['payment_intent'] = ['object' => 'payment_intent', 'id' => $intent, 'livemode' => false, 'status' => 'succeeded',
            'amount' => $amount, 'amount_received' => $amount, 'currency' => $currency,
            'latest_charge' => ['object' => 'charge', 'id' => 'ch_'.substr($intent, 3), 'livemode' => false, 'payment_intent' => $intent,
                'amount' => $amount, 'amount_captured' => $amount, 'amount_refunded' => 0, 'currency' => $currency,
                'paid' => true, 'captured' => true, 'status' => 'succeeded', 'disputed' => false]];

        return $session;
    }
}
