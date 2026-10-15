<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Payments\CheckoutRefused;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Commerce\Payments\RefundRecovery;
use Kitsune\Commerce\Payments\StripeCheckout;
use Kitsune\Commerce\Payments\StripeEventInbox;
use Kitsune\Commerce\Payments\StripeWebhook;
use Kitsune\Core\Credentials\Secret;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;

require_once __DIR__.'/../Core/Fixtures/RefundRecoveryFixture.php';

function refundInboxSigned(array $seed, string $type = 'refund.updated', string $id = 'evt_RefundFixture', array $changes = []): array
{
    $event = ['id' => $id, 'object' => 'event', 'type' => $type, 'api_version' => StripeCheckout::API_VERSION, 'livemode' => false,
        'data' => ['object' => array_replace(refundRecoveryObject($seed, $seed['row']), $changes)]];
    $raw = json_encode($event, JSON_THROW_ON_ERROR);
    $timestamp = CarbonImmutable::now('UTC')->getTimestamp();
    $secret = new Secret('whsec_'.str_repeat('a', 32));
    $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$raw, $secret->reveal());
    $verified = app(StripeWebhook::class)->verify($raw, $signature, [$secret], $timestamp);

    return compact('raw', 'verified', 'signature', 'secret', 'timestamp');
}

afterEach(function (): void {
    Fx::tearDown();
    CarbonImmutable::setTestNow();
    ModuleKernel::flush();
});

it('projects the three signed refund event types without requiring invented Refund mode fields or trusting status', function (string $type): void {
    $seed = refundRecoverySeed();
    $signed = refundInboxSigned($seed, $type, changes: ['status' => 'private-untrusted-status', 'instructions_email' => 'private@example.test']);
    $request = app(PaidOrders::class)->refundRequest($seed['row']);
    expect($signed['verified'])->toBe(['id' => 'evt_RefundFixture', 'type' => $type, 'apiVersion' => StripeCheckout::API_VERSION,
        'financialChargeId' => $seed['receipt']->charge_id, 'financialSubjectId' => 're_Recovery', 'test' => true,
        'refundId' => 're_Recovery', 'orderReference' => $seed['order']->reference, 'refundReference' => $seed['row']->reference,
        'requestDigest' => $request['metadata']['kitsune_request']])
        ->and(json_encode($signed['verified'], JSON_THROW_ON_ERROR))->not->toContain('private');
})->with(['refund.created', 'refund.updated', 'refund.failed']);

it('routes a verified refund receipt to its original account Session and Site and refreshes later failure once', function (): void {
    $seed = refundRecoverySeed();
    $provider = refundRecoveryProvider($seed);
    app(RefundRecovery::class)->reconcile($seed['order']->reference, $seed['row']->reference);
    $provider->calls = [];
    $inbox = app(StripeEventInbox::class);
    $signed = refundInboxSigned($seed, 'refund.created', 'evt_RefundCreated', ['status' => 'failed']);
    $first = $inbox->capture((int) $seed['org']->id, $signed['verified'], $signed['raw'], CarbonImmutable::now('UTC'));
    expect($inbox->process($first['id']))->toBe('processed')->and(DB::table(PaidOrders::ATTEMPTS)->value('state'))->toBe('succeeded');
    $provider->status = 'failed';
    $signed = refundInboxSigned($seed, 'refund.failed', 'evt_RefundFailed', ['status' => 'succeeded']);
    $second = $inbox->capture((int) $seed['org']->id, $signed['verified'], $signed['raw'], CarbonImmutable::now('UTC'));
    expect($inbox->process($second['id']))->toBe('processed')->and(DB::table(PaidOrders::ATTEMPTS)->value('state'))->toBe('failed');
    $before = count($provider->calls);
    $duplicate = $inbox->capture((int) $seed['org']->id, $signed['verified'], $signed['raw'], CarbonImmutable::now('UTC'));
    expect($duplicate['duplicate'])->toBeTrue()->and($inbox->process($second['id']))->toBe('processed')
        ->and(count($provider->calls))->toBe($before)->and(DB::table(PaidOrders::ATTEMPTS)->count())->toBe(1);
    $receipt = DB::table(StripeEventInbox::TABLE)->where('id', $second['id'])->sole();
    expect($receipt->session_id)->toBe($seed['attempt']->session_id)->and($receipt->account_id)->toBe($seed['receipt']->account_id)
        ->and((int) $receipt->site_id)->toBe((int) $seed['site']->id)->and($receipt->refund_id)->toBe('re_Recovery');
    expect(array_unique(array_column($provider->calls, 'method')))->toBe(['GET']);
    refundRecoveryAssertOutside($provider);
});

it('uses the verified event Refund ID for GET adoption after a lost POST even when list results omit it', function (): void {
    $seed = refundRecoverySeed();
    $provider = refundRecoveryProvider($seed);
    $provider->timeout = true;
    expect(fn () => app(RefundRecovery::class)->reconcile($seed['order']->reference, $seed['row']->reference))->toThrow(CheckoutRefused::class);
    $provider->refunds = [];
    $provider->timeout = false;
    $provider->calls = [];
    $signed = refundInboxSigned($seed, 'refund.created');
    $inbox = app(StripeEventInbox::class);
    $receipt = $inbox->capture((int) $seed['org']->id, $signed['verified'], $signed['raw'], CarbonImmutable::now('UTC'));
    expect($inbox->process($receipt['id']))->toBe('processed')->and(DB::table(PaidOrders::ATTEMPTS)->value('refund_id'))->toBe('re_Recovery')
        ->and(array_column($provider->calls, 'path'))->toBe(['/v1/account', '/v1/charges/'.$seed['receipt']->charge_id, '/v1/refunds/re_Recovery']);
    refundRecoveryAssertOutside($provider);
});

it('retains known event identity through failed GET and raw pruning so ordinary reconciliation cannot POST again', function (): void {
    $seed = refundRecoverySeed();
    $provider = refundRecoveryProvider($seed);
    $provider->timeout = true;
    expect(fn () => app(RefundRecovery::class)->reconcile($seed['order']->reference, $seed['row']->reference))->toThrow(CheckoutRefused::class);
    $signed = refundInboxSigned($seed, 'refund.created');
    $inbox = app(StripeEventInbox::class);
    $receipt = $inbox->capture((int) $seed['org']->id, $signed['verified'], $signed['raw'], CarbonImmutable::now('UTC'));
    $provider->hook = static function (string $path, $request): void {
        if ($path === '/v1/refunds/re_Recovery') {
            throw new ConnectException('private GET timeout', $request);
        }
    };
    expect($inbox->process($receipt['id']))->toBe('retryable')
        ->and(DB::table(PaidOrders::ATTEMPTS)->value('refund_id'))->toBeNull();
    $provider->timeout = false;
    $provider->refunds = [];
    $provider->calls = [];
    $provider->hook = null;
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDays(8));
    expect($inbox->prune())->toBe(1)->and(DB::table(StripeEventInbox::TABLE)->value('raw_payload'))->toBeNull();
    expect(app(RefundRecovery::class)->reconcile($seed['order']->reference, $seed['row']->reference)['state'])->toBe('succeeded')
        ->and(array_column($provider->calls, 'path'))->toBe(['/v1/account', '/v1/charges/'.$seed['receipt']->charge_id, '/v1/refunds/re_Recovery'])
        ->and(DB::table(PaidOrders::ATTEMPTS)->count())->toBe(1);
    refundRecoveryAssertOutside($provider);
});

it('stops an old no-ID claim when a verified Refund identity arrives during its provider read', function (): void {
    $seed = refundRecoverySeed();
    $provider = refundRecoveryProvider($seed);
    $signed = refundInboxSigned($seed, 'refund.created');
    $provider->hook = static function (string $path) use ($seed, $signed): void {
        if ($path === '/v1/account') {
            app(StripeEventInbox::class)->capture((int) $seed['org']->id, $signed['verified'], $signed['raw'], CarbonImmutable::now('UTC'));
        }
    };
    expect(fn () => app(RefundRecovery::class)->reconcile($seed['order']->reference, $seed['row']->reference))->toThrow(CheckoutRefused::class);
    expect(array_column($provider->calls, 'path'))->toBe(['/v1/account'])
        ->and(DB::table(PaidOrders::ATTEMPTS)->value('refund_id'))->toBeNull();
    $provider->calls = [];
    $provider->hook = null;
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds(91));
    expect(app(RefundRecovery::class)->reconcile($seed['order']->reference, $seed['row']->reference)['state'])->toBe('succeeded')
        ->and(array_column($provider->calls, 'path'))->toBe(['/v1/account', '/v1/charges/'.$seed['receipt']->charge_id, '/v1/refunds/re_Recovery']);
    refundRecoveryAssertOutside($provider);
});

it('refuses unknown foreign changed-hash and conflicting Refund bindings before raw payload custody', function (string $scenario): void {
    $seed = refundRecoverySeed();
    $signed = refundInboxSigned($seed);
    $org = (int) $seed['org']->id;
    if ($scenario === 'foreign') {
        $foreign = Org::create(['name' => 'Foreign', 'slug' => 'foreign-refund', 'currency' => 'USD']);
        $org = (int) $foreign->id;
    } elseif ($scenario === 'unknown') {
        $signed['verified']['refundReference'] = str_repeat('f', 32);
    } elseif ($scenario === 'hash') {
        $signed['verified']['requestDigest'] = str_repeat('f', 64);
    } elseif ($scenario === 'order') {
        $signed['verified']['orderReference'] = str_repeat('f', 32);
    } else {
        DB::table(PaidOrders::ATTEMPTS)->update(['refund_id' => 're_Different']);
    }
    expect(fn () => app(StripeEventInbox::class)->capture($org, $signed['verified'], $signed['raw'], CarbonImmutable::now('UTC')))
        ->toThrow(CheckoutRefused::class);
    expect(DB::table(StripeEventInbox::TABLE)->count())->toBe(0)->and(DB::table(PaidOrders::ATTEMPTS)->value('state'))->toBe('owed');
})->with(['foreign', 'hash', 'order', 'id']);

it('requires the exact signed bytes and local refund metadata envelope', function (string $scenario): void {
    $seed = refundRecoverySeed();
    $signed = refundInboxSigned($seed);
    $raw = $signed['raw'];
    $header = $signed['signature'];
    if ($scenario === 'signature') {
        $raw .= ' ';
    } else {
        $event = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        match ($scenario) {
            'mode' => $event['livemode'] = true,
            'connect' => $event['account'] = 'acct_Another',
            'hash' => $event['data']['object']['metadata']['kitsune_request'] = 'invalid',
            'reference' => $event['data']['object']['metadata']['kitsune_refund'] = 'private-reference',
            'type' => $event['data']['object']['object'] = 'charge',
            'api' => $event['api_version'] = '2025-01-01.basil',
        };
        $raw = json_encode($event, JSON_THROW_ON_ERROR);
        $header = 't='.$signed['timestamp'].',v1='.hash_hmac('sha256', $signed['timestamp'].'.'.$raw, $signed['secret']->reveal());
    }
    expect(fn () => app(StripeWebhook::class)->verify($raw, $header, [$signed['secret']], $signed['timestamp']))->toThrow(CheckoutRefused::class);
})->with(['signature', 'mode', 'connect', 'hash', 'reference', 'type', 'api']);
