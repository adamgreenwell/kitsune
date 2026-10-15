<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Filament\OrderAdministration;
use Kitsune\Commerce\Filament\OrderList;
use Kitsune\Commerce\Filament\OrderResource;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Orders\OrderRefused;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as OrderAdminFx;
use Kitsune\Core\Tests\Fixtures\PaidOrderFixture;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    OrderAdminFx::boot();
    $this->org = OrderAdminFx::org('order-admin');
    $this->org->update(['currency' => 'USD']);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'order-admin-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);
    $this->owner = OrderAdminFx::member();
    Module::create(['handle' => 'kitsune/commerce', 'version' => ModuleDiscovery::version('kitsune/commerce'), 'is_enabled' => true, 'installed_at' => now()]);
    ModuleKernel::flush();
    ModuleKernel::boot(app());
    $this->reference = orderAdminFixture($this->org->id, $this->site->id);
});

afterEach(fn () => OrderAdminFx::tearDown());

/** Historical data deliberately references no live catalogue. Admin views must not need one. */
function orderAdminFixture(int $org, int $site, array $overrides = []): string
{
    $reference = bin2hex(random_bytes(16));
    $owner = hash('sha256', random_bytes(32));
    $id = DB::table('commerce_orders')->insertGetId(array_replace([
        'org_id' => $org, 'site_id' => $site, 'reference' => $reference, 'reader_id' => null, 'owner_key' => $owner,
        'contact_email' => 'private-buyer@orders.test', 'cart_ref' => 1, 'cart_version' => 0,
        'request_key' => hash('sha256', random_bytes(32)), 'request_digest' => hash('sha256', 'request'),
        'snapshot_hash' => hash('sha256', 'snapshot'), 'active_cart_key' => hash('sha256', $owner."\0".'1'."\0".'0'),
        'state' => 'prepared', 'mode' => 'test', 'currency' => 'USD', 'tax_mode' => 'exclusive',
        'billing' => json_encode(['name' => 'Private Buyer', 'address' => ['line1' => 'Private Ocean Road', 'country' => 'US']], JSON_THROW_ON_ERROR),
        'delivery' => null, 'shipping' => null, 'site_snapshot' => json_encode(['name' => 'Original brand', 'base_url' => 'https://original.test'], JSON_THROW_ON_ERROR),
        'quoted_item_net_minor' => '2000', 'quoted_shipping_net_minor' => '0', 'quoted_tax_minor' => '200', 'quoted_total_minor' => '2200',
        'confirmed_total_minor' => null, 'created_at' => '2026-10-14 10:00:00', 'cancelled_at' => null,
    ], $overrides));
    DB::table(AccessFulfillment::WORK)->insert(['org_id' => $org, 'site_id' => $site, 'order_id' => $id,
        'state' => 'awaiting_payment', 'category' => null, 'manifest' => null, 'manifest_hash' => null,
        'claim_binding' => null, 'claim_hash' => null, 'claim_reader_id' => null, 'effect_count' => 0, 'replay_count' => 0,
        'created_at' => '2026-10-14 10:00:00', 'updated_at' => '2026-10-14 10:00:00']);
    DB::table('commerce_order_lines')->insert([
        'org_id' => $org, 'site_id' => $site, 'order_id' => $id, 'sellable_ref' => 123, 'listing_ref' => 456, 'entry_ref' => 789,
        'sku' => 'HISTORICAL-BOARD', 'title' => 'Original title', 'quantity' => 1, 'unit_price_minor' => '2000',
        'tax_class' => 'standard', 'tax_rate_parts' => 100000, 'delivery_kind' => 'digital',
        'net_minor' => '2000', 'tax_minor' => '200', 'gross_minor' => '2200', 'entitlement_name' => null, 'offer_terms' => null,
    ]);
    DB::table('commerce_order_access_tokens')->insert([
        'org_id' => $org, 'site_id' => $site, 'order_id' => $id, 'token_hash' => hash('sha256', random_bytes(32)),
        'created_at' => '2026-10-14 10:00:00', 'expires_at' => '2026-10-14 10:30:00', 'consumed_at' => null,
    ]);

    return $reference;
}

it('lists only bounded safe summaries and opens a historical snapshot without live product content', function (): void {
    $page = app(OrderList::class);
    $page->mount($this->reference);
    $detail = $page->detail();
    expect($detail['lines'][0]['title'])->toBe('Original title')->and($detail['billing']['name'])->toBe('Private Buyer')
        ->and($detail['quoted_total_minor'])->toBe('2200')->and($detail)->not->toHaveKeys(['owner_key', 'request_digest', 'reader_id']);
    $rows = $page->orders()->items();
    expect($rows)->toHaveCount(1)->and(array_keys($rows[0]))->toEqualCanonicalizing(['reference', 'state', 'mode', 'currency', 'quoted_total_minor', 'created_at'])
        ->and(json_encode($rows))->not->toContain('Private Buyer')->not->toContain('private-buyer');
});

it('refuses sibling site and cross-org direct order references', function (bool $foreignOrg): void {
    if ($foreignOrg) {
        $other = OrderAdminFx::org('other-orders');
        $other->update(['currency' => 'USD']);
        $site = Site::create(['handle' => 'other', 'slug' => 'other-orders', 'name' => 'Other', 'locale' => 'en']);
    } else {
        $site = Site::create(['handle' => 'sibling', 'slug' => 'sibling-orders', 'name' => 'Sibling', 'locale' => 'en']);
    }
    $reference = orderAdminFixture($site->org_id, $site->id);
    app(Context::class)->setSite($this->site);
    Permissions::forget();
    expect(fn () => app(OrderList::class)->mount($reference))->toThrow(HttpException::class);
    foreach (['cancel', 'erase'] as $action) {
        expect(fn () => app(OrderAdministration::class)->{$action}($reference))->toThrow(OrderRefused::class);
    }
    expect(DB::table('commerce_orders')->where('reference', $reference)->value('state'))->toBe('prepared');
})->with([false, true]);

it('refuses malformed direct reference spellings', function (string $reference): void {
    expect(fn () => app(OrderList::class)->mount($reference))->toThrow(HttpException::class);
})->with(['1', '', str_repeat('A', 32), str_repeat('a', 33), "private\nreference"]);

it('rechecks every order read and destructive action without an owner or ready module', function (string $denial): void {
    $page = app(OrderList::class);
    $page->reference = $this->reference;
    match ($denial) {
        'member' => OrderAdminFx::member(owner: false, email: 'editor@orders.test'),
        'no actor' => Auth::guard('web')->logout(),
        'disabled' => Module::query()->where('handle', 'kitsune/commerce')->firstOrFail()->update(['is_enabled' => false]),
        'stale' => Module::query()->where('handle', 'kitsune/commerce')->firstOrFail()->update(['version' => 'old']),
    };
    Permissions::forget();
    expect(OrderResource::canAccess())->toBeFalse();
    foreach (['orders', 'detail', 'cancelOrder', 'eraseOrder', 'retryAccess'] as $action) {
        expect(fn () => $page->{$action}())->toThrow(HttpException::class);
    }
    foreach (['cancel', 'erase'] as $action) {
        expect(fn () => app(OrderAdministration::class)->{$action}($this->reference))->toThrow(HttpException::class);
    }
    expect(DB::table('commerce_orders')->value('state'))->toBe('prepared')->and(DB::table('commerce_order_lines')->count())->toBe(1);
})->with(['member', 'no actor', 'disabled', 'stale']);

it('lets an owner check retained unknown terms without inventing access or changing the accepted purchase', function (): void {
    $this->site->update(['base_url' => 'https://owner-access.test']);
    $attempt = PaidOrderFixture::attempt($this->site, null, 1);
    $order = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
    $reader = '42';
    $owner = CartOwner::readerKey($reader);
    DB::table('commerce_orders')->where('id', $order->id)->update(['reader_id' => $reader, 'owner_key' => $owner,
        'active_cart_key' => hash('sha256', $owner."\0".$order->cart_ref."\0".$order->cart_version)]);
    $attempt = PaidOrderFixture::refresh($attempt);
    app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm(DB::connection(),
        DB::table('commerce_orders')->where('id', $order->id)->sole(), $attempt, PaidOrderFixture::session($attempt)));
    $snapshot = (array) DB::table('commerce_order_lines')->where('order_id', $order->id)->sole();
    $page = app(OrderList::class);
    $page->mount($order->reference);
    $page->retryAccess();
    $page->retryAccess();
    expect($page->getErrorBag()?->has('order') ?? false)->toBeFalse()
        ->and($page->detail()['access_fulfillment'])->toMatchArray(['state' => 'held', 'category' => 'unknown_terms'])
        ->and(DB::table('entitlements')->count())->toBe(0)
        ->and((array) DB::table('commerce_order_lines')->where('order_id', $order->id)->sole())->toBe($snapshot)
        ->and(session('filament.notifications'))->not->toBeEmpty();
});

it('cancels once without changing snapshots and then erases all checked unpaid order children', function (): void {
    $raw = (array) DB::table('commerce_orders')->first();
    $admin = app(OrderAdministration::class);
    $admin->cancel($this->reference);
    $stored = (array) DB::table('commerce_orders')->first();
    $immutable = array_diff_key($raw, array_flip(['state', 'active_cart_key', 'cancelled_at']));
    expect(array_intersect_key($stored, $immutable))->toBe($immutable)->and($stored['state'])->toBe('cancelled')
        ->and($stored['active_cart_key'])->toBeNull()->and($stored['cancelled_at'])->not->toBeNull();
    $admin->cancel($this->reference);
    expect(AuditLog::query()->where('action', 'commerce.order_cancelled')->count())->toBe(1);
    $admin->erase($this->reference);
    foreach (['commerce_orders', 'commerce_order_lines', 'commerce_order_access_tokens'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
    expect(AuditLog::query()->where('action', 'commerce.order_erased')->count())->toBe(1)
        ->and(json_encode(AuditLog::query()->get()->toArray()))->not->toContain('Private Buyer')->not->toContain('private-buyer');
});

it('rolls back cancellation or erasure if its audit is cancelled', function (string $action): void {
    $events = AuditLog::getEventDispatcher();
    $name = 'eloquent.creating: '.AuditLog::class;
    $old = $events->getRawListeners()[$name] ?? [];
    AuditLog::creating(static fn (): bool => false);
    $before = DB::transactionLevel();
    try {
        expect(fn () => app(OrderAdministration::class)->{$action}($this->reference))->toThrow(OrderRefused::class);
        expect(DB::table('commerce_orders')->value('state'))->toBe('prepared')->and(DB::table('commerce_order_lines')->count())->toBe(1)
            ->and(DB::table('commerce_order_access_tokens')->count())->toBe(1)->and(DB::transactionLevel())->toBe($before);
    } finally {
        $events->forget($name);
        foreach ($old as $listener) {
            $events->listen($name, $listener);
        }
    }
})->with(['cancel', 'erase']);

it('refuses incompatible paid or corrupt financial custody instead of erasing or exposing it', function (array $change): void {
    DB::table('commerce_orders')->where('reference', $this->reference)->update($change);
    expect(fn () => app(OrderList::class)->mount($this->reference))->toThrow(HttpException::class);
    foreach (['cancel', 'erase'] as $action) {
        expect(fn () => app(OrderAdministration::class)->{$action}($this->reference))->toThrow(OrderRefused::class);
    }
    expect(DB::table('commerce_order_lines')->count())->toBe(1)->and(AuditLog::query()->where('action', 'like', 'commerce.order_%')->count())->toBe(0);
})->with([[['confirmed_total_minor' => '2200']], [['state' => 'paid']], [['quoted_total_minor' => '2201']]]);

it('rolls back an audited cancellation whose observer rewrites a historical line', function (): void {
    $events = AuditLog::getEventDispatcher();
    $name = 'eloquent.creating: '.AuditLog::class;
    $old = $events->getRawListeners()[$name] ?? [];
    AuditLog::creating(static function (AuditLog $audit): void {
        if ($audit->action === 'commerce.order_cancelled') {
            DB::table('commerce_order_lines')->update(['title' => 'Observer rewrote history']);
        }
    });
    try {
        expect(fn () => app(OrderAdministration::class)->cancel($this->reference))->toThrow(OrderRefused::class);
        expect(DB::table('commerce_orders')->value('state'))->toBe('prepared')
            ->and(DB::table('commerce_order_lines')->value('title'))->toBe('Original title');
    } finally {
        $events->forget($name);
        foreach ($old as $listener) {
            $events->listen($name, $listener);
        }
    }
});

it('rolls back an audit listener that recreates an erased order or removes owner authority', function (bool $reinsert): void {
    $raw = (array) DB::table('commerce_orders')->first();
    $events = AuditLog::getEventDispatcher();
    $name = 'eloquent.creating: '.AuditLog::class;
    $old = $events->getRawListeners()[$name] ?? [];
    AuditLog::creating(static function (AuditLog $audit) use ($raw, $reinsert): void {
        if ($audit->action !== 'commerce.order_erased') {
            return;
        }
        if ($reinsert) {
            DB::table('commerce_orders')->insert($raw);
        } else {
            Auth::guard('web')->logout();
            Permissions::forget();
        }
    });
    try {
        expect(fn () => app(OrderAdministration::class)->erase($this->reference))->toThrow(OrderRefused::class);
        expect(DB::table('commerce_orders')->count())->toBe(1)->and(DB::table('commerce_order_lines')->count())->toBe(1)
            ->and(DB::table('commerce_order_access_tokens')->count())->toBe(1);
    } finally {
        $events->forget($name);
        foreach ($old as $listener) {
            $events->listen($name, $listener);
        }
    }
})->with([true, false]);

it('does not certify erasure when an audit observer recreates an orphan child with another site stamp', function (string $table): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite deferred constraints isolate the missing-parent child postcondition without destructive schema edits.');
    }
    $child = (array) DB::table($table)->first();
    $other = Site::create(['handle' => 'orphan', 'slug' => 'orphan-stamp', 'name' => 'Orphan', 'locale' => 'en']);
    $child['site_id'] = $other->id;
    $events = AuditLog::getEventDispatcher();
    $name = 'eloquent.creating: '.AuditLog::class;
    $old = $events->getRawListeners()[$name] ?? [];
    DB::statement('PRAGMA defer_foreign_keys = ON');
    AuditLog::creating(static function (AuditLog $audit) use ($table, $child): void {
        if ($audit->action === 'commerce.order_erased') {
            DB::table($table)->insert($child);
        }
    });
    try {
        expect(fn () => app(OrderAdministration::class)->erase($this->reference))->toThrow(OrderRefused::class);
        expect(DB::table('commerce_orders')->count())->toBe(1)->and(DB::table($table)->value('site_id'))->toBe($this->site->id);
    } finally {
        $events->forget($name);
        foreach ($old as $listener) {
            $events->listen($name, $listener);
        }
        DB::statement('PRAGMA defer_foreign_keys = OFF');
    }
})->with(['commerce_order_lines', 'commerce_order_access_tokens', AccessFulfillment::WORK]);
