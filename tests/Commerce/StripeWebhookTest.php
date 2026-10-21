<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Commerce\Payments\CheckoutRefused;
use Kitsune\Commerce\Payments\StripeCheckout;
use Kitsune\Commerce\Payments\StripeWebhook;
use Kitsune\Core\Credentials\Secret;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StripeWebhookTest extends TestCase
{
    private const NOW = 1800000000;

    private const KEY = 'whsec_private_signature_fixture';

    public function test_exact_bytes_are_verified_before_parsing_and_only_safe_routing_hints_leave_the_verifier(): void
    {
        $raw = " \n".json_encode(self::event(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $result = self::verify($raw);
        self::assertSame(['id' => 'evt_fixture', 'type' => 'checkout.session.completed', 'apiVersion' => StripeCheckout::API_VERSION,
            'sessionId' => 'cs_test_fixture', 'orderReference' => str_repeat('a', 32), 'attemptReference' => str_repeat('b', 32), 'test' => true], $result);
        self::assertStringNotContainsString('private', json_encode($result));
        $this->expectException(CheckoutRefused::class);
        (new StripeWebhook)->verify(trim($raw), self::header($raw), [new Secret(self::KEY)], self::NOW);
    }

    public function test_multiple_v1_and_explicit_previous_secret_overlap_work_without_accepting_v0(): void
    {
        $raw = json_encode(self::event(), JSON_THROW_ON_ERROR);
        $header = 'v1='.str_repeat('0', 64).',v0=ignored,'.self::header($raw);
        self::assertSame('evt_fixture', (new StripeWebhook)->verify($raw, $header,
            [new Secret('whsec_new_unmatched_secret'), new Secret(self::KEY)], self::NOW)['id']);
    }

    #[DataProvider('timestampOffsets')]
    public function test_the_absolute_timestamp_window_has_no_zero_tolerance_bypass(int $offset, bool $accepted): void
    {
        $raw = json_encode(self::event(), JSON_THROW_ON_ERROR);
        self::assertSame($accepted, StripeWebhook::signatureIsPlausible(self::header($raw, self::NOW + $offset), self::NOW));
        if (! $accepted) {
            $this->expectException(CheckoutRefused::class);
        }
        self::assertSame('evt_fixture', self::verify($raw, self::header($raw, self::NOW + $offset))['id']);
    }

    public static function timestampOffsets(): iterable
    {
        foreach ([-301 => false, -300 => true, 0 => true, 300 => true, 301 => false] as $offset => $accepted) {
            yield [$offset, $accepted];
        }
    }

    #[DataProvider('invalidHeaders')]
    public function test_malformed_ambiguous_missing_and_unbounded_signatures_are_refused(string $header): void
    {
        self::assertFalse(StripeWebhook::signatureIsPlausible($header, self::NOW));
        $this->expectException(CheckoutRefused::class);
        self::verify(json_encode(self::event(), JSON_THROW_ON_ERROR), $header);
    }

    public static function invalidHeaders(): iterable
    {
        yield [''];
        yield ['t=1800000000,v0='.str_repeat('0', 64)];
        yield ['t=01800000000,v1='.str_repeat('0', 64)];
        yield ['t=1800000000,t=1800000000,v1='.str_repeat('0', 64)];
        yield ['t=1800000000,v1=secret'];
        yield [str_repeat('x', 4097)];
        yield ['t=1800000000,'.str_repeat('v0=ignored,', 32)];
    }

    public function test_a_plausible_envelope_does_not_authenticate_a_forgery(): void
    {
        $header = 't='.self::NOW.',v1='.str_repeat('0', 64);
        self::assertTrue(StripeWebhook::signatureIsPlausible($header, self::NOW));
        $this->expectException(CheckoutRefused::class);
        self::verify(json_encode(self::event(), JSON_THROW_ON_ERROR), $header);
    }

    #[DataProvider('invalidEvents')]
    public function test_signed_wrong_account_mode_version_or_routing_shapes_are_refused(array $event): void
    {
        $this->expectException(CheckoutRefused::class);
        self::verify(json_encode($event, JSON_THROW_ON_ERROR));
    }

    public static function invalidEvents(): iterable
    {
        foreach (['object' => 'account', 'livemode' => true, 'api_version' => '2025-01-01.old', 'account' => 'acct_foreign',
            'context' => 'org_foreign', 'id' => 'evt_../private', 'type' => 'invoice.paid'] as $field => $value) {
            yield $field => [array_replace(self::event(), [$field => $value])];
        }
        foreach (['object' => 'account', 'livemode' => true, 'mode' => 'setup', 'id' => 'cs_live_fixture',
            'client_reference_id' => str_repeat('f', 32), 'metadata' => ['kitsune_order' => str_repeat('a', 32)]] as $field => $value) {
            $event = self::event();
            $event['data']['object'][$field] = $value;
            yield 'session '.$field => [$event];
        }
    }

    public function test_body_and_json_depth_budgets_fail_without_payload_or_exception_chain_leaks(): void
    {
        foreach (['{private', str_repeat(' ', StripeWebhook::MAX_BODY + 1), str_repeat('[', 33).'0'.str_repeat(']', 33)] as $raw) {
            try {
                self::verify($raw);
                self::fail('Malformed signed data was accepted.');
            } catch (CheckoutRefused $failure) {
                self::assertNull($failure->getPrevious());
                self::assertStringNotContainsString('private', $failure->getMessage());
            }
        }
    }

    private static function verify(string $raw, ?string $header = null): array
    {
        return (new StripeWebhook)->verify($raw, $header ?? self::header($raw), [new Secret(self::KEY)], self::NOW);
    }

    private static function header(string $raw, int $timestamp = self::NOW): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$raw, self::KEY);
    }

    private static function event(): array
    {
        return ['object' => 'event', 'id' => 'evt_fixture', 'type' => 'checkout.session.completed', 'livemode' => false,
            'api_version' => StripeCheckout::API_VERSION, 'data' => ['object' => ['object' => 'checkout.session',
                'id' => 'cs_test_fixture', 'livemode' => false, 'mode' => 'payment', 'client_reference_id' => str_repeat('a', 32),
                'metadata' => ['kitsune_order' => str_repeat('a', 32), 'kitsune_attempt' => str_repeat('b', 32)],
                'customer_details' => ['name' => 'private café', 'email' => 'private@example.test']]]];
    }
}
