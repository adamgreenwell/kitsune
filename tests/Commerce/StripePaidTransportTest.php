<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Kitsune\Commerce\Payments\StripeCheckout;
use Kitsune\Commerce\Payments\StripeUnavailable;
use Kitsune\Core\Credentials\Secret;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Actual bounded transport and parsers with outbound responses replaced; no Stripe account or services. */
final class StripePaidTransportTest extends TestCase
{
    public function test_paid_session_reads_one_expanded_graph_and_discards_client_secrets_and_buyer_data(): void
    {
        [$client, $mock] = self::client(self::response(self::session()));
        $result = $client->retrievePaid(self::secret(), 'cs_test_fixture');

        self::assertSame('pi_fixture', $result['paymentIntent']);
        self::assertSame(['intentId' => 'pi_fixture', 'chargeId' => 'ch_fixture', 'currency' => 'USD', 'amount' => '2000',
            'received' => '2000', 'captured' => '2000', 'refunded' => '0', 'disputed' => false, 'test' => true], $result['payment']);
        self::assertStringNotContainsString('private', json_encode($result, JSON_THROW_ON_ERROR));
        $request = $mock->getLastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://api.stripe.com/v1/checkout/sessions/cs_test_fixture?expand%5B%5D=payment_intent.latest_charge', (string) $request->getUri());
        self::assertSame(StripeCheckout::API_VERSION, $request->getHeaderLine('Stripe-Version'));
        self::assertSame('', $request->getHeaderLine('Idempotency-Key'));
        self::assertSame('', (string) $request->getBody());
        self::assertCount(0, $mock);
        self::assertSame(10, $mock->getLastOptions()['timeout']);
    }

    public function test_unpaid_open_expired_and_incomplete_sessions_never_claim_payment(): void
    {
        foreach (['open', 'expired', 'complete'] as $status) {
            foreach ([null, 'pi_fixture', self::intent()] as $intent) {
                $session = array_replace(self::session(), ['status' => $status, 'payment_status' => 'unpaid', 'payment_intent' => $intent]);
                [$client] = self::client(self::response($session));
                $result = $client->retrievePaid(self::secret(), 'cs_test_fixture');
                self::assertNull($result['payment']);
                self::assertSame($intent === null ? null : 'pi_fixture', $result['paymentIntent']);
            }
        }
    }

    #[DataProvider('invalidPaidGraphs')]
    public function test_incomplete_or_transplanted_paid_graphs_are_unknown_and_never_trigger_extra_reads(array $session): void
    {
        [$client, $mock] = self::client(self::response($session), self::response(self::session()));
        $this->refused(fn () => $client->retrievePaid(self::secret(), 'cs_test_fixture'), 'unknown');
        self::assertCount(1, $mock);
    }

    public static function invalidPaidGraphs(): iterable
    {
        foreach (['open', 'expired'] as $status) {
            yield 'wrong Session status '.$status => [array_replace(self::session(), ['status' => $status])];
        }
        yield 'unexpanded intent' => [array_replace(self::session(), ['payment_intent' => 'pi_fixture'])];
        yield 'missing intent' => [array_replace(self::session(), ['payment_intent' => null])];
        yield 'zero completion' => [array_replace(self::session(), ['amount_total' => 0])];
        yield 'wrong Session ID' => [array_replace(self::session(), ['id' => 'cs_test_another'])];
        foreach (['object' => ['charge', null], 'livemode' => [true, null, 0], 'id' => ['ch_fixture', '', null],
            'status' => ['processing', 'requires_capture', null], 'amount' => [1999, 2001, 2000.0, '02000', null],
            'amount_received' => [1999, 2001, null], 'currency' => ['eur', 'USD', null],
            'latest_charge' => ['ch_fixture', null, []]] as $field => $values) {
            foreach ($values as $i => $value) {
                yield 'intent '.$field.' '.$i => [array_replace(self::session(), ['payment_intent' => array_replace(self::intent(), [$field => $value])])];
            }
        }
        foreach (['object' => ['payment_intent', null], 'livemode' => [true, null, 0], 'id' => ['pi_fixture', null],
            'payment_intent' => ['pi_another', null, ['id' => 'pi_fixture']], 'amount' => [1999, 2001, null],
            'amount_captured' => [1999, 2001, null], 'amount_refunded' => [-1, 2001, null], 'currency' => ['eur', 'USD', null],
            'paid' => [false, 1, null], 'captured' => [false, 1, null], 'status' => ['pending', 'failed', 'unknown'],
            'disputed' => [0, null]] as $field => $values) {
            foreach ($values as $i => $value) {
                $intent = array_replace(self::intent(), ['latest_charge' => array_replace(self::charge(), [$field => $value])]);
                yield 'charge '.$field.' '.$i => [array_replace(self::session(), ['payment_intent' => $intent])];
            }
        }
    }

    public function test_charge_projection_preserves_exact_refund_totals_and_non_success_states_for_the_workflow(): void
    {
        foreach (['succeeded', 'pending', 'failed'] as $status) {
            [$client, $mock] = self::client(self::response(array_replace(self::charge(), ['status' => $status, 'amount_refunded' => 500, 'disputed' => true])));
            $result = $client->retrieveCharge(self::secret(), 'ch_fixture');
            self::assertSame(['id' => 'ch_fixture', 'paymentIntent' => 'pi_fixture', 'currency' => 'USD', 'amount' => '2000',
                'captured' => '2000', 'refunded' => '500', 'paid' => true, 'capturedFully' => true,
                'status' => $status, 'disputed' => true, 'test' => true], $result);
            self::assertSame('https://api.stripe.com/v1/charges/ch_fixture', (string) $mock->getLastRequest()->getUri());
            self::assertStringNotContainsString('private', json_encode($result, JSON_THROW_ON_ERROR));
        }
        [$client] = self::client(self::response(array_replace(self::charge(), ['amount_captured' => 1000])));
        self::assertFalse($client->retrieveCharge(self::secret(), 'ch_fixture')['capturedFully']);
    }

    public function test_provider_money_is_not_cast_through_float_or_native_integer_arithmetic(): void
    {
        $amount = '9223372036854775807';
        $charge = array_replace(self::charge(), ['amount' => $amount, 'amount_captured' => $amount, 'amount_refunded' => '9007199254740993']);
        $intent = array_replace(self::intent(), ['amount' => $amount, 'amount_received' => $amount, 'latest_charge' => $charge]);
        [$client] = self::client(self::response(array_replace(self::session(), ['amount_total' => $amount, 'payment_intent' => $intent])));
        $result = $client->retrievePaid(self::secret(), 'cs_test_fixture')['payment'];
        self::assertSame($amount, $result['amount']);
        self::assertSame('9007199254740993', $result['refunded']);
    }

    public function test_refund_create_posts_only_the_exact_durable_amount_charge_metadata_and_key(): void
    {
        [$client, $mock] = self::client(self::response(self::refund()));
        $result = $client->createRefund(self::secret(), self::payload(), 'refund-test-1');
        self::assertSame(self::normalizedRefund(), $result);
        $request = $mock->getLastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.stripe.com/v1/refunds', (string) $request->getUri());
        self::assertSame('refund-test-1', $request->getHeaderLine('Idempotency-Key'));
        self::assertSame(StripeCheckout::API_VERSION, $request->getHeaderLine('Stripe-Version'));
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        parse_str((string) $request->getBody(), $payload);
        self::assertSame(self::payload(), $payload);
        self::assertStringNotContainsString('private', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('test', $result, 'Refund itself has no documented mode field.');
    }

    public function test_wrong_requested_charge_and_transplanted_refund_create_receipts_are_unknown(): void
    {
        [$client] = self::client(self::response(array_replace(self::charge(), ['id' => 'ch_another'])));
        $this->refused(fn () => $client->retrieveCharge(self::secret(), 'ch_fixture'), 'unknown');
        foreach (['charge' => 'ch_another', 'amount' => 999,
            'metadata' => array_replace(self::metadata(), ['kitsune_refund' => str_repeat('d', 32)])] as $field => $value) {
            [$client, $mock] = self::client(self::response(array_replace(self::refund(), [$field => $value])), self::response(self::refund()));
            $this->refused(fn () => $client->createRefund(self::secret(), self::payload(), 'refund-1'), 'unknown');
            self::assertCount(1, $mock, 'A mismatched response must not issue a replacement refund.');
        }
    }

    #[DataProvider('refundStatuses')]
    public function test_every_refund_status_is_recognized_without_conflating_pending_or_failure_with_success(string $status): void
    {
        [$client, $mock] = self::client(self::response(array_replace(self::refund(), ['status' => $status])));
        $result = $client->retrieveRefund(self::secret(), 're_fixture');
        self::assertSame($status, $result['status']);
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('https://api.stripe.com/v1/refunds/re_fixture', (string) $mock->getLastRequest()->getUri());
        self::assertSame('', $mock->getLastRequest()->getHeaderLine('Idempotency-Key'));
    }

    public static function refundStatuses(): iterable
    {
        foreach (['pending', 'requires_action', 'succeeded', 'failed', 'canceled'] as $status) {
            yield $status => [$status];
        }
    }

    public function test_a_previously_successful_refund_is_read_again_and_can_report_later_failure(): void
    {
        [$client, $mock] = self::client(self::response(self::refund()), self::response(array_replace(self::refund(), ['status' => 'failed'])));
        self::assertSame('succeeded', $client->retrieveRefund(self::secret(), 're_fixture')['status']);
        self::assertSame('failed', $client->retrieveRefund(self::secret(), 're_fixture')['status']);
        self::assertCount(0, $mock);
    }

    #[DataProvider('unclaimedMetadata')]
    public function test_external_or_changed_metadata_is_unclaimed_and_never_leaks_other_fields(mixed $metadata): void
    {
        [$client] = self::client(self::response(array_replace(self::refund(), ['metadata' => $metadata])));
        $result = $client->retrieveRefund(self::secret(), 're_fixture');
        self::assertNull($result['metadata']);
        self::assertStringNotContainsString('private', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public static function unclaimedMetadata(): iterable
    {
        foreach ([null, [], ['private' => 'private buyer'], array_replace(self::metadata(), ['kitsune_request' => 'invalid']),
            [...self::metadata(), 'private' => 'private buyer'], array_replace(self::metadata(), ['kitsune_order' => 1]),
            array_replace(self::metadata(), ['kitsune_refund' => strtoupper(str_repeat('a', 32))])] as $i => $metadata) {
            yield 'metadata '.$i => [$metadata];
        }
    }

    public function test_refund_pages_are_charge_filtered_bounded_and_return_an_explicit_cursor_only_when_needed(): void
    {
        $first = self::refund();
        $second = array_replace($first, ['id' => 're_next']);
        [$client, $mock] = self::client(self::response(self::page([$first, $second], true)), self::response(self::page([], false)));
        $page = $client->listRefunds(self::secret(), 'ch_fixture', null, 2);
        self::assertSame([self::normalizedRefund(), array_replace(self::normalizedRefund(), ['id' => 're_next'])], $page['refunds']);
        self::assertTrue($page['hasMore']);
        self::assertSame('re_next', $page['next']);
        self::assertSame('https://api.stripe.com/v1/refunds?charge=ch_fixture&limit=2', (string) $mock->getLastRequest()->getUri());
        self::assertSame('', $mock->getLastRequest()->getHeaderLine('Idempotency-Key'));
        self::assertSame(['refunds' => [], 'hasMore' => false, 'next' => null], $client->listRefunds(self::secret(), 'ch_fixture', 're_next', 2));
        self::assertSame('https://api.stripe.com/v1/refunds?charge=ch_fixture&limit=2&starting_after=re_next', (string) $mock->getLastRequest()->getUri());
    }

    #[DataProvider('invalidPages')]
    public function test_incomplete_malformed_or_transplanted_list_pages_cannot_establish_recovery_evidence(mixed $page): void
    {
        [$client] = self::client(self::response($page));
        $this->refused(fn () => $client->listRefunds(self::secret(), 'ch_fixture', 're_prior', 2), 'unknown');
    }

    public static function invalidPages(): iterable
    {
        yield 'not an object' => [[]];
        yield 'wrong object' => [array_replace(self::page([]), ['object' => 'refund'])];
        yield 'wrong list URL' => [array_replace(self::page([]), ['url' => '/v1/charges'])];
        yield 'unknown completion' => [array_replace(self::page([]), ['has_more' => 0])];
        yield 'empty incomplete' => [self::page([], true)];
        yield 'too many' => [self::page([self::refund(), self::refund(), self::refund()])];
        yield 'repeated ID' => [self::page([self::refund(), self::refund()])];
        yield 'previous cursor' => [self::page([array_replace(self::refund(), ['id' => 're_prior'])])];
        yield 'wrong Charge' => [self::page([array_replace(self::refund(), ['charge' => 'ch_another'])])];
        yield 'bad refund' => [self::page([array_replace(self::refund(), ['amount' => -1])])];
        yield 'associative data' => [self::page(['private' => self::refund()])];
    }

    #[DataProvider('invalidRefunds')]
    public function test_malformed_refund_responses_do_not_prove_any_financial_effect(mixed $refund): void
    {
        [$client] = self::client(self::response($refund));
        $this->refused(fn () => $client->retrieveRefund(self::secret(), 're_fixture'), 'unknown');
    }

    public static function invalidRefunds(): iterable
    {
        yield 'not an object' => [[]];
        foreach (['object' => ['charge', null], 'id' => ['re_another', 'ch_fixture', null], 'charge' => ['pi_fixture', null],
            'payment_intent' => ['ch_fixture', null, ['id' => 'pi_fixture']], 'currency' => ['USD', null],
            'amount' => [0, -1, 1000.0, '01000', '9223372036854775808', null],
            'status' => ['complete', null], 'created' => [0, '1800000000', 1800000000.0, null]] as $field => $values) {
            foreach ($values as $i => $value) {
                yield $field.' '.$i => [array_replace(self::refund(), [$field => $value])];
            }
        }
    }

    #[DataProvider('invalidLocalInputs')]
    public function test_invalid_local_paths_and_refund_requests_are_rejected_before_http(string $operation, mixed $value): void
    {
        [$client, $mock] = self::client(self::response(self::refund()));
        $this->refused(fn () => match ($operation) {
            'session' => $client->retrievePaid(self::secret(), $value),
            'charge' => $client->retrieveCharge(self::secret(), $value),
            'refund' => $client->retrieveRefund(self::secret(), $value),
            'list' => $client->listRefunds(self::secret(), $value),
            'cursor' => $client->listRefunds(self::secret(), 'ch_fixture', $value),
            'limit' => $client->listRefunds(self::secret(), 'ch_fixture', null, $value),
            'payload' => $client->createRefund(self::secret(), $value, 'refund-1'),
            'key' => $client->createRefund(self::secret(), self::payload(), $value),
        }, 'rejected');
        self::assertNull($mock->getLastRequest());
    }

    public static function invalidLocalInputs(): iterable
    {
        foreach (['session' => 'cs_test_', 'charge' => 'ch_', 'refund' => 're_', 'list' => 'ch_', 'cursor' => 're_'] as $operation => $prefix) {
            foreach ([$prefix.'../private', $prefix.'a?private=1', '', $prefix] as $i => $value) {
                yield $operation.' '.$i => [$operation, $value];
            }
        }
        yield 'live Session' => ['session', 'cs_live_fixture'];
        yield 'limit below' => ['limit', 0];
        yield 'limit above' => ['limit', 101];
        foreach (['amount' => 0, 'charge' => 'pi_fixture', 'metadata' => ['private' => 'private buyer']] as $field => $value) {
            yield 'payload '.$field => ['payload', array_replace(self::payload(), [$field => $value])];
        }
        yield 'extra payload' => ['payload', [...self::payload(), 'reverse_transfer' => true]];
        yield 'float amount' => ['payload', array_replace(self::payload(), ['amount' => 1000.0])];
        yield 'bad key' => ['key', "private\nkey"];
    }

    public function test_new_operations_reject_live_keys_and_ambient_transactions_before_network_io(): void
    {
        $operations = ['paid', 'charge', 'create', 'refund', 'list'];
        foreach ($operations as $operation) {
            [$client, $mock] = self::client(self::response(self::refund()));
            $this->refused(fn () => self::invoke($client, $operation, new Secret('sk_live_'.str_repeat('a', 32))), 'rejected');
            self::assertNull($mock->getLastRequest());
        }
        $previous = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['database' => ['default' => 'fixture', 'connections' => ['fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]]]));
        $manager = new DatabaseManager($container, new ConnectionFactory($container));
        $container->instance('db', $manager);
        Container::setInstance($container);
        $connection = $manager->connection();
        $connection->beginTransaction();
        try {
            foreach ($operations as $operation) {
                [$client, $mock] = self::client(self::response(self::refund()));
                $this->refused(fn () => self::invoke($client, $operation, self::secret()), 'rejected');
                self::assertNull($mock->getLastRequest());
            }
        } finally {
            $connection->rollBack();
            Container::setInstance($previous);
        }
    }

    public function test_refund_timeout_and_cached_errors_remain_unknown_without_auto_retry_or_secret_chaining(): void
    {
        $secret = self::secret();
        $before = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            foreach ([new ConnectException('private '.$secret->reveal(), new Request('POST', 'https://api.stripe.com/v1/refunds')),
                self::response(['error' => ['type' => 'api_error', 'message' => 'private provider']], 500),
                new Response(200, ['Content-Type' => 'application/json'], str_repeat(' ', StripeCheckout::MAX_RESPONSE_BYTES + 1))] as $response) {
                [$client, $mock] = self::client($response, self::response(self::refund()));
                try {
                    $client->createRefund($secret, self::payload(), 'refund-1');
                    self::fail('Unknown outcome was admitted.');
                } catch (StripeUnavailable $failure) {
                    self::assertSame('unknown', $failure->category);
                    self::assertNull($failure->getPrevious());
                    self::assertStringNotContainsString($secret->reveal(), (string) $failure);
                    self::assertStringNotContainsString('private provider', (string) $failure);
                    self::assertCount(1, $mock);
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $before);
        }
    }

    private function refused(Closure $operation, string $category): void
    {
        try {
            $operation();
            self::fail('The operation was unexpectedly admitted.');
        } catch (StripeUnavailable $failure) {
            self::assertSame($category, $failure->category);
            self::assertNull($failure->getPrevious());
        }
    }

    private static function invoke(StripeCheckout $client, string $operation, Secret $secret): array
    {
        return match ($operation) {
            'paid' => $client->retrievePaid($secret, 'cs_test_fixture'),
            'charge' => $client->retrieveCharge($secret, 'ch_fixture'),
            'create' => $client->createRefund($secret, self::payload(), 'refund-1'),
            'refund' => $client->retrieveRefund($secret, 're_fixture'),
            'list' => $client->listRefunds($secret, 'ch_fixture'),
        };
    }

    private static function client(mixed ...$responses): array
    {
        $mock = new MockHandler($responses);

        return [new StripeCheckout(new Client(['handler' => HandlerStack::create($mock)])), $mock];
    }

    private static function secret(): Secret
    {
        return new Secret('sk_test_'.str_repeat('a', 32));
    }

    private static function response(mixed $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function session(): array
    {
        return ['object' => 'checkout.session', 'id' => 'cs_test_fixture', 'livemode' => false, 'mode' => 'payment',
            'status' => 'complete', 'payment_status' => 'paid', 'amount_total' => 2000, 'currency' => 'usd', 'expires_at' => 1800000000,
            'client_reference_id' => str_repeat('a', 32), 'metadata' => ['kitsune_attempt' => str_repeat('b', 32), 'kitsune_order' => str_repeat('a', 32)],
            'url' => null, 'payment_intent' => self::intent(), 'customer_details' => ['email' => 'private@example.test']];
    }

    private static function intent(): array
    {
        return ['object' => 'payment_intent', 'id' => 'pi_fixture', 'livemode' => false, 'status' => 'succeeded', 'amount' => 2000,
            'amount_received' => 2000, 'currency' => 'usd', 'latest_charge' => self::charge(), 'client_secret' => 'private_client_secret'];
    }

    private static function charge(): array
    {
        return ['object' => 'charge', 'id' => 'ch_fixture', 'livemode' => false, 'status' => 'succeeded', 'amount' => 2000,
            'amount_captured' => 2000, 'amount_refunded' => 0, 'currency' => 'usd', 'payment_intent' => 'pi_fixture',
            'paid' => true, 'captured' => true, 'disputed' => false, 'billing_details' => ['email' => 'private@example.test']];
    }

    private static function refund(): array
    {
        return ['object' => 'refund', 'id' => 're_fixture', 'charge' => 'ch_fixture', 'payment_intent' => 'pi_fixture',
            'amount' => 1000, 'currency' => 'usd', 'status' => 'succeeded', 'created' => 1800000000,
            'metadata' => self::metadata(), 'instructions_email' => 'private@example.test'];
    }

    private static function metadata(): array
    {
        return ['kitsune_order' => str_repeat('a', 32), 'kitsune_refund' => str_repeat('b', 32), 'kitsune_request' => str_repeat('c', 64)];
    }

    private static function payload(): array
    {
        return ['charge' => 'ch_fixture', 'amount' => '1000', 'metadata' => self::metadata()];
    }

    private static function normalizedRefund(): array
    {
        return ['id' => 're_fixture', 'chargeId' => 'ch_fixture', 'paymentIntent' => 'pi_fixture', 'currency' => 'USD',
            'amount' => '1000', 'status' => 'succeeded', 'created' => 1800000000, 'metadata' => self::metadata()];
    }

    private static function page(array $refunds, bool $hasMore = false): array
    {
        return ['object' => 'list', 'url' => '/v1/refunds', 'has_more' => $hasMore, 'data' => $refunds];
    }
}
