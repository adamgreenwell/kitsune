<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Kitsune\Commerce\Payments\StripeCheckout;
use Kitsune\Commerce\Payments\StripeUnavailable;
use Kitsune\Core\Credentials\Secret;
use Kitsune\Core\Tests\Fixtures\PaidOrderFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Live mode is explicit at every transport seam; default callers retain their test-only boundary. */
final class LiveStripeTransportTest extends TestCase
{
    public function test_explicit_live_create_retrieve_and_expire_have_the_same_bounded_contract(): void
    {
        [$client, $mock] = self::client(self::response(self::session()), self::response(self::session()), self::response(array_replace(self::session(), ['status' => 'expired', 'url' => null])));
        self::assertSame(self::normalized(), $client->create(self::secret(), self::payload(), 'live-create', 'live'));
        self::assertSame(self::normalized(), $client->retrieve(self::secret(), 'cs_live_fixture', 'live'));
        self::assertSame('expired', $client->expire(self::secret(), 'cs_live_fixture', 'live-expire', 'live')['status']);
        self::assertSame('2026-09-30.endive', $mock->getLastRequest()->getHeaderLine('Stripe-Version'));
        self::assertSame(10, $mock->getLastOptions()['timeout']);
        self::assertTrue($mock->getLastOptions()['verify']);
        self::assertFalse($mock->getLastOptions()['allow_redirects']);
    }

    public function test_only_account_id_leaves_a_ready_live_account_response(): void
    {
        [$client] = self::client(self::response(['object' => 'account', 'id' => 'acct_Live', 'charges_enabled' => true,
            'payouts_enabled' => true, 'details_submitted' => true, 'email' => 'private@example.test', 'company' => ['name' => 'Private']]));
        self::assertSame('acct_Live', $client->liveAccount(self::secret()));
    }

    #[DataProvider('wrongModes')]
    public function test_wrong_keys_and_session_modes_never_reach_http(string $mode, string $prefix, string $session): void
    {
        [$client, $mock] = self::client(self::response(self::session()));
        try {
            $client->retrieve(new Secret($prefix.bin2hex(random_bytes(24))), $session, $mode);
            self::fail('Mismatched mode accepted.');
        } catch (StripeUnavailable $failure) {
            self::assertNull($failure->getPrevious());
            self::assertNull($mock->getLastRequest());
        }
    }

    public static function wrongModes(): array
    {
        return [['live', 'sk_test_', 'cs_live_fixture'], ['test', 'sk_live_', 'cs_test_fixture'],
            ['live', 'sk_live_', 'cs_test_fixture'], ['test', 'sk_test_', 'cs_live_fixture'], ['unexpected', 'sk_live_', 'cs_live_fixture']];
    }

    #[DataProvider('mismatchedObjects')]
    public function test_expanded_live_payment_objects_must_all_match_recorded_mode(string $object): void
    {
        $body = PaidOrderFixture::expanded([...self::session(), 'payment_status' => 'paid', 'status' => 'complete', 'url' => null, 'payment_intent' => 'pi_Live']);
        $body['payment_intent']['livemode'] = true;
        $body['payment_intent']['latest_charge']['livemode'] = true;
        if ($object === 'session') {
            $body['livemode'] = false;
        } elseif ($object === 'intent') {
            $body['payment_intent']['livemode'] = false;
        } else {
            $body['payment_intent']['latest_charge']['livemode'] = false;
        }
        [$client] = self::client(self::response($body));
        $this->expectException(StripeUnavailable::class);
        $client->retrievePaid(self::secret(), 'cs_live_fixture', 'live');
    }

    public static function mismatchedObjects(): array
    {
        return [['session'], ['intent'], ['charge']];
    }

    public function test_expanded_live_payment_proof_normalizes_mode_without_personal_data(): void
    {
        $body = PaidOrderFixture::expanded([...self::session(), 'payment_status' => 'paid', 'status' => 'complete', 'url' => null, 'payment_intent' => 'pi_Live']);
        $body['payment_intent']['livemode'] = true;
        $body['payment_intent']['latest_charge']['livemode'] = true;
        $body['customer_details'] = ['email' => 'private@example.test'];
        [$client] = self::client(self::response($body));
        $result = $client->retrievePaid(self::secret(), 'cs_live_fixture', 'live');
        self::assertFalse($result['test']);
        self::assertFalse($result['payment']['test']);
        self::assertSame('2000', $result['payment']['amount']);
        self::assertStringNotContainsString('private@example.test', json_encode($result, JSON_THROW_ON_ERROR));
    }

    /** @return array{StripeCheckout,MockHandler} */
    private static function client(mixed ...$responses): array
    {
        $mock = new MockHandler($responses);

        return [new StripeCheckout(new Client(['handler' => HandlerStack::create($mock)])), $mock];
    }

    private static function secret(): Secret
    {
        return new Secret('sk_live_'.bin2hex(random_bytes(24)));
    }

    private static function response(mixed $body, int $status = 200, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json; charset=UTF-8', ...$headers], json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function payload(): array
    {
        return ['mode' => 'payment', 'ui_mode' => 'hosted_page', 'allowed_payment_method_types' => ['card'],
            'automatic_tax' => ['enabled' => false], 'adaptive_pricing' => ['enabled' => false],
            'line_items' => [['price_data' => ['currency' => 'usd', 'unit_amount' => 2000, 'product_data' => ['name' => '20.00 & course']], 'quantity' => 1]],
            'metadata' => ['kitsune_attempt' => 'attempt1', 'kitsune_order' => 'order1'], 'client_reference_id' => 'order1',
            'expires_at' => 1800000000, 'success_url' => 'https://shop.test/cart/orders/order1', 'cancel_url' => 'https://shop.test/cart/orders/order1'];
    }

    private static function session(): array
    {
        return ['object' => 'checkout.session', 'id' => 'cs_live_fixture', 'livemode' => true, 'mode' => 'payment',
            'status' => 'open', 'payment_status' => 'unpaid', 'amount_total' => 2000, 'currency' => 'usd', 'expires_at' => 1800000000,
            'client_reference_id' => 'order1', 'metadata' => ['kitsune_attempt' => 'attempt1', 'kitsune_order' => 'order1'],
            'url' => 'https://checkout.stripe.com/c/pay/cs_live_fixture#opaque', 'payment_intent' => null];
    }

    private static function normalized(): array
    {
        return ['id' => 'cs_live_fixture', 'url' => 'https://checkout.stripe.com/c/pay/cs_live_fixture#opaque', 'status' => 'open',
            'paymentStatus' => 'unpaid', 'amountTotal' => '2000', 'currency' => 'USD', 'expiresAt' => 1800000000,
            'clientReference' => 'order1', 'attemptReference' => 'attempt1', 'orderReference' => 'order1', 'test' => false, 'paymentIntent' => null];
    }
}
