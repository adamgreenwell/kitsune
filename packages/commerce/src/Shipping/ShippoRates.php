<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Shipping;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Kitsune\Commerce\Money\MinorUnits;
use Kitsune\Commerce\Orders\BillingDetails;
use Kitsune\Core\Credentials\Secret;
use Kitsune\Core\Currency\Currencies;
use Psr\Http\Message\ResponseInterface;
use stdClass;
use Throwable;

/**
 * Concrete Shippo rate shopping. It creates a shipment only: never a transaction, label or pickup.
 *
 * Pinned contract, checked 2026-10-18:
 * https://docs.goshippo.com/api-reference/shipments/create-a-new-shipment
 * https://docs.goshippo.com/api-reference/rates/retrieve-a-rate
 * https://docs.goshippo.com/shipments/multi-piece-shipment
 *
 * async=false completes rate generation in this one request. Rate.amount covers the WHOLE shipment, including all
 * parcels, in its stated sender currency. amount_local is never an implicit FX conversion into the org's currency.
 * Service availability and multi-piece limits remain carrier-specific; failure never becomes a local fallback.
 * Cross-border requests require explicit checked owner customs inputs and mode-specific carrier accounts.
 *
 * The caller reads the current org-mode Secret immediately before calling and retains the selected quote, freshness
 * and ownership. This client stores no key, address, response body or quote; a quote is never a stock reservation.
 *
 * @internal First-party commerce primitive; no extension stability promise before v1.2.
 *
 * @phpstan-type Rate array{id:string,carrier:string,service:string,amountMinor:string,currency:string,shipmentId:string,test:bool,international?:true}
 */
final class ShippoRates
{
    public const API_VERSION = '2018-02-08';

    public const MAX_RESPONSE_BYTES = 262144;

    public const MAX_RATES = 100;

    private const ENDPOINT = 'https://api.goshippo.com/shipments/';

    public function __construct(private readonly ?ClientInterface $http = null) {}

    /**
     * Addresses use BillingDetails' {name,address:{line1,line2,city,region,postal_code,country}} envelope.
     * Every provider/input/transport failure is deliberately mapped without the original exception or response.
     *
     * @param  array<mixed>  $from
     * @param  array<mixed>  $to
     * @param  array<mixed>  $parcels
     * @param  array<string,mixed>|null  $international
     * @return list<Rate>
     */
    public function quote(Secret $secret, bool $test, #[\SensitiveParameter] array $from, #[\SensitiveParameter] array $to, array $parcels, string $currency, #[\SensitiveParameter] ?array $international = null): array
    {
        try {
            $from = BillingDetails::from($from);
            $to = BillingDetails::from($to);

            if (! Currencies::supports($currency)) {
                throw new ShippingUnavailable;
            }

            $payload = ['address_from' => self::address($from), 'address_to' => self::address($to),
                'parcels' => ParcelPacking::validate($parcels), 'async' => false];
            $crossBorder = $from['address']['country'] !== $to['address']['country'];
            if ($crossBorder) {
                $payload = [...$payload, ...InternationalShipping::payload($international ?? [], $to['address']['country'], $currency, $test)];
            } elseif ($international !== null) {
                throw new ShippingUnavailable;
            }
            $body = Utils::streamFor('');
            $bounded = FnStream::decorate($body, ['write' => static function (#[\SensitiveParameter] string $bytes) use ($body): int {
                if (strlen($bytes) > self::MAX_RESPONSE_BYTES - ($body->getSize() ?? 0)) {
                    throw new ShippingUnavailable;
                }

                return $body->write($bytes);
            }]);
            $response = ($this->http ?? new Client)->request('POST', self::ENDPOINT, [
                'headers' => ['Authorization' => self::authorization($secret, $test), 'SHIPPO-API-VERSION' => self::API_VERSION,
                    'Accept' => 'application/json', 'Accept-Encoding' => 'identity'],
                'json' => $payload, 'timeout' => 10, 'connect_timeout' => 3, 'read_timeout' => 10,
                'allow_redirects' => false, 'http_errors' => false, 'decode_content' => false, 'verify' => true,
                'cookies' => false, 'debug' => false, 'sink' => $bounded,
                'on_headers' => static function (#[\SensitiveParameter] ResponseInterface $response): void {
                    self::headers($response);
                },
            ]);
            self::headers($response);
            $body->rewind();
            $raw = $body->getContents();
            $length = $response->getHeaderLine('Content-Length');

            if ($response->getStatusCode() !== 201 || ($length !== '' && (int) $length !== strlen($raw))) {
                throw new ShippingUnavailable;
            }

            return self::rates(json_decode($raw, false, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING), $currency, $test, $payload['carrier_accounts'] ?? null);
        } catch (Throwable) {
            throw new ShippingUnavailable;
        }
    }

    private static function authorization(Secret $secret, bool $test): string
    {
        $value = $secret->reveal();

        if (strlen($value) < 16 || strlen($value) > 1024 || preg_match('/\A[\x21-\x7e]+\z/D', $value) !== 1
            || ! str_starts_with($value, $test ? 'shippo_test_' : 'shippo_live_')) {
            throw new ShippingUnavailable;
        }

        return 'ShippoToken '.$value;
    }

    /** @param array{name:string,address:array{line1:string,line2:string,city:string,region:string,postal_code:string,country:string}} $details
     * @return array<string,string>
     */
    private static function address(#[\SensitiveParameter] array $details): array
    {
        $address = $details['address'];

        return ['name' => $details['name'], 'street1' => $address['line1'], 'street2' => $address['line2'],
            'city' => $address['city'], 'state' => $address['region'], 'zip' => $address['postal_code'], 'country' => $address['country']];
    }

    private static function headers(#[\SensitiveParameter] ResponseInterface $response): void
    {
        $length = $response->getHeaderLine('Content-Length');

        if (($length !== '' && (preg_match('/\A[0-9]{1,6}\z/D', $length) !== 1 || (int) $length > self::MAX_RESPONSE_BYTES))
            || preg_match('/\Aapplication\/json(?:\s*;[^\r\n]*)?\z/iD', $response->getHeaderLine('Content-Type')) !== 1
            || ! in_array(strtolower($response->getHeaderLine('Content-Encoding')), ['', 'identity'], true)) {
            throw new ShippingUnavailable;
        }
    }

    /** @param list<string>|null $accounts
     * @return list<Rate> */
    private static function rates(#[\SensitiveParameter] mixed $shipment, string $currency, bool $test, ?array $accounts): array
    {
        if (! $shipment instanceof stdClass || ($shipment->status ?? null) !== 'SUCCESS' || ($shipment->test ?? null) !== $test
            || ! is_array($shipment->rates ?? null) || ! array_is_list($shipment->rates)
            || count($shipment->rates) < 1 || count($shipment->rates) > self::MAX_RATES) {
            throw new ShippingUnavailable;
        }

        $shipmentId = self::id($shipment->object_id ?? null);
        $result = [];
        $seen = [];

        foreach ($shipment->rates as $rate) {
            if (! $rate instanceof stdClass || ($rate->test ?? null) !== $test || ($rate->currency ?? null) !== $currency
                || ($rate->shipment ?? null) !== $shipmentId || ! ($rate->servicelevel ?? null) instanceof stdClass) {
                throw new ShippingUnavailable;
            }

            if ($accounts !== null && ! in_array($rate->carrier_account ?? null, $accounts, true)) {
                throw new ShippingUnavailable;
            }
            $id = self::id($rate->object_id ?? null);

            if (isset($seen[$id])) {
                throw new ShippingUnavailable;
            }

            $seen[$id] = true;
            $result[] = ['id' => $id, 'carrier' => self::label($rate->provider ?? null),
                'service' => self::label($rate->servicelevel->name ?? null), 'amountMinor' => self::amount($rate->amount ?? null, $currency),
                'currency' => $currency, 'shipmentId' => $shipmentId, 'test' => $test, ...($accounts === null ? [] : ['international' => true])];
        }

        return $result;
    }

    /** Provider decimals may pad a zero-minor-unit currency with .00; remove only redundant zeroes, never round. */
    private static function amount(#[\SensitiveParameter] mixed $value, string $currency): string
    {
        if (! is_string($value) || strlen($value) > 100 || preg_match('/\A([0-9]+)(?:\.([0-9]+))?\z/D', $value, $parts) !== 1) {
            throw new ShippingUnavailable;
        }

        $places = Currencies::minorUnits($currency);
        $fraction = $parts[2] ?? '';

        if (strlen($fraction) > $places && trim(substr($fraction, $places), '0') !== '') {
            throw new ShippingUnavailable;
        }

        $fraction = substr($fraction, 0, $places);

        return MinorUnits::fromDecimal($parts[1].($fraction === '' ? '' : '.'.$fraction), $currency);
    }

    private static function id(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 100 || preg_match('/\A[A-Za-z0-9_-]+\z/D', $value) !== 1) {
            throw new ShippingUnavailable;
        }

        return $value;
    }

    private static function label(#[\SensitiveParameter] mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 800 || ! mb_check_encoding($value, 'UTF-8')
            || mb_strlen($value, 'UTF-8') > 200 || trim($value) === '' || preg_match('/[\p{Cc}\x{2028}\x{2029}]/u', $value) === 1) {
            throw new ShippingUnavailable;
        }

        return $value;
    }
}
