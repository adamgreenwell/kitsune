<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Orders\HistoricalAccess;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Orders\OrderRefused;
use Kitsune\Commerce\Payments\AccessAdjustments;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Commerce\Privacy\OrderPrivacy;
use Kitsune\Commerce\Privacy\RetentionPolicies;
use Kitsune\Commerce\Privacy\RetentionPolicySnapshot;
use Kitsune\Commerce\Privacy\RetentionRefused;
use Kitsune\Core\Readers\ReaderErasure;
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
    config(['hashing.bcrypt.rounds' => 4, 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
    CarbonImmutable::setTestNow('2026-10-15 12:00:00 UTC');
    $this->world = ReaderFixture::world();
    DB::table('orgs')->update(['currency' => 'USD']);
    $this->org = $this->world['golfdom'];
    ReaderFixture::mode($this->org, 'sign-in');
    $this->site = $this->world['sites']['golfdom'];
    $this->reader = ReaderFixture::reader($this->org, 'access-privacy@example.test');
    app(Context::class)->setSite($this->site);
    $this->site->update(['base_url' => 'https://retention-access.example.test']);
    $this->owner = CredentialFixture::member(email: 'access-retention-owner@example.test');
    $this->request = OrderFixture::request(app('session.store'));
    $this->policy = app(RetentionPolicies::class)->createAsOwner(['name'], 'Explicit invoice obligation',
        'Bounded billing profile', RetentionPolicySnapshot::CONFIRMATION_DAYS, 1);
    $attempt = PaidOrderFixture::attempt($this->site, stock: null, quantity: 1);
    $key = (string) $this->reader->id;
    $owner = CartOwner::readerKey($key);
    DB::table('commerce_orders')->where('id', $attempt->order_id)->update(['reader_id' => $key, 'owner_key' => $owner,
        'active_cart_key' => hash('sha256', $owner."\0".'1'."\0".'0')]);
    DB::table('commerce_order_lines')->where('order_id', $attempt->order_id)->update(['entitlement_name' => 'course.retention-access',
        'offer_terms' => null]);
    $attempt = PaidOrderFixture::refresh($attempt);
    app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm(DB::connection(),
        DB::table('commerce_orders')->where('id', $attempt->order_id)->sole(), $attempt, PaidOrderFixture::session($attempt)));
    $this->order = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
    app(OrderPrivacy::class)->adoptAsOwner($this->order->reference, $this->policy->version, 'Explicit historical policy adoption', true);
    $this->line = (int) DB::table('commerce_order_lines')->where('order_id', $this->order->id)->value('id');
    app(HistoricalAccess::class)->decideAsOwner($this->order->reference, $this->line, 'award',
        ['version' => 1, 'kind' => 'fixed', 'days' => 30], 0, 'Reviewed protected purchase notes', 'archive:private-review', bin2hex(random_bytes(16)), true);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    ReaderFixture::forget();
    EntitlementFixture::tearDown();
});

it('redacts private review provenance on account erasure and preserves safe financial custody', function (): void {
    app(ReaderErasure::class)->erase((string) $this->reader->id);
    $order = DB::table('commerce_orders')->where('id', $this->order->id)->sole();
    $review = DB::table(HistoricalAccess::TABLE)->sole();
    expect($review->reason)->toBeNull()->and($review->evidence)->toBeNull()->and($review->actor_id)->toBeNull()
        ->and($review->redacted_at)->not->toBeNull();
    $record = app(OrderReaderData::class)->record(DB::connection(), $order);
    expect($record['historical_access']['revision'])->toBe(1)->and($record['privacy']['subject_erased'])->toBeTrue()
        ->and(fn () => app(HistoricalAccess::class)->decideAsOwner($order->reference, $this->line, 'award',
            ['version' => 1, 'kind' => 'permanent'], 1, 'New review cannot revive erased subject', 'archive:no-revive', bin2hex(random_bytes(16)), true))->toThrow(OrderRefused::class);
    app(AccessFulfillment::class)->reconcile($order->reference);
    app(AccessAdjustments::class)->reconcile($order->reference);
    expect(DB::table('entitlements')->count())->toBe(0);
});

it('redacts review notes at invoice expiry while preserving a current awarded promise', function (): void {
    CarbonImmutable::setTestNow('2026-10-17 12:00:00 UTC');
    $result = app(OrderPrivacy::class)->maintainOrder($this->order->reference);
    $order = DB::table('commerce_orders')->where('id', $this->order->id)->sole();
    expect($result['held'])->toBe(1)->and(DB::table(HistoricalAccess::TABLE)->value('evidence'))->toBeNull()
        ->and(app(OrderReaderData::class)->record(DB::connection(), $order)['historical_access']['revision'])->toBe(1)
        ->and(DB::table('entitlements')->value('expires_at'))->toBe('2026-11-14 12:00:00');
});

it('includes checked review custody in final order purging after its promise ends', function (): void {
    CarbonImmutable::setTestNow('2026-11-20 12:00:00 UTC');
    FinancialAccessFixture::observe($this->order);
    $result = app(OrderPrivacy::class)->maintainOrder($this->order->reference);
    expect($result['purged'])->toBe(1)->and(DB::table('commerce_orders')->count())->toBe(0)
        ->and(DB::table(HistoricalAccess::TABLE)->count())->toBe(0)
        ->and(DB::table(HistoricalAccess::WORK)->count())->toBe(0);
});

it('refuses restored private review notes after subject minimization', function (): void {
    $original = DB::table(HistoricalAccess::TABLE)->sole();
    app(ReaderErasure::class)->erase((string) $this->reader->id);
    DB::table(HistoricalAccess::TABLE)->update(['reason' => $original->reason, 'evidence' => $original->evidence,
        'actor_id' => $original->actor_id, 'actor_type' => $original->actor_type, 'redacted_at' => null]);
    $order = DB::table('commerce_orders')->where('id', $this->order->id)->sole();
    expect(fn () => app(OrderReaderData::class)->record(DB::connection(), $order))->toThrow(RuntimeException::class);
});

it('allows evidenced corrections after invoice expiry without retaining new private notes', function (): void {
    CarbonImmutable::setTestNow('2026-10-17 12:00:00 UTC');
    app(OrderPrivacy::class)->maintainOrder($this->order->reference);
    app(HistoricalAccess::class)->decideAsOwner($this->order->reference, $this->line, 'award',
        ['version' => 1, 'kind' => 'fixed', 'days' => 60], 1, 'New private post-expiry evidence', 'archive:post-expiry-private', bin2hex(random_bytes(16)), true);
    $review = DB::table(HistoricalAccess::TABLE)->orderByDesc('id')->first();
    expect($review->reason)->toBeNull()->and($review->evidence)->toBeNull()->and($review->actor_id)->toBeNull()
        ->and($review->redacted_at)->not->toBeNull()->and(DB::table('entitlements')->value('expires_at'))->toBe('2026-12-14 12:00:00');
    $order = DB::table('commerce_orders')->where('id', $this->order->id)->sole();
    expect(app(OrderReaderData::class)->record(DB::connection(), $order)['historical_access']['revision'])->toBe(2);
});

it('refuses a coherent review-chain rewrite during private-note minimization', function (): void {
    CarbonImmutable::setTestNow('2026-10-17 12:00:00 UTC');
    $before = DB::table(HistoricalAccess::TABLE)->sole();
    $dispatcher = DB::getEventDispatcher();
    $copy = clone $dispatcher;
    DB::setEventDispatcher($copy);
    $fired = false;
    $copy->listen(QueryExecuted::class, function ($event) use (&$fired): void {
        $class = HistoricalAccess::class;
        if (! $fired && str_starts_with(strtolower($event->sql), 'update') && str_contains($event->sql, $class::TABLE)) {
            $fired = true;
            DB::table($class::TABLE)->update(['terms' => json_encode(['version' => 1, 'kind' => 'permanent'])]);
            $row = DB::table($class::TABLE)->sole();
            $hash = (new ReflectionMethod($class, 'recordHash'))->invoke(null, $row);
            DB::table($class::TABLE)->update(['record_hash' => $hash]);
            DB::table($class::WORK)->update(['head_hash' => $hash]);
        }
    });
    try {
        expect(fn () => app(OrderPrivacy::class)->maintainOrder($this->order->reference))->toThrow(RetentionRefused::class)
            ->and($fired)->toBeTrue()->and(DB::table(HistoricalAccess::TABLE)->sole())->toEqual($before);
    } finally {
        DB::setEventDispatcher($dispatcher);
    }
});
