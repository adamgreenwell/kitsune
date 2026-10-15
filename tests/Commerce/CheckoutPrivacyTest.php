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
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\CommerceServiceProvider;
use Kitsune\Commerce\Filament\OrderAdministration;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Orders\OrderRefused;
use Kitsune\Commerce\Orders\OrderWriter;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Payments\StripeCheckout;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Readers\ReaderErasure;
use Kitsune\Core\Readers\ReaderExport;
use Kitsune\Core\Readers\ReaderModuleDataUnavailable;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture;
use Kitsune\Core\Tests\Fixtures\OrderFixture as Orders;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Kitsune\Core\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    config(AppServiceProvider::readerConfig());
    config(['hashing.bcrypt.rounds' => 4, 'app.key' => 'base64:'.base64_encode(str_repeat('p', 32))]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
    $this->world = ReaderFixture::world();
    DB::table('orgs')->update(['currency' => 'USD']);
    $this->org = $this->world['golfdom'];
    $this->site = $this->world['sites']['golfdom'];
    $this->reader = ReaderFixture::reader($this->org, 'checkout-private@example.test');
    app(Context::class)->setOrg($this->org)->setSite($this->site);
    Module::create(['handle' => 'kitsune/commerce', 'version' => 'old-installed-version', 'is_enabled' => false, 'installed_at' => now()]);
    $this->privacy = app(OrderReaderData::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Cookie::flushQueuedCookies();
    ReaderFixture::forget();
});

/** A retained provider request deliberately contains no invoice, email, address or guest-access secret. */
function checkoutPrivacyAttempt(object $order, string $state, array $overrides = []): object
{
    $reference = bin2hex(random_bytes(16));
    $record = app(OrderReaderData::class)->record(DB::connection(), $order);
    $payload = CheckoutAttempts::payload($record, $reference,
        CarbonImmutable::now('UTC')->addMinutes(30)->timestamp, 'https://checkout-return.test');
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    $terminal = in_array($state, ['expired', 'completed', 'rejected'], true);
    $hasSession = in_array($state, ['open', 'expired', 'completed'], true);
    $session = $hasSession ? 'cs_test_fixture_'.bin2hex(random_bytes(8)) : null;
    $id = DB::table('commerce_checkout_attempts')->insertGetId([
        'org_id' => $order->org_id, 'site_id' => $order->site_id, 'order_id' => $order->id,
        'reference' => $reference, 'active_order_id' => $terminal ? null : $order->id,
        'mode' => 'test', 'currency' => 'USD', 'account_id' => 'acct_fixtureCheckout',
        'api_version' => StripeCheckout::API_VERSION,
        'credential_witness' => str_repeat('a', 64), 'state' => $state,
        'request_payload' => $json, 'request_hash' => CheckoutAttempts::digest($payload), 'order_hash' => $order->snapshot_hash,
        'session_id' => $session, 'checkout_url' => $state === 'open' ? 'https://checkout.stripe.com/c/pay/'.$session.'#private-checkout-fragment' : null,
        'reviewed_at' => Orders::now(), 'expires_at' => CarbonImmutable::now('UTC')->addMinutes(30)->format('Y-m-d H:i:s'),
        'lease_token' => null, 'lease_until' => null, 'created_at' => Orders::now(), 'updated_at' => Orders::now(),
        ...$overrides,
    ]);

    return DB::table('commerce_checkout_attempts')->where('id', $id)->first() ?? throw new RuntimeException('Missing checkout privacy fixture.');
}

function checkoutPrivacyEnable(): void
{
    Module::query()->where('handle', 'kitsune/commerce')->firstOrFail()
        ->update(['version' => ModuleDiscovery::version('kitsune/commerce'), 'is_enabled' => true]);
    ModuleKernel::flush();
    ModuleKernel::boot(app());
}

it('exports safe checkout history through disabled stale commerce without exposing provider capabilities', function (): void {
    $reader = (string) $this->reader->getKey();
    $order = Orders::plant($this->site, $reader);
    $attempt = checkoutPrivacyAttempt($order, 'open');
    $sibling = Orders::plant($this->world['sites']['golfdom-fr'], $reader);
    $siblingAttempt = checkoutPrivacyAttempt($sibling, 'completed');
    $foreign = Orders::plant($this->world['sites']['rival'], $reader);
    $foreignAttempt = checkoutPrivacyAttempt($foreign, 'open');

    $orders = app(ReaderExport::class)->for($reader)['module_data']['kitsune/commerce']['orders'];
    $json = json_encode($orders, JSON_THROW_ON_ERROR);
    expect($orders)->toHaveCount(2)
        ->and($orders[0]['checkout_attempts'])->toHaveCount(1)
        ->and($orders[0]['checkout_attempts'][0])->toMatchArray(['reference' => $attempt->reference, 'state' => 'open', 'mode' => 'test'])
        ->and($orders[1]['checkout_attempts'][0])->toMatchArray(['reference' => $siblingAttempt->reference, 'state' => 'completed'])
        ->and($json)->not->toContain($foreignAttempt->reference, 'request_payload', 'request_hash', 'credential_witness',
            'lease_token', 'private-checkout-fragment', $attempt->session_id, $attempt->checkout_url)
        ->and(app()->getProviders(CommerceServiceProvider::class))->toBe([]);
});

it('rolls back complete account erasure while any checkout outcome remains unresolved', function (string $state): void {
    $reader = (string) $this->reader->getKey();
    $first = Orders::plant($this->site, $reader);
    checkoutPrivacyAttempt($first, 'expired');
    $pending = Orders::plant($this->world['sites']['golfdom-fr'], $reader);
    checkoutPrivacyAttempt($pending, $state);
    EntitlementFixture::plant($this->site, $reader, 'course.saved');

    expect(fn () => app(ReaderErasure::class)->erase($reader))->toThrow(ReaderModuleDataUnavailable::class)
        ->and(DB::table('commerce_orders')->count())->toBe(2)
        ->and(DB::table('commerce_order_lines')->count())->toBe(2)
        ->and(DB::table('commerce_checkout_attempts')->count())->toBe(2)
        ->and(Reader::query()->count())->toBe(1)
        ->and(DB::table('entitlements')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'entitlement.erased')->count())->toBe(0);
})->with(['creating', 'open', 'uncertain', 'completed']);

it('erases terminal test checkout history and the reader without inventing paid retention', function (string $state): void {
    $reader = (string) $this->reader->getKey();
    $order = Orders::plant($this->site, $reader);
    checkoutPrivacyAttempt($order, $state);
    Orders::token($order, bin2hex(random_bytes(32)));

    expect(app(ReaderErasure::class)->erase($reader)['account'])->toBeTrue()
        ->and(DB::table('commerce_orders')->count())->toBe(0)
        ->and(DB::table('commerce_order_lines')->count())->toBe(0)
        ->and(DB::table('commerce_order_access_tokens')->count())->toBe(0)
        ->and(DB::table('commerce_checkout_attempts')->count())->toBe(0)
        ->and(Reader::query()->count())->toBe(0);
})->with(['expired', 'rejected']);

it('keeps exact guest proof for export and refuses erase while the test checkout is unresolved', function (string $state): void {
    $request = Orders::request();
    $owner = CartOwner::current($request, true);
    $order = Orders::plant($this->site, ownerKey: $owner->key);
    $attempt = checkoutPrivacyAttempt($order, $state);
    $stranger = Orders::request();
    expect(fn () => $this->privacy->exportGuest($stranger, $order->reference))->toThrow(RuntimeException::class)
        ->and($this->privacy->exportGuest($request, $order->reference)['checkout_attempts'][0]['reference'])->toBe($attempt->reference)
        ->and(fn () => $this->privacy->eraseGuest($request, $order->reference))->toThrow(RuntimeException::class)
        ->and(DB::table('commerce_orders')->count())->toBe(1)
        ->and(DB::table('commerce_checkout_attempts')->count())->toBe(1);
})->with(['creating', 'open', 'uncertain', 'completed']);

it('erases terminal test checkout children through exact guest ownership', function (string $state): void {
    $request = Orders::request();
    $owner = CartOwner::current($request, true);
    $order = Orders::plant($this->site, ownerKey: $owner->key);
    checkoutPrivacyAttempt($order, $state);
    expect($this->privacy->eraseGuest($request, $order->reference))->toBe(1)
        ->and(DB::table('commerce_orders')->count())->toBe(0)
        ->and(DB::table('commerce_checkout_attempts')->count())->toBe(0);
})->with(['expired', 'rejected']);

it('refuses buyer and owner cancellation while a provider test Session can still complete', function (string $state): void {
    checkoutPrivacyEnable();
    $request = Orders::request();
    $owner = CartOwner::current($request, true);
    $order = Orders::plant($this->site, ownerKey: $owner->key);
    checkoutPrivacyAttempt($order, $state);
    expect(fn () => app(OrderWriter::class)->cancel($request, $order->reference))->toThrow(OrderRefused::class);
    config(['auth.providers.users.model' => TestUser::class]);
    CredentialFixture::member();
    expect(fn () => app(OrderAdministration::class)->cancel($order->reference))->toThrow(OrderRefused::class)
        ->and(fn () => app(OrderAdministration::class)->erase($order->reference))->toThrow(OrderRefused::class)
        ->and(DB::table('commerce_orders')->value('state'))->toBe('prepared')
        ->and(DB::table('commerce_orders')->value('confirmed_total_minor'))->toBeNull()
        ->and(DB::table('commerce_checkout_attempts')->count())->toBe(1)
        ->and(DB::table('audit_log')->whereIn('action', ['commerce.order_cancelled', 'commerce.order_erased'])->count())->toBe(0);
})->with(['creating', 'open', 'uncertain', 'completed']);

it('cancels without losing terminal test evidence and then permits operator erasure', function (string $state): void {
    checkoutPrivacyEnable();
    config(['auth.providers.users.model' => TestUser::class]);
    CredentialFixture::member();
    $order = Orders::plant($this->site);
    $attempt = checkoutPrivacyAttempt($order, $state);
    app(OrderAdministration::class)->cancel($order->reference);
    expect(DB::table('commerce_orders')->value('state'))->toBe('cancelled')
        ->and(DB::table('commerce_orders')->value('confirmed_total_minor'))->toBeNull()
        ->and(DB::table('commerce_checkout_attempts')->value('reference'))->toBe($attempt->reference);
    app(OrderAdministration::class)->erase($order->reference);
    expect(DB::table('commerce_orders')->count())->toBe(0)->and(DB::table('commerce_checkout_attempts')->count())->toBe(0);
})->with(['expired', 'rejected']);

it('refuses mismatched checkout stamps before export or any order erasure', function (string $column): void {
    $reader = (string) $this->reader->getKey();
    $order = Orders::plant($this->site, $reader);
    checkoutPrivacyAttempt($order, 'expired', [$column => $column === 'org_id'
        ? $this->world['rival']->getKey() : $this->world['sites']['golfdom-fr']->getKey()]);
    expect(fn () => app(ReaderExport::class)->for($reader))->toThrow(ReaderModuleDataUnavailable::class)
        ->and(fn () => app(ReaderErasure::class)->erase($reader))->toThrow(ReaderModuleDataUnavailable::class)
        ->and(DB::table('commerce_orders')->count())->toBe(1)
        ->and(DB::table('commerce_checkout_attempts')->count())->toBe(1)
        ->and(Reader::query()->count())->toBe(1);
})->with(['org_id', 'site_id']);

it('fails closed on unknown checkout state or a future live attempt without leaking its payload', function (array $patch): void {
    $reader = (string) $this->reader->getKey();
    $order = Orders::plant($this->site, $reader);
    checkoutPrivacyAttempt($order, 'expired', $patch);
    expect(fn () => app(ReaderExport::class)->for($reader))->toThrow(ReaderModuleDataUnavailable::class)
        ->and(fn () => app(ReaderErasure::class)->erase($reader))->toThrow(ReaderModuleDataUnavailable::class)
        ->and(DB::table('commerce_orders')->count())->toBe(1)
        ->and(DB::table('commerce_checkout_attempts')->count())->toBe(1);
})->with(['future state' => [['state' => 'paid']], 'future live attempt' => [['mode' => 'live']]]);

it('refuses oversized checkout custody without partially erasing the account', function (): void {
    $reader = (string) $this->reader->getKey();
    $order = Orders::plant($this->site, $reader);
    foreach (range(1, CheckoutAttempts::MAX_ATTEMPTS + 1) as $index) {
        checkoutPrivacyAttempt($order, 'completed');
    }
    expect(fn () => app(ReaderExport::class)->for($reader))->toThrow(ReaderModuleDataUnavailable::class)
        ->and(fn () => app(ReaderErasure::class)->erase($reader))->toThrow(ReaderModuleDataUnavailable::class)
        ->and(DB::table('commerce_checkout_attempts')->count())->toBe(21)
        ->and(DB::table('commerce_orders')->count())->toBe(1)->and(Reader::query()->count())->toBe(1);
});

it('refuses incomplete empty checkout custody before exporting or deleting its order', function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite transactional schema probe; server DDL would commit the test wrapper.');
    }
    $reader = (string) $this->reader->getKey();
    Orders::plant($this->site, $reader);
    Schema::table('commerce_checkout_attempts', static fn ($table) => $table->renameColumn('request_payload', 'incomplete_payload'));
    try {
        expect(fn () => app(ReaderExport::class)->for($reader))->toThrow(ReaderModuleDataUnavailable::class)
            ->and(fn () => app(ReaderErasure::class)->erase($reader))->toThrow(ReaderModuleDataUnavailable::class)
            ->and(DB::table('commerce_orders')->count())->toBe(1)->and(Reader::query()->count())->toBe(1);
    } finally {
        Schema::table('commerce_checkout_attempts', static fn ($table) => $table->renameColumn('incomplete_payload', 'request_payload'));
    }
});

it('refuses missing recorded checkout custody rather than claiming no attempts were retained', function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite transactional schema probe; server DDL would commit the test wrapper.');
    }
    $reader = (string) $this->reader->getKey();
    Orders::plant($this->site, $reader);
    Schema::rename('commerce_checkout_attempts', 'checkout_privacy_missing_attempts');
    try {
        expect(fn () => app(ReaderExport::class)->for($reader))->toThrow(ReaderModuleDataUnavailable::class)
            ->and(fn () => app(ReaderErasure::class)->erase($reader))->toThrow(ReaderModuleDataUnavailable::class)
            ->and(Reader::query()->count())->toBe(1);
    } finally {
        Schema::rename('checkout_privacy_missing_attempts', 'commerce_checkout_attempts');
    }
});

it('rechecks the unresolved-checkout fence after a cancellation audit listener runs', function (): void {
    checkoutPrivacyEnable();
    $request = Orders::request();
    $owner = CartOwner::current($request, true);
    $order = Orders::plant($this->site, ownerKey: $owner->key);
    $attempt = checkoutPrivacyAttempt($order, 'expired');
    $event = 'eloquent.created: '.AuditLog::class;
    Event::listen($event, static function (AuditLog $audit) use ($order, $attempt): void {
        if ($audit->action === 'commerce.order_cancelled') {
            DB::table('commerce_checkout_attempts')->where('id', $attempt->id)->update([
                'state' => 'open', 'active_order_id' => $order->id,
                'checkout_url' => 'https://checkout.stripe.com/c/pay/'.$attempt->session_id,
            ]);
        }
    });
    try {
        expect(fn () => app(OrderWriter::class)->cancel($request, $order->reference))->toThrow(OrderRefused::class)
            ->and(DB::table('commerce_orders')->value('state'))->toBe('prepared')
            ->and(DB::table('commerce_checkout_attempts')->value('state'))->toBe('expired')
            ->and(DB::table('audit_log')->where('action', 'commerce.order_cancelled')->count())->toBe(0);
    } finally {
        Event::forget($event);
    }
});
