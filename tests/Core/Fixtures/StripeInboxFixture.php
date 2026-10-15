<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Payments\CheckoutReview;
use Kitsune\Commerce\Payments\StripeCheckout;
use Kitsune\Commerce\Payments\StripeEventInbox;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Tenancy\Context;

/** Small valid historical test attempt, with no account/key/provider bootstrap. */
final class StripeInboxFixture
{
    public static function enable(): void
    {
        DB::table('modules')->updateOrInsert(['handle' => 'kitsune/commerce'], ['version' => ModuleDiscovery::version('kitsune/commerce'), 'is_enabled' => true, 'installed_at' => OrderFixture::now()]);
    }

    public static function attempt(Site $site, string $state = 'open', bool $route = true): object
    {
        $context = app(Context::class);
        $beforeOrg = $context->org();
        $beforeSite = $context->site();
        try {
            $context->setSite($site);
            $order = OrderFixture::plant($site);
            $record = app(OrderReaderData::class)->record(DB::connection(), $order);
            $reference = bin2hex(random_bytes(16));
            $expires = CarbonImmutable::now('UTC')->startOfSecond()->addSeconds(1860);
            $payload = CheckoutAttempts::payload($record, $reference, $expires->getTimestamp(), rtrim($site->base_url, '/'));
            $data = ['org_id' => $site->org_id, 'site_id' => $site->getKey(), 'order_id' => $order->id, 'reference' => $reference,
                'active_order_id' => in_array($state, ['expired', 'completed', 'rejected'], true) ? null : $order->id,
                'mode' => 'test', 'currency' => 'USD', 'account_id' => 'acct_InboxFixture', 'api_version' => StripeCheckout::API_VERSION,
                'credential_witness' => str_repeat('a', 64), 'state' => $state, 'request_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'request_hash' => CheckoutAttempts::digest($payload), 'order_hash' => CheckoutReview::orderHash($record),
                'session_id' => in_array($state, ['creating', 'uncertain', 'rejected'], true) ? null : 'cs_test_'.bin2hex(random_bytes(16)),
                'checkout_url' => $state === 'open' ? 'https://checkout.stripe.com/c/pay/inboxFixture' : null,
                'reviewed_at' => OrderFixture::now(), 'expires_at' => $expires->format('Y-m-d H:i:s'),
                'lease_token' => null, 'lease_until' => null, 'created_at' => OrderFixture::now(), 'updated_at' => OrderFixture::now()];
            $id = DB::table('commerce_checkout_attempts')->insertGetId($data);
            $attempt = (object) [...$data, 'id' => $id];
            if ($route) {
                DB::transaction(fn () => app(StripeEventInbox::class)->registerAttempt(DB::connection(), $attempt));
            }

            return $attempt;
        } finally {
            $context->forget();
            $beforeSite !== null ? $context->setSite($beforeSite) : $context->setOrg($beforeOrg);
        }
    }

    /** @return array{id:string,type:string,apiVersion:string,sessionId:string,orderReference:string,attemptReference:string,test:bool} */
    public static function event(object $attempt, ?string $id = null): array
    {
        $order = DB::table('commerce_orders')->where('id', $attempt->order_id)->first() ?? throw new \RuntimeException;

        return ['id' => $id ?? 'evt_'.bin2hex(random_bytes(12)), 'type' => 'checkout.session.completed', 'apiVersion' => StripeCheckout::API_VERSION,
            'sessionId' => $attempt->session_id ?? 'cs_test_unknownFixture', 'orderReference' => $order->reference,
            'attemptReference' => $attempt->reference, 'test' => true];
    }
}
