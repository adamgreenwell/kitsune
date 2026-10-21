<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Payments;

use Kitsune\Core\Credentials\Secret;
use Throwable;

/** Raw-byte own-account snapshot verification. Nothing from a payload chooses an Org. @internal */
final class StripeWebhook
{
    public const SLOT = 'commerce.stripe-webhook';

    public const PREVIOUS_SLOT = 'commerce.stripe-webhook-previous';

    public const MAX_BODY = 262144;

    public const MAX_HEADER = 4096;

    public const TOLERANCE = 300;

    /** A query-free rejection only; no header, including a well-formed forgery, is authenticated by this check. */
    public static function signatureIsPlausible(#[\SensitiveParameter] string $header, int $now): bool
    {
        try {
            self::signatureParts($header, $now);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param list<Secret> $secrets
     * @return array<string,mixed>
     */
    public function verify(#[\SensitiveParameter] string $raw, #[\SensitiveParameter] string $header, array $secrets, int $now, string $mode = 'test'): array
    {
        try {
            $test = StripeMode::isTest($mode);
            if ($raw === '' || strlen($raw) > self::MAX_BODY || $header === '' || strlen($header) > self::MAX_HEADER
                || count($secrets) < 1 || count($secrets) > 2) {
                throw new CheckoutRefused;
            }
            [$timestamp, $signatures] = self::signatureParts($header, $now);
            $matched = false;
            foreach ($secrets as $secret) {
                $value = $secret->reveal();
                if (strlen($value) < 16 || strlen($value) > 255 || preg_match('/\Awhsec_[A-Za-z0-9_]+\z/D', $value) !== 1) {
                    throw new CheckoutRefused;
                }
                $expected = hash_hmac('sha256', $timestamp.'.'.$raw, $value);
                unset($value);
                foreach ($signatures as $signature) {
                    $matched = hash_equals($expected, $signature) || $matched;
                }
            }
            if (! $matched) {
                throw new CheckoutRefused;
            }
            $event = json_decode($raw, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (! is_array($event) || ($event['object'] ?? null) !== 'event' || ($event['livemode'] ?? null) !== ! $test
                || ($event['api_version'] ?? null) !== StripeCheckout::API_VERSION || ($event['account'] ?? null) !== null
                || ($event['context'] ?? null) !== null || ! self::identifier($event['id'] ?? null, 'evt_')
                || ! in_array($event['type'] ?? null, ['checkout.session.completed', 'checkout.session.expired',
                    'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed',
                    ...FinancialSignals::TYPES], true)) {
                throw new CheckoutRefused;
            }
            $session = $event['data']['object'] ?? null;
            if (in_array($event['type'], FinancialSignals::TYPES, true)) {
                $refund = str_starts_with($event['type'], 'refund.');
                $dispute = str_starts_with($event['type'], 'charge.dispute.');
                $kind = $refund ? 'refund' : ($dispute ? 'dispute' : 'charge');
                $prefix = $refund ? 're_' : ($dispute ? 'du_' : 'ch_');
                $charge = $kind === 'charge' ? ($session['id'] ?? null) : ($session['charge'] ?? null);
                if (! is_array($session) || ($session['object'] ?? null) !== $kind
                    || ! self::identifier($session['id'] ?? null, $prefix) || strlen($session['id']) > 100
                    || ! self::identifier($charge, 'ch_') || strlen($charge) > 100
                    || (! $refund && ($session['livemode'] ?? null) !== ! $test)) {
                    throw new CheckoutRefused;
                }
                $result = ['id' => $event['id'], 'type' => $event['type'], 'apiVersion' => $event['api_version'],
                    'financialChargeId' => $charge, 'financialSubjectId' => $session['id'], 'test' => $test];
                $metadata = $session['metadata'] ?? [];
                if ($refund && is_array($metadata) && array_intersect(['kitsune_order', 'kitsune_refund', 'kitsune_request'], array_keys($metadata)) !== []) {
                    if (! self::reference($metadata['kitsune_order'] ?? null) || ! self::reference($metadata['kitsune_refund'] ?? null)
                        || ! is_string($metadata['kitsune_request'] ?? null)
                        || preg_match('/\A[a-f0-9]{64}\z/D', $metadata['kitsune_request']) !== 1) {
                        throw new CheckoutRefused;
                    }

                    return [...$result, 'refundId' => $session['id'], 'orderReference' => $metadata['kitsune_order'],
                        'refundReference' => $metadata['kitsune_refund'], 'requestDigest' => $metadata['kitsune_request']];
                }

                return $result;
            }
            if (! is_array($session) || ($session['object'] ?? null) !== 'checkout.session' || ($session['livemode'] ?? null) !== ! $test
                || ($session['mode'] ?? null) !== 'payment' || ! StripeMode::session($session['id'] ?? null, $mode)
                || ! self::reference($session['metadata']['kitsune_order'] ?? null)
                || ! self::reference($session['metadata']['kitsune_attempt'] ?? null)
                || ($session['client_reference_id'] ?? null) !== $session['metadata']['kitsune_order']) {
                throw new CheckoutRefused;
            }

            return ['id' => $event['id'], 'type' => $event['type'], 'apiVersion' => $event['api_version'],
                'sessionId' => $session['id'], 'orderReference' => $session['metadata']['kitsune_order'],
                'attemptReference' => $session['metadata']['kitsune_attempt'], 'test' => $test];
        } catch (Throwable) {
            // Neither signatures, payloads nor provider text may survive in an exception chain.
            throw new CheckoutRefused;
        }
    }

    /** @return array{string,list<string>} */
    private static function signatureParts(#[\SensitiveParameter] string $header, int $now): array
    {
        if ($header === '' || strlen($header) > self::MAX_HEADER) {
            throw new CheckoutRefused;
        }
        $parts = explode(',', $header);
        if (count($parts) > 32) {
            throw new CheckoutRefused;
        }
        $timestamp = null;
        $signatures = [];
        foreach ($parts as $part) {
            [$scheme, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($scheme === 't') {
                if ($timestamp !== null || ! is_string($value) || preg_match('/\A[1-9][0-9]{0,11}\z/D', $value) !== 1) {
                    throw new CheckoutRefused;
                }
                $timestamp = $value;
            } elseif ($scheme === 'v1') {
                if (! is_string($value) || preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
                    throw new CheckoutRefused;
                }
                $signatures[] = $value;
            }
        }
        if ($timestamp === null || abs($now - (int) $timestamp) > self::TOLERANCE || $signatures === []) {
            throw new CheckoutRefused;
        }

        return [$timestamp, $signatures];
    }

    private static function identifier(mixed $value, string $prefix): bool
    {
        return is_string($value) && strlen($value) <= 255 && preg_match('/\A'.preg_quote($prefix, '/').'[A-Za-z0-9_]+\z/D', $value) === 1;
    }

    private static function reference(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{32}\z/D', $value) === 1;
    }
}
