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

/** Pure transport/parser fixtures: no real provider, credentials, account or framework database is required. */
final class StripeCheckoutTest extends TestCase
{
    public function test_it_creates_only_test_hosted_card_checkout_with_fixed_version_and_explicit_form_encoding(): void
    {
        [$client, $mock] = self::client(self::response(self::session()));
        $secret = self::secret();
        $payload = self::payload();
        $result = $client->create($secret, $payload, 'attempt-create-1');

        self::assertSame(self::normalized(), $result);
        $request = $mock->getLastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.stripe.com/v1/checkout/sessions', (string) $request->getUri());
        self::assertSame('Bearer '.$secret->reveal(), $request->getHeaderLine('Authorization'));
        self::assertSame('2026-09-30.endive', $request->getHeaderLine('Stripe-Version'));
        self::assertSame('attempt-create-1', $request->getHeaderLine('Idempotency-Key'));
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        parse_str((string) $request->getBody(), $form);
        self::assertSame(['card'], $form['allowed_payment_method_types']);
        self::assertSame('false', $form['automatic_tax']['enabled']);
        self::assertSame('false', $form['adaptive_pricing']['enabled']);
        self::assertSame('20.00 & course', $form['line_items'][0]['price_data']['product_data']['name']);
        self::assertArrayNotHasKey('payment_method_types', $form);
        self::assertSame('2000', $form['line_items'][0]['price_data']['unit_amount']);
        $options = $mock->getLastOptions();
        self::assertSame(3, $options['connect_timeout']);
        self::assertSame(10, $options['timeout']);
        self::assertSame(10, $options['read_timeout']);
        foreach (['allow_redirects', 'http_errors', 'decode_content', 'cookies', 'debug'] as $name) {
            self::assertFalse($options[$name]);
        }
        self::assertTrue($options['verify']);
        self::assertCount(0, $mock);
    }

    public function test_account_reads_only_the_authenticated_account_id_and_no_personal_data(): void
    {
        [$client, $mock] = self::client(self::response(['object' => 'account', 'id' => 'acct_fixture', 'email' => 'private@example.test']));
        self::assertSame('acct_fixture', $client->account(self::secret()));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('https://api.stripe.com/v1/account', (string) $mock->getLastRequest()->getUri());
        self::assertSame('', $mock->getLastRequest()->getHeaderLine('Idempotency-Key'));
        self::assertSame('', (string) $mock->getLastRequest()->getBody());
    }

    public function test_retrieval_and_expiry_use_only_an_exact_test_session_path_and_separate_operation_key(): void
    {
        $expired = array_replace(self::session(), ['status' => 'expired', 'url' => null]);
        [$client, $mock] = self::client(self::response($expired));
        self::assertSame('expired', $client->expire(self::secret(), 'cs_test_fixture', 'attempt-expire-1')['status']);
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('https://api.stripe.com/v1/checkout/sessions/cs_test_fixture/expire', (string) $mock->getLastRequest()->getUri());
        self::assertSame('attempt-expire-1', $mock->getLastRequest()->getHeaderLine('Idempotency-Key'));
        self::assertSame('', (string) $mock->getLastRequest()->getBody());

        [$client, $mock] = self::client(self::response($expired));
        self::assertNull($client->retrieve(self::secret(), 'cs_test_fixture')['url']);
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('https://api.stripe.com/v1/checkout/sessions/cs_test_fixture', (string) $mock->getLastRequest()->getUri());
        self::assertSame('', $mock->getLastRequest()->getHeaderLine('Idempotency-Key'));
    }

    #[DataProvider('invalidSecrets')]
    public function test_every_direct_transport_method_refuses_live_missing_and_malformed_keys_before_http(string $value): void
    {
        foreach (['account', 'create', 'retrieve', 'expire'] as $operation) {
            [$client, $mock] = self::client(self::response(self::session()));
            $this->refused(fn () => self::invoke($client, $operation, new Secret($value)), 'rejected');
            self::assertNull($mock->getLastRequest());
            self::assertCount(1, $mock);
        }
    }

    public static function invalidSecrets(): iterable
    {
        yield 'live' => ['sk_live_'.bin2hex(random_bytes(20))];
        yield 'restricted unsupported' => ['rk_test_'.bin2hex(random_bytes(20))];
        yield 'missing' => [''];
        yield 'short' => ['sk_test_a'];
        yield 'long' => ['sk_test_'.str_repeat('a', 1024)];
        yield 'header injection' => ["sk_test_fixture\r\nSecret: exposed"];
        yield 'whitespace' => ['sk_test_fixture '];
        yield 'nonascii' => ['sk_test_fixtureé'];
    }

    #[DataProvider('invalidRequests')]
    public function test_invalid_local_payload_identity_or_path_has_no_remote_outcome(string $operation, mixed $change): void
    {
        [$client, $mock] = self::client(self::response(self::session()));
        $this->refused(fn () => match ($operation) {
            'payload' => $client->create(self::secret(), array_replace(self::payload(), $change), 'create-1'),
            'key' => $client->create(self::secret(), self::payload(), $change),
            'retrieve' => $client->retrieve(self::secret(), $change),
            'expire' => $client->expire(self::secret(), $change, 'expire-1'),
        }, 'rejected');
        self::assertNull($mock->getLastRequest());
    }

    public static function invalidRequests(): iterable
    {
        foreach (['mode' => 'subscription', 'ui_mode' => 'hosted', 'allowed_payment_method_types' => ['card', 'link'],
            'metadata' => ['bad' => (object) ['private' => true]], 'expires_at' => 1.5,
            'customer_email' => "private@example.test\n", 'line_items' => array_fill(0, 101, []),
            'long' => str_repeat('a', StripeCheckout::MAX_REQUEST_BYTES + 1)] as $field => $value) {
            yield $field => ['payload', [$field => $value]];
        }
        foreach (['', "key\r\nInjected: secret", str_repeat('a', 256), ' key'] as $i => $key) {
            yield 'key '.$i => ['key', $key];
        }
        foreach (['cs_live_fixture', 'cs_test_../private', 'cs_test_a?secret=private', 'cs_test_', str_repeat('a', 240)] as $i => $id) {
            yield 'retrieve '.$i => ['retrieve', $id];
            yield 'expire '.$i => ['expire', $id];
        }
    }

    #[DataProvider('invalidSessions')]
    public function test_malformed_or_wrong_mode_success_is_unknown_and_never_a_safe_rejection(mixed $session): void
    {
        [$client] = self::client(self::response($session));
        $this->refused(fn () => $client->create(self::secret(), self::payload(), 'create-1'), 'unknown');
    }

    public static function invalidSessions(): iterable
    {
        yield 'null' => [null];
        yield 'list' => [[]];
        foreach (['object' => ['account', null], 'mode' => ['setup', 'subscription', null], 'livemode' => [true, 0, 'false', null],
            'id' => ['cs_live_fixture', 'cs_test_../private', '', null], 'status' => ['processing', '', null],
            'payment_status' => ['succeeded', '', null], 'amount_total' => [2000.0, 20.5, '-1', '01', null, true, '9223372036854775808'],
            'currency' => ['USD', 'us', null], 'expires_at' => ['1800000000', 1800000000.0, 0, null],
            'client_reference_id' => ['', null, "private\nreference", str_repeat('a', 201)],
            'metadata' => [null, [], ['kitsune_order' => 'order1'], ['kitsune_attempt' => 'attempt1']],
            'url' => ['http://checkout.stripe.com/private', 'https://evil.test/', 'https://checkout.stripe.com.evil.test/',
                'https://private@checkout.stripe.com/', 'https://checkout.stripe.com:443/', "https://checkout.stripe.com/\nprivate", 1],
            'payment_intent' => [['id' => 'pi_fixture'], 'cs_test_fixture', '', 1]] as $field => $values) {
            foreach ($values as $i => $value) {
                yield $field.' '.$i => [array_replace(self::session(), [$field => $value])];
            }
        }
        foreach (['id', 'amount_total', 'url', 'payment_intent', 'livemode'] as $field) {
            $session = self::session();
            unset($session[$field]);
            yield 'missing '.$field => [$session];
        }
    }

    #[DataProvider('responseOutcomes')]
    public function test_only_narrow_nonreplayed_parameter_validation_errors_are_definitive(int $status, array $headers, mixed $body, string $category): void
    {
        [$client, $mock] = self::client(self::response($body, $status, $headers), self::response(self::session()));
        $this->refused(fn () => $client->create(self::secret(), self::payload(), 'create-1'), $category);
        self::assertCount(1, $mock, 'The transport must never retry automatically.');
    }

    public static function responseOutcomes(): iterable
    {
        $validation = ['error' => ['type' => 'invalid_request_error', 'code' => 'parameter_unknown', 'message' => 'private provider details']];
        yield 'first parameter validation' => [400, [], $validation, 'rejected'];
        yield 'replayed error' => [400, ['Idempotent-Replayed' => 'true'], $validation, 'unknown'];
        yield 'retry requested' => [400, ['Stripe-Should-Retry' => 'true'], $validation, 'unknown'];
        yield 'generic 400' => [400, [], ['error' => ['type' => 'invalid_request_error', 'message' => 'private']], 'unknown'];
        foreach (['amount_too_small', 'amount_too_large'] as $code) {
            $amount = ['error' => ['type' => 'invalid_request_error', 'code' => $code, 'message' => 'private provider details']];
            yield $code.' first validation' => [400, [], $amount, 'rejected'];
            yield $code.' no retry' => [400, ['Stripe-Should-Retry' => 'false'], $amount, 'rejected'];
            yield $code.' replayed' => [400, ['Idempotent-Replayed' => 'true'], $amount, 'unknown'];
            yield $code.' retry requested' => [400, ['Stripe-Should-Retry' => 'true'], $amount, 'unknown'];
            yield $code.' wrong type' => [400, [], ['error' => ['type' => 'api_error', 'code' => $code]], 'unknown'];
            foreach ([402, 409, 500] as $status) {
                yield $code.' status '.$status => [$status, [], $amount, 'unknown'];
            }
        }
        foreach ([201, 301, 401, 402, 403, 404, 409, 424, 429, 500, 502, 503, 504] as $status) {
            yield 'status '.$status => [$status, [], $validation, 'unknown'];
        }
    }

    public function test_the_projection_keeps_provider_money_exact_without_exposing_unrelated_personal_data(): void
    {
        foreach (['0', '9007199254740993', '9223372036854775807'] as $amount) {
            $session = array_replace(self::session(), ['amount_total' => $amount, 'customer_details' => ['email' => 'private@example.test'],
                'payment_intent' => 'pi_fixture', 'status' => 'complete', 'payment_status' => 'paid', 'url' => null]);
            [$client] = self::client(self::response($session));
            $result = $client->retrieve(self::secret(), 'cs_test_fixture');
            self::assertSame($amount, $result['amountTotal']);
            self::assertSame('pi_fixture', $result['paymentIntent']);
            self::assertStringNotContainsString('private', json_encode($result, JSON_THROW_ON_ERROR));
        }
        // Recognition is separate from policy: the workflow decides whether a zero/no-payment completion is supported.
        [$client] = self::client(self::response(array_replace(self::session(), ['amount_total' => 0, 'status' => 'complete', 'payment_status' => 'no_payment_required', 'url' => null])));
        self::assertSame('no_payment_required', $client->retrieve(self::secret(), 'cs_test_fixture')['paymentStatus']);
    }

    #[DataProvider('badResponses')]
    public function test_bad_headers_json_and_lengths_leave_an_unknown_outcome(Response $response): void
    {
        [$client] = self::client($response);
        $this->refused(fn () => $client->create(self::secret(), self::payload(), 'create-1'), 'unknown');
    }

    public static function badResponses(): iterable
    {
        yield 'nonjson content type' => [new Response(200, ['Content-Type' => 'text/html'], '<private>')];
        yield 'missing content type' => [new Response(200, [], '{}')];
        yield 'compression' => [new Response(200, ['Content-Type' => 'application/json', 'Content-Encoding' => 'gzip'], '{}')];
        yield 'invalid content length' => [new Response(200, ['Content-Type' => 'application/json', 'Content-Length' => '1e2'], '{}')];
        yield 'false content length' => [new Response(200, ['Content-Type' => 'application/json', 'Content-Length' => '20'], '{}')];
        yield 'declared oversize' => [new Response(200, ['Content-Type' => 'application/json', 'Content-Length' => (string) (StripeCheckout::MAX_RESPONSE_BYTES + 1)], '{}')];
        yield 'undeclared oversize' => [new Response(200, ['Content-Type' => 'application/json'], str_repeat(' ', StripeCheckout::MAX_RESPONSE_BYTES + 1))];
        yield 'malformed json' => [new Response(200, ['Content-Type' => 'application/json'], '{private')];
    }

    public function test_incremental_response_chunks_cannot_pass_the_memory_bound(): void
    {
        [$client] = self::client(static function ($request, array $options): Response {
            $options['sink']->write(str_repeat(' ', StripeCheckout::MAX_RESPONSE_BYTES));
            $options['sink']->write('x');

            return self::response(self::session());
        });
        $this->refused(fn () => $client->create(self::secret(), self::payload(), 'create-1'), 'unknown');
    }

    public function test_ambient_database_transactions_refuse_every_operation_before_http(): void
    {
        $previous = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['database' => ['default' => 'fixture', 'connections' => ['fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]]]));
        $manager = new DatabaseManager($container, new ConnectionFactory($container));
        $container->instance('db', $manager);
        Container::setInstance($container);
        $connection = $manager->connection();
        $connection->beginTransaction();

        try {
            foreach (['account', 'create', 'retrieve', 'expire'] as $operation) {
                [$client, $mock] = self::client(self::response(self::session()));
                $this->refused(fn () => self::invoke($client, $operation, self::secret()), 'rejected');
                self::assertNull($mock->getLastRequest());
            }
        } finally {
            $connection->rollBack();
            Container::setInstance($previous);
        }
    }

    public function test_network_failures_are_not_chained_and_payload_credentials_and_provider_errors_do_not_enter_the_trace(): void
    {
        $before = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        $secret = self::secret();

        try {
            [$client, $mock] = self::client(new ConnectException('private transport '.$secret->reveal(), new Request('POST', 'https://api.stripe.com/private')),
                self::response(self::session()));
            try {
                $client->create($secret, self::payload() + ['private' => 'private buyer details'], 'create-1');
                self::fail('Transport failure was not mapped.');
            } catch (StripeUnavailable $failure) {
                self::assertSame('unknown', $failure->category);
                self::assertNull($failure->getPrevious());
                self::assertStringNotContainsString($secret->reveal(), (string) $failure);
                self::assertStringNotContainsString('private buyer', (string) $failure);
                self::assertStringNotContainsString('private transport', (string) $failure);
                self::assertCount(1, $mock);
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
            self::assertStringNotContainsString('private', $failure->getMessage());
        }
    }

    private static function invoke(StripeCheckout $client, string $operation, Secret $secret): mixed
    {
        return match ($operation) {
            'account' => $client->account($secret),
            'create' => $client->create($secret, self::payload(), 'create-1'),
            'retrieve' => $client->retrieve($secret, 'cs_test_fixture'),
            'expire' => $client->expire($secret, 'cs_test_fixture', 'expire-1'),
        };
    }

    /** @return array{StripeCheckout,MockHandler} */
    private static function client(mixed ...$responses): array
    {
        $mock = new MockHandler($responses);

        return [new StripeCheckout(new Client(['handler' => HandlerStack::create($mock)])), $mock];
    }

    private static function secret(): Secret
    {
        return new Secret('sk_test_'.bin2hex(random_bytes(24)));
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
        return ['object' => 'checkout.session', 'id' => 'cs_test_fixture', 'livemode' => false, 'mode' => 'payment',
            'status' => 'open', 'payment_status' => 'unpaid', 'amount_total' => 2000, 'currency' => 'usd', 'expires_at' => 1800000000,
            'client_reference_id' => 'order1', 'metadata' => ['kitsune_attempt' => 'attempt1', 'kitsune_order' => 'order1'],
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_fixture#opaque', 'payment_intent' => null];
    }

    private static function normalized(): array
    {
        return ['id' => 'cs_test_fixture', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_fixture#opaque', 'status' => 'open',
            'paymentStatus' => 'unpaid', 'amountTotal' => '2000', 'currency' => 'USD', 'expiresAt' => 1800000000,
            'clientReference' => 'order1', 'attemptReference' => 'attempt1', 'orderReference' => 'order1', 'test' => true, 'paymentIntent' => null];
    }
}
