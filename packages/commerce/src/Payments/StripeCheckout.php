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
 * Concrete, bounded own-account Stripe v1 Checkout/refunds with an explicit requested mode. No Connect.
 * Provider contract checked 2026-10-14: https://docs.stripe.com/api/checkout/sessions/create and
 * https://docs.stripe.com/error-low-level. An unknown response never authorizes a replacement attempt.
 * The caller owns durable request identity, consent, exact semantic comparison and recovery across credential changes.
 *
 * @internal
 *
 * @phpstan-type Session array{id:string,url:?string,status:string,paymentStatus:string,amountTotal:string,currency:string,expiresAt:int,clientReference:string,attemptReference:string,orderReference:string,test:bool,paymentIntent:?string}
 * @phpstan-type Payment array{intentId:string,chargeId:string,currency:string,amount:string,received:string,captured:string,refunded:string,disputed:bool,test:bool}
 * @phpstan-type PaidSession array{id:string,url:?string,status:string,paymentStatus:string,amountTotal:string,currency:string,expiresAt:int,clientReference:string,attemptReference:string,orderReference:string,test:bool,paymentIntent:?string,payment:?Payment}
 * @phpstan-type Charge array{id:string,paymentIntent:string,currency:string,amount:string,captured:string,refunded:string,paid:bool,capturedFully:bool,status:string,disputed:bool,test:bool}
 * @phpstan-type RefundMetadata array{kitsune_order:string,kitsune_refund:string,kitsune_request:string}
 * @phpstan-type Refund array{id:string,chargeId:string,paymentIntent:string,currency:string,amount:string,status:string,created:int,metadata:?RefundMetadata}
 * @phpstan-type RefundPage array{refunds:list<Refund>,hasMore:bool,next:?string}
 * @phpstan-type Dispute array{id:string,chargeId:string,paymentIntent:string,currency:string,amount:string,status:string,created:int,test:bool}
 * @phpstan-type DisputePage array{disputes:list<Dispute>,hasMore:bool,next:?string}
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

    public function account(Secret $secret, string $mode = 'test'): string
    {
        try {
            $account = $this->send('GET', '/account', self::authorization($secret, $mode));

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

    /** Admission checks current account readiness; only its ID leaves this boundary, never its personal fields. */
    public function liveAccount(Secret $secret): string
    {
        try {
            $account = $this->send('GET', '/account', self::authorization($secret, 'live'));
            if (! $account instanceof stdClass || ($account->object ?? null) !== 'account'
                || ($account->charges_enabled ?? null) !== true || ($account->payouts_enabled ?? null) !== true
                || ($account->details_submitted ?? null) !== true) {
                throw new StripeUnavailable('rejected');
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
    public function create(Secret $secret, #[\SensitiveParameter] array $payload, string $idempotencyKey, string $mode = 'test'): array
    {
        try {
            $authorization = self::authorization($secret, $mode);
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

            return self::session($this->send('POST', '/checkout/sessions', $authorization, $body, $idempotencyKey), $mode);
        } catch (StripeUnavailable $refused) {
            throw $refused;
        } catch (Throwable) {
            throw new StripeUnavailable;
        }
    }

    /** @return Session */
    public function retrieve(Secret $secret, string $sessionId, string $mode = 'test'): array
    {
        try {
            $authorization = self::authorization($secret, $mode);
            self::id($sessionId, 'cs_'.$mode.'_', 'rejected');

            return self::session($this->send('GET', '/checkout/sessions/'.$sessionId, $authorization), $mode);
        } catch (StripeUnavailable $refused) {
            throw $refused;
        } catch (Throwable) {
            throw new StripeUnavailable;
        }
    }

    /**
     * Retrieve the payment graph in the existing single Session read. Both edges are documented expandable fields:
     * https://docs.stripe.com/expand and https://docs.stripe.com/api/payment_intents/object#payment_intent_object-latest_charge.
     * The projection discards expanded client secrets and buyer details. Incomplete paid proof is never downgraded
     * to an ordinary Session or repaired with additional unbounded provider reads.
     *
     * @return PaidSession
     */
    public function retrievePaid(Secret $secret, string $sessionId, string $mode = 'test'): array
    {
        try {
            $authorization = self::authorization($secret, $mode);
            self::id($sessionId, 'cs_'.$mode.'_', 'rejected');
            $data = $this->send('GET', '/checkout/sessions/'.$sessionId.'?expand%5B%5D=payment_intent.latest_charge', $authorization);

            if (! $data instanceof stdClass) {
                throw new StripeUnavailable;
            }
            $intent = $data->payment_intent ?? null;
            $plain = clone $data;
            if ($intent instanceof stdClass) {
                if (($intent->object ?? null) !== 'payment_intent' || ($intent->livemode ?? null) !== (! StripeMode::isTest($mode))) {
                    throw new StripeUnavailable;
                }
                $plain->payment_intent = self::id($intent->id ?? null, 'pi_');
            }
            $session = self::session($plain, $mode);
            if ($session['id'] !== $sessionId) {
                throw new StripeUnavailable;
            }
            $payment = null;
            if ($session['paymentStatus'] === 'paid') {
                if ($session['status'] !== 'complete' || $session['amountTotal'] === '0' || ! $intent instanceof stdClass) {
                    throw new StripeUnavailable;
                }
                $payment = self::payment($intent, $session['amountTotal'], $session['currency'], $mode);
            }

            return [...$session, 'payment' => $payment];
        } catch (StripeUnavailable $refused) {
            throw $refused;
        } catch (Throwable) {
            throw new StripeUnavailable;
        }
    }

    /** @return Charge */
    public function retrieveCharge(Secret $secret, string $chargeId, string $mode = 'test'): array
    {
        try {
            $authorization = self::authorization($secret, $mode);
            self::id($chargeId, 'ch_', 'rejected');
            $charge = self::charge($this->send('GET', '/charges/'.$chargeId, $authorization), $mode);
            if ($charge['id'] !== $chargeId) {
                throw new StripeUnavailable;
            }

            return $charge;
        } catch (StripeUnavailable $refused) {
            throw $refused;
        } catch (Throwable) {
            throw new StripeUnavailable;
        }
    }

    /**
     * Exact card-refund request: the workflow owns durable identity, amount allocation and ambiguity recovery.
     * A Refund has no documented livemode field; mode/account custody comes from the verified original Charge.
     * https://docs.stripe.com/api/refunds/create and https://docs.stripe.com/api/refunds/object.
     *
     * @param  array<mixed>  $payload
     * @return Refund
     */
    public function createRefund(Secret $secret, #[\SensitiveParameter] array $payload, string $idempotencyKey, string $mode = 'test'): array
    {
        try {
            $authorization = self::authorization($secret, $mode);
            self::key($idempotencyKey);
            $keys = array_keys($payload);
            sort($keys);
            if ($keys !== ['amount', 'charge', 'metadata'] || ! is_array($payload['metadata'])) {
                throw new StripeUnavailable('rejected');
            }
            $charge = self::id($payload['charge'], 'ch_', 'rejected');
            try {
                $amount = MinorUnits::integer($payload['amount']);
            } catch (Throwable) {
                throw new StripeUnavailable('rejected');
            }
            $metadata = self::refundMetadata((object) $payload['metadata']);
            if ($amount === '0' || $metadata === null) {
                throw new StripeUnavailable('rejected');
            }
            $budget = 1024;
            $body = http_build_query(self::form(['charge' => $charge, 'amount' => $amount, 'metadata' => $metadata], 0, $budget), '', '&', PHP_QUERY_RFC3986);
            $refund = self::refund($this->send('POST', '/refunds', $authorization, $body, $idempotencyKey));
            if ($refund['chargeId'] !== $charge || $refund['amount'] !== $amount || $refund['metadata'] !== $metadata) {
                throw new StripeUnavailable;
            }

            return $refund;
        } catch (StripeUnavailable $refused) {
            throw $refused;
        } catch (Throwable) {
            throw new StripeUnavailable;
        }
    }

    /** @return Refund */
    public function retrieveRefund(Secret $secret, string $refundId, string $mode = 'test'): array
    {
        try {
            $authorization = self::authorization($secret, $mode);
            self::id($refundId, 're_', 'rejected');
            $refund = self::refund($this->send('GET', '/refunds/'.$refundId, $authorization));
            if ($refund['id'] !== $refundId) {
                throw new StripeUnavailable;
            }

            return $refund;
        } catch (StripeUnavailable $refused) {
            throw $refused;
        } catch (Throwable) {
            throw new StripeUnavailable;
        }
    }

    /** One bounded, Charge-filtered page; the workflow decides whether a complete search has been established.
     * @return RefundPage
     */
    public function listRefunds(Secret $secret, string $chargeId, ?string $startingAfter = null, int $limit = 100, string $mode = 'test'): array
    {
        try {
            $authorization = self::authorization($secret, $mode);
            self::id($chargeId, 'ch_', 'rejected');
            if ($startingAfter !== null) {
                self::id($startingAfter, 're_', 'rejected');
            }
            if ($limit < 1 || $limit > 100) {
                throw new StripeUnavailable('rejected');
            }
            $parameters = ['charge' => $chargeId, 'limit' => $limit];
            if ($startingAfter !== null) {
                $parameters['starting_after'] = $startingAfter;
            }
            $data = $this->send('GET', '/refunds?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986), $authorization);
            if (! $data instanceof stdClass || ($data->object ?? null) !== 'list' || ($data->url ?? null) !== '/v1/refunds'
                || ! is_bool($data->has_more ?? null) || ! is_array($data->data ?? null) || ! array_is_list($data->data)
                || count($data->data) > $limit || ($data->has_more && $data->data === [])) {
                throw new StripeUnavailable;
            }
            $refunds = [];
            $seen = [];
            foreach ($data->data as $value) {
                $refund = self::refund($value);
                if ($refund['chargeId'] !== $chargeId || isset($seen[$refund['id']]) || $refund['id'] === $startingAfter) {
                    throw new StripeUnavailable;
                }
                $seen[$refund['id']] = true;
                $refunds[] = $refund;
            }

            return ['refunds' => $refunds, 'hasMore' => $data->has_more,
                'next' => $data->has_more ? $refunds[array_key_last($refunds)]['id'] : null];
        } catch (StripeUnavailable $refused) {
            throw $refused;
        } catch (Throwable) {
            throw new StripeUnavailable;
        }
    }

    /** Current original-Charge dispute state; an event's funds movement is never proof of a win.
     * @return Dispute
     */
    public function retrieveDispute(Secret $secret, string $disputeId, string $mode = 'test'): array
    {
        try {
            $authorization = self::authorization($secret, $mode);
            self::id($disputeId, 'du_', 'rejected');
            $dispute = self::dispute($this->send('GET', '/disputes/'.$disputeId, $authorization), $mode);
            if ($dispute['id'] !== $disputeId) {
                throw new StripeUnavailable;
            }

            return $dispute;
        } catch (StripeUnavailable $refused) {
            throw $refused;
        } catch (Throwable) {
            throw new StripeUnavailable;
        }
    }

    /** One bounded page. Multiple disputes can exist on one Charge; a partial page never proves their absence.
     * @return DisputePage
     */
    public function listDisputes(Secret $secret, string $chargeId, ?string $startingAfter = null, int $limit = 20, string $mode = 'test'): array
    {
        try {
            $authorization = self::authorization($secret, $mode);
            self::id($chargeId, 'ch_', 'rejected');
            if ($startingAfter !== null) {
                self::id($startingAfter, 'du_', 'rejected');
            }
            if ($limit < 1 || $limit > 100) {
                throw new StripeUnavailable('rejected');
            }
            $parameters = ['charge' => $chargeId, 'limit' => $limit];
            if ($startingAfter !== null) {
                $parameters['starting_after'] = $startingAfter;
            }
            $data = $this->send('GET', '/disputes?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986), $authorization);
            if (! $data instanceof stdClass || ($data->object ?? null) !== 'list' || ($data->url ?? null) !== '/v1/disputes'
                || ! is_bool($data->has_more ?? null) || ! is_array($data->data ?? null) || ! array_is_list($data->data)
                || count($data->data) > $limit || ($data->has_more && $data->data === [])) {
                throw new StripeUnavailable;
            }
            $disputes = [];
            $seen = [];
            foreach ($data->data as $value) {
                $dispute = self::dispute($value, $mode);
                if ($dispute['chargeId'] !== $chargeId || isset($seen[$dispute['id']]) || $dispute['id'] === $startingAfter) {
                    throw new StripeUnavailable;
                }
                $seen[$dispute['id']] = true;
                $disputes[] = $dispute;
            }

            return ['disputes' => $disputes, 'hasMore' => $data->has_more,
                'next' => $data->has_more ? $disputes[array_key_last($disputes)]['id'] : null];
        } catch (StripeUnavailable $refused) {
            throw $refused;
        } catch (Throwable) {
            throw new StripeUnavailable;
        }
    }

    /** @return Session */
    public function expire(Secret $secret, string $sessionId, string $idempotencyKey, string $mode = 'test'): array
    {
        try {
            $authorization = self::authorization($secret, $mode);
            self::id($sessionId, 'cs_'.$mode.'_', 'rejected');
            self::key($idempotencyKey);

            return self::session($this->send('POST', '/checkout/sessions/'.$sessionId.'/expire', $authorization, '', $idempotencyKey), $mode);
        } catch (StripeUnavailable $refused) {
            throw $refused;
        } catch (Throwable) {
            throw new StripeUnavailable;
        }
    }

    private static function authorization(Secret $secret, string $mode = 'test'): string
    {
        StripeMode::isTest($mode);
        $value = $secret->reveal();

        if (strlen($value) < 16 || strlen($value) > 1024 || preg_match('/\Ask_'.$mode.'_[A-Za-z0-9]+\z/D', $value) !== 1) {
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
    private static function session(#[\SensitiveParameter] mixed $data, string $mode = 'test'): array
    {
        if (! $data instanceof stdClass || ($data->object ?? null) !== 'checkout.session' || ($data->mode ?? null) !== 'payment'
            || ($data->livemode ?? null) !== (! StripeMode::isTest($mode)) || ! in_array($data->status ?? null, ['open', 'complete', 'expired'], true)
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

        return ['id' => self::id($data->id ?? null, 'cs_'.$mode.'_'), 'url' => $url, 'status' => $data->status,
            'paymentStatus' => $data->payment_status, 'amountTotal' => MinorUnits::integer($data->amount_total ?? null),
            'currency' => strtoupper($data->currency), 'expiresAt' => $data->expires_at,
            'clientReference' => self::reference($data->client_reference_id ?? null),
            'attemptReference' => self::reference($data->metadata->kitsune_attempt ?? null),
            'orderReference' => self::reference($data->metadata->kitsune_order ?? null), 'test' => StripeMode::isTest($mode),
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

    /** @return Payment */
    private static function payment(#[\SensitiveParameter] stdClass $intent, string $amount, string $currency, string $mode = 'test'): array
    {
        $intentId = self::id($intent->id ?? null, 'pi_');
        if (($intent->object ?? null) !== 'payment_intent' || ($intent->livemode ?? null) !== (! StripeMode::isTest($mode))
            || ($intent->status ?? null) !== 'succeeded' || self::currency($intent->currency ?? null) !== $currency
            || MinorUnits::integer($intent->amount ?? null) !== $amount || MinorUnits::integer($intent->amount_received ?? null) !== $amount) {
            throw new StripeUnavailable;
        }
        $charge = self::charge($intent->latest_charge ?? null, $mode);
        if ($charge['paymentIntent'] !== $intentId || $charge['currency'] !== $currency || $charge['amount'] !== $amount
            || ! $charge['paid'] || ! $charge['capturedFully'] || $charge['status'] !== 'succeeded') {
            throw new StripeUnavailable;
        }

        return ['intentId' => $intentId, 'chargeId' => $charge['id'], 'currency' => $currency, 'amount' => $amount,
            'received' => $amount, 'captured' => $charge['captured'], 'refunded' => $charge['refunded'],
            'disputed' => $charge['disputed'], 'test' => StripeMode::isTest($mode)];
    }

    /** @return Charge */
    private static function charge(#[\SensitiveParameter] mixed $data, string $mode = 'test'): array
    {
        if (! $data instanceof stdClass || ($data->object ?? null) !== 'charge' || ($data->livemode ?? null) !== (! StripeMode::isTest($mode))
            || ! is_bool($data->paid ?? null) || ! is_bool($data->captured ?? null) || ! is_bool($data->disputed ?? null)
            || ! in_array($data->status ?? null, ['succeeded', 'pending', 'failed'], true)) {
            throw new StripeUnavailable;
        }
        $amount = MinorUnits::integer($data->amount ?? null);
        $captured = MinorUnits::integer($data->amount_captured ?? null);
        $refunded = MinorUnits::integer($data->amount_refunded ?? null);
        if (self::greater($captured, $amount) || self::greater($refunded, $captured)) {
            throw new StripeUnavailable;
        }

        return ['id' => self::id($data->id ?? null, 'ch_'), 'paymentIntent' => self::id($data->payment_intent ?? null, 'pi_'),
            'currency' => self::currency($data->currency ?? null), 'amount' => $amount, 'captured' => $captured,
            'refunded' => $refunded, 'paid' => $data->paid, 'capturedFully' => $data->captured && $captured === $amount,
            'status' => $data->status, 'disputed' => $data->disputed, 'test' => StripeMode::isTest($mode)];
    }

    /** @return Refund */
    private static function refund(#[\SensitiveParameter] mixed $data): array
    {
        if (! $data instanceof stdClass || ($data->object ?? null) !== 'refund'
            || ! in_array($data->status ?? null, ['pending', 'requires_action', 'succeeded', 'failed', 'canceled'], true)
            || ! is_int($data->created ?? null) || $data->created < 1) {
            throw new StripeUnavailable;
        }
        $amount = MinorUnits::integer($data->amount ?? null);
        if ($amount === '0') {
            throw new StripeUnavailable;
        }

        return ['id' => self::id($data->id ?? null, 're_'), 'chargeId' => self::id($data->charge ?? null, 'ch_'),
            'paymentIntent' => self::id($data->payment_intent ?? null, 'pi_'), 'currency' => self::currency($data->currency ?? null),
            'amount' => $amount, 'status' => $data->status, 'created' => $data->created,
            'metadata' => self::refundMetadata($data->metadata ?? null)];
    }

    /** Financial statements are deliberately discarded, including submitted evidence and customer details.
     * @return Dispute
     */
    private static function dispute(#[\SensitiveParameter] mixed $data, string $mode = 'test'): array
    {
        if (! $data instanceof stdClass || ($data->object ?? null) !== 'dispute' || ($data->livemode ?? null) !== (! StripeMode::isTest($mode))
            || ! in_array($data->status ?? null, ['warning_needs_response', 'warning_under_review', 'warning_closed',
                'needs_response', 'under_review', 'won', 'lost', 'prevented'], true)
            || ! is_int($data->created ?? null) || $data->created < 1) {
            throw new StripeUnavailable;
        }
        $amount = MinorUnits::integer($data->amount ?? null);
        if ($amount === '0') {
            throw new StripeUnavailable;
        }

        return ['id' => self::id($data->id ?? null, 'du_'), 'chargeId' => self::id($data->charge ?? null, 'ch_'),
            'paymentIntent' => self::id($data->payment_intent ?? null, 'pi_'), 'currency' => self::currency($data->currency ?? null),
            'amount' => $amount, 'status' => $data->status, 'created' => $data->created, 'test' => StripeMode::isTest($mode)];
    }

    /** Invalid or external metadata remains unclaimed; callers must not adopt it by amount alone.
     * @return RefundMetadata|null
     */
    private static function refundMetadata(#[\SensitiveParameter] mixed $metadata): ?array
    {
        if (! $metadata instanceof stdClass) {
            return null;
        }
        $values = (array) $metadata;
        $keys = array_keys($values);
        sort($keys);
        if ($keys !== ['kitsune_order', 'kitsune_refund', 'kitsune_request']) {
            return null;
        }
        foreach (['kitsune_order' => 32, 'kitsune_refund' => 32, 'kitsune_request' => 64] as $key => $length) {
            if (! is_string($values[$key]) || preg_match('/\A[a-f0-9]{'.$length.'}\z/D', $values[$key]) !== 1) {
                return null;
            }
        }

        return ['kitsune_order' => $values['kitsune_order'], 'kitsune_refund' => $values['kitsune_refund'],
            'kitsune_request' => $values['kitsune_request']];
    }

    private static function currency(#[\SensitiveParameter] mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[a-z]{3}\z/D', $value) !== 1) {
            throw new StripeUnavailable;
        }

        return strtoupper($value);
    }

    private static function greater(string $left, string $right): bool
    {
        return strlen($left) > strlen($right) || (strlen($left) === strlen($right) && strcmp($left, $right) > 0);
    }
}
