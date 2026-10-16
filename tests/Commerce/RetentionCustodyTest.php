<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Models\OrderPrivacyWork;
use Kitsune\Commerce\Models\PrivacyDelivery;
use Kitsune\Commerce\Models\PrivacyHold;
use Kitsune\Commerce\Models\PrivacyTombstone;
use Kitsune\Commerce\Models\PrivacyTransition;
use Kitsune\Commerce\Money\MinorUnits;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Payments\CheckoutReview;
use Kitsune\Commerce\Payments\FinancialState;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Commerce\Privacy\OrderPrivacy;
use Kitsune\Commerce\Privacy\PrivacyState;
use Kitsune\Commerce\Privacy\RetentionPolicies;
use Kitsune\Commerce\Privacy\RetentionPolicySnapshot;
use Kitsune\Commerce\Privacy\RetentionRefused;
use Kitsune\Core\Entitlements\EntitlementWriter;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Readers\ReaderErasure;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture;
use Kitsune\Core\Tests\Fixtures\FinancialAccessFixture;
use Kitsune\Core\Tests\Fixtures\OrderFixture;
use Kitsune\Core\Tests\Fixtures\PaidOrderFixture;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;

beforeEach(function (): void {
    EntitlementFixture::boot();
    config(AppServiceProvider::readerConfig());
    config(['hashing.bcrypt.rounds' => 4]);
    CarbonImmutable::setTestNow('2026-10-15 12:00:00 UTC');
    $this->world = ReaderFixture::world();
    DB::table('orgs')->update(['currency' => 'USD']);
    $this->org = $this->world['golfdom'];
    ReaderFixture::mode($this->org, 'sign-in');
    $this->site = $this->world['sites']['golfdom'];
    $this->site->update(['base_url' => 'https://privacy-custody.example.test']);
    app(Context::class)->setSite($this->site);
    $this->reader = ReaderFixture::reader($this->org, 'custody-reader@example.test');
    app(Context::class)->setSite($this->site);
    $this->owner = CredentialFixture::member(email: 'privacy-custody-owner@example.test');
    $this->policy = app(RetentionPolicies::class)->createAsOwner(['name', 'address.country'], 'Recorded invoice obligation',
        'Explicit bounded profile', RetentionPolicySnapshot::CONFIRMATION_DAYS, 30);
    $this->engine = app(OrderPrivacy::class);
});

afterEach(function (): void {
    AuditLog::flushEventListeners();
    CarbonImmutable::setTestNow();
    ReaderFixture::forget();
    EntitlementFixture::tearDown();
});

/** A real checked allocation with an explicit historical policy; no external transport. */
function retentionCustodyPurchase(object $test, bool $physical = false, ?int $days = 1): object
{
    if (! $physical) {
        $order = FinancialAccessFixture::purchase($test->site, (string) $test->reader->id, [['days' => $days, 'quantity' => 1]]);
    } else {
        $attempt = PaidOrderFixture::attempt($test->site, null, 1);
        $reader = (string) $test->reader->id;
        $owner = CartOwner::readerKey($reader);
        $shipping = ['id' => 'rate_retention', 'carrier' => 'Saved carrier', 'service' => 'Ground', 'amountMinor' => '500', 'currency' => 'USD',
            'shipmentId' => 'shipment_retention', 'test' => true, 'taxClass' => 'shipping', 'taxMode' => 'exclusive', 'taxRateParts' => 0,
            'net' => '500', 'tax' => '0', 'gross' => '500'];
        DB::table('commerce_orders')->where('id', $attempt->order_id)->update(['reader_id' => $reader, 'owner_key' => $owner,
            'active_cart_key' => hash('sha256', $owner."\0".'1'."\0".'0'), 'delivery' => json_encode(OrderFixture::billing(), JSON_THROW_ON_ERROR),
            'shipping' => json_encode($shipping, JSON_THROW_ON_ERROR), 'quoted_shipping_net_minor' => '500', 'quoted_total_minor' => '2600']);
        DB::table('commerce_order_lines')->where('order_id', $attempt->order_id)->update(['delivery_kind' => 'physical', 'entitlement_name' => null, 'offer_terms' => null]);
        $attempt = PaidOrderFixture::refresh($attempt);
        app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm(DB::connection(),
            DB::table('commerce_orders')->where('id', $attempt->order_id)->sole(), $attempt, PaidOrderFixture::session($attempt)));
        $order = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
    }
    $test->engine->adoptAsOwner($order->reference, $test->policy->version, 'Explicit historical adoption', true);

    return $order;
}

it('declares scoped read-only custody models and keeps raw private data out of implicit serialization', function (): void {
    foreach ([OrderPrivacyWork::class, PrivacyDelivery::class, PrivacyHold::class, PrivacyTransition::class] as $class) {
        expect((new ReflectionClass($class))->getAttributes(SiteScoped::class))->toHaveCount(1)
            ->and((new $class)->toArray())->toBe([]);
    }
    expect((new ReflectionClass(PrivacyTombstone::class))->getAttributes(OrgScoped::class))->toHaveCount(1)
        ->and((new PrivacyTombstone)->toArray())->toBe([]);
});

it('freezes activation at preparation and uses the first paid instant even when a later version is active', function (): void {
    app(RetentionPolicies::class)->activateAsOwner($this->policy->version);
    $attempt = PaidOrderFixture::attempt($this->site, null, 1);
    $order = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
    // Replace the deliberately historical marker with the actual production birth seam.
    DB::transaction(function () use ($order): void {
        DB::table(PrivacyState::TABLE)->where('order_id', $order->id)->delete();
        app(PrivacyState::class)->prepare(DB::connection(), $order);
    });
    $second = app(RetentionPolicies::class)->createAsOwner(['name'], 'Different explicit obligation', 'Prospective only', RetentionPolicySnapshot::CONFIRMATION_DAYS, 90);
    app(RetentionPolicies::class)->activateAsOwner($second->version);
    CarbonImmutable::setTestNow('2026-10-17 15:00:00 UTC');
    app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm(DB::connection(), $order, $attempt, PaidOrderFixture::session($attempt)));
    $paid = DB::table('commerce_orders')->where('id', $order->id)->sole();
    $state = app(PrivacyState::class)->checked(DB::connection(), $paid);
    expect($state['policy']->version)->toBe($this->policy->version)->and($state['deadline'])->toBe('2026-11-16 15:00:00');
    CarbonImmutable::setTestNow('2026-10-18 15:00:00 UTC');
    app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm(DB::connection(), $paid, $attempt, PaidOrderFixture::session($attempt)));
    expect(app(PrivacyState::class)->checked(DB::connection(), $paid)['deadline'])->toBe($state['deadline']);
});

it('requires historical adoption before erasing a paid account and rolls the whole account erasure back', function (): void {
    $order = FinancialAccessFixture::purchase($this->site, (string) $this->reader->id);
    app(AccessFulfillment::class)->reconcile($order->reference);
    expect(fn () => app(ReaderErasure::class)->erase((string) $this->reader->id))->toThrow(RuntimeException::class);
    expect(DB::table('readers')->where('id', $this->reader->id)->exists())->toBeTrue()
        ->and(DB::table('entitlements')->where('source', 'commerce.order:'.$order->id)->count())->toBe(1)
        ->and(DB::table('commerce_orders')->where('id', $order->id)->value('contact_email'))->toBe($order->contact_email);
});

it('holds a late payment after pending account erasure without stock allocation or an access award', function (): void {
    $attempt = PaidOrderFixture::attempt($this->site, '10', 1);
    $reader = (string) $this->reader->id;
    $owner = CartOwner::readerKey($reader);
    DB::table('commerce_orders')->where('id', $attempt->order_id)->update(['reader_id' => $reader, 'owner_key' => $owner,
        'active_cart_key' => hash('sha256', $owner."\0".'1'."\0".'0')]);
    $attempt = PaidOrderFixture::refresh($attempt);
    $originalHash = $attempt->order_hash;
    $order = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
    $this->engine->adoptAsOwner($order->reference, $this->policy->version, 'Explicit pending adoption', true);
    app(ReaderErasure::class)->erase($reader);
    $erased = DB::table('commerce_orders')->where('id', $order->id)->sole();
    expect($erased->billing)->toBeNull()->and($erased->contact_email)->toBeNull()
        ->and(app(PrivacyState::class)->checked(DB::connection(), $erased)['deadline'])->toBeNull();
    app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm(DB::connection(), $erased, $attempt, PaidOrderFixture::session($attempt)));
    $paid = DB::table('commerce_orders')->where('id', $order->id)->sole();
    $state = app(PrivacyState::class)->checked(DB::connection(), $paid);
    expect($state['commitments']['financial_version'])->toBe(2)
        ->and($state['commitments']['checkout_hash'])->toBe($originalHash)
        ->and(DB::table(PaidOrders::RECEIPTS)->where('order_id', $paid->id)->value('category'))->toBe('privacy_erased')
        ->and(DB::table(PaidOrders::ALLOCATIONS)->where('order_id', $paid->id)->count())->toBe(0)
        ->and(MinorUnits::integer(DB::table('commerce_sellables')->value('stock')))->toBe('10')
        ->and(DB::table('entitlements')->where('source', 'commerce.order:'.$paid->id)->count())->toBe(0)
        ->and(app(AccessFulfillment::class)->reconcile($paid->reference)['state'])->toBe('suppressed');
});

it('retires a minimized never-paid order only after authoritative terminal Session custody', function (): void {
    $attempt = PaidOrderFixture::attempt($this->site, null, 1);
    $reader = (string) $this->reader->id;
    DB::table('commerce_orders')->where('id', $attempt->order_id)->update(['reader_id' => $reader, 'owner_key' => CartOwner::readerKey($reader),
        'active_cart_key' => hash('sha256', CartOwner::readerKey($reader)."\0".'1'."\0".'0')]);
    $attempt = PaidOrderFixture::refresh($attempt);
    $order = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
    $this->engine->adoptAsOwner($order->reference, $this->policy->version, 'Pending policy adoption', true);
    app(ReaderErasure::class)->erase($reader);
    expect($this->engine->maintainOrder($order->reference)['held'])->toBe(1);
    // This exact fixture mutation mirrors a freshly verified original Session expiry, never a local timer.
    DB::table(CheckoutAttempts::TABLE)->where('id', $attempt->id)->update(['state' => 'expired', 'active_order_id' => null]);
    expect($this->engine->maintainOrder($order->reference)['purged'])->toBe(1)
        ->and(DB::table('commerce_orders')->where('id', $order->id)->exists())->toBeFalse()
        ->and(app(PrivacyState::class)->replayIdentity(DB::connection(), $this->org->id, 'test', 'session', $attempt->session_id, $attempt->account_id)['purged'])->toBeTrue();
});

it('keeps only essential private delivery until explicit completion even after its review reminder passes', function (bool $beforeErasure): void {
    $order = retentionCustodyPurchase($this, true);
    if ($beforeErasure) {
        $this->engine->completeDeliveryAsOwner($order->reference, 'Delivered before erasure', true);
    }
    app(ReaderErasure::class)->erase((string) $this->reader->id);
    $current = DB::table('commerce_orders')->where('id', $order->id)->sole();
    expect($current->contact_email)->toBeNull()->and($current->delivery)->toBeNull();
    CarbonImmutable::setTestNow('2026-12-15 12:00:00 UTC');
    $delivery = $this->engine->deliveryAsOwner($order->reference);
    if ($beforeErasure) {
        expect($delivery)->toBeNull()->and(DB::table(PrivacyState::DELIVERIES)->where('order_id', $order->id)->value('payload'))->toBeNull();
    } else {
        expect($delivery['contact_email'])->toBe('historical-buyer@example.test')->and($delivery['delivery'])->toBe(OrderFixture::billing());
        expect($this->engine->maintainOrder($order->reference)['held'])->toBe(1);
        $this->engine->completeDeliveryAsOwner($order->reference, 'Exact shipment delivered', true);
        expect($this->engine->deliveryAsOwner($order->reference))->toBeNull();
    }
})->with([true, false]);

it('records explicit policy replacements and never restores a personal field removed by earlier minimization', function (): void {
    $order = retentionCustodyPurchase($this);
    app(ReaderErasure::class)->erase((string) $this->reader->id);
    $short = app(RetentionPolicies::class)->createAsOwner(['name'], 'Changed invoice obligation', 'Narrower profile', RetentionPolicySnapshot::CONFIRMATION_DAYS, 10);
    $this->engine->adoptAsOwner($order->reference, $short->version, 'Owner reviewed the shorter period', true);
    $long = app(RetentionPolicies::class)->createAsOwner(['name', 'address.line1', 'address.country'], 'New invoice obligation', 'Longer profile', RetentionPolicySnapshot::CONFIRMATION_DAYS, 90);
    $this->engine->adoptAsOwner($order->reference, $long->version, 'Owner reviewed the longer period', true);
    $current = DB::table('commerce_orders')->where('id', $order->id)->sole();
    expect(app(PrivacyState::class)->billing(DB::connection(), $current))->toBe(['name' => 'Historical Buyer'])
        ->and(app(PrivacyState::class)->checked(DB::connection(), $current)['deadline'])->toBe('2027-01-13 12:00:00');
    $receipt = DB::table(PrivacyState::TRANSITIONS)->where('order_id', $order->id)->where('action', 'policy_adopted')->orderByDesc('id')->first();
    expect($receipt->reason)->toBe('Owner reviewed the longer period')->and($receipt->policy_before_hash)->toBe($short->configHash)
        ->and($receipt->policy_after_hash)->toBe($long->configHash);
});

it('keeps manual and financial holds independent and does not auto-release a past review date', function (): void {
    $order = retentionCustodyPurchase($this);
    $hold = $this->engine->holdAsOwner($order->reference, 'Specific legal case', '2026-10-16', true);
    CarbonImmutable::setTestNow('2026-12-15 12:00:00 UTC');
    expect($this->engine->maintainOrder($order->reference))->toBe(['examined' => 1, 'minimized' => 0, 'purged' => 0, 'held' => 1])
        ->and($this->engine->holdsAsOwner($order->reference)[0]['reference'])->toBe($hold);
    $this->engine->releaseHoldAsOwner($order->reference, $hold, 'Legal case explicitly released', true);
    expect($this->engine->maintainOrder($order->reference))->toBe(['examined' => 1, 'minimized' => 1, 'purged' => 0, 'held' => 1]);
    expect(DB::table('commerce_orders')->where('id', $order->id)->exists())->toBeTrue();
});

it('purges settled expired custody with opaque replay guards and preserves other Site records', function (): void {
    $order = retentionCustodyPurchase($this);
    FinancialAccessFixture::observe($order);
    $sibling = Site::create(['handle' => 'privacy-sibling', 'slug' => 'privacy-sibling', 'name' => 'Sibling', 'base_url' => 'https://privacy-sibling.example.test']);
    $other = OrderFixture::plant($sibling);
    $attempt = DB::table(CheckoutAttempts::TABLE)->where('order_id', $order->id)->sole();
    $charge = DB::table(PaidOrders::RECEIPTS)->where('order_id', $order->id)->value('charge_id');
    app(ReaderErasure::class)->erase((string) $this->reader->id);
    CarbonImmutable::setTestNow('2026-12-15 12:00:00 UTC');
    expect($this->engine->maintainOrder($order->reference, true))->toBe(['examined' => 1, 'minimized' => 0, 'purged' => 1, 'held' => 0])
        ->and(DB::table('commerce_orders')->where('id', $order->id)->exists())->toBeTrue();
    expect($this->engine->maintainOrder($order->reference)['purged'])->toBe(1)
        ->and(DB::table('commerce_orders')->where('id', $order->id)->exists())->toBeFalse()
        ->and((array) DB::table('commerce_orders')->where('id', $other->id)->sole())->toBe((array) $other);
    foreach ([['charge', $charge], ['order', $order->reference], ['attempt', $attempt->reference], ['session', $attempt->session_id], ['source', 'commerce.order:'.$order->id]] as [$kind, $value]) {
        expect(app(PrivacyState::class)->replayIdentity(DB::connection(), $this->org->id, 'test', $kind, $value, $attempt->account_id)['purged'])->toBeTrue()
            ->and(app(PrivacyState::class)->replayIdentity(DB::connection(), $this->org->id, 'test', $kind, $value, 'acct_Different'))->toBeNull();
    }
    expect(json_encode(DB::table(PrivacyState::TOMBSTONES)->get()))->not->toContain('historical-buyer@example.test', 'Historical Buyer', $attempt->session_id, $charge);
});

it('does not bless an altered candidate consent or heal a missing required negative guard', function (): void {
    $order = retentionCustodyPurchase($this);
    app(ReaderErasure::class)->erase((string) $this->reader->id);
    $current = DB::table('commerce_orders')->where('id', $order->id)->sole();
    $record = app(OrderReaderData::class)->record(DB::connection(), $current);
    expect(CheckoutReview::orderHash($record))->toBe(DB::table(CheckoutAttempts::TABLE)->where('order_id', $order->id)->value('order_hash'));
    expect(fn () => CheckoutReview::orderHash([...$record, 'quoted_total_minor' => '1']))->toThrow(RetentionRefused::class);
    DB::table(PrivacyState::TOMBSTONES)->where('org_id', $this->org->id)->where('kind', 'charge')->delete();
    expect(fn () => app(PrivacyState::class)->checked(DB::connection(), $current))->toThrow(RetentionRefused::class)
        ->and(fn () => $this->engine->maintainOrder($order->reference))->toThrow(RetentionRefused::class);
    expect(DB::table(PrivacyState::TOMBSTONES)->where('kind', 'charge')->count())->toBe(0);
});

it('rejects money or personal field contamination after redaction without resealing modified history', function (array $changes): void {
    $order = retentionCustodyPurchase($this);
    app(ReaderErasure::class)->erase((string) $this->reader->id);
    DB::table('commerce_orders')->where('id', $order->id)->update($changes);
    expect(fn () => app(PrivacyState::class)->checked(DB::connection(), DB::table('commerce_orders')->where('id', $order->id)->sole()))->toThrow(RetentionRefused::class);
})->with([[['quoted_total_minor' => '2200']], [['contact_email' => 'restored@example.test']], [['reader_id' => 'old-reader']]]);

it('advances bounded apply batches beyond earlier held orders without changing preview progress', function (): void {
    OrderFixture::plant($this->site);
    $order = retentionCustodyPurchase($this, days: null);
    CarbonImmutable::setTestNow('2026-12-15 12:00:00 UTC');
    $before = DB::table(PrivacyState::TABLE)->pluck('maintenance_count')->all();
    $this->engine->sweep(1, true);
    expect(DB::table(PrivacyState::TABLE)->pluck('maintenance_count')->all())->toBe($before);
    expect($this->engine->sweep(1)['held'])->toBe(1)->and($this->engine->sweep(1)['minimized'])->toBe(1)
        ->and(DB::table('commerce_orders')->where('id', $order->id)->value('billing'))->toBeNull();
});

it('rolls all minimization and original source erasure back when its final audit callback poisons a guard', function (): void {
    $order = retentionCustodyPurchase($this);
    app(AccessFulfillment::class)->reconcile($order->reference);
    AuditLog::created(function (AuditLog $audit): void {
        if ($audit->action === 'commerce.order_privacy_subject_erased') {
            DB::table(PrivacyState::TOMBSTONES)->where('kind', 'charge')->update(['digest' => str_repeat('f', 64)]);
        }
    });
    expect(fn () => app(ReaderErasure::class)->erase((string) $this->reader->id))->toThrow(RuntimeException::class)
        ->and(DB::table('readers')->where('id', $this->reader->id)->exists())->toBeTrue()
        ->and(DB::table('commerce_orders')->where('id', $order->id)->value('contact_email'))->toBe($order->contact_email)
        ->and(DB::table(PrivacyState::TOMBSTONES)->count())->toBe(0)
        ->and(DB::table('entitlements')->where('source', 'commerce.order:'.$order->id)->count())->toBe(1);
});

it('verifies the exact minimized graphs after a later host account callback rather than rediscovering ownership', function (): void {
    $order = retentionCustodyPurchase($this);
    DB::transaction(function () use ($order): void {
        app(OrderReaderData::class)->erase(DB::connection(), $this->org->id, (string) $this->reader->id);
        $verify = $this->engine->verificationForSubject(DB::connection(), $this->org->id, (string) $this->reader->id);
        $verify();
        DB::table('commerce_orders')->where('id', $order->id)->update(['contact_email' => 'observer-restored@example.test']);
        expect($verify)->toThrow(RetentionRefused::class);
    });
});

it('resolves consent by the exact recorded Site when sibling orders share a reference', function (): void {
    $order = retentionCustodyPurchase($this);
    app(ReaderErasure::class)->erase((string) $this->reader->id);
    $sibling = Site::create(['handle' => 'same-reference', 'slug' => 'same-reference', 'name' => 'Sibling', 'base_url' => 'https://same-reference.example.test']);
    $other = OrderFixture::plant($sibling, overrides: ['reference' => $order->reference]);
    $record = app(OrderReaderData::class)->record(DB::connection(), $other);
    expect(CheckoutReview::orderHash($record))->toBe(CheckoutReview::unmodifiedOrderHash($record))
        ->and(CheckoutReview::orderHash($record))->not->toBe(DB::table(CheckoutAttempts::TABLE)->where('order_id', $order->id)->value('order_hash'));
});

it('minimizes account PII during an open dispute while keeping only the restricted financial case', function (): void {
    $order = retentionCustodyPurchase($this);
    FinancialAccessFixture::observe($order, disputes: [FinancialAccessFixture::dispute($order, 'under_review')]);
    app(ReaderErasure::class)->erase((string) $this->reader->id);
    CarbonImmutable::setTestNow('2026-12-15 12:00:00 UTC');
    expect($this->engine->maintainOrder($order->reference))->toBe(['examined' => 1, 'minimized' => 1, 'purged' => 0, 'held' => 1]);
    $current = DB::table('commerce_orders')->where('id', $order->id)->sole();
    expect($current->reader_id)->toBeNull()->and($current->contact_email)->toBeNull()->and($current->billing)->toBeNull()
        ->and(app(FinancialState::class)->checked(DB::connection(), $current)['observations'][0]->state)->toBe('under_review');
});

it('retires only the ended order source when a fully refunded permanent contribution no longer survives', function (): void {
    $order = retentionCustodyPurchase($this, days: null);
    app(AccessFulfillment::class)->reconcile($order->reference);
    app(EntitlementWriter::class)->grant((string) $this->reader->id, 'unrelated.pass', 'comp.manual:retain', null);
    $line = DB::table('commerce_order_lines')->where('order_id', $order->id)->value('id');
    $refund = app(FinancialState::class)->requestAsOwner($order->reference, [$line => 1], 'Return exact original pass', str_repeat('a', 32));
    FinancialAccessFixture::observe($order, [FinancialAccessFixture::refund($order, $refund, 'succeeded')]);
    CarbonImmutable::setTestNow('2026-12-15 12:00:00 UTC');
    expect($this->engine->maintainOrder($order->reference)['purged'])->toBe(1)
        ->and(DB::table('entitlements')->where('source', 'commerce.order:'.$order->id)->count())->toBe(0)
        ->and(DB::table('entitlements')->where('source', 'comp.manual:retain')->count())->toBe(1)
        ->and(DB::table('readers')->where('id', $this->reader->id)->exists())->toBeTrue();
});

it('never retires a minimized graph when its producer-known subject marker disappears before final witness collection', function (): void {
    $order = retentionCustodyPurchase($this);
    DB::transaction(function () use ($order): void {
        $engine = app(OrderPrivacy::class);
        $engine->eraseForSubject(DB::connection(), $order, (string) $this->reader->id);
        DB::table(PrivacyState::TABLE)->where('order_id', $order->id)->update(['subject_key' => null]);
        expect(fn () => $engine->verificationForSubject(DB::connection(), $this->org->id, (string) $this->reader->id))->toThrow(RetentionRefused::class);
    });
});

it('retires expired order-source rows after their promise ends without touching a living manual source', function (): void {
    $order = retentionCustodyPurchase($this, days: 1);
    app(AccessFulfillment::class)->reconcile($order->reference);
    app(EntitlementWriter::class)->grant((string) $this->reader->id, 'unrelated.pass', 'comp.manual:retain', null);
    FinancialAccessFixture::observe($order);
    CarbonImmutable::setTestNow('2026-12-15 12:00:00 UTC');
    expect($this->engine->maintainOrder($order->reference))->toBe(['examined' => 1, 'minimized' => 0, 'purged' => 1, 'held' => 0])
        ->and(DB::table('entitlements')->where('source', 'commerce.order:'.$order->id)->count())->toBe(0)
        ->and(DB::table('entitlements')->where('source', 'comp.manual:retain')->whereNull('revoked_at')->count())->toBe(1)
        ->and(DB::table('readers')->where('id', $this->reader->id)->exists())->toBeTrue();
});

it('retains a minimized invoice when a stale still-active source outlives a fully refunded contribution', function (): void {
    $order = retentionCustodyPurchase($this, days: null);
    app(AccessFulfillment::class)->reconcile($order->reference);
    $line = DB::table('commerce_order_lines')->where('order_id', $order->id)->value('id');
    $refund = app(FinancialState::class)->requestAsOwner($order->reference, [$line => 1], 'Refund the original pass', str_repeat('b', 32));
    FinancialAccessFixture::observe($order, [FinancialAccessFixture::refund($order, $refund, 'succeeded')]);
    // Simulate a host reactivating a stale row after the checked refund suspension. Privacy cannot delete it as inactive.
    DB::table('entitlements')->where('source', 'commerce.order:'.$order->id)->update(['revoked_at' => null]);
    CarbonImmutable::setTestNow('2026-12-15 12:00:00 UTC');
    expect($this->engine->maintainOrder($order->reference))->toBe(['examined' => 1, 'minimized' => 1, 'purged' => 0, 'held' => 1])
        ->and(DB::table('commerce_orders')->where('id', $order->id)->value('billing'))->toBeNull()
        ->and(DB::table('entitlements')->where('source', 'commerce.order:'.$order->id)->whereNull('revoked_at')->count())->toBe(1);
});

it('keeps privacy models and owner actions behind both the Site and Org attacker boundaries', function (): void {
    $order = retentionCustodyPurchase($this);
    $this->engine->holdAsOwner($order->reference, 'Recorded exact order hold', '2026-10-16', true);
    $sibling = Site::create(['handle' => 'privacy-attacker', 'slug' => 'privacy-attacker', 'name' => 'Sibling', 'base_url' => 'https://privacy-attacker.example.test']);
    foreach ([$sibling, $this->world['sites']['rival']] as $attacker) {
        app(Context::class)->setSite($attacker);
        foreach ([OrderPrivacyWork::class, PrivacyHold::class, PrivacyDelivery::class, PrivacyTransition::class] as $model) {
            expect($model::query()->count())->toBe(0);
        }
        expect(fn () => $this->engine->holdAsOwner($order->reference, 'Attacker cannot change hold', '2026-10-16', true))->toThrow(RetentionRefused::class);
    }
    app(Context::class)->setSite($this->site);
    expect(DB::table(PrivacyState::HOLDS)->where('order_id', $order->id)->count())->toBe(1);
});

it('rejects an erased original source transplanted to a foreign Org after the final witness was captured', function (): void {
    $order = retentionCustodyPurchase($this);
    app(AccessFulfillment::class)->reconcile($order->reference);
    $source = (array) DB::table('entitlements')->where('source', 'commerce.order:'.$order->id)->sole();
    app(ReaderErasure::class)->erase((string) $this->reader->id);
    DB::transaction(function () use ($source): void {
        $verify = $this->engine->verificationForSubject(DB::connection(), $this->org->id, (string) $this->reader->id);
        $verify();
        DB::table('entitlements')->insert([...$source, 'org_id' => $this->world['rival']->id, 'site_id' => $this->world['sites']['rival']->id]);
        expect($verify)->toThrow(RetentionRefused::class);
    });
});

it('rolls an owner privacy intent back if its audit callback changes the installed module receipt', function (array $changes): void {
    $order = retentionCustodyPurchase($this);
    $module = (array) DB::table('modules')->where('handle', 'kitsune/commerce')->sole();
    AuditLog::created(function (AuditLog $audit) use ($changes): void {
        if ($audit->action === 'commerce.order_privacy_hold_added') {
            DB::table('modules')->where('handle', 'kitsune/commerce')->update($changes);
        }
    });
    expect(fn () => $this->engine->holdAsOwner($order->reference, 'Specific bounded case', '2026-10-16', true))->toThrow(RetentionRefused::class)
        ->and(DB::table(PrivacyState::HOLDS)->where('order_id', $order->id)->count())->toBe(0)
        ->and((array) DB::table('modules')->where('handle', 'kitsune/commerce')->sole())->toBe($module);
})->with([[['is_enabled' => false]], [['version' => 'callback-mutated']]]);
