<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kitsune\Commerce\Shipping\ShippingUnavailable;
use Kitsune\Commerce\Shipping\ShippoRates;
use Kitsune\Core\Credentials\Secret;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pure transport fixtures. Every request uses Guzzle's in-memory MockHandler, never a provider or database. */
final class ShippoRatesTest extends TestCase
{
    #[DataProvider('modes')]
    public function test_it_creates_only_a_synchronous_shipment_and_projects_exact_whole_shipment_rates(bool $test): void
    {
        $fixture = self::shipment($test);
        $fixture['object_owner'] = 'private@merchant.test';
        $fixture['address_to'] = ['street1' => 'private destination'];
        $fixture['rates'][0]['amount_local'] = '999.99';
        $fixture['rates'][0]['currency_local'] = 'EUR';
        $fixture['rates'][] = array_replace($fixture['rates'][0], ['object_id' => 'rate-2', 'amount' => '0.00']);
        [$client, $mock] = self::client(self::response($fixture));
        $parcels = array_fill(0, 2, self::parcel());

        self::assertSame([
            ['id' => 'rate-1', 'carrier' => 'USPS', 'service' => 'Priority Mail', 'amountMinor' => '550', 'currency' => 'USD', 'shipmentId' => 'shipment-1', 'test' => $test],
            ['id' => 'rate-2', 'carrier' => 'USPS', 'service' => 'Priority Mail', 'amountMinor' => '0', 'currency' => 'USD', 'shipmentId' => 'shipment-1', 'test' => $test],
        ], $client->quote(self::secret($test), $test, self::address('Origin'), self::address('Destination'), $parcels, 'USD'));

        $request = $mock->getLastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.goshippo.com/shipments/', (string) $request->getUri());
        self::assertSame('ShippoToken '.self::secret($test)->reveal(), $request->getHeaderLine('Authorization'));
        self::assertSame('2018-02-08', $request->getHeaderLine('SHIPPO-API-VERSION'));
        self::assertSame('identity', $request->getHeaderLine('Accept-Encoding'));
        self::assertSame([
            'address_from' => ['name' => 'Origin', 'street1' => '10 Ocean Road', 'street2' => '', 'city' => 'San Francisco', 'state' => 'CA', 'zip' => '94103', 'country' => 'US'],
            'address_to' => ['name' => 'Destination', 'street1' => '10 Ocean Road', 'street2' => '', 'city' => 'San Francisco', 'state' => 'CA', 'zip' => '94103', 'country' => 'US'],
            'parcels' => $parcels, 'async' => false,
        ], json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR));
        $options = $mock->getLastOptions();
        self::assertSame(10, $options['timeout']);
        self::assertSame(3, $options['connect_timeout']);
        self::assertFalse($options['allow_redirects']);
        self::assertFalse($options['http_errors']);
        self::assertFalse($options['decode_content']);
        self::assertTrue($options['verify']);
        self::assertFalse($options['debug']);
        self::assertCount(0, $mock);
    }

    /** @return iterable<string,array{bool}> */
    public static function modes(): iterable
    {
        yield 'test' => [true];
        yield 'live' => [false];
    }

    #[DataProvider('currencies')]
    public function test_it_uses_currency_minor_units_without_float_or_local_amount_conversion(string $currency, string $amount, string $minor): void
    {
        $fixture = self::shipment();
        $fixture['rates'][0]['currency'] = $currency;
        $fixture['rates'][0]['amount'] = $amount;
        [$client] = self::client(self::response($fixture));

        $rate = $client->quote(self::secret(), true, self::address(), self::address(), [self::parcel()], $currency)[0];
        self::assertSame($minor, $rate['amountMinor']);
        self::assertSame($currency, $rate['currency']);
    }

    /** @return iterable<string,array{string,string,string}> */
    public static function currencies(): iterable
    {
        yield 'zero digits' => ['JPY', '5', '5'];
        yield 'zero digits provider padding' => ['JPY', '5.000', '5'];
        yield 'three digits' => ['KWD', '0.005', '5'];
        yield 'three digits provider padding' => ['KWD', '0.00500', '5'];
        yield 'two digits provider padding' => ['USD', '5.500', '550'];
        yield 'float integer boundary' => ['USD', '90071992547409.93', '9007199254740993'];
        yield 'signed bigint boundary' => ['USD', '92233720368547758.07', '9223372036854775807'];
    }

    #[DataProvider('invalidInputs')]
    public function test_bad_mode_currency_address_parcels_and_cross_border_requests_make_no_http_call(array $change): void
    {
        [$client, $mock] = self::client(self::response(self::shipment()));

        try {
            $client->quote($change['secret'] ?? self::secret(), $change['test'] ?? true,
                $change['from'] ?? self::address(), $change['to'] ?? self::address(), $change['parcels'] ?? [self::parcel()], $change['currency'] ?? 'USD');
            self::fail('Invalid request input was admitted.');
        } catch (ShippingUnavailable $failure) {
            self::assertSame('Shipping rates are unavailable. Please try again.', $failure->getMessage());
            self::assertNull($failure->getPrevious());
            self::assertNull($mock->getLastRequest());
            self::assertCount(1, $mock);
        }
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidInputs(): iterable
    {
        yield 'live token in test' => [['secret' => self::secret(false)]];
        yield 'test token in live' => [['test' => false]];
        yield 'no mode prefix' => [['secret' => new Secret(str_repeat('x', 32))]];
        yield 'header injection' => [['secret' => new Secret("shippo_test_private\r\nInjected: yes")]];
        yield 'too short token' => [['secret' => new Secret('shippo_test_a')]];
        yield 'too long token' => [['secret' => new Secret('shippo_test_'.str_repeat('a', 1024))]];
        yield 'unsupported currency' => [['currency' => 'XYZ']];
        yield 'currency alias' => [['currency' => 'usd']];
        yield 'foreign destination' => [['to' => ['name' => 'Destination', 'address' => ['line1' => 'Harbour', 'country' => 'CA']]]];
        yield 'profile payload' => [['from' => self::address() + ['email' => 'private@merchant.test']]];
        yield 'address control' => [['to' => ['name' => 'Destination', 'address' => ['line1' => "Ocean\nRoad", 'country' => 'US']]]];
        yield 'no parcels' => [['parcels' => []]];
        yield 'too many parcels' => [['parcels' => array_fill(0, 21, self::parcel())]];
        yield 'parcel extra' => [['parcels' => [self::parcel() + ['extra' => ['cod' => ['amount' => '100']]]]]];
        yield 'unchecked parcel id' => [['parcels' => ['parcel-private']]];
    }

    #[DataProvider('invalidShipments')]
    public function test_invalid_provider_schema_cannot_become_a_quote(mixed $fixture): void
    {
        [$client] = self::client(self::response($fixture));
        $this->expectException(ShippingUnavailable::class);
        $client->quote(self::secret(), true, self::address(), self::address(), [self::parcel()], 'USD');
    }

    /** @return iterable<string,array{mixed}> */
    public static function invalidShipments(): iterable
    {
        yield 'null' => [null];
        yield 'list' => [[]];

        foreach (['WAITING', 'QUEUED', 'ERROR', 'success', null] as $i => $value) {
            yield 'shipment status '.$i => [array_replace(self::shipment(), ['status' => $value])];
        }

        foreach ([false, 'true', 1, null] as $i => $value) {
            yield 'shipment mode '.$i => [array_replace(self::shipment(), ['test' => $value])];
            $fixture = self::shipment();
            $fixture['rates'][0]['test'] = $value;
            yield 'rate mode '.$i => [$fixture];
        }

        foreach ([[], 'rates', (object) ['0' => self::shipment()['rates'][0]], array_fill(0, 101, self::shipment()['rates'][0])] as $i => $value) {
            yield 'invalid rates collection '.$i => [array_replace(self::shipment(), ['rates' => $value])];
        }

        foreach ([null, 1, '', "private\naddress", str_repeat('a', 101)] as $i => $value) {
            yield 'shipment id '.$i => [array_replace(self::shipment(), ['object_id' => $value])];
            $fixture = self::shipment();
            $fixture['rates'][0]['object_id'] = $value;
            yield 'rate id '.$i => [$fixture];
        }

        foreach (['currency' => ['EUR', 'usd', null], 'amount' => [5.5, 5, null, '', '5.501', '-1', '1e2', ' 5', '92233720368547758.08', str_repeat('1', 101)],
            'shipment' => ['another-shipment', null], 'provider' => ['', null, "USPS\nprivate", str_repeat('a', 201)],
            'servicelevel' => [null, 'Priority Mail', [], ['name' => ''], ['name' => null], ['name' => "Priority\nMail"]]] as $field => $values) {
            foreach ($values as $i => $value) {
                $fixture = self::shipment();
                $fixture['rates'][0][$field] = $value;
                yield $field.' invalid '.$i => [$fixture];
            }
        }

        $fixture = self::shipment();
        $fixture['rates'][] = $fixture['rates'][0];
        yield 'duplicate rate identity' => [$fixture];
        $fixture = self::shipment();
        $fixture['rates'][] = array_replace($fixture['rates'][0], ['object_id' => 'rate-2', 'currency' => 'EUR']);
        yield 'invalid second rate refuses entire result' => [$fixture];
    }

    public function test_one_hundred_distinct_rates_are_supported(): void
    {
        $fixture = self::shipment();
        $fixture['rates'] = array_map(static fn (int $i): array => array_replace($fixture['rates'][0], ['object_id' => 'rate-'.$i]), range(1, 100));
        [$client] = self::client(self::response($fixture));
        self::assertCount(100, $client->quote(self::secret(), true, self::address(), self::address(), [self::parcel()], 'USD'));
    }

    #[DataProvider('invalidResponses')]
    public function test_http_failure_redirect_truncation_and_body_bounds_are_generic(Response $response): void
    {
        [$client, $mock] = self::client($response);

        try {
            $client->quote(self::secret(), true, self::address(), self::address(), [self::parcel()], 'USD');
            self::fail('An unsafe provider response was admitted.');
        } catch (ShippingUnavailable $failure) {
            self::assertNull($failure->getPrevious());
            self::assertSame('Shipping rates are unavailable. Please try again.', $failure->getMessage());
            self::assertStringNotContainsString('private', (string) $failure);
            self::assertCount(0, $mock);
        }
    }

    /** @return iterable<string,array{Response}> */
    public static function invalidResponses(): iterable
    {
        $valid = json_encode(self::shipment(), JSON_THROW_ON_ERROR);
        foreach ([200, 301, 302, 307, 400, 401, 429, 500, 503] as $status) {
            yield 'HTTP '.$status => [new Response($status, ['Content-Type' => 'application/json', 'Location' => 'https://other.test/private'], '{"private":"secret and address"}')];
        }
        yield 'non JSON media type' => [new Response(201, ['Content-Type' => 'text/html'], $valid)];
        yield 'missing media type' => [new Response(201, [], $valid)];
        yield 'compressed body' => [new Response(201, ['Content-Type' => 'application/json', 'Content-Encoding' => 'gzip'], $valid)];
        yield 'bad declared length' => [new Response(201, ['Content-Type' => 'application/json', 'Content-Length' => '99999999999999999999'], $valid)];
        yield 'declared over bound' => [new Response(201, ['Content-Type' => 'application/json', 'Content-Length' => (string) (ShippoRates::MAX_RESPONSE_BYTES + 1)], $valid)];
        yield 'truncated valid JSON' => [new Response(201, ['Content-Type' => 'application/json', 'Content-Length' => (string) (strlen($valid) + 1)], $valid)];
        yield 'length shorter than body' => [new Response(201, ['Content-Type' => 'application/json', 'Content-Length' => (string) (strlen($valid) - 1)], $valid)];
        yield 'truncated JSON' => [new Response(201, ['Content-Type' => 'application/json'], substr($valid, 0, -1))];
        yield 'empty body' => [new Response(201, ['Content-Type' => 'application/json'], '')];
        yield 'oversized chunked body' => [new Response(201, ['Content-Type' => 'application/json'], str_repeat(' ', ShippoRates::MAX_RESPONSE_BYTES + 1))];
    }

    public function test_the_write_sink_refuses_incremental_chunks_past_the_bound(): void
    {
        $mock = new MockHandler([static function ($request, array $options): Response {
            $sink = $options['sink'];
            $sink->write(str_repeat(' ', ShippoRates::MAX_RESPONSE_BYTES));
            $sink->write('x');

            return self::response(self::shipment());
        }]);
        $client = new ShippoRates(new Client(['handler' => HandlerStack::create($mock)]));
        $this->expectException(ShippingUnavailable::class);
        $client->quote(self::secret(), true, self::address(), self::address(), [self::parcel()], 'USD');
    }

    public function test_transport_failure_is_not_chained_and_sensitive_arguments_do_not_enter_its_trace(): void
    {
        $before = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');

        try {
            foreach ([new ConnectException('private transport failure', new Request('POST', 'https://api.goshippo.com/private')),
                new ServerException('private request body and token', new Request('POST', 'https://api.goshippo.com/private'), new Response(500, [], 'private address'))] as $transport) {
                $mock = new MockHandler([$transport]);
                $client = new ShippoRates(new Client(['handler' => HandlerStack::create($mock)]));
                try {
                    $client->quote(new Secret('shippo_test_privatekey'), true, self::address('private origin'), self::address('private buyer'), [self::parcel()], 'USD');
                    self::fail('Transport failure was not mapped.');
                } catch (ShippingUnavailable $failure) {
                    self::assertNull($failure->getPrevious());
                    self::assertStringNotContainsString('private', (string) $failure);
                    self::assertCount(0, $mock);
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $before);
        }
    }

    /** @return array{ShippoRates,MockHandler} */
    private static function client(Response $response): array
    {
        $mock = new MockHandler([$response]);

        return [new ShippoRates(new Client(['handler' => HandlerStack::create($mock)])), $mock];
    }

    private static function response(mixed $fixture): Response
    {
        return new Response(201, ['Content-Type' => 'application/json; charset=UTF-8'], json_encode($fixture, JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    private static function shipment(bool $test = true): array
    {
        return ['object_id' => 'shipment-1', 'status' => 'SUCCESS', 'test' => $test, 'rates' => [
            ['object_id' => 'rate-1', 'shipment' => 'shipment-1', 'test' => $test, 'amount' => '5.50', 'currency' => 'USD',
                'provider' => 'USPS', 'servicelevel' => ['name' => 'Priority Mail', 'token' => 'usps_priority']],
        ]];
    }

    private static function secret(bool $test = true): Secret
    {
        return new Secret($test ? 'shippo_test_fixturekey' : 'shippo_live_fixturekey');
    }

    /** @return array<string,mixed> */
    private static function address(string $name = 'Buyer'): array
    {
        return ['name' => $name, 'address' => ['line1' => '10 Ocean Road', 'country' => 'US',
            'city' => 'San Francisco', 'region' => 'CA', 'postal_code' => '94103']];
    }

    /** @return array<string,string> */
    private static function parcel(): array
    {
        return ['length' => '300', 'width' => '200', 'height' => '100', 'distance_unit' => 'mm', 'weight' => '625', 'mass_unit' => 'g'];
    }
}
