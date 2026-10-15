<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Payments\AccessAdjustments;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\CheckoutRefused;
use Kitsune\Commerce\Payments\FinancialState;
use Kitsune\Core\Entitlements\EntitlementWriter;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\FinancialAccessFixture as FinancialFx;

beforeEach(function (): void {
    Fx::boot();
    CarbonImmutable::setTestNow('2026-10-15 12:00:00 UTC');
    $this->org = Org::create(['slug' => 'access-adjustments', 'name' => 'Access adjustments', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'access-adjustments-main', 'name' => 'Main', 'base_url' => 'https://adjustments.example.test']);
    app(Context::class)->setSite($this->site);
    Fx::declareReaders();
    $this->reader = Fx::reader();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Fx::tearDown();
});

it('shortens only the refunded order to surviving original terms and restores no extra days after failure', function (): void {
    $order = FinancialFx::purchase($this->site, (string) $this->reader->id, [['days' => 7, 'quantity' => 1], ['days' => 30, 'quantity' => 1]]);
    $second = FinancialFx::purchase($this->site, (string) $this->reader->id);
    app(AccessFulfillment::class)->reconcile($order->reference);
    app(AccessFulfillment::class)->reconcile($second->reference);
    Fx::owner();
    Fx::writer()->comp($this->reader->id, 'course.purchased', null);
    $unrelated = DB::table('entitlements')->where('source', '!=', 'commerce.order:'.$order->id)->orderBy('id')->get()->all();
    $line = (int) DB::table('commerce_order_lines')->where('order_id', $order->id)->orderByDesc('id')->value('id');
    $reference = app(FinancialState::class)->requestAsOwner($order->reference, [$line => 1], 'Refund the longer pass', bin2hex(random_bytes(16)));
    app(AccessAdjustments::class)->reconcile($order->reference);
    expect(DB::table('entitlements')->where('source', 'commerce.order:'.$order->id)->value('expires_at'))->toBe('2026-10-22 12:00:00')
        ->and(DB::table('entitlements')->where('source', '!=', 'commerce.order:'.$order->id)->orderBy('id')->get()->all())->toEqual($unrelated);
    CarbonImmutable::setTestNow('2026-10-20 09:00:00 UTC');
    FinancialFx::observe($order, [FinancialFx::refund($order, $reference, 'failed')]);
    app(AccessAdjustments::class)->reconcile($order->reference);
    expect(DB::table('entitlements')->where('source', 'commerce.order:'.$order->id)->value('expires_at'))->toBe('2026-11-14 12:00:00')
        ->and(DB::table('entitlements')->where('source', '!=', 'commerce.order:'.$order->id)->orderBy('id')->get()->all())->toEqual($unrelated);
});

it('owns an exact suspension and never overrides a subsequent same-second manual revoke', function (): void {
    $order = FinancialFx::purchase($this->site, (string) $this->reader->id);
    app(AccessFulfillment::class)->reconcile($order->reference);
    $id = DB::table('entitlements')->value('id');
    Fx::owner();
    $line = (int) DB::table('commerce_order_lines')->where('order_id', $order->id)->value('id');
    $reference = app(FinancialState::class)->requestAsOwner($order->reference, [$line => 1], 'Refund one pass', bin2hex(random_bytes(16)));
    app(AccessAdjustments::class)->reconcile($order->reference);
    $revoked = DB::table('entitlements')->sole();
    expect($revoked->revoked_at)->not->toBeNull()->and(DB::table(AccessAdjustments::EFFECTS)->value('revocation_witness'))->not->toBeNull();
    FinancialFx::observe($order, [FinancialFx::refund($order, $reference, 'failed')]);
    app(AccessAdjustments::class)->reconcile($order->reference);
    expect(DB::table('entitlements')->value('revoked_at'))->toBeNull()->and(DB::table('entitlements')->value('id'))->toBe($id);
    Fx::writer()->revoke($this->reader->id, 'course.purchased', 'commerce.order:'.$order->id);
    $manual = DB::table('entitlements')->sole();
    expect($manual->revoked_at)->toBe($revoked->revoked_at)->and($manual->changed_at)->toBe($revoked->changed_at)
        ->and($manual->change_token)->not->toBe($revoked->change_token);
    app(AccessAdjustments::class)->reconcile($order->reference);
    expect(DB::table('entitlements')->value('revoked_at'))->not->toBeNull()
        ->and(DB::table(AccessAdjustments::EFFECTS)->value('revocation_witness'))->toBeNull();
});

it('blocks an initial grant during a refund and never regenerates a completed erased source', function (): void {
    $order = FinancialFx::purchase($this->site, (string) $this->reader->id);
    Fx::owner();
    $line = (int) DB::table('commerce_order_lines')->where('order_id', $order->id)->value('id');
    $reference = app(FinancialState::class)->requestAsOwner($order->reference, [$line => 1], 'Refund before initial access', bin2hex(random_bytes(16)));
    app(AccessFulfillment::class)->reconcile($order->reference);
    app(AccessAdjustments::class)->reconcile($order->reference);
    expect(DB::table('entitlements')->count())->toBe(0);
    FinancialFx::observe($order, [FinancialFx::refund($order, $reference, 'canceled')]);
    app(AccessFulfillment::class)->reconcile($order->reference);
    app(AccessAdjustments::class)->reconcile($order->reference);
    expect(DB::table('entitlements')->count())->toBe(1);
    Fx::writer()->forget($this->reader->id);
    app(AccessFulfillment::class)->reconcile($order->reference);
    app(AccessAdjustments::class)->reconcile($order->reference);
    expect(DB::table('entitlements')->count())->toBe(0)
        ->and(DB::table(AccessAdjustments::EFFECTS)->value('state'))->toBe('missing');
});

it('requires a verified won status and preserves another refund blocker', function (): void {
    $order = FinancialFx::purchase($this->site, (string) $this->reader->id);
    app(AccessFulfillment::class)->reconcile($order->reference);
    Fx::owner();
    FinancialFx::observe($order, disputes: [FinancialFx::dispute($order, 'needs_response')]);
    app(AccessAdjustments::class)->reconcile($order->reference);
    expect(DB::table('entitlements')->value('revoked_at'))->not->toBeNull();
    FinancialFx::observe($order, disputes: [FinancialFx::dispute($order, 'lost')]);
    app(AccessAdjustments::class)->reconcile($order->reference);
    expect(DB::table('entitlements')->value('revoked_at'))->not->toBeNull();
    FinancialFx::observe($order, disputes: [FinancialFx::dispute($order, 'won')]);
    app(AccessAdjustments::class)->reconcile($order->reference);
    expect(DB::table('entitlements')->value('revoked_at'))->toBeNull()
        ->and(DB::table('entitlements')->value('expires_at'))->toBe('2026-11-14 12:00:00');
    $line = (int) DB::table('commerce_order_lines')->where('order_id', $order->id)->value('id');
    app(FinancialState::class)->requestAsOwner($order->reference, [$line => 1], 'Refund remains after win', bin2hex(random_bytes(16)));
    app(AccessAdjustments::class)->reconcile($order->reference);
    FinancialFx::observe($order, disputes: [FinancialFx::dispute($order, 'won')]);
    app(AccessAdjustments::class)->reconcile($order->reference);
    expect(DB::table('entitlements')->value('revoked_at'))->not->toBeNull();
});

it('leaves a won fixed-term dispute expired at its original end', function (): void {
    $order = FinancialFx::purchase($this->site, (string) $this->reader->id, [['days' => 1, 'quantity' => 1]]);
    app(AccessFulfillment::class)->reconcile($order->reference);
    Fx::owner();
    FinancialFx::observe($order, disputes: [FinancialFx::dispute($order, 'under_review')]);
    app(AccessAdjustments::class)->reconcile($order->reference);
    CarbonImmutable::setTestNow('2026-10-17 12:00:00 UTC');
    FinancialFx::observe($order, disputes: [FinancialFx::dispute($order, 'won')]);
    app(AccessAdjustments::class)->reconcile($order->reference);
    expect(DB::table('entitlements')->value('revoked_at'))->not->toBeNull()
        ->and(DB::table('entitlements')->value('expires_at'))->toBe('2026-10-16 12:00:00')
        ->and(DB::table(AccessAdjustments::EFFECTS)->value('state'))->toBe('expired');
});

it('refuses foreign site and org recovery and ordinary buyer calls', function (): void {
    $order = FinancialFx::purchase($this->site, (string) $this->reader->id);
    app(AccessFulfillment::class)->reconcile($order->reference);
    Fx::signIn($this->reader);
    expect(fn () => app(AccessAdjustments::class)->reconcile($order->reference))->toThrow(CheckoutRefused::class);
    Fx::nobody();
    Fx::site($this->org, 'sibling');
    expect(fn () => app(AccessAdjustments::class)->reconcile($order->reference))->toThrow(CheckoutRefused::class);
    $other = Fx::org('access-adjustments-foreign');
    Fx::site($other, 'foreign');
    Fx::owner('foreign@example.test');
    expect(fn () => app(AccessAdjustments::class)->reconcile($order->reference))->toThrow(CheckoutRefused::class)
        ->and(DB::table('entitlements')->value('revoked_at'))->toBeNull();
});

it('rolls back a source effect when its financial proof is rewritten by an audit callback', function (): void {
    $order = FinancialFx::purchase($this->site, (string) $this->reader->id);
    app(AccessFulfillment::class)->reconcile($order->reference);
    Fx::owner();
    $line = (int) DB::table('commerce_order_lines')->where('order_id', $order->id)->value('id');
    $dispatcher = AuditLog::getEventDispatcher();
    AuditLog::setEventDispatcher(clone $dispatcher);
    AuditLog::created(function (AuditLog $row) use ($order): void {
        if ($row->action === EntitlementWriter::REVOKED) {
            DB::table(FinancialState::WORK)->where('order_id', $order->id)->update(['revision' => 999]);
        }
    });
    try {
        expect(fn () => app(FinancialState::class)->requestAsOwner($order->reference, [$line => 1],
            'Audit-fenced refund', bin2hex(random_bytes(16))))->toThrow(CheckoutRefused::class);
    } finally {
        AuditLog::setEventDispatcher($dispatcher);
    }
    expect(DB::table('entitlements')->value('revoked_at'))->toBeNull()
        ->and(DB::table('audit_log')->where('action', EntitlementWriter::REVOKED)->count())->toBe(0)
        ->and((int) DB::table(FinancialState::WORK)->where('order_id', $order->id)->value('revision'))->toBe(0)
        ->and(DB::table(FinancialState::REFUNDS)->count())->toBe(0);
});

it('refuses a later source audit that reactivates or recreates an earlier selected source', function (bool $missing): void {
    $order = FinancialFx::purchase($this->site, (string) $this->reader->id, [
        ['days' => 30, 'quantity' => 1], ['days' => 7, 'quantity' => 1, 'name' => 'course.second'],
    ]);
    app(AccessFulfillment::class)->reconcile($order->reference);
    Fx::owner();
    $first = (array) DB::table('entitlements')->where('entitlement', 'course.purchased')->sole();
    if ($missing) {
        DB::table('entitlements')->where('id', $first['id'])->delete();
    }
    $lines = DB::table('commerce_order_lines')->where('order_id', $order->id)->pluck('id')->all();
    $selection = array_fill_keys($lines, 1);
    $dispatcher = AuditLog::getEventDispatcher();
    AuditLog::setEventDispatcher(clone $dispatcher);
    AuditLog::created(function (AuditLog $audit) use ($first, $missing): void {
        if ($audit->action !== EntitlementWriter::REVOKED
            || DB::table('entitlements')->where('id', $audit->target_id)->value('entitlement') !== 'course.second') {
            return;
        }
        if ($missing) {
            DB::table('entitlements')->insert($first);
        } else {
            DB::table('entitlements')->where('id', $first['id'])->update(['revoked_at' => null]);
        }
    });
    try {
        expect(fn () => app(FinancialState::class)->requestAsOwner($order->reference, $selection,
            'Every selected source must stay suspended', bin2hex(random_bytes(16))))->toThrow(CheckoutRefused::class);
    } finally {
        AuditLog::setEventDispatcher($dispatcher);
    }
    expect(DB::table(FinancialState::REFUNDS)->count())->toBe(0)
        ->and(DB::table('entitlements')->where('entitlement', 'course.second')->value('revoked_at'))->toBeNull()
        ->and(DB::table('audit_log')->where('action', EntitlementWriter::REVOKED)->count())->toBe(0);
    if ($missing) {
        expect(DB::table('entitlements')->where('id', $first['id'])->exists())->toBeFalse();
    } else {
        expect((array) DB::table('entitlements')->where('id', $first['id'])->sole())->toBe($first);
    }
})->with(['prior live source' => false, 'prior erased source' => true]);

it('preserves operator-review holds for unknown historical terms and zero-effect held allocations', function (): void {
    $unknown = FinancialFx::purchase($this->site, (string) $this->reader->id, [['days' => 30, 'quantity' => 1, 'unknown' => true]]);
    app(AccessFulfillment::class)->reconcile($unknown->reference);
    expect(app(AccessAdjustments::class)->reconcile($unknown->reference))->toMatchArray(['state' => 'held', 'category' => 'unknown_terms']);
    $projection = app(AccessAdjustments::class)->projection(DB::connection(), $this->org->id, $unknown);
    expect($projection['financial_access']['category'])->toBe('unknown_terms')
        ->and($projection['financial_access']['effects'][0]['state'])->toBe('held');
    $held = FinancialFx::purchase($this->site, (string) $this->reader->id, payment: ['refunded' => '2100']);
    expect(app(AccessAdjustments::class)->reconcile($held->reference))->toMatchArray(['state' => 'held', 'category' => 'original_financial_hold'])
        ->and(DB::table('entitlements')->count())->toBe(0);
});
