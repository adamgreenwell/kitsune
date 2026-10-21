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
use Kitsune\Commerce\Payments\StripeReadBudget;
use Kitsune\Commerce\Payments\StripeUnavailable;
use Kitsune\Core\Credentials\Secret;
use PHPUnit\Framework\TestCase;

final class StripeReadBudgetTest extends TestCase
{
    public function test_remaining_transport_time_shrinks_without_changing_the_reusable_adapter(): void
    {
        $now = 0.0;
        $budget = new StripeReadBudget(static function () use (&$now): float {
            return $now;
        });
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], '{"object":"account","id":"acct_Budget"}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"object":"account","id":"acct_Budget"}')]);
        $adapter = new StripeCheckout(new Client(['handler' => HandlerStack::create($mock)]));
        $reader = $adapter->withReadBudget($budget);
        $now = 18.75;
        self::assertSame('acct_Budget', $reader->account(new Secret('sk_test_'.str_repeat('a', 32))));
        foreach (['timeout', 'connect_timeout', 'read_timeout'] as $option) {
            self::assertSame(1.25, $mock->getLastOptions()[$option]);
        }
        $now = 20.0;
        try {
            $reader->account(new Secret('sk_test_'.str_repeat('a', 32)));
            self::fail('Expired budget reached the transport.');
        } catch (StripeUnavailable) {
            self::assertCount(1, $mock);
        }
        self::assertSame('acct_Budget', $adapter->account(new Secret('sk_test_'.str_repeat('a', 32))));
        self::assertSame(10, $mock->getLastOptions()['timeout']);
    }

    public function test_a_sub_millisecond_allowance_never_reaches_the_transport(): void
    {
        $now = 0.0;
        $budget = new StripeReadBudget(static function () use (&$now): float {
            return $now;
        });
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], '{"object":"account","id":"acct_Budget"}')]);
        $reader = (new StripeCheckout(new Client(['handler' => HandlerStack::create($mock)])))->withReadBudget($budget);
        $now = 19.9995;
        try {
            $reader->account(new Secret('sk_test_'.str_repeat('a', 32)));
            self::fail('A sub-millisecond allowance reached the transport.');
        } catch (StripeUnavailable) {
            self::assertNull($mock->getLastRequest());
            self::assertCount(1, $mock);
        }
    }

    public function test_a_late_response_is_refused_even_when_a_handler_ignores_its_timeout(): void
    {
        $now = 0.0;
        $budget = new StripeReadBudget(static function () use (&$now): float {
            return $now;
        });
        $mock = new MockHandler([static function () use (&$now): Response {
            $now = 20.0;

            return new Response(200, ['Content-Type' => 'application/json'], '{"object":"account","id":"acct_Budget"}');
        }]);
        $reader = (new StripeCheckout(new Client(['handler' => HandlerStack::create($mock)])))->withReadBudget($budget);
        $this->expectException(StripeUnavailable::class);
        $reader->account(new Secret('sk_test_'.str_repeat('a', 32)));
    }

    public function test_a_budgeted_reader_cannot_send_a_money_request(): void
    {
        $payload = ['charge' => 'ch_Budget', 'amount' => '100', 'metadata' => ['kitsune_order' => str_repeat('a', 32),
            'kitsune_refund' => str_repeat('b', 32), 'kitsune_request' => str_repeat('c', 64)]];
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'object' => 'refund', 'id' => 're_Budget', 'charge' => 'ch_Budget', 'payment_intent' => 'pi_Budget',
            'currency' => 'usd', 'amount' => 100, 'status' => 'succeeded', 'created' => 1800000000,
            'metadata' => $payload['metadata'],
        ], JSON_THROW_ON_ERROR))]);
        $adapter = new StripeCheckout(new Client(['handler' => HandlerStack::create($mock)]));
        $reader = $adapter->withReadBudget(new StripeReadBudget);
        try {
            $reader->createRefund(new Secret('sk_test_'.str_repeat('a', 32)), $payload, 'budget-refund');
            self::fail('Reader accepted a POST.');
        } catch (StripeUnavailable) {
            self::assertNull($mock->getLastRequest());
            self::assertCount(1, $mock);
        }
        self::assertSame('re_Budget', $adapter->createRefund(new Secret('sk_test_'.str_repeat('a', 32)), $payload, 'budget-refund')['id']);
    }
}
