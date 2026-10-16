<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Models\Reader;
use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Models\Cart;
use Kitsune\Commerce\Models\CartLine;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Commerce\Privacy\OrderPrivacy;
use Kitsune\Commerce\Privacy\RetentionPolicies;
use Kitsune\Commerce\Privacy\RetentionPolicySnapshot;
use Kitsune\Core\Entitlements\EntitlementWriter;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Readers\ReaderErasure;
use Kitsune\Core\Readers\ReaderExport;
use Kitsune\Core\Readers\ReaderModuleDataUnavailable;
use Kitsune\Core\Readers\ReaderTokens;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture;
use Kitsune\Core\Tests\Fixtures\OrderFixture;
use Kitsune\Core\Tests\Fixtures\PaidOrderFixture;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;

beforeEach(function (): void {
    EntitlementFixture::boot();
    config(AppServiceProvider::readerConfig());
    config(['hashing.bcrypt.rounds' => 4, 'app.key' => 'base64:'.base64_encode(str_repeat('r', 32))]);
    CarbonImmutable::setTestNow('2026-10-15 12:00:00 UTC');
    $this->world = ReaderFixture::world();
    DB::table('orgs')->update(['currency' => 'USD']);
    $this->org = $this->world['golfdom'];
    $this->site = $this->world['sites']['golfdom'];
    $this->reader = ReaderFixture::reader($this->org, 'retention-reader@example.test');
    app(Context::class)->setSite($this->site);
    $this->site->update(['base_url' => 'https://retention-read.example.test']);
    $this->owner = CredentialFixture::member(email: 'retention-read-owner@example.test');
    $this->policies = app(RetentionPolicies::class);
    $this->policy = $this->policies->createAsOwner(['name', 'address.country'], 'Recorded invoice obligation',
        'Operator selected bounded profile', RetentionPolicySnapshot::CONFIRMATION_DAYS, 30);
    $this->policies->activateAsOwner($this->policy->version);
    $this->privacy = app(OrderReaderData::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    ReaderFixture::forget();
    EntitlementFixture::tearDown();
});

/** Real checked payment/allocation and frozen access, without a provider transport. */
function retentionReaderPurchase(Site $site, string $reader): object
{
    $attempt = PaidOrderFixture::attempt($site, stock: null, quantity: 1);
    $order = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
    $owner = CartOwner::readerKey($reader);
    DB::table('commerce_orders')->where('id', $order->id)->update(['reader_id' => $reader, 'owner_key' => $owner,
        'active_cart_key' => hash('sha256', $owner."\0".$order->cart_ref."\0".$order->cart_version)]);
    DB::table('commerce_order_lines')->where('order_id', $order->id)->update(['entitlement_name' => 'course.retention',
        'offer_terms' => json_encode(['version' => 1, 'kind' => 'permanent'], JSON_THROW_ON_ERROR)]);
    $attempt = PaidOrderFixture::refresh($attempt);
    app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm(DB::connection(),
        DB::table('commerce_orders')->where('id', $order->id)->sole(), $attempt, PaidOrderFixture::session($attempt)));
    // This raw historical fixture receives no implicit policy; adopt this test's explicit configured version.
    $policy = app(RetentionPolicies::class)->current(DB::connection(), (int) $site->org_id);
    app(OrderPrivacy::class)->adoptAsOwner($order->reference, $policy->version, 'Explicit fixture policy adoption', true);

    return DB::table('commerce_orders')->where('id', $order->id)->sole();
}

/** A real older commerce schema retains carts but has never installed orders or later custody. */
function retentionReaderCartOnlySchema(): void
{
    DB::table('commerce_retention_policy_activations')->delete();
    DB::table('commerce_retention_policies')->delete();
    $files = glob(dirname(__DIR__, 2).'/packages/commerce/database/migrations/*.php');
    rsort($files);
    foreach ($files as $file) {
        if (strcmp(basename($file), '0001_01_01_000204_') < 0) {
            continue;
        }
        (require $file)->down();
        DB::table('migrations')->where('migration', pathinfo($file, PATHINFO_FILENAME))->delete();
    }
    DB::table('modules')->insert(['handle' => 'kitsune/commerce', 'version' => ModuleDiscovery::version('kitsune/commerce'),
        'is_enabled' => true, 'installed_at' => now()]);
}

it('finishes account erasure through disabled or stale cart-only commerce without requiring later order schemas', function (string $receipt): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Older-schema rollback probes use transactional disposable SQLite only.');
    }
    retentionReaderCartOnlySchema();
    DB::table('modules')->where('handle', 'kitsune/commerce')->update($receipt === 'disabled' ? ['is_enabled' => false] : ['version' => 'old']);
    $key = (string) $this->reader->id;
    $cart = Cart::create(['reader_id' => $key, 'owner_key' => CartOwner::readerKey($key), 'expires_at' => now()->addDay()]);
    CartLine::create(['cart_id' => $cart->getKey(), 'listing_id' => null, 'quantity' => 1]);
    expect(app(ReaderErasure::class)->erase($key))->toBe(['grants' => 0, 'links' => 0, 'account' => true])
        ->and(DB::table('commerce_carts')->count())->toBe(0)->and(DB::table('commerce_cart_lines')->count())->toBe(0)
        ->and(Reader::query()->whereKey($key)->exists())->toBeFalse()
        ->and(DB::getSchemaBuilder()->hasTable('commerce_orders'))->toBeFalse();
})->with(['disabled', 'stale']);

it('refuses a host observer adding partial or recorded later custody after cold cart erasure', function (string $migration, string $table, bool $recorded): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Older-schema callback probes use transactional disposable SQLite only.');
    }
    retentionReaderCartOnlySchema();
    $key = (string) $this->reader->id;
    Cart::create(['reader_id' => $key, 'owner_key' => CartOwner::readerKey($key), 'expires_at' => now()->addDay()]);
    $events = Reader::getEventDispatcher();
    Reader::setEventDispatcher(clone $events);
    Reader::deleted(static function (Reader $account) use ($key, $migration, $table, $recorded): void {
        if ((string) $account->getKey() !== $key) {
            return;
        }
        if ($recorded) {
            DB::table('migrations')->insert(['migration' => $migration, 'batch' => 1]);
        } else {
            DB::getSchemaBuilder()->create($table, static fn (Blueprint $blueprint) => $blueprint->id());
        }
    });
    try {
        expect(fn () => app(ReaderErasure::class)->erase($key))->toThrow(ReaderModuleDataUnavailable::class);
    } finally {
        Reader::setEventDispatcher($events);
    }
    expect(Reader::query()->whereKey($key)->exists())->toBeTrue()->and(DB::table('commerce_carts')->count())->toBe(1)
        ->and(DB::getSchemaBuilder()->hasTable($table))->toBeFalse()
        ->and(DB::table('migrations')->where('migration', $migration)->exists())->toBeFalse();
})->with([
    'partial orders' => ['0001_01_01_000205_create_commerce_orders', 'commerce_orders', false],
    'missing recorded orders' => ['0001_01_01_000205_create_commerce_orders', 'commerce_orders', true],
    'partial claims' => ['0001_01_01_000212_create_commerce_order_claims', 'commerce_order_claims', false],
    'missing recorded claims' => ['0001_01_01_000212_create_commerce_order_claims', 'commerce_order_claims', true],
    'partial access' => ['0001_01_01_000213_create_commerce_access_fulfillment', 'commerce_access_work', false],
    'missing recorded access' => ['0001_01_01_000213_create_commerce_access_fulfillment', 'commerce_access_work', true],
    'partial privacy' => ['0001_01_01_000218_create_commerce_order_privacy', 'commerce_order_privacy', false],
    'missing recorded privacy' => ['0001_01_01_000218_create_commerce_order_privacy', 'commerce_order_privacy', true],
]);

it('minimizes a paid account while disabled or stale without losing unrelated order custody', function (string $receipt): void {
    $key = (string) $this->reader->id;
    $order = retentionReaderPurchase($this->site, $key);
    OrderFixture::token($order, bin2hex(random_bytes(32)));
    app(AccessFulfillment::class)->reconcile($order->reference);
    $other = OrderFixture::plant($this->world['sites']['rival']);
    $beforeOther = (array) $other;
    // Deliberate disabled/stale historical receipt setup; production model bulk writes remain prohibited.
    DB::table('modules')->where('handle', 'kitsune/commerce')->update($receipt === 'disabled' ? ['is_enabled' => false] : ['version' => 'old']);

    $erased = app(ReaderErasure::class)->erase($key);
    expect($erased['account'])->toBeTrue()->and($erased['grants'])->toBe(1)
        ->and(Reader::query()->whereKey($key)->exists())->toBeFalse()
        ->and(DB::table('commerce_orders')->where('id', $order->id)->exists())->toBeTrue()
        ->and(DB::table('commerce_order_access_tokens')->where('order_id', $order->id)->count())->toBe(0)
        ->and((array) DB::table('commerce_orders')->where('id', $other->id)->sole())->toBe($beforeOther);

    $row = $this->privacy->find(DB::connection(), $this->org->id, $this->site->id, $order->reference);
    $record = $this->privacy->record(DB::connection(), $row);
    expect($record['contact_email'])->toBeNull()->and($record['delivery'])->toBeNull()
        ->and($record['billing'])->toBe(['name' => 'Historical Buyer', 'address' => ['country' => 'US']])
        ->and($record['privacy']['subject_erased'])->toBeTrue()->and($record['quoted_total_minor'])->toBe('2100')
        ->and($row->reader_id)->toBeNull()->and($row->owner_key)->not->toBe($order->owner_key)
        ->and($row->cart_ref)->toBeNull()->and($row->cart_version)->toBeNull()
        ->and($this->privacy->export(DB::connection(), $this->org->id, $key))->toBe(['orders' => []]);
    $serialized = json_encode($record, JSON_THROW_ON_ERROR);
    expect($serialized)->not->toContain('historical-buyer@example.test', '1 Saved Street', '10001', 'Operator selected bounded profile', 'Recorded invoice obligation');
    app('view')->addNamespace('commerce', dirname(__DIR__, 2).'/packages/commerce/resources/views');
    app('translator')->addNamespace('commerce', dirname(__DIR__, 2).'/packages/commerce/resources/lang');
    $html = view('commerce::orders.access-summary', ['order' => $record, 'operator' => true])->render();
    expect($html)->toContain('access was withdrawn when the account was erased')
        ->not->toContain('Pass awarded', 'Permanent access', 'Sign in or register', 'Fixed-term passes run');
})->with(['disabled', 'stale']);

it('never rediscovers a minimized invoice when a host improperly reuses the old reader identifier', function (): void {
    $key = (string) $this->reader->id;
    $order = retentionReaderPurchase($this->site, $key);
    app(ReaderErasure::class)->erase($key);
    // The host contract prohibits re-issued keys; retained commerce still fails closed if a host violates it.
    DB::table('readers')->insert(['id' => $key, 'org_id' => $this->org->id, 'email' => 'different-person@example.test',
        'password' => null, 'email_verified_at' => null, 'created_at' => now(), 'updated_at' => now()]);
    expect(app(ReaderExport::class)->for($key)['module_data']['kitsune/commerce']['orders'])->toBe([]);
    app(ReaderErasure::class)->erase($key);
    expect(DB::table('commerce_orders')->where('id', $order->id)->exists())->toBeTrue()
        ->and(DB::table('readers')->where('id', $key)->exists())->toBeFalse();
});

it('counts retained minimization as zero deleted parents and still counts wholly unpaid deletion', function (): void {
    $key = (string) $this->reader->id;
    $paid = retentionReaderPurchase($this->site, $key);
    $unpaid = OrderFixture::plant($this->site, $key);
    $count = DB::transaction(function () use ($key): int {
        app(EntitlementWriter::class)->forget($key);

        return $this->privacy->erase(DB::connection(), $this->org->id, $key);
    });
    expect($count)->toBe(1)->and(DB::table('commerce_orders')->where('id', $paid->id)->exists())->toBeTrue()
        ->and(DB::table('commerce_orders')->where('id', $unpaid->id)->exists())->toBeFalse();
});

it('clears expired invoice fields while retaining a live original access contract', function (): void {
    $key = (string) $this->reader->id;
    $order = retentionReaderPurchase($this->site, $key);
    app(AccessFulfillment::class)->reconcile($order->reference);
    CarbonImmutable::setTestNow('2026-11-14 12:00:00 UTC');
    expect(app(OrderPrivacy::class)->maintainOrder($order->reference, dryRun: true)['minimized'])->toBe(1)
        ->and(DB::table('commerce_orders')->where('id', $order->id)->value('billing'))->not->toBeNull();
    app(OrderPrivacy::class)->maintainOrder($order->reference);
    $row = $this->privacy->find(DB::connection(), $this->org->id, $this->site->id, $order->reference);
    $record = $this->privacy->record(DB::connection(), $row);
    expect($record['privacy']['invoice_expired'])->toBeTrue()->and($record['privacy']['subject_erased'])->toBeFalse()
        ->and($record['billing'])->toBeNull()->and($record['contact_email'])->toBeNull()
        ->and((string) $row->reader_id)->toBe($key)->and(DB::table('entitlements')->where('reader_id', $key)->count())->toBe(1)
        ->and($this->privacy->export(DB::connection(), $this->org->id, $key)['orders'])->toHaveCount(1);
});

it('rolls back account grants links and retained custody when a host deletion observer poisons the final proof', function (string $event, string $mutation): void {
    $key = (string) $this->reader->id;
    $order = retentionReaderPurchase($this->site, $key);
    app(AccessFulfillment::class)->reconcile($order->reference);
    app(ReaderTokens::class)->mint(ReaderTokens::RECOVER, $key);
    app(ReaderTokens::class)->mint(ReaderTokens::REGISTER, $this->reader->email);
    $grant = (array) DB::table('entitlements')->where('org_id', $this->org->id)->where('reader_id', $key)->sole();
    $snapshot = fn (): array => ['order' => (array) DB::table('commerce_orders')->where('id', $order->id)->sole(),
        'grants' => DB::table('entitlements')->where('org_id', $this->org->id)->orderBy('id')->get()->all(),
        'links' => DB::table('reader_tokens')->where('org_id', $this->org->id)->orderBy('id')->get()->all(),
        'privacy' => DB::table('commerce_order_privacy')->where('order_id', $order->id)->get()->all(),
        'guards' => DB::table('commerce_privacy_tombstones')->where('org_id', $this->org->id)->orderBy('id')->get()->all(),
        'audits' => DB::table('audit_log')->where('org_id', $this->org->id)->orderBy('id')->get()->all()];
    $before = $snapshot();
    $level = DB::connection()->transactionLevel();
    $pdo = DB::connection()->getPdo()->inTransaction();
    $events = Reader::getEventDispatcher();
    Reader::setEventDispatcher(clone $events);
    Reader::{$event}(static function (Reader $account) use ($key, $order, $grant, $mutation): void {
        if ((string) $account->getKey() !== $key) {
            return;
        }
        if ($mutation === 'personal copy') {
            DB::table('commerce_orders')->where('id', $order->id)->update(['contact_email' => 'reintroduced-private@example.test']);
        } elseif ($mutation === 'source') {
            DB::table('entitlements')->insert($grant);
        } else {
            foreach (['commerce_privacy_holds', 'commerce_privacy_deliveries', 'commerce_privacy_transitions', 'commerce_order_privacy'] as $table) {
                DB::table($table)->where('order_id', $order->id)->delete();
            }
        }
    });
    try {
        expect(fn () => app(ReaderErasure::class)->erase($key))->toThrow(ReaderModuleDataUnavailable::class);
    } finally {
        Reader::setEventDispatcher($events);
    }
    expect($snapshot())->toEqual($before)->and(Reader::query()->whereKey($key)->exists())->toBeTrue()
        ->and(DB::connection()->transactionLevel())->toBe($level)->and(DB::connection()->getPdo()->inTransaction())->toBe($pdo);
})->with([
    'deleting restores personal copy' => ['deleting', 'personal copy'],
    'deleted restores personal copy' => ['deleted', 'personal copy'],
    'deleting restores source' => ['deleting', 'source'],
    'deleted restores source' => ['deleted', 'source'],
    'deleting removes receipt' => ['deleting', 'missing receipt'],
    'deleted removes receipt' => ['deleted', 'missing receipt'],
]);
