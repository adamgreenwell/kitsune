<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Payments;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Container\Container;
use Kitsune\Commerce\Money\MinorUnits;
use Kitsune\Core\Credentials\Secret;
use Psr\Http\Message\ResponseInterface;
use stdClass;
use Throwable;

/**
 * Concrete, bounded Stripe v1 hosted Checkout, TEST MODE ONLY. No account activation, Connect or payment effects.
 * Provider contract checked 2026-10-14: https://docs.stripe.com/api/checkout/sessions/create and
 * https://docs.stripe.com/error-low-level. An unknown response never authorizes a replacement attempt.
 * The caller owns durable request identity, consent, exact semantic comparison and recovery across credential changes.
 *
 * @internal
 *
 * @phpstan-type Session array{id:string,url:?string,status:string,paymentStatus:string,amountTotal:string,currency:string,expiresAt:int,clientReference:string,attemptReference:string,orderReference:string,test:bool,paymentIntent:?string}
 */
final class StripeCheckout
{
    public const SLOT = 'commerce.stripe-secret-key';

    public const API_VERSION = '2026-09-30.endive';

    public const MAX_RESPONSE_BYTES = 262144;

    public const MAX_REQUEST_BYTES = 65536;

    private const ENDPOINT = 'https://api.stripe.com/v1';

    /**
     * Structured parameter-validation refusals, including invalid amount bounds: https://docs.stripe.com/error-codes.
     * Never infer nonexecution from a generic 400, a replay or a server failure.
     */
    private const INVALID_PARAMETERS = [
        'parameter_missing', 'parameter_unknown', 'parameter_invalid_empty', 'parameter_invalid_integer',
        'parameter_invalid_string', 'parameter_invalid_boolean', 'parameter_invalid_enum',
        'amount_too_small', 'amount_too_large',
    ];

    public function __construct(private readonly ?ClientInterface $http = null) {}

    public function account(Secret $secret): string
    {
        try {
            $account = $this->send('GET', '/account', self::authorization($secret));

            if (! $account instanceof stdClass || ($account->object ?? null) !== 'account') {
                throw new StripeUnavailable;
            }

            return self::id($account->id ?? null, 'acct_');
        } catch (StripeUnavailable $refused) {
            throw $refused;
        } catch (Throwable) {
            throw new StripeUnavailable;
        }
    }

    /** @param array<mixed> $payload
     * @return Session
     */
    public function create(Secret $secret, #[\SensitiveParameter] array $payload, string $idempotencyKey): array
    {
        try {
            $authorization = self::authorization($secret);
            self::key($idempotencyKey);

            if (($payload['mode'] ?? null) !== 'payment' || ($payload['ui_mode'] ?? null) !== 'hosted_page'
                || ($payload['allowed_payment_method_types'] ?? null) !== ['card']) {
                throw new StripeUnavailable('rejected');
            }

            $budget = 1024;
            $body = http_build_query(self::form($payload, 0, $budget), '', '&', PHP_QUERY_RFC3986);

            if (strlen($body) > self::MAX_REQUEST_BYTES) {
                throw new StripeUnavailable('rejected');
            }

            return self::session($this->send('POST', '/checkout/sessions', $authorization, $body, $idempotencyKey));
        } catch (StripeUnavailable $refused) {
            throw $refused;
        } catch (Throwable) {
            throw new StripeUnavailable;
        }
    }

    /** @return Session */
    public function retrieve(Secret $secret, string $sessionId): array
    {
        try {
            $authorization = self::authorization($secret);
            self::id($sessionId, 'cs_test_', 'rejected');

            return self::session($this->send('GET', '/checkout/sessions/'.$sessionId, $authorization));
        } catch (StripeUnavailable $refused) {
            throw $refused;
        } catch (Throwable) {
            throw new StripeUnavailable;
        }
    }

    /** @return Session */
    public function expire(Secret $secret, string $sessionId, string $idempotencyKey): array
    {
        try {
            $authorization = self::authorization($secret);
            self::id($sessionId, 'cs_test_', 'rejected');
            self::key($idempotencyKey);

            return self::session($this->send('POST', '/checkout/sessions/'.$sessionId.'/expire', $authorization, '', $idempotencyKey));
        } catch (StripeUnavailable $refused) {
            throw $refused;
        } catch (Throwable) {
            throw new StripeUnavailable;
        }
    }

    private static function authorization(Secret $secret): string
    {
        $value = $secret->reveal();

        if (strlen($value) < 16 || strlen($value) > 1024 || preg_match('/\Ask_test_[A-Za-z0-9]+\z/D', $value) !== 1) {
            throw new StripeUnavailable('rejected');
        }

        return 'Bearer '.$value;
    }

    private static function key(string $key): void
    {
        if (preg_match('/\A[A-Za-z0-9_.:-]{1,255}\z/D', $key) !== 1) {
            throw new StripeUnavailable('rejected');
        }
    }

    /** @param array<mixed> $input
     * @return array<mixed>
     */
    private static function form(#[\SensitiveParameter] array $input, int $depth, int &$budget): array
    {
        if ($depth > 8 || count($input) > 100) {
            throw new StripeUnavailable('rejected');
        }

        $result = [];

        foreach ($input as $key => $value) {
            if (--$budget < 0) {
                throw new StripeUnavailable('rejected');
            }

            if ((! is_int($key) && preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $key) !== 1)
                || (is_int($key) && ($key < 0 || $key > 99))) {
                throw new StripeUnavailable('rejected');
            }

            if (is_array($value)) {
                $result[$key] = self::form($value, $depth + 1, $budget);
            } elseif (is_bool($value)) {
                $result[$key] = $value ? 'true' : 'false';
            } elseif (is_int($value) || (is_string($value) && strlen($value) <= self::MAX_REQUEST_BYTES
                && mb_check_encoding($value, 'UTF-8') && preg_match('/[\p{Cc}\x{2028}\x{2029}]/u', $value) !== 1)) {
                $result[$key] = $value;
            } else {
                throw new StripeUnavailable('rejected');
            }
        }

        return $result;
    }

    /** No external HTTP while a host-held transaction would keep locks or an old snapshot alive. */
    private static function outsideTransactions(): void
    {
        $container = Container::getInstance();

        if ($container->bound('db')) {
            $manager = $container->make('db');

            foreach ($manager->getConnections() as $connection) {
                if ($connection->transactionLevel() !== 0) {
                    throw new StripeUnavailable('rejected');
                }
            }
        }
    }

    private function send(string $method, string $path, #[\SensitiveParameter] string $authorization, #[\SensitiveParameter] ?string $body = null, ?string $key = null): mixed
    {
        self::outsideTransactions();

        try {
            $sink = Utils::streamFor('');
            $bounded = FnStream::decorate($sink, ['write' => static function (#[\SensitiveParameter] string $bytes) use ($sink): int {
                if (strlen($bytes) > self::MAX_RESPONSE_BYTES - ($sink->getSize() ?? 0)) {
                    throw new StripeUnavailable;
                }

                return $sink->write($bytes);
            }]);
            $headers = ['Authorization' => $authorization, 'Stripe-Version' => self::API_VERSION,
                'Accept' => 'application/json', 'Accept-Encoding' => 'identity'];

            if ($key !== null) {
                $headers['Idempotency-Key'] = $key;
            }

            if ($body !== null) {
                $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            }

            $options = ['headers' => $headers, 'timeout' => 10, 'connect_timeout' => 3, 'read_timeout' => 10,
                'allow_redirects' => false, 'http_errors' => false, 'decode_content' => false, 'verify' => true,
                'cookies' => false, 'debug' => false, 'sink' => $bounded,
                'on_headers' => static function (#[\SensitiveParameter] ResponseInterface $response): void {
                    self::headers($response);
                }];

            if ($body !== null) {
                $options['body'] = $body;
            }

            $response = ($this->http ?? new Client)->request($method, self::ENDPOINT.$path, $options);
            self::headers($response);
            $sink->rewind();
            $raw = $sink->getContents();
            $length = $response->getHeaderLine('Content-Length');

            if ($length !== '' && (int) $length !== strlen($raw)) {
                throw new StripeUnavailable;
            }

            $data = json_decode($raw, false, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);

            if ($response->getStatusCode() !== 200) {
                $error = $data instanceof stdClass ? ($data->error ?? null) : null;
                // The workflow must retain earlier uncertainty even if a later request proves invalid parameters.
                $rejected = $response->getStatusCode() === 400 && $error instanceof stdClass
                    && ($error->type ?? null) === 'invalid_request_error' && in_array($error->code ?? null, self::INVALID_PARAMETERS, true)
                    && $response->getHeaderLine('Idempotent-Replayed') === ''
                    && in_array(strtolower($response->getHeaderLine('Stripe-Should-Retry')), ['', 'false'], true);

                throw new StripeUnavailable($rejected ? 'rejected' : 'unknown');
            }

            return $data;
        } catch (StripeUnavailable $refused) {
            throw $refused;
        } catch (Throwable) {
            throw new StripeUnavailable;
        }
    }

    private static function headers(#[\SensitiveParameter] ResponseInterface $response): void
    {
        $length = $response->getHeaderLine('Content-Length');

        if (($length !== '' && (preg_match('/\A[0-9]{1,9}\z/D', $length) !== 1 || (int) $length > self::MAX_RESPONSE_BYTES))
            || preg_match('/\Aapplication\/json(?:\s*;[^\r\n]*)?\z/iD', $response->getHeaderLine('Content-Type')) !== 1
            || ! in_array(strtolower($response->getHeaderLine('Content-Encoding')), ['', 'identity'], true)) {
            throw new StripeUnavailable;
        }
    }

    /** @return Session */
    private static function session(#[\SensitiveParameter] mixed $data): array
    {
        if (! $data instanceof stdClass || ($data->object ?? null) !== 'checkout.session' || ($data->mode ?? null) !== 'payment'
            || ($data->livemode ?? null) !== false || ! in_array($data->status ?? null, ['open', 'complete', 'expired'], true)
            || ! in_array($data->payment_status ?? null, ['paid', 'unpaid', 'no_payment_required'], true)
            || ! is_string($data->currency ?? null) || preg_match('/\A[a-z]{3}\z/D', $data->currency) !== 1
            || ! is_int($data->expires_at ?? null) || $data->expires_at < 1 || ! ($data->metadata ?? null) instanceof stdClass
            || ! property_exists($data, 'url') || ! property_exists($data, 'payment_intent')) {
            throw new StripeUnavailable;
        }

        $url = $data->url;

        if ($url !== null) {
            if (! is_string($url) || strlen($url) > 4096 || preg_match('/\A[\x21-\x7e]+\z/D', $url) !== 1) {
                throw new StripeUnavailable;
            }

            $parts = parse_url($url);

            if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== 'checkout.stripe.com'
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
                throw new StripeUnavailable;
            }
        }

        return ['id' => self::id($data->id ?? null, 'cs_test_'), 'url' => $url, 'status' => $data->status,
            'paymentStatus' => $data->payment_status, 'amountTotal' => MinorUnits::integer($data->amount_total ?? null),
            'currency' => strtoupper($data->currency), 'expiresAt' => $data->expires_at,
            'clientReference' => self::reference($data->client_reference_id ?? null),
            'attemptReference' => self::reference($data->metadata->kitsune_attempt ?? null),
            'orderReference' => self::reference($data->metadata->kitsune_order ?? null), 'test' => true,
            'paymentIntent' => $data->payment_intent === null ? null : self::id($data->payment_intent, 'pi_')];
    }

    private static function id(#[\SensitiveParameter] mixed $value, string $prefix, string $category = 'unknown'): string
    {
        if (! is_string($value) || preg_match('/\A'.preg_quote($prefix, '/').'[A-Za-z0-9]{1,200}\z/D', $value) !== 1) {
            throw new StripeUnavailable($category);
        }

        return $value;
    }

    private static function reference(#[\SensitiveParameter] mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[A-Za-z0-9_-]{1,200}\z/D', $value) !== 1) {
            throw new StripeUnavailable;
        }

        return $value;
    }
}
