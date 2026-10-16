<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Payments\AccessAdjustments;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\FinancialReconciler;
use Kitsune\Commerce\Payments\FinancialSignals;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Commerce\Payments\StripeCheckout;
use Kitsune\Commerce\Payments\StripeEventInbox;
use Kitsune\Commerce\Payments\StripeWebhook;
use Kitsune\Commerce\Privacy\OrderPrivacy;
use Kitsune\Commerce\Privacy\PrivacyState;
use Kitsune\Commerce\Privacy\RetentionPolicies;
use Kitsune\Commerce\Privacy\RetentionPolicySnapshot;
use Kitsune\Core\Credentials\Secret;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Core\Readers\ReaderErasure;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture;
use Kitsune\Core\Tests\Fixtures\FinancialAccessFixture;
use Kitsune\Core\Tests\Fixtures\OrderFixture;
use Kitsune\Core\Tests\Fixtures\PaidOrderFixture;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Kitsune\Core\Tests\Fixtures\TestUser;
use Kitsune\Core\Tests\TestCase;

beforeEach(function (): void {
    EntitlementFixture::boot();
    config(AppServiceProvider::readerConfig());
    config(['hashing.bcrypt.rounds' => 4, 'app.key' => 'base64:'.base64_encode(str_repeat('b', 32))]);
    CarbonImmutable::setTestNow('2026-10-15 12:00:00 UTC');
    ModuleKernel::flush();
    ModuleLifecycle::install(app(), 'kitsune/commerce');
    ModuleLifecycle::enable(app(), 'kitsune/commerce');
    Artisan::call('migrate', ['--database' => 'custody', '--path' => TestCase::READERS_MIGRATION, '--realpath' => true, '--force' => true]);
    $this->org = Org::create(['slug' => 'retention-restore', 'name' => 'Restore drill', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'retention-restore-main', 'name' => 'Main', 'base_url' => 'https://retention-restore.example.test']);
    $this->reader = ReaderFixture::reader($this->org, 'restore-reader@example.test');
    app(Context::class)->setSite($this->site);
    $this->owner = CredentialFixture::member(email: 'retention-restore-owner@example.test');
    $this->policy = app(RetentionPolicies::class)->createAsOwner(['name', 'address.country'], 'Explicit invoice obligation',
        'Bounded restore drill profile', RetentionPolicySnapshot::CONFIRMATION_DAYS, 30);
    app(RetentionPolicies::class)->activateAsOwner($this->policy->version);
    $this->retentionBackup = (string) tempnam(sys_get_temp_dir(), 'kitsune-retention-backup-');
    unlink($this->retentionBackup);

    // Both transports fail if the offline restore drill accidentally performs provider I/O.
    app()->instance(StripeCheckout::class, new StripeCheckout(new Client(['handler' => HandlerStack::create(new MockHandler)])));
    $this->retentionProvider = new class
    {
        public int $calls = 0;

        public function reconcile(mixed ...$arguments): never
        {
            $this->calls++;
            throw new RuntimeException('A quarantined restore must not request provider I/O.');
        }

        public function submit(mixed ...$arguments): never
        {
            $this->calls++;
            throw new RuntimeException('A quarantined restore must not submit a refund.');
        }
    };
    app()->instance(FinancialReconciler::class, $this->retentionProvider);
});

afterEach(function (): void {
    DB::setDefaultConnection('custody');
    CarbonImmutable::setTestNow();
    ReaderFixture::forget();
    EntitlementFixture::tearDown();
    ModuleKernel::flush();
    foreach (['', '-journal', '-wal', '-shm'] as $suffix) {
        if (isset($this->retentionBackup) && is_file($this->retentionBackup.$suffix)) {
            unlink($this->retentionBackup.$suffix);
        }
    }
});

/** A paid finite-stock purchase with a fixed original term and no provider transport. */
function retentionRestorePurchase(Site $site, string $reader, int $policyVersion): array
{
    $attempt = PaidOrderFixture::attempt($site, stock: '2', quantity: 1);
    $order = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
    $owner = CartOwner::readerKey($reader);
    DB::table('commerce_orders')->where('id', $order->id)->update(['reader_id' => $reader, 'owner_key' => $owner,
        'active_cart_key' => hash('sha256', $owner."\0".$order->cart_ref."\0".$order->cart_version)]);
    DB::table('commerce_order_lines')->where('order_id', $order->id)->update(['entitlement_name' => 'course.restore',
        'offer_terms' => json_encode(['version' => 1, 'kind' => 'fixed', 'days' => 30], JSON_THROW_ON_ERROR)]);
    $attempt = PaidOrderFixture::refresh($attempt);
    app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm(DB::connection(),
        DB::table('commerce_orders')->where('id', $order->id)->sole(), $attempt, PaidOrderFixture::session($attempt)));
    $order = DB::table('commerce_orders')->where('id', $order->id)->sole();
    app(OrderPrivacy::class)->adoptAsOwner($order->reference, $policyVersion, 'Explicit historical restore policy adoption', true);
    FinancialAccessFixture::observe($order);
    app(AccessFulfillment::class)->reconcile($order->reference);
    app(AccessAdjustments::class)->reconcile($order->reference);
    OrderFixture::token($order, bin2hex(random_bytes(32)));

    return [$order, $attempt];
}

/** @return array{0:array<string,mixed>,1:string} */
function retentionRestoreEvent(string $id, string $type, array $object): array
{
    $raw = json_encode(['object' => 'event', 'id' => $id, 'type' => $type, 'api_version' => StripeCheckout::API_VERSION,
        'livemode' => false, 'data' => ['object' => $object], 'customer_email' => 'must-not-resurrect@example.test'], JSON_THROW_ON_ERROR);
    $stamp = CarbonImmutable::now('UTC')->timestamp;
    $secret = 'whsec_retention_restore_fixture';
    $signature = 't='.$stamp.',v1='.hash_hmac('sha256', $stamp.'.'.$raw, $secret);

    return [app(StripeWebhook::class)->verify($raw, $signature, [new Secret($secret)], $stamp), $raw];
}

it('reapplies a separately recorded erasure to a quarantined physical backup before accepting fresh replay events', function (): void {
    [$order, $attempt] = retentionRestorePurchase($this->site, (string) $this->reader->id, $this->policy->version);
    $session = ['object' => 'checkout.session', 'id' => $attempt->session_id, 'mode' => 'payment', 'livemode' => false,
        'client_reference_id' => $order->reference, 'metadata' => ['kitsune_order' => $order->reference, 'kitsune_attempt' => $attempt->reference]];
    [$event, $raw] = retentionRestoreEvent('evt_BeforeBackup', 'checkout.session.completed', $session);
    app(StripeEventInbox::class)->capture($this->org->id, $event, $raw, CarbonImmutable::now('UTC'));
    // This request is recorded outside the backup. There is deliberately no automatic core erasure-log replay.
    $recordedRequest = ['org' => $this->org->id, 'site' => $this->site->id, 'reader' => (string) $this->reader->id, 'owner' => $this->owner->id];
    $grant = (array) DB::table('entitlements')->sole();
    $payment = (array) DB::table(PaidOrders::RECEIPTS)->sole();
    $allocation = (array) DB::table(PaidOrders::ALLOCATIONS)->sole();
    expect($grant['expires_at'])->toBe('2026-11-14 12:00:00')->and(DB::connection()->transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse()->and(DB::table('commerce_stock_movements')->count())->toBe(1);

    // VACUUM INTO writes an actual consistent SQLite file, rather than cloning rows through the application.
    DB::connection()->statement('VACUUM INTO ?', [$this->retentionBackup]);
    $backup = new PDO('sqlite:'.$this->retentionBackup);
    expect(is_file($this->retentionBackup))->toBeTrue()->and(filesize($this->retentionBackup))->toBeGreaterThan(0)
        ->and((int) $backup->query('select count(*) from readers')->fetchColumn())->toBe(1)
        ->and((int) $backup->query('select count(*) from entitlements')->fetchColumn())->toBe(1)
        ->and($backup->query('select raw_payload from commerce_stripe_events')->fetchColumn())->toBe($raw);
    $backup = null;
    expect(app(ReaderErasure::class)->erase($recordedRequest['reader']))->toBe(['grants' => 1, 'links' => 0, 'account' => true]);
    expect(DB::table('readers')->count())->toBe(0)->and(DB::table('entitlements')->count())->toBe(0)
        ->and(DB::table(StripeEventInbox::TABLE)->value('raw_payload'))->toBeNull();

    // Restore to the separate quarantined file. No runtime work is admitted between restoration and manual reapply.
    DB::purge('secondary');
    expect(copy($this->retentionBackup, $this->secondaryFile))->toBeTrue();
    DB::setDefaultConnection('secondary');
    ReaderFixture::forget();
    EntitlementFixture::forget();
    app(Context::class)->setOrg(Org::query()->findOrFail($recordedRequest['org']));
    app(Context::class)->setSite(Site::query()->findOrFail($recordedRequest['site']));
    Auth::guard('web')->setUser(TestUser::query()->findOrFail($recordedRequest['owner']));
    expect(DB::connection()->getDatabaseName())->toBe($this->secondaryFile)
        ->and(DB::table('readers')->count())->toBe(1)->and((array) DB::table('entitlements')->sole())->toBe($grant)
        ->and(DB::table('commerce_orders')->value('contact_email'))->toBe('historical-buyer@example.test')
        ->and(DB::table(StripeEventInbox::TABLE)->value('raw_payload'))->toBe($raw);

    CarbonImmutable::setTestNow('2026-10-16 12:00:00 UTC');
    expect(app(ReaderErasure::class)->erase($recordedRequest['reader']))->toBe(['grants' => 1, 'links' => 0, 'account' => true]);
    $restored = DB::table('commerce_orders')->where('id', $order->id)->sole();
    $record = app(OrderReaderData::class)->record(DB::connection(), $restored);
    expect($record['privacy']['subject_erased'])->toBeTrue()->and($record['contact_email'])->toBeNull()->and($record['delivery'])->toBeNull()
        ->and($record['billing'])->toBe(['name' => 'Historical Buyer', 'address' => ['country' => 'US']])
        ->and($restored->reader_id)->toBeNull()->and(DB::table('readers')->count())->toBe(0)->and(DB::table('entitlements')->count())->toBe(0)
        ->and(DB::table('commerce_order_access_tokens')->count())->toBe(0)
        ->and((array) DB::table(PaidOrders::RECEIPTS)->sole())->toBe($payment)
        ->and((array) DB::table(PaidOrders::ALLOCATIONS)->sole())->toBe($allocation)
        ->and(DB::table('commerce_stock_movements')->count())->toBe(1)->and((string) DB::table('commerce_sellables')->value('stock'))->toBe('1');

    foreach (['evt_AfterManualReapplyA', 'evt_AfterManualReapplyB'] as $id) {
        [$fresh, $payload] = retentionRestoreEvent($id, 'checkout.session.completed', $session);
        $receipt = app(StripeEventInbox::class)->capture($recordedRequest['org'], $fresh, $payload, CarbonImmutable::now('UTC'));
        expect($receipt['state'])->toBe('pending')->and($receipt['id'])->toBeGreaterThan(0)
            ->and(DB::table(StripeEventInbox::TABLE)->where('id', $receipt['id'])->value('raw_payload'))->toBeNull();
    }
    expect(app(AccessFulfillment::class)->reconcile($order->reference)['state'])->toBe('suppressed')
        ->and(app(AccessAdjustments::class)->reconcile($order->reference)['state'])->toBe('suppressed')
        ->and(DB::table(StripeEventInbox::TABLE)->whereNotNull('raw_payload')->count())->toBe(0)
        ->and(DB::table('entitlements')->count())->toBe(0)->and(DB::table('commerce_stock_movements')->count())->toBe(1);

    // After the configured invoice lifetime and verified closed financial case, only opaque negative guards remain.
    CarbonImmutable::setTestNow('2026-11-15 12:00:00 UTC');
    expect(app(OrderPrivacy::class)->maintainOrder($order->reference)['purged'])->toBe(1)
        ->and(DB::table('commerce_orders')->count())->toBe(0)->and(DB::table(PrivacyState::TOMBSTONES)->count())->toBeGreaterThan(0);
    foreach ([['evt_AfterPhysicalPurgeSession', 'checkout.session.completed', $session],
        ['evt_AfterPhysicalPurgeCharge', 'charge.refunded', ['object' => 'charge', 'id' => $payment['charge_id'], 'livemode' => false]]] as [$id, $type, $object]) {
        [$fresh, $payload] = retentionRestoreEvent($id, $type, $object);
        expect(app(StripeEventInbox::class)->capture($recordedRequest['org'], $fresh, $payload, CarbonImmutable::now('UTC')))
            ->toBe(['id' => 0, 'duplicate' => false, 'state' => 'erased']);
    }
    expect(DB::table(StripeEventInbox::TABLE)->count())->toBe(0)->and(DB::table(FinancialSignals::TABLE)->count())->toBe(0)
        ->and(DB::table('entitlements')->count())->toBe(0)->and(DB::table('commerce_stock_movements')->count())->toBe(1)
        ->and($this->retentionProvider->calls)->toBe(0)->and(DB::connection()->transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
});
