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
use Kitsune\Commerce\Payments\CheckoutRefused;
use Kitsune\Commerce\Payments\StripeCheckout;
use Kitsune\Commerce\Payments\StripeFinancialGraph;
use Kitsune\Commerce\Payments\StripeUnavailable;
use Kitsune\Core\Credentials\Secret;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Real bounded transport/parsers; HTTP is replaced, and no provider account or database is touched. */
final class StripeFinancialTransportTest extends TestCase
{
    public function test_dispute_retrieval_keeps_exact_parent_status_and_discards_customer_evidence(): void
    {
        [$stripe, $mock] = self::client(self::dispute() + ['evidence' => ['customer_email_address' => 'private@example.test']]);
        $result = $stripe->retrieveDispute(self::secret(), 'du_Fixture');
        self::assertSame(['id' => 'du_Fixture', 'chargeId' => 'ch_Fixture', 'paymentIntent' => 'pi_Fixture',
            'currency' => 'USD', 'amount' => '2500', 'status' => 'lost', 'created' => 1800000000, 'test' => true], $result);
        self::assertStringNotContainsString('private', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame('/v1/disputes/du_Fixture', $mock->getLastRequest()->getUri()->getPath());
        self::assertSame(StripeCheckout::API_VERSION, $mock->getLastRequest()->getHeaderLine('Stripe-Version'));
        self::assertSame('', $mock->getLastRequest()->getHeaderLine('Idempotency-Key'));
    }

    #[DataProvider('invalidDisputes')]
    public function test_unknown_malformed_foreign_mode_and_unbound_disputes_fail_without_private_error_chains(string $field, mixed $value): void
    {
        [$stripe] = self::client(array_replace(self::dispute(), [$field => $value]));
        try {
            $stripe->retrieveDispute(self::secret(), 'du_Fixture');
            self::fail('Invalid dispute admitted.');
        } catch (StripeUnavailable $failure) {
            self::assertSame('unknown', $failure->category);
            self::assertNull($failure->getPrevious());
            self::assertStringNotContainsString('private@example.test', (string) $failure);
        }
    }

    public static function invalidDisputes(): array
    {
        return [['object', 'private'], ['id', 'du_Other'], ['charge', 'ch_../private'], ['payment_intent', null],
            ['livemode', true], ['currency', 'USD'], ['amount', '-1'], ['amount', 1.5], ['amount', 0],
            ['status', 'private-status'], ['created', '1800000000']];
    }

    public function test_pages_are_charge_filtered_bounded_and_do_not_accept_duplicate_or_foreign_children(): void
    {
        [$stripe, $mock] = self::client(self::page('disputes', [self::dispute()], true));
        $page = $stripe->listDisputes(self::secret(), 'ch_Fixture', 'du_Previous');
        self::assertTrue($page['hasMore']);
        self::assertSame('du_Fixture', $page['next']);
        parse_str($mock->getLastRequest()->getUri()->getQuery(), $query);
        self::assertSame(['charge' => 'ch_Fixture', 'limit' => '20', 'starting_after' => 'du_Previous'], $query);
        foreach ([self::page('disputes', [self::dispute(), self::dispute()]),
            self::page('disputes', [array_replace(self::dispute(), ['charge' => 'ch_Other'])]),
            self::page('disputes', [], true)] as $bad) {
            [$stripe] = self::client($bad);
            try {
                $stripe->listDisputes(self::secret(), 'ch_Fixture');
                self::fail('Bad page admitted.');
            } catch (StripeUnavailable $failure) {
                self::assertSame('unknown', $failure->category);
            }
        }
    }

    public function test_invalid_paths_live_keys_and_limits_are_refused_before_http(): void
    {
        [$stripe, $mock] = self::client(self::dispute());
        foreach ([fn () => $stripe->retrieveDispute(self::secret(), 'du_../private'),
            fn () => $stripe->retrieveDispute(new Secret('sk_live_'.str_repeat('a', 32)), 'du_Fixture'),
            fn () => $stripe->listDisputes(self::secret(), 'ch_Fixture', limit: 101),
            fn () => $stripe->listDisputes(self::secret(), 'ch_Fixture', 'du_../private')] as $operation) {
            try {
                $operation();
                self::fail('Invalid request admitted.');
            } catch (StripeUnavailable $failure) {
                self::assertSame('rejected', $failure->category);
                self::assertNull($mock->getLastRequest());
            }
        }
    }

    public function test_current_graph_preserves_multiple_dispute_outcomes_and_a_late_failed_refund(): void
    {
        $charge = self::charge();
        $charge['disputed'] = true;
        [$stripe, $mock] = self::client($charge,
            self::page('refunds', [self::refund('failed')]),
            self::page('disputes', [self::dispute(), array_replace(self::dispute(), ['id' => 'du_Won', 'status' => 'won'])]), $charge);
        $checks = 0;
        $graph = (new StripeFinancialGraph($stripe))->retrieve(self::secret(), self::receipt(), static function () use (&$checks): void {
            $checks++;
        });
        self::assertSame('failed', $graph['refunds'][0]['status']);
        self::assertSame(['lost', 'won'], array_column($graph['disputes'], 'status'));
        self::assertSame('2500', $graph['disputes'][0]['amount'], 'A dispute may exceed the original captured amount.');
        self::assertSame(5, $checks);
        self::assertCount(0, $mock);
    }

    #[DataProvider('invalidGraphs')]
    public function test_a_truncated_inconsistent_or_changed_graph_never_returns_permission_to_restore(string $scenario): void
    {
        $charge = self::charge();
        $refund = self::refund();
        $refunds = self::page('refunds', [$refund]);
        $disputes = self::page('disputes', []);
        $final = $charge;
        $responses = [$charge, $refunds, $disputes, $final];
        if ($scenario === 'foreign-currency') {
            $responses[1] = self::page('refunds', [array_replace($refund, ['currency' => 'eur'])]);
        } elseif ($scenario === 'foreign-intent') {
            $responses[1] = self::page('refunds', [array_replace($refund, ['payment_intent' => 'pi_Other'])]);
        } elseif ($scenario === 'changing-charge') {
            $responses[3] = array_replace($charge, ['amount_refunded' => 100]);
        } elseif ($scenario === 'missing-dispute') {
            $responses[0] = $responses[3] = array_replace($charge, ['disputed' => true]);
        } elseif ($scenario === 'uncovered-refund') {
            $responses[0] = $responses[3] = array_replace($charge, ['amount_refunded' => 700]);
        } elseif ($scenario === 'duplicate-pages') {
            $responses = [$charge, self::page('refunds', [$refund], true), self::page('refunds', [$refund])];
        } elseif ($scenario === 'refund-cap') {
            $responses = [$charge, self::page('refunds', [$refund], true),
                self::page('refunds', [array_replace($refund, ['id' => 're_Second'])], true)];
        } elseif ($scenario === 'dispute-cap') {
            $responses = [$charge, $refunds, self::page('disputes', [self::dispute()], true),
                self::page('disputes', [array_replace(self::dispute(), ['id' => 'du_Second'])], true)];
        }
        [$stripe] = self::client(...$responses);
        $this->expectException($scenario === 'duplicate-pages' ? StripeUnavailable::class : CheckoutRefused::class);
        (new StripeFinancialGraph($stripe))->retrieve(self::secret(), self::receipt(), static function (): void {});
    }

    public static function invalidGraphs(): array
    {
        return array_map(static fn (string $case): array => [$case], ['foreign-currency', 'foreign-intent', 'changing-charge',
            'missing-dispute', 'uncovered-refund', 'duplicate-pages', 'refund-cap', 'dispute-cap']);
    }

    private static function client(array ...$bodies): array
    {
        $mock = new MockHandler(array_map(static fn (array $body): Response => new Response(200,
            ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)), $bodies));

        return [new StripeCheckout(new Client(['handler' => HandlerStack::create($mock)])), $mock];
    }

    private static function secret(): Secret
    {
        return new Secret('sk_test_'.str_repeat('a', 32));
    }

    private static function receipt(): object
    {
        return (object) ['mode' => 'test', 'charge_id' => 'ch_Fixture', 'payment_intent_id' => 'pi_Fixture', 'currency' => 'USD', 'amount_minor' => '2000'];
    }

    private static function charge(): array
    {
        return ['id' => 'ch_Fixture', 'object' => 'charge', 'payment_intent' => 'pi_Fixture', 'currency' => 'usd', 'amount' => 2000,
            'amount_captured' => 2000, 'amount_refunded' => 0, 'livemode' => false, 'paid' => true, 'captured' => true,
            'disputed' => false, 'status' => 'succeeded'];
    }

    private static function refund(string $status = 'succeeded'): array
    {
        return ['id' => 're_Fixture', 'object' => 'refund', 'charge' => 'ch_Fixture', 'payment_intent' => 'pi_Fixture',
            'currency' => 'usd', 'amount' => 500, 'status' => $status, 'created' => 1800000000, 'metadata' => (object) []];
    }

    private static function dispute(): array
    {
        return ['id' => 'du_Fixture', 'object' => 'dispute', 'charge' => 'ch_Fixture', 'payment_intent' => 'pi_Fixture',
            'currency' => 'usd', 'amount' => 2500, 'status' => 'lost', 'created' => 1800000000, 'livemode' => false];
    }

    private static function page(string $type, array $objects, bool $more = false): array
    {
        return ['object' => 'list', 'url' => '/v1/'.$type, 'has_more' => $more, 'data' => $objects];
    }
}
