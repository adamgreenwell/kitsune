<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Catalogue\CatalogueWriter;
use Kitsune\Commerce\Exports\ExportFormat;
use Kitsune\Commerce\Exports\OrderExportRecords;
use Kitsune\Commerce\Filament\OrderList;
use Kitsune\Commerce\Models\AccessReview;
use Kitsune\Commerce\Models\AccessReviewWork;
use Kitsune\Commerce\Orders\HistoricalAccess;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Orders\OrderRefused;
use Kitsune\Commerce\Payments\AccessAdjustments;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\CheckoutReview;
use Kitsune\Commerce\Payments\FinancialState;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\FinancialAccessFixture as FinancialFx;
use Kitsune\Core\Tests\Fixtures\PaidOrderFixture;

beforeEach(function (): void {
    Fx::boot();
    CarbonImmutable::setTestNow('2026-10-15 12:00:00 UTC');
    $this->org = Org::create(['slug' => 'historical-access', 'name' => 'Historical access', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'historical-main', 'name' => 'Main', 'base_url' => 'https://historical.example.test']);
    app(Context::class)->setSite($this->site);
    Fx::declareReaders();
    $this->reader = Fx::reader();
    $this->order = FinancialFx::purchase($this->site, (string) $this->reader->id, [['days' => 30, 'quantity' => 2, 'unknown' => true]]);
    $this->line = (int) DB::table('commerce_order_lines')->where('order_id', $this->order->id)->value('id');
    Fx::owner();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Fx::tearDown();
});

function reviewHistorical(object $test, string $decision = 'award', ?array $terms = ['version' => 1, 'kind' => 'fixed', 'days' => 30], int $revision = 0, ?string $key = null): string
{
    return app(HistoricalAccess::class)->decideAsOwner($test->order->reference, $test->line, $decision, $terms,
        $revision, 'Reviewed original purchase record', 'archive:purchase-42', $key ?? bin2hex(random_bytes(16)), true);
}

it('awards evidenced terms from original confirmation without rewriting the purchase', function (): void {
    $before = [DB::table('commerce_order_lines')->get()->all(), DB::table(PaidOrders::RECEIPTS)->get()->all(), DB::table('commerce_stock_movements')->get()->all()];
    CarbonImmutable::setTestNow('2026-10-20 12:00:00 UTC');
    reviewHistorical($this);
    expect(DB::table('entitlements')->value('expires_at'))->toBe('2026-11-14 12:00:00')
        ->and([DB::table('commerce_order_lines')->get()->all(), DB::table(PaidOrders::RECEIPTS)->get()->all(), DB::table('commerce_stock_movements')->get()->all()])->toEqual($before);
    app(AccessFulfillment::class)->retryAsOwner($this->order->reference);
    expect(DB::table('entitlements')->count())->toBe(1);
});

it('retains unknown holds and can resolve them as no access without a grant', function (): void {
    reviewHistorical($this, 'hold', null);
    expect(DB::table('entitlements')->count())->toBe(0);
    reviewHistorical($this, 'none', null, 1);
    expect(DB::table('entitlements')->count())->toBe(0)
        ->and(app(OrderReaderData::class)->record(DB::connection(), $this->order)['historical_access']['revision'])->toBe(2);
});

it('never restarts a reviewed expired term', function (): void {
    CarbonImmutable::setTestNow('2026-11-20 12:00:00 UTC');
    reviewHistorical($this);
    expect(DB::table('entitlements')->count())->toBe(0);
    app(AccessFulfillment::class)->retryAsOwner($this->order->reference);
    expect(DB::table('entitlements')->count())->toBe(0);
});

it('makes exact request replay harmless and refuses stale replacements', function (): void {
    $key = bin2hex(random_bytes(16));
    $ref = reviewHistorical($this, key: $key);
    expect(reviewHistorical($this, key: $key))->toBe($ref)
        ->and(DB::table(HistoricalAccess::TABLE)->count())->toBe(1);
    expect(fn () => reviewHistorical($this))->toThrow(OrderRefused::class);
});

it('replaces a reviewed award with an audited decision while retaining history', function (): void {
    reviewHistorical($this);
    reviewHistorical($this, 'none', null, 1);
    expect(DB::table('entitlements')->value('revoked_at'))->not->toBeNull();
    reviewHistorical($this, 'award', ['version' => 1, 'kind' => 'fixed', 'days' => 60], 2);
    expect(DB::table('entitlements')->value('revoked_at'))->toBeNull()
        ->and(DB::table('entitlements')->value('expires_at'))->toBe('2026-12-14 12:00:00')
        ->and(DB::table(HistoricalAccess::TABLE)->count())->toBe(3);
});

it('does not put reviewed history into original checkout consent or buyer private notes', function (): void {
    $before = app(OrderReaderData::class)->record(DB::connection(), $this->order);
    reviewHistorical($this);
    $after = app(OrderReaderData::class)->record(DB::connection(), $this->order);
    expect(CheckoutReview::unmodifiedOrderHash($after))->toBe(CheckoutReview::unmodifiedOrderHash($before))
        ->and(json_encode($after['historical_access']))->not->toContain('archive:purchase-42', 'Reviewed original purchase record');
});

it('suspends reviewed access on dispute and restores only its original remaining term', function (): void {
    reviewHistorical($this);
    FinancialFx::observe($this->order, disputes: [FinancialFx::dispute($this->order, 'needs_response')]);
    app(AccessAdjustments::class)->reconcile($this->order->reference);
    expect(DB::table('entitlements')->value('revoked_at'))->not->toBeNull();
    CarbonImmutable::setTestNow('2026-10-20 12:00:00 UTC');
    FinancialFx::observe($this->order, disputes: [FinancialFx::dispute($this->order, 'won')]);
    app(AccessAdjustments::class)->reconcile($this->order->reference);
    expect(DB::table('entitlements')->value('revoked_at'))->toBeNull()
        ->and(DB::table('entitlements')->value('expires_at'))->toBe('2026-11-14 12:00:00');
});

it('keeps a reviewed award pending while all awarded units are being refunded', function (): void {
    $refund = app(FinancialState::class)->requestAsOwner($this->order->reference, [$this->line => 2], 'Return both units', bin2hex(random_bytes(16)));
    reviewHistorical($this);
    expect(DB::table('entitlements')->count())->toBe(0);
    FinancialFx::observe($this->order, [FinancialFx::refund($this->order, $refund, 'failed')]);
    app(AccessFulfillment::class)->retryAsOwner($this->order->reference);
    expect(DB::table('entitlements')->value('expires_at'))->toBe('2026-11-14 12:00:00');
});

it('does not let a correction override a later manual revocation or recreate a deleted completed source', function (bool $deleted): void {
    reviewHistorical($this);
    if ($deleted) {
        Fx::writer()->forget($this->reader->id);
    } else {
        Fx::writer()->revoke($this->reader->id, 'course.purchased', 'commerce.order:'.$this->order->id);
    }
    reviewHistorical($this, 'award', ['version' => 1, 'kind' => 'permanent'], 1);
    $deleted ? expect(DB::table('entitlements')->count())->toBe(0) : expect(DB::table('entitlements')->value('revoked_at'))->not->toBeNull();
})->with([false, true]);

it('refuses sibling-site and cross-org review requests', function (bool $otherOrg): void {
    if ($otherOrg) {
        $org = Org::create(['slug' => 'other-review', 'name' => 'Other', 'currency' => 'USD']);
        app(Context::class)->setOrg($org);
    }
    $site = Site::create(['handle' => 'other', 'slug' => 'other-review-site', 'name' => 'Other', 'base_url' => 'https://other-review.example.test']);
    app(Context::class)->setSite($site);
    Fx::owner('other-owner@example.test');
    expect(fn () => reviewHistorical($this))->toThrow(OrderRefused::class)
        ->and(DB::table(HistoricalAccess::TABLE)->count())->toBe(0)
        ->and(DB::table('entitlements')->count())->toBe(0);
})->with([false, true]);

it('requires current owner authority and refuses unsigned decisions', function (): void {
    auth()->forgetUser();
    expect(fn () => reviewHistorical($this))->toThrow(OrderRefused::class);
    Fx::owner('review-owner-again@example.test');
    expect(fn () => app(HistoricalAccess::class)->decideAsOwner($this->order->reference, $this->line, 'none', null, 0,
        'Reviewed original purchase record', 'archive:purchase-42', bin2hex(random_bytes(16)), false))->toThrow(OrderRefused::class)
        ->and(DB::table(HistoricalAccess::TABLE)->count())->toBe(0);
});

it('keeps original financial and stock custody unchanged across review audit observers', function (): void {
    $dispatcher = AuditLog::getEventDispatcher();
    $copy = clone $dispatcher;
    AuditLog::setEventDispatcher($copy);
    AuditLog::created(function ($audit): void {
        if ($audit->action === 'commerce.historical_access_reviewed') {
            DB::table(FinancialState::WORK)->update(['revision' => 99]);
        }
    });
    try {
        expect(fn () => reviewHistorical($this))->toThrow(OrderRefused::class)
            ->and(DB::table(HistoricalAccess::TABLE)->count())->toBe(0)
            ->and(DB::table('entitlements')->count())->toBe(0);
    } finally {
        AuditLog::setEventDispatcher($dispatcher);
    }
});

it('refuses missing or changed review custody rather than silently losing decisions', function (string $damage): void {
    reviewHistorical($this);
    $damage === 'parent' ? DB::table(HistoricalAccess::WORK)->delete() : DB::table(HistoricalAccess::TABLE)->update(['evidence' => 'changed']);
    expect(fn () => app(OrderReaderData::class)->record(DB::connection(), $this->order))->toThrow(RuntimeException::class);
})->with(['parent', 'notes']);

it('keeps evidenced guest access awaiting the immutable whole-order claim', function (): void {
    $guest = FinancialFx::purchase($this->site, null, [['days' => 30, 'quantity' => 1, 'unknown' => true]]);
    $line = (int) DB::table('commerce_order_lines')->where('order_id', $guest->id)->value('id');
    app(HistoricalAccess::class)->decideAsOwner($guest->reference, $line, 'award', ['version' => 1, 'kind' => 'permanent'],
        0, 'Guest purchase evidence reviewed', 'archive:guest', bin2hex(random_bytes(16)), true);
    expect(DB::table('entitlements')->count())->toBe(0)
        ->and(app(OrderReaderData::class)->record(DB::connection(), $guest)['access_fulfillment']['state'])->toBe('awaiting_claim');
});

it('reviews an unclaimed guest without reading or locking unrelated reader entitlements', function (): void {
    Fx::writer()->grant($this->reader->id, 'course.purchased', 'comp.staff:guest-review', null);
    $guest = FinancialFx::purchase($this->site, null, [['days' => 30, 'quantity' => 1, 'unknown' => true]]);
    $line = (int) DB::table('commerce_order_lines')->where('order_id', $guest->id)->value('id');
    $before = DB::table('entitlements')->get()->all();
    $queries = [];
    $dispatcher = DB::getEventDispatcher();
    $copy = clone $dispatcher;
    DB::setEventDispatcher($copy);
    $copy->listen(QueryExecuted::class, function ($event) use (&$queries): void {
        if (str_starts_with(strtolower($event->sql), 'select') && preg_match('/["`]entitlements["`]/', $event->sql) === 1) {
            $queries[] = $event->sql;
        }
    });
    try {
        app(HistoricalAccess::class)->decideAsOwner($guest->reference, $line, 'award', ['version' => 1, 'kind' => 'permanent'],
            0, 'Guest purchase evidence reviewed', 'archive:guest-with-unrelated-readers', bin2hex(random_bytes(16)), true);
    } finally {
        DB::setEventDispatcher($dispatcher);
    }
    expect($queries)->toBe([])
        ->and(DB::table('entitlements')->get()->all())->toEqual($before)
        ->and(app(OrderReaderData::class)->record(DB::connection(), $guest)['access_fulfillment']['state'])->toBe('awaiting_claim');
});

it('resolves a missing line without removing another line for the same entitlement', function (): void {
    $order = FinancialFx::purchase($this->site, (string) $this->reader->id, [
        ['days' => 30, 'quantity' => 1, 'unknown' => true], ['days' => 60, 'quantity' => 1],
    ]);
    $line = (int) DB::table('commerce_order_lines')->where('order_id', $order->id)->orderBy('id')->value('id');
    Fx::owner('mixed-review-owner@example.test');
    app(HistoricalAccess::class)->decideAsOwner($order->reference, $line, 'none', null, 0, 'No access sold on this line', 'archive:mixed', bin2hex(random_bytes(16)), true);
    expect(DB::table('entitlements')->where('source', 'commerce.order:'.$order->id)->value('expires_at'))->toBe('2026-12-14 12:00:00');
});

it('can hold a previously reviewed award and restore only the newly evidenced remaining term', function (): void {
    reviewHistorical($this);
    reviewHistorical($this, 'hold', null, 1);
    expect(DB::table('entitlements')->value('revoked_at'))->not->toBeNull();
    CarbonImmutable::setTestNow('2026-10-20 12:00:00 UTC');
    reviewHistorical($this, 'award', ['version' => 1, 'kind' => 'fixed', 'days' => 30], 2);
    expect(DB::table('entitlements')->value('revoked_at'))->toBeNull()
        ->and(DB::table('entitlements')->value('expires_at'))->toBe('2026-11-14 12:00:00');
});

it('refuses unsupported terms and unbounded evidence before recording authority', function (?array $terms, string $evidence): void {
    expect(fn () => app(HistoricalAccess::class)->decideAsOwner($this->order->reference, $this->line, 'award', $terms,
        0, 'Reviewed original purchase record', $evidence, bin2hex(random_bytes(16)), true))->toThrow(OrderRefused::class)
        ->and(DB::table(HistoricalAccess::TABLE)->count())->toBe(0);
})->with([
    'zero days' => [['version' => 1, 'kind' => 'fixed', 'days' => 0], 'archive:42'],
    'unknown version' => [['version' => 2, 'kind' => 'permanent'], 'archive:42'],
    'no terms' => [null, 'archive:42'],
    'private multiline input' => [['version' => 1, 'kind' => 'permanent'], "archive:42\nprivate"],
    'oversized' => [['version' => 1, 'kind' => 'permanent'], str_repeat('a', 256)],
]);

it('refuses known original lines, unavailable units and held financial allocations', function (string $kind): void {
    $order = FinancialFx::purchase($this->site, (string) $this->reader->id, [['days' => 30, 'quantity' => 1, 'unknown' => $kind !== 'known']],
        payment: $kind === 'financial_hold' ? ['disputed' => true] : []);
    $line = (int) DB::table('commerce_order_lines')->where('order_id', $order->id)->value('id');
    Fx::owner('ineligible-review-owner@example.test');
    expect(fn () => app(HistoricalAccess::class)->decideAsOwner($order->reference, $line, 'award', ['version' => 1, 'kind' => 'permanent'],
        0, 'Reviewed original purchase record', 'archive:ineligible', bin2hex(random_bytes(16)), true))->toThrow(OrderRefused::class);
})->with(['known', 'financial_hold']);

it('shows the original fixed expiry and records reviewed terms through the owner page', function (): void {
    ModuleKernel::flush();
    ModuleKernel::boot(app());
    $page = app(OrderList::class);
    $page->mount($this->order->reference);
    $page->reviewLine = (string) $this->line;
    $page->reviewDecision = 'award';
    $page->reviewKind = 'fixed';
    $page->reviewDays = '30';
    $page->reviewReason = 'Page reviewed original evidence';
    $page->reviewEvidence = 'archive:owner-page';
    $page->reviewConfirmed = true;
    expect($page->reviewPreview())->toBe('2026-11-14 12:00:00 UTC');
    $page->reviewHistoricalAccess();
    expect($page->getErrorBag()?->has('order') ?? false)->toBeFalse()
        ->and($page->reviewRevision)->toBe(1)->and($page->reviewConfirmed)->toBeFalse()
        ->and($page->historicalReviews()[0]['evidence'])->toBe('archive:owner-page')
        ->and(DB::table('entitlements')->value('expires_at'))->toBe('2026-11-14 12:00:00');
});

it('declares reviewed decision models Site scoped and hides private raw serialization', function (string $class): void {
    expect((new ReflectionClass($class))->getAttributes(SiteScoped::class))->toHaveCount(1)
        ->and(class_uses_recursive($class))->toContain(EnforcesScope::class)
        ->and((new $class)->forceFill(['reason' => 'Private notes', 'evidence' => 'Private document'])->toArray())->toBe([]);
})->with([AccessReview::class, AccessReviewWork::class]);

it('keeps checked review history available to cold financial exports while denying disabled writes', function (): void {
    reviewHistorical($this);
    DB::table('modules')->where('handle', 'kitsune/commerce')->update(['is_enabled' => false]);
    app(Context::class)->setOrg($this->org);
    $record = app(OrderReaderData::class)->record(DB::connection(), $this->order);
    expect($record['historical_access']['revision'])->toBe(1)
        ->and(json_encode($record))->not->toContain('archive:purchase-42', 'Reviewed original purchase record');
    app(Context::class)->setSite($this->site);
    expect(fn () => reviewHistorical($this, 'none', null, 1))->toThrow(OrderRefused::class);
});

it('rolls back a review whose persisted audit or review receipt is changed by an observer', function (string $damage): void {
    $dispatcher = AuditLog::getEventDispatcher();
    AuditLog::setEventDispatcher(clone $dispatcher);
    AuditLog::created(function ($audit) use ($damage): void {
        if ($audit->action !== 'commerce.historical_access_reviewed') {
            return;
        }
        if ($damage === 'audit') {
            DB::table('audit_log')->where('id', $audit->id)->update(['actor_id' => null]);
        } elseif ($damage === 'review') {
            DB::table(HistoricalAccess::TABLE)->update(['evidence' => 'changed after submission']);
        } else {
            DB::table('role_user')->delete();
        }
    });
    try {
        expect(fn () => reviewHistorical($this))->toThrow(OrderRefused::class)
            ->and(DB::table(HistoricalAccess::TABLE)->count())->toBe(0)->and(DB::table('entitlements')->count())->toBe(0);
    } finally {
        AuditLog::setEventDispatcher($dispatcher);
    }
})->with(['audit', 'review', 'authority']);

it('exports reviewed terms as financial history without private operator provenance', function (): void {
    reviewHistorical($this);
    $rows = app(OrderLocks::class)->run(fn () => iterator_to_array(
        app(OrderExportRecords::class)->forOrder(DB::connection(), $this->order, $this->org->slug, false), false));
    $reviews = array_values(array_filter($rows, static fn (array $row): bool => $row['type'] === 'historical_access_review'));
    expect($reviews)->toHaveCount(1)->and($reviews[0]['data'])->toMatchArray(['kind' => 'award', 'offer_kind' => 'fixed', 'offer_duration_days' => 30]);
    foreach (['jsonl', 'csv'] as $format) {
        $encoded = (new ExportFormat($format))->encode($reviews[0]);
        expect($encoded)->toContain('award')->not->toContain('archive:purchase-42', 'Reviewed original purchase record', 'actor_id');
    }
});

it('resolves evidenced historical terms after the current product is removed', function (): void {
    $product = DB::table('commerce_order_lines')->where('id', $this->line)->value('sellable_ref');
    $catalogue = app(CatalogueWriter::class);
    foreach (DB::table('commerce_listings')->where('sellable_id', $product)->pluck('id') as $listing) {
        $catalogue->removeListing($listing);
    }
    $catalogue->removeSellable($product);
    reviewHistorical($this);
    expect(DB::table('entitlements')->value('expires_at'))->toBe('2026-11-14 12:00:00')
        ->and(DB::table('commerce_order_lines')->where('id', $this->line)->value('offer_terms'))->toBeNull();
});

it('keeps the original reviewed term when one of two awarded units is refunded', function (): void {
    reviewHistorical($this);
    app(FinancialState::class)->requestAsOwner($this->order->reference, [$this->line => 1], 'Return one of two units', bin2hex(random_bytes(16)));
    app(AccessAdjustments::class)->reconcile($this->order->reference);
    expect(DB::table('entitlements')->value('revoked_at'))->toBeNull()
        ->and(DB::table('entitlements')->value('expires_at'))->toBe('2026-11-14 12:00:00');
});

it('never treats an unrelated reviewed entitlement as authority for an unreviewed held effect', function (): void {
    $order = FinancialFx::purchase($this->site, (string) $this->reader->id, [
        ['days' => 30, 'quantity' => 1, 'unknown' => true],
        ['days' => 30, 'quantity' => 1, 'unknown' => true, 'name' => 'course.unreviewed'],
    ]);
    $line = (int) DB::table('commerce_order_lines')->where('order_id', $order->id)->orderBy('id')->value('id');
    Fx::owner('unrelated-review-owner@example.test');
    app(HistoricalAccess::class)->decideAsOwner($order->reference, $line, 'none', null, 0,
        'No access sold for the reviewed line', 'archive:only-first-line', bin2hex(random_bytes(16)), true);
    DB::table(AccessFulfillment::EFFECTS)->where('order_id', $order->id)->where('entitlement', 'course.unreviewed')
        ->update(['state' => 'granted', 'category' => null, 'processed_at' => '2026-10-15 12:00:00']);
    DB::table(AccessFulfillment::WORK)->where('order_id', $order->id)->update(['state' => 'processed', 'category' => null]);
    expect(fn () => app(OrderReaderData::class)->record(DB::connection(), $order))->toThrow(RuntimeException::class);
});

it('preserves unrelated complimentary access across the owner review audit', function (): void {
    Fx::writer()->grant($this->reader->id, 'course.purchased', 'comp.staff:review-preserved', null);
    $dispatcher = AuditLog::getEventDispatcher();
    AuditLog::setEventDispatcher(clone $dispatcher);
    AuditLog::created(function ($audit): void {
        if ($audit->action === 'commerce.historical_access_reviewed') {
            DB::table('entitlements')->where('source', 'comp.staff:review-preserved')->delete();
        }
    });
    try {
        expect(fn () => reviewHistorical($this))->toThrow(OrderRefused::class)
            ->and(DB::table(HistoricalAccess::TABLE)->count())->toBe(0)
            ->and(DB::table('entitlements')->where('source', 'comp.staff:review-preserved')->count())->toBe(1);
    } finally {
        AuditLog::setEventDispatcher($dispatcher);
    }
});

it('refuses stock mutation during the review receipt insert before any grant', function (): void {
    $product = DB::table('commerce_order_lines')->where('id', $this->line)->value('sellable_ref');
    $dispatcher = DB::getEventDispatcher();
    $copy = clone $dispatcher;
    DB::setEventDispatcher($copy);
    $fired = false;
    $copy->listen(QueryExecuted::class, function ($event) use (&$fired, $product): void {
        if (! $fired && str_starts_with(strtolower($event->sql), 'insert') && str_contains($event->sql, HistoricalAccess::TABLE)) {
            $fired = true;
            DB::table('commerce_sellables')->where('id', $product)->update(['stock' => '10']);
        }
    });
    try {
        expect(fn () => reviewHistorical($this))->toThrow(OrderRefused::class)
            ->and($fired)->toBeTrue()->and(DB::table(HistoricalAccess::TABLE)->count())->toBe(0)
            ->and(DB::table('commerce_sellables')->where('id', $product)->value('stock'))->toBeNull();
    } finally {
        DB::setEventDispatcher($dispatcher);
    }
});

it('does not let a queue-write callback recreate another completed erased source', function (): void {
    $order = FinancialFx::purchase($this->site, (string) $this->reader->id, [
        ['days' => 30, 'quantity' => 1, 'unknown' => true], ['days' => 30, 'quantity' => 1, 'name' => 'course.completed'],
    ]);
    app(AccessFulfillment::class)->reconcile($order->reference);
    DB::table('entitlements')->where('source', 'commerce.order:'.$order->id)->delete();
    $line = (int) DB::table('commerce_order_lines')->where('order_id', $order->id)->orderBy('id')->value('id');
    Fx::owner('queue-review-owner@example.test');
    $dispatcher = DB::getEventDispatcher();
    $copy = clone $dispatcher;
    DB::setEventDispatcher($copy);
    $fired = false;
    $copy->listen(QueryExecuted::class, function ($event) use (&$fired, $order): void {
        if (! $fired && str_starts_with(strtolower($event->sql), 'update') && str_contains($event->sql, AccessFulfillment::EFFECTS)) {
            $fired = true;
            DB::table(AccessFulfillment::EFFECTS)->where('order_id', $order->id)->where('entitlement', 'course.completed')
                ->update(['state' => 'pending', 'category' => null, 'processed_at' => null]);
        }
    });
    try {
        expect(fn () => app(HistoricalAccess::class)->decideAsOwner($order->reference, $line, 'award', ['version' => 1, 'kind' => 'permanent'],
            0, 'Reviewed only the original unknown line', 'archive:queue-proof', bin2hex(random_bytes(16)), true))->toThrow(OrderRefused::class)
            ->and($fired)->toBeTrue()->and(DB::table('entitlements')->count())->toBe(0)
            ->and(DB::table(HistoricalAccess::TABLE)->count())->toBe(0);
    } finally {
        DB::setEventDispatcher($dispatcher);
    }
});

it('refuses a historical access award for a paid line whose units were unavailable at confirmation', function (): void {
    $attempt = PaidOrderFixture::attempt($this->site, '1', 1);
    $order = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
    $owner = CartOwner::readerKey((string) $this->reader->id);
    DB::table('commerce_orders')->where('id', $order->id)->update(['reader_id' => (string) $this->reader->id, 'owner_key' => $owner,
        'active_cart_key' => hash('sha256', $owner."\0".$order->cart_ref."\0".$order->cart_version)]);
    $line = DB::table('commerce_order_lines')->where('order_id', $order->id)->sole();
    DB::table('commerce_order_lines')->where('id', $line->id)->update(['entitlement_name' => 'course.unavailable', 'offer_terms' => null]);
    // Another purchase exhausted the shared stock between preparation and confirmed payment.
    DB::table('commerce_sellables')->where('id', $line->sellable_ref)->update(['stock' => '0']);
    $attempt = PaidOrderFixture::refresh($attempt);
    app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm(DB::connection(),
        DB::table('commerce_orders')->where('id', $order->id)->sole(), $attempt, PaidOrderFixture::session($attempt)));
    Fx::owner('unavailable-review-owner@example.test');
    $allocation = DB::table(PaidOrders::ALLOCATIONS)->where('order_id', $order->id)->sole();
    expect((int) $allocation->awarded)->toBe(0)->and((int) $allocation->unavailable)->toBe(1)
        ->and(fn () => app(HistoricalAccess::class)->decideAsOwner($order->reference, (int) $line->id, 'award',
            ['version' => 1, 'kind' => 'permanent'], 0, 'Cannot turn an unavailable unit into access', 'archive:zero-awarded', bin2hex(random_bytes(16)), true))->toThrow(OrderRefused::class)
        ->and(DB::table(HistoricalAccess::TABLE)->count())->toBe(0)->and(DB::table('entitlements')->count())->toBe(0);
});
