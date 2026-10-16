<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Payments\CheckoutRefused;
use Kitsune\Commerce\Payments\FinancialSignals;
use Kitsune\Commerce\Payments\FinancialState;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Commerce\Payments\StripeCheckout;
use Kitsune\Commerce\Payments\StripeEventInbox;
use Kitsune\Commerce\Payments\StripeWebhook;
use Kitsune\Commerce\Privacy\PrivacyState;
use Kitsune\Core\Credentials\Secret;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture;
use Kitsune\Core\Tests\Fixtures\FinancialAccessFixture;
use Kitsune\Core\Tests\Fixtures\PaidOrderFixture;
use Kitsune\Core\Tests\Fixtures\StripeInboxFixture;

beforeEach(function (): void {
    EntitlementFixture::boot();
    CarbonImmutable::setTestNow('2026-10-15 12:00:00 UTC');
    StripeInboxFixture::enable();
    $this->org = Org::create(['slug' => 'privacy-ingress', 'name' => 'Ingress', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'selling', 'slug' => 'privacy-ingress', 'name' => 'Selling', 'base_url' => 'https://privacy-ingress.example.test']);
    app(Context::class)->setSite($this->site);
    $this->attempt = StripeInboxFixture::attempt($this->site);
    $this->order = DB::table('commerce_orders')->where('id', $this->attempt->order_id)->sole();
    $this->inbox = app(StripeEventInbox::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    EntitlementFixture::tearDown();
});

/** A strict opaque replay receipt, independent of a still-present or removed order graph. */
function retentionIngressGuard(int $org, string $kind, string $value, string $state = 'purged', string $mode = 'test', string $account = 'acct_InboxFixture'): void
{
    $commitments = ['version' => 1, 'snapshot_hash' => hash('sha256', 'original snapshot'), 'checkout_hash' => null,
        'financial_hash' => null, 'financial_version' => 1, 'access_manifest_hash' => null, 'claim_hash' => null, 'adjustment_hash' => null];
    DB::table(PrivacyState::TOMBSTONES)->insert(['org_id' => $org, 'mode' => $mode, 'kind' => $kind,
        'digest' => PrivacyState::guardDigest($kind, $value), 'account_digest' => PrivacyState::guardDigest('account', $account),
        'custody_digest' => hash('sha256', 'verified original ingress fixture custody'),
        'subject_erased_at' => $state === 'subject_erased' ? PrivacyState::now() : null,
        'invoice_expired_at' => $state === 'invoice_expired' ? PrivacyState::now() : null,
        'purged_at' => $state === 'purged' ? PrivacyState::now() : null,
        'commitments' => json_encode($commitments, JSON_THROW_ON_ERROR), 'created_at' => PrivacyState::now(), 'updated_at' => PrivacyState::now()]);
}

/** Exact signature verification still precedes privacy suppression.
 * @return array{0:array<string,mixed>,1:string}
 */
function retentionIngressVerified(string $id, string $type, array $object): array
{
    $raw = json_encode(['object' => 'event', 'id' => $id, 'type' => $type, 'api_version' => StripeCheckout::API_VERSION,
        'livemode' => false, 'data' => ['object' => $object], 'private_customer_email' => 'must-not-return@example.test'], JSON_THROW_ON_ERROR);
    $now = CarbonImmutable::now('UTC')->timestamp;
    $key = 'whsec_private_retention_ingress_fixture';
    $header = 't='.$now.',v1='.hash_hmac('sha256', $now.'.'.$raw, $key);

    return [app(StripeWebhook::class)->verify($raw, $header, [new Secret($key)], $now), $raw];
}

function retentionIngressSession(object $attempt, object $order): array
{
    return ['object' => 'checkout.session', 'id' => $attempt->session_id, 'mode' => 'payment', 'livemode' => false,
        'client_reference_id' => $order->reference, 'metadata' => ['kitsune_order' => $order->reference, 'kitsune_attempt' => $attempt->reference]];
}

it('suppresses fresh verified event IDs for a purged charge before creating either financial or inbox work', function (): void {
    $charge = 'ch_ErasedOriginal';
    retentionIngressGuard($this->org->id, 'charge', $charge);
    $before = (array) $this->order;
    foreach (['evt_NewAfterPurgeA', 'evt_NewAfterPurgeB'] as $id) {
        [$event, $raw] = retentionIngressVerified($id, 'charge.refunded', ['object' => 'charge', 'id' => $charge, 'livemode' => false]);
        expect($this->inbox->capture($this->org->id, $event, $raw, CarbonImmutable::now('UTC')))->toBe(['id' => 0, 'duplicate' => false, 'state' => 'erased']);
        expect(DB::transaction(fn () => app(FinancialSignals::class)->capture(DB::connection(), $this->org->id, $event, $raw, CarbonImmutable::now('UTC'))))
            ->toBe(['id' => 0, 'duplicate' => false, 'state' => 'erased']);
    }
    expect(DB::table(FinancialSignals::TABLE)->count())->toBe(0)->and(DB::table(StripeEventInbox::TABLE)->count())->toBe(0)
        ->and(DB::table('entitlements')->count())->toBe(0)
        ->and((array) DB::table('commerce_orders')->where('id', $this->order->id)->sole())->toBe($before);
});

it('quarantines a purged provider Session without reconstructing a missing routing graph', function (): void {
    retentionIngressGuard($this->org->id, 'session', $this->attempt->session_id);
    DB::table(StripeEventInbox::ROUTING)->delete();
    [$event, $raw] = retentionIngressVerified('evt_NewPurgedCheckout', 'checkout.session.completed', retentionIngressSession($this->attempt, $this->order));
    expect($this->inbox->capture($this->org->id, $event, $raw, CarbonImmutable::now('UTC')))->toBe(['id' => 0, 'duplicate' => false, 'state' => 'erased']);
    expect(DB::table(StripeEventInbox::TABLE)->count())->toBe(0)->and(DB::table(FinancialSignals::TABLE)->count())->toBe(0)
        ->and(DB::table(StripeEventInbox::ROUTING)->count())->toBe(0);
});

it('does not acknowledge an unknown checkout as erased from a local reference match alone', function (string $kind): void {
    $value = match ($kind) {
        'order' => $this->order->reference, 'attempt' => $this->attempt->reference,
    };
    retentionIngressGuard($this->org->id, $kind, $value);
    DB::table(StripeEventInbox::ROUTING)->delete();
    [$event, $raw] = retentionIngressVerified('evt_NewPurgedCheckout', 'checkout.session.completed', retentionIngressSession($this->attempt, $this->order));
    expect(fn () => $this->inbox->capture($this->org->id, $event, $raw, CarbonImmutable::now('UTC')))->toThrow(CheckoutRefused::class);
    expect(DB::table(StripeEventInbox::TABLE)->count())->toBe(0)->and(DB::table(FinancialSignals::TABLE)->count())->toBe(0);
})->with(['order', 'attempt']);

it('does not retire a sibling Site checkout whose order reference equals a purged local reference', function (): void {
    retentionIngressGuard($this->org->id, 'order', $this->order->reference);
    $sibling = Site::create(['handle' => 'sibling', 'slug' => 'privacy-ingress-sibling', 'name' => 'Sibling', 'base_url' => 'https://sibling-privacy.example.test']);
    $attempt = StripeInboxFixture::attempt($sibling);
    $oldReference = DB::table('commerce_orders')->where('id', $attempt->order_id)->value('reference');
    $payload = CheckoutAttempts::request($attempt);
    $payload['client_reference_id'] = $this->order->reference;
    $payload['metadata']['kitsune_order'] = $this->order->reference;
    foreach (['success_url', 'cancel_url'] as $key) {
        $payload[$key] = str_replace($oldReference, $this->order->reference, $payload[$key]);
    }
    DB::table(CheckoutAttempts::TABLE)->where('id', $attempt->id)->update(['request_payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'request_hash' => CheckoutAttempts::digest($payload)]);
    $attempt = DB::table(CheckoutAttempts::TABLE)->where('id', $attempt->id)->sole();
    DB::table('commerce_orders')->where('id', $attempt->order_id)->update(['reference' => $this->order->reference]);
    DB::table(PrivacyState::TABLE)->where('order_id', $attempt->order_id)->update(['order_reference' => $this->order->reference]);
    DB::table(FinancialState::WORK)->where('order_id', $attempt->order_id)->update(['order_reference' => $this->order->reference]);
    DB::table(StripeEventInbox::ROUTING)->where('attempt_id', $attempt->id)->update(['order_reference' => $this->order->reference]);
    $attempt = PaidOrderFixture::refresh($attempt);
    $order = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
    [$event, $raw] = retentionIngressVerified('evt_LegitimateSibling', 'checkout.session.completed', retentionIngressSession($attempt, $order));
    $receipt = $this->inbox->capture($this->org->id, $event, $raw, CarbonImmutable::now('UTC'));
    expect($receipt['state'])->toBe('pending')->and($receipt['id'])->toBeGreaterThan(0)
        ->and((int) DB::table(StripeEventInbox::TABLE)->sole()->order_id)->toBe($order->id)
        ->and((int) DB::table(StripeEventInbox::TABLE)->sole()->site_id)->toBe($sibling->id);
});

it('keeps fresh and duplicate minimized receipts rawless without losing exact dedup proof', function (string $state): void {
    retentionIngressGuard($this->org->id, 'order', $this->order->reference, $state);
    [$event, $raw] = retentionIngressVerified('evt_NewMinimized', 'checkout.session.completed', retentionIngressSession($this->attempt, $this->order));
    $receipt = $this->inbox->capture($this->org->id, $event, $raw, CarbonImmutable::now('UTC'));
    $row = DB::table(StripeEventInbox::TABLE)->sole();
    expect($receipt['state'])->toBe('pending')->and($row->raw_payload)->toBeNull()->and($row->payload_hash)->toBe(hash('sha256', $raw));
    DB::table(StripeEventInbox::TABLE)->where('id', $row->id)->update(['raw_payload' => $raw]);
    expect($this->inbox->capture($this->org->id, $event, $raw, CarbonImmutable::now('UTC')))->toBe([...$receipt, 'duplicate' => true])
        ->and(DB::table(StripeEventInbox::TABLE)->value('raw_payload'))->toBeNull()
        ->and(fn () => $this->inbox->capture($this->org->id, $event, $raw.' ', CarbonImmutable::now('UTC')))->toThrow(CheckoutRefused::class);
})->with(['subject_erased', 'invoice_expired']);

it('turns an older pending duplicate into a non-replayable erased envelope without renewing it', function (): void {
    [$event, $raw] = retentionIngressVerified('evt_OlderPending', 'checkout.session.completed', retentionIngressSession($this->attempt, $this->order));
    $first = $this->inbox->capture($this->org->id, $event, $raw, CarbonImmutable::now('UTC'));
    retentionIngressGuard($this->org->id, 'session', $this->attempt->session_id);
    $again = $this->inbox->capture($this->org->id, $event, $raw, CarbonImmutable::now('UTC'));
    expect($again)->toBe(['id' => $first['id'], 'duplicate' => true, 'state' => 'erased']);
    $row = (array) DB::table(StripeEventInbox::TABLE)->sole();
    foreach (['raw_payload', 'order_id', 'site_id', 'attempt_id', 'order_reference', 'attempt_reference', 'session_id'] as $column) {
        expect($row[$column])->toBeNull();
    }
    expect($this->inbox->capture($this->org->id, $event, $raw, CarbonImmutable::now('UTC')))->toBe($again)
        ->and((array) DB::table(StripeEventInbox::TABLE)->sole())->toBe($row);
});

it('does not use a different Org or mode tombstone as authority to suppress an unrelated financial signal', function (string $boundary): void {
    $charge = 'ch_SameOpaqueValue';
    $foreign = Org::create(['slug' => 'other-privacy-ingress', 'name' => 'Other']);
    retentionIngressGuard($boundary === 'Org' ? $foreign->id : $this->org->id, 'charge', $charge, mode: $boundary === 'mode' ? 'live' : 'test');
    [$event, $raw] = retentionIngressVerified('evt_UnrelatedCharge', 'charge.refunded', ['object' => 'charge', 'id' => $charge, 'livemode' => false]);
    expect($this->inbox->capture($this->org->id, $event, $raw, CarbonImmutable::now('UTC'))['state'])->toBe('unresolved')
        ->and(DB::table(FinancialSignals::TABLE)->count())->toBe(1)->and(DB::table(StripeEventInbox::TABLE)->count())->toBe(0);
})->with(['Org', 'mode']);

it('uses an explicitly verified account only to narrow suppression and never guesses one at ingress', function (): void {
    $charge = 'ch_AccountBoundary';
    retentionIngressGuard($this->org->id, 'charge', $charge, account: 'acct_ErasedOriginal');
    [$event, $raw] = retentionIngressVerified('evt_IndependentAccount', 'charge.refunded', ['object' => 'charge', 'id' => $charge, 'livemode' => false]);
    $unknown = $this->inbox->capture($this->org->id, $event, $raw, CarbonImmutable::now('UTC'));
    expect($unknown['state'])->toBe('erased')->and(DB::table(FinancialSignals::TABLE)->count())->toBe(0);
    $known = DB::transaction(fn () => app(FinancialSignals::class)->capture(DB::connection(), $this->org->id, $event, $raw, CarbonImmutable::now('UTC'), 'acct_Independent'));
    expect($known['state'])->toBe('unresolved')->and(DB::table(FinancialSignals::TABLE)->count())->toBe(1)
        ->and(DB::table(FinancialSignals::TABLE)->sole()->payload_hash)->toBe(hash('sha256', $raw));
});

it('proves an existing other-account Charge before considering the purged unknown-account match', function (): void {
    $charge = 'ch_EqualAcrossAccounts';
    retentionIngressGuard($this->org->id, 'charge', $charge, account: 'acct_ErasedOriginal');
    $order = FinancialAccessFixture::purchase($this->site, null, payment: ['chargeId' => $charge]);
    $payment = DB::table(PaidOrders::RECEIPTS)->where('order_id', $order->id)->sole();
    expect($payment->account_id)->not->toBe('acct_ErasedOriginal');
    [$event, $raw] = retentionIngressVerified('evt_ExistingIndependentAccount', 'charge.refunded', ['object' => 'charge', 'id' => $charge, 'livemode' => false]);
    $receipt = $this->inbox->capture($this->org->id, $event, $raw, CarbonImmutable::now('UTC'));
    expect($receipt['state'])->toBe('pending')->and($receipt['id'])->toBeGreaterThan(0)
        ->and(DB::table(FinancialSignals::TABLE)->count())->toBe(1)
        ->and(DB::table(StripeEventInbox::TABLE)->sole()->account_id)->toBe($payment->account_id)
        ->and($this->inbox->capture($this->org->id, $event, $raw, CarbonImmutable::now('UTC')))->toBe([...$receipt, 'duplicate' => true]);
});

it('refuses malformed retained replay proof before accepting any new personal bytes', function (): void {
    retentionIngressGuard($this->org->id, 'order', $this->order->reference);
    DB::table(PrivacyState::TOMBSTONES)->update(['commitments' => '{}']);
    [$event, $raw] = retentionIngressVerified('evt_DamagedPrivacyProof', 'checkout.session.completed', retentionIngressSession($this->attempt, $this->order));
    expect(fn () => $this->inbox->capture($this->org->id, $event, $raw, CarbonImmutable::now('UTC')))->toThrow(CheckoutRefused::class)
        ->and(DB::table(StripeEventInbox::TABLE)->count())->toBe(0)->and(DB::table(FinancialSignals::TABLE)->count())->toBe(0);
});
