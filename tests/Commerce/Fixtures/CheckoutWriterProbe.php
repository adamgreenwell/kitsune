<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Blueprints\StorefrontBlueprint;
use Kitsune\Commerce\Carts\CartAmounts;
use Kitsune\Commerce\Carts\CartWriter;
use Kitsune\Commerce\Catalogue\ProductWriter;
use Kitsune\Commerce\Checkout\CommerceSetupWriter;
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Models\Sellable;
use Kitsune\Commerce\Orders\OrderQuotes;
use Kitsune\Commerce\Orders\OrderRefused;
use Kitsune\Commerce\Orders\OrderWriter;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Payments\CheckoutRefused;
use Kitsune\Commerce\Payments\CheckoutReview;
use Kitsune\Commerce\Payments\CheckoutWriter;
use Kitsune\Commerce\Payments\StripeCheckout;
use Kitsune\Commerce\Shipping\ShippoRates;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Credentials\CredentialConsoleAuthority;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialSlot;
use Kitsune\Core\Credentials\CredentialSlots;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\KitsuneServiceProvider;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\OrderFixture;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;
use Psr\Http\Message\RequestInterface;

[$script, $root, $directory, $scenario] = $argv;
$role = $argv[4] ?? null;
require $root.'/vendor/autoload.php';

/** Real commits, primary custody and actual protocol client; only the outbound HTTP transport is fake. */
final class CheckoutWriterApplication extends TestbenchApplication
{
    public string $database;

    protected function defineEnvironment($app): void
    {
        $app->detectEnvironment(static fn (): string => 'testing');
        $app['config']->set('database.default', 'checkout_writer');
        $app['config']->set('database.connections.checkout_writer', ['driver' => 'sqlite', 'database' => $this->database, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 5000]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('mail.default', 'array');
        $app['config']->set('mail.mailers.array', ['transport' => 'array']);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('w', 32)));
        $app['config']->set('hashing.bcrypt.rounds', 4);
    }
}

$applicationPath = $directory.'/app'.($role === null ? '' : '-'.$role);
foreach (['bootstrap/cache', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs'] as $path) {
    mkdir($applicationPath.'/'.$path, 0700, true);
}
$application = new CheckoutWriterApplication($applicationPath);
$application->database = $directory.'/writer.sqlite';
$app = $application->configure(['extra' => ['providers' => [BladeIconsServiceProvider::class, BladeHeroiconsServiceProvider::class, KitsuneServiceProvider::class], 'dont-discover' => ['*']]])->createApplication();
$app->make(HttpKernel::class)->pushMiddleware(InvokeDeferredCallbacks::class);
Fx::boot();
CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
$shippingRequests = [];
if ($role === 'A' || $role === 'B') {
    $initialized = json_decode((string) file_get_contents($directory.'/initialized.json'), true, flags: JSON_THROW_ON_ERROR);
    app(Context::class)->setOrg($org = Org::query()->findOrFail($initialized['org']));
    app(Context::class)->setSite($site = Site::query()->findOrFail($initialized['site']));
    ModuleKernel::boot($app);
    $product = Sellable::query()->findOrFail($initialized['product']);
    $order = Order::query()->where('reference', $initialized['reference'])->firstOrFail();
    $request = OrderFixture::request();
    $request->session()->replace($initialized['session']);
    $review = ['token' => $initialized['review']];
} else {
    if (Artisan::call('migrate', ['--path' => [$root.'/packages/core/database/migrations', $root.'/tests/Commerce/migrations'], '--realpath' => true, '--force' => true]) !== 0) {
        throw new RuntimeException(Artisan::output());
    }
    ModuleLifecycle::install($app, 'kitsune/commerce');
    ModuleLifecycle::enable($app, 'kitsune/commerce');
    ModuleKernel::boot($app);
    $org = Org::create(['name' => 'Checkout writer', 'slug' => 'checkout-writer', 'currency' => 'USD']);
    app(Context::class)->setOrg($org);
    $site = Site::create(['handle' => 'main', 'slug' => 'checkout-writer-main', 'name' => 'Main', 'locale' => 'en', 'base_url' => 'https://writer.example.test/brand']);
    app(Context::class)->setSite($site);
    BlueprintApplier::apply(new StorefrontBlueprint);
    $price = match ($scenario) {
        'zero', 'free-paid-shipping' => '0', 'below-minimum' => '2', default => '2000',
    };
    $product = app(ProductWriter::class)->createProduct(['sku' => 'CHECKOUT', 'price_minor' => $price, 'stock' => '5'], 'USD', [
        'title' => 'Checkout course', 'slug' => 'checkout-course', 'status' => 'published', 'values' => [],
    ], [], true);
    $setup = app(CommerceSetupWriter::class);
    $setup->setSettings(['tax_mode' => 'exclusive', 'shipping_tax_mode' => 'exclusive', 'shipping_tax_class' => 'shipping',
        ...($scenario === 'free-paid-shipping' ? ['ship_from_name' => 'Warehouse', 'ship_from_address' => ['line1' => '1 Warehouse Street', 'country' => 'US']] : [])]);
    $setup->saveTaxRate(null, ['country' => 'US', 'region' => '*', 'tax_class' => 'standard', 'rate_parts' => 200000]);
    $setup->setSalesPolicy($product->getKey(), ['tax_class' => 'standard', 'delivery_kind' => $scenario === 'free-paid-shipping' ? 'physical' : 'digital',
        ...($scenario === 'free-paid-shipping' ? ['weight_grams' => 500, 'length_mm' => 300, 'width_mm' => 200,
            'height_mm' => 100, 'tare_grams' => 50, 'units_per_parcel' => 1] : [])]);
    if (app(CredentialSlots::class)->find(StripeCheckout::SLOT) === null) {
        app(CredentialSlots::class)->register(new CredentialSlot(StripeCheckout::SLOT, 'test.label', 'test.help', true,
            ['test' => ['sk_test_'], 'live' => ['sk_live_']], minLength: 16));
    }
    CredentialConsoleAuthority::run(app(Context::class), 'set', StripeCheckout::SLOT, CredentialMode::Test, null,
        static fn (CredentialConsoleAuthority $authority) => app(CredentialWriter::class)->set(StripeCheckout::SLOT, CredentialMode::Test, 'sk_test_PrivateFixtureCheckoutKey', console: $authority));
    if ($scenario === 'free-paid-shipping') {
        $setup->saveTaxRate(null, ['country' => 'US', 'region' => '*', 'tax_class' => 'shipping', 'rate_parts' => 200000]);
        CredentialConsoleAuthority::run(app(Context::class), 'set', OrderQuotes::SHIPPO_SLOT, CredentialMode::Test, null,
            static fn (CredentialConsoleAuthority $authority) => app(CredentialWriter::class)->set(OrderQuotes::SHIPPO_SLOT, CredentialMode::Test, 'shippo_test_free_product_fixture', console: $authority));
        $shipping = static function () use (&$shippingRequests): Response {
            $shippingRequests[] = ['level' => DB::connection()->transactionLevel(), 'pdo' => DB::connection()->getPdo()->inTransaction()];

            return new Response(201, ['Content-Type' => 'application/json'], json_encode(['object_id' => 'free_shipment', 'status' => 'SUCCESS', 'test' => true,
                'rates' => [['object_id' => 'free_shipping_rate', 'shipment' => 'free_shipment', 'amount' => '5.00', 'currency' => 'USD', 'test' => true,
                    'provider' => 'USPS', 'servicelevel' => ['name' => 'Priority']]]], JSON_THROW_ON_ERROR));
        };
        $app->instance(ShippoRates::class, new ShippoRates(new Client(['handler' => HandlerStack::create(new MockHandler([$shipping, $shipping]))])));
    }
    $request = OrderFixture::request();
    app(CartWriter::class)->add($request, $product->listings()->sole()->getKey(), 2);
    if ($scenario === 'mixed-free-paid') {
        $free = app(ProductWriter::class)->createProduct(['sku' => 'FREE', 'price_minor' => '0', 'stock' => '5'], 'USD', [
            'title' => 'Free companion course', 'slug' => 'free-companion-course', 'status' => 'published', 'values' => [],
        ], [], true);
        $setup->setSalesPolicy($free->getKey(), ['tax_class' => 'standard', 'delivery_kind' => 'digital']);
        app(CartWriter::class)->add($request, $free->listings()->sole()->getKey(), 1);
    }
    $quote = app(OrderQuotes::class)->quote($request, ['email' => 'private-checkout-buyer@example.test', 'billing' => OrderFixture::billing()]);
    $order = app(OrderWriter::class)->prepare($request, $quote['token'], $scenario === 'free-paid-shipping' ? 'free_shipping_rate' : 'none');
    $review = app(CheckoutReview::class)->review($request, $order->reference);
}
if ($role === 'init') {
    file_put_contents($directory.'/initialized.json', json_encode(['org' => $org->getKey(), 'site' => $site->getKey(),
        'product' => $product->getKey(), 'reference' => $order->reference, 'review' => $review['token'], 'session' => $request->session()->all()], JSON_THROW_ON_ERROR));
    echo json_encode(['initialized' => true, 'level' => DB::connection()->transactionLevel(), 'pdo' => DB::connection()->getPdo()->inTransaction()], JSON_THROW_ON_ERROR);
    exit;
}
$originalOrder = (array) DB::table('commerce_orders')->where('id', $order->getKey())->first();
$originalLines = DB::table('commerce_order_lines')->where('order_id', $order->getKey())->get()->map(static fn (object $line): array => (array) $line)->all();
$requests = [];
$creates = 0;
$networkSession = null;
$nestedRefusals = [];
$http = static function (RequestInterface $outbound) use (&$requests, &$creates, &$networkSession, &$nestedRefusals, $request, $scenario, $order, $product, $directory, $role): Response {
    $path = $outbound->getUri()->getPath();
    $body = (string) $outbound->getBody();
    $requests[] = ['method' => $outbound->getMethod(), 'path' => $path, 'body' => $body,
        'key' => $outbound->getHeaderLine('Idempotency-Key'), 'version' => $outbound->getHeaderLine('Stripe-Version'),
        'level' => DB::connection()->transactionLevel(), 'pdo' => DB::connection()->getPdo()->inTransaction()];
    if ($path === '/v1/account') {
        if ($role !== null && ! is_file($directory.'/account-'.$role.'.json')) {
            file_put_contents($directory.'/account-'.$role.'.json', json_encode(['pid' => getmypid(),
                'level' => DB::connection()->transactionLevel(), 'pdo' => DB::connection()->getPdo()->inTransaction()], JSON_THROW_ON_ERROR));
            $deadline = microtime(true) + 15;
            while (! is_file($directory.'/release-'.$role)) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Checkout account race barrier timed out.');
                }
                usleep(5000);
            }
        }
        if ($scenario === 'price-during-account') {
            DB::table('commerce_sellables')->where('id', $product->getKey())->update(['price_minor' => 2100]);
        }

        return new Response(200, ['Content-Type' => 'application/json'], json_encode(['object' => 'account',
            'id' => $scenario === 'wrong-account' && $creates > 0 ? 'acct_Transplanted' : 'acct_FixtureA'], JSON_THROW_ON_ERROR));
    }
    $create = $path === '/v1/checkout/sessions';
    if ($create) {
        $creates++;
        parse_str($body, $payload);
        $total = '0';
        foreach ($payload['line_items'] as $item) {
            $total = CartAmounts::add($total, $item['price_data']['unit_amount']);
        }
        $networkSession = ['object' => 'checkout.session', 'id' => 'cs_test_FixtureA', 'mode' => 'payment', 'livemode' => false,
            'status' => 'open', 'payment_status' => 'unpaid', 'currency' => 'usd', 'amount_total' => $total,
            'expires_at' => (int) $payload['expires_at'], 'client_reference_id' => $payload['client_reference_id'],
            'metadata' => $payload['metadata'], 'url' => 'https://checkout.stripe.com/c/pay/fixture', 'payment_intent' => null];
        if ($scenario === 'long-url') {
            $networkSession['url'] .= '#'.str_repeat('f', 2900);
        } elseif ($scenario === 'query-url') {
            $networkSession['url'] .= '?opaque='.str_repeat('q', 2200).'#fragment';
        }
        if ($role !== null) {
            file_put_contents($directory.'/provider-session.json', json_encode($networkSession, JSON_THROW_ON_ERROR));
        }
        if ($scenario === 'lease-busy') {
            $attempt = DB::table(CheckoutAttempts::TABLE)->where('order_id', $order->getKey())->first();
            try {
                app(CheckoutWriter::class)->refresh($request, $order->reference, $attempt->reference);
            } catch (CheckoutRefused $failure) {
                $nestedRefusals[] = $failure->reason;
            }
        }
        if ($scenario === 'credential-during-io') {
            CredentialConsoleAuthority::run(app(Context::class), 'set', StripeCheckout::SLOT, CredentialMode::Test, null,
                static fn (CredentialConsoleAuthority $authority) => app(CredentialWriter::class)->set(StripeCheckout::SLOT, CredentialMode::Test, 'sk_test_ChangedDuringPaymentIO', console: $authority));
        }
        if (($scenario === 'timeout' || $scenario === 'retry-rejected' || str_starts_with($scenario, 'owner-')) && $creates === 1) {
            throw new ConnectException('private-transport-credentials-and-invoice', $outbound);
        }
        if ($scenario === 'below-minimum') {
            return new Response(400, ['Content-Type' => 'application/json'], '{"error":{"type":"invalid_request_error","code":"amount_too_small"}}');
        }
        if ($scenario === 'rejected' && $creates === 1 || $scenario === 'retry-rejected' && $creates === 2) {
            return new Response(400, ['Content-Type' => 'application/json'], '{"error":{"type":"invalid_request_error","code":"parameter_missing","message":"private-provider-data"}}');
        }
    }
    if ($networkSession === null && $role !== null && is_file($directory.'/provider-session.json')) {
        $networkSession = json_decode((string) file_get_contents($directory.'/provider-session.json'), true, flags: JSON_THROW_ON_ERROR);
    }
    if ($networkSession === null) {
        throw new RuntimeException('The fake provider lost its Session.');
    }
    $session = $networkSession;
    if (str_ends_with($path, '/expire') || str_starts_with($scenario, 'owner-')) {
        $session['status'] = 'expired';
        $session['url'] = null;
    }
    if ($scenario === 'completed' || $scenario === 'paid-without-intent') {
        $session['status'] = 'complete';
        $session['payment_status'] = 'paid';
        $session['payment_intent'] = $scenario === 'paid-without-intent' ? null : 'pi_FixtureA';
        $session['url'] = null;
    }
    if ($scenario === 'complete-unpaid') {
        $session['status'] = 'complete';
        $session['url'] = null;
    }
    match ($scenario) {
        'wrong-metadata' => $session['metadata']['kitsune_attempt'] = str_repeat('b', 32),
        'wrong-order' => $session['metadata']['kitsune_order'] = str_repeat('b', 32),
        'wrong-reference' => $session['client_reference_id'] = str_repeat('b', 32),
        'wrong-mode' => $session['livemode'] = true,
        'wrong-amount' => $session['amount_total'] = '4801',
        'wrong-currency' => $session['currency'] = 'eur',
        'wrong-expiry' => $session['expires_at']++,
        'no-payment-required' => $session['payment_status'] = 'no_payment_required',
        default => null,
    };

    return new Response(200, ['Content-Type' => 'application/json'], json_encode($session, JSON_THROW_ON_ERROR));
};
$app->instance(StripeCheckout::class, new StripeCheckout(new Client(['handler' => HandlerStack::create(new MockHandler(array_fill(0, 30, $http)))])));
$app->forgetInstance(CheckoutWriter::class);
$writer = app(CheckoutWriter::class);
$auditHit = false;
$auditListener = static function (AuditLog $audit) use ($scenario, &$auditHit, $order): ?bool {
    $action = str_starts_with($scenario, 'audit-start-') ? 'commerce.checkout_started' : 'commerce.checkout_open';
    if ($audit->action !== $action || $auditHit) {
        return null;
    }
    $auditHit = true;
    match ($scenario) {
        'audit-start-cancel' => null,
        'audit-start-misfile' => $audit->action = 'commerce.misfiled',
        'audit-start-row', 'audit-open-row' => DB::table(CheckoutAttempts::TABLE)->where('id', $audit->target_id)->update(['account_id' => 'acct_Transplanted']),
        'audit-open-order' => DB::table('commerce_orders')->where('id', $order->getKey())->update(['contact_email' => 'private-listener@example.test']),
        'audit-open-context' => app(Context::class)->forget(),
        'local-commit' => throw new RuntimeException('private-audit-failure-after-provider-success'),
        default => null,
    };

    return $scenario === 'audit-start-cancel' ? false : null;
};
if (str_starts_with($scenario, 'audit-') || $scenario === 'local-commit') {
    AuditLog::creating($auditListener);
}
if ($scenario === 'missing-key') {
    DB::table('credentials')->where('org_id', $org->getKey())->delete();
} elseif ($scenario === 'unreadable-key') {
    DB::table('credentials')->where('org_id', $org->getKey())->update(['ciphertext' => 'private-unreadable']);
} elseif ($scenario === 'live-mode') {
    DB::table('org_credential_modes')->updateOrInsert(['org_id' => $org->getKey()], ['mode' => 'live', 'changed_at' => OrderFixture::now()]);
} elseif ($scenario === 'ambient') {
    DB::connection()->beginTransaction();
}
$states = [];
$refusals = [];
$result = null;
$call = static function (Closure $work) use (&$states, &$refusals, &$result): void {
    try {
        $result = $work();
        $states[] = $result['state'];
    } catch (CheckoutRefused $failure) {
        $refusals[] = $failure->reason;
    }
};
$call(fn () => $writer->start($request, $order->reference, $review['token']));
if ($scenario === 'ambient') {
    DB::connection()->rollBack();
}
app(Context::class)->setOrg($org)->setSite($site);
$attempt = DB::table(CheckoutAttempts::TABLE)->where('order_id', $order->getKey())->first();
if ($attempt === null && $role === null && ! in_array($scenario, ['missing-key', 'unreadable-key', 'live-mode', 'zero', 'ambient', 'price-during-account', 'audit-start-cancel', 'audit-start-misfile', 'audit-start-row'], true)) {
    throw new RuntimeException('Initial checkout refused before retaining an attempt: '.implode(', ', $refusals).'; outbound calls '.count($requests));
}
$firstState = $attempt?->state;
$beforeSecond = count($requests);
$attemptsBeforeCancellation = null;
if (str_starts_with($scenario, 'cancel-attempt-')) {
    $call(fn () => $writer->expire($request, $order->reference, $attempt->reference));
    $attemptsBeforeCancellation = DB::table(CheckoutAttempts::TABLE)->where('order_id', $order->getKey())->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
    AuditLog::creating(static function (AuditLog $audit) use ($scenario, $attempt): void {
        if ($audit->action !== 'commerce.order_cancelled') {
            return;
        }
        $changes = $scenario === 'cancel-attempt-state' ? ['state' => 'completed'] : ['account_id' => 'acct_Transplanted'];
        DB::table(CheckoutAttempts::TABLE)->where('id', $attempt->id)->update($changes);
    });
    try {
        app(OrderWriter::class)->cancel($request, $order->reference);
    } catch (OrderRefused $failure) {
        $refusals[] = $failure->reason;
    }
} elseif ($scenario === 'timeout' || $scenario === 'retry-rejected' || $scenario === 'local-commit') {
    CarbonImmutable::setTestNow($attempt->lease_until === null ? CarbonImmutable::now('UTC')->addSecond()
        : CheckoutAttempts::instant($attempt->lease_until)->addSecond());
    $call(fn () => $writer->refresh($request, $order->reference, $attempt->reference));
} elseif ($scenario === 'duplicate' || $scenario === 'completed') {
    $call(fn () => $writer->start($request, $order->reference, str_repeat('f', 64)));
} elseif ($scenario === 'expire') {
    $call(fn () => $writer->expire($request, $order->reference, $attempt->reference));
} elseif ($scenario === 'retrieve') {
    $call(fn () => $writer->refresh($request, $order->reference, $attempt->reference));
} elseif ($scenario === 'other-browser') {
    $fresh = OrderFixture::request();
    $call(fn () => $writer->refresh($fresh, $order->reference, $attempt->reference));
} elseif ($scenario === 'wrong-account') {
    // The provider account changed; the durable account/routing graph itself remains coherent.
    $call(fn () => $writer->refresh($request, $order->reference, $attempt->reference));
} elseif (in_array($scenario, ['wrong-witness', 'misfiled-account', 'wrong-api-version'], true)) {
    $column = match ($scenario) {
        'wrong-witness' => 'credential_witness', 'misfiled-account' => 'account_id', default => 'api_version'
    };
    $value = match ($scenario) {
        'wrong-witness' => str_repeat('b', 64), 'misfiled-account' => 'acct_Transplanted', default => '2020-01-01'
    };
    DB::table(CheckoutAttempts::TABLE)->where('id', $attempt->id)->update([$column => $value]);
    $call(fn () => $writer->refresh($request, $order->reference, $attempt->reference));
} elseif (str_starts_with($scenario, 'owner-')) {
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHours(24));
    if ($scenario === 'owner-known') {
        Fx::owner('operator@example.test');
    } elseif ($scenario === 'owner-member') {
        Fx::member('member@example.test');
    }
    $call(fn () => $writer->operateAsOwner($order->reference, $attempt->reference, 'cs_test_FixtureA'));
} elseif ($scenario === 'rejected') {
    $call(fn () => $writer->start($request, $order->reference, $review['token']));
}
$rows = DB::table(CheckoutAttempts::TABLE)->where('order_id', $order->getKey())->orderBy('id')->get();
$createRequests = array_values(array_filter($requests, static fn (array $sent): bool => $sent['path'] === '/v1/checkout/sessions'));
$sameRequests = count($createRequests) < 2 || ($createRequests[0]['body'] === $createRequests[1]['body'] && $createRequests[0]['key'] === $createRequests[1]['key']);
$firstPayload = [];
if ($createRequests !== []) {
    parse_str($createRequests[0]['body'], $firstPayload);
}
$orderUnchanged = $originalOrder === (array) DB::table('commerce_orders')->where('id', $order->getKey())->first();
$linesUnchanged = $originalLines === DB::table('commerce_order_lines')->where('order_id', $order->getKey())->get()->map(static fn (object $line): array => (array) $line)->all();
echo json_encode(['states' => $states, 'refusals' => $refusals, 'firstState' => $firstState,
    'nestedRefusals' => $nestedRefusals,
    'retainedStates' => $rows->pluck('state')->all(), 'active' => $rows->whereNotNull('active_order_id')->count(),
    'attempts' => $rows->count(), 'creates' => count($createRequests), 'sameRequests' => $sameRequests,
    'attemptsUnchangedAfterCancellation' => $attemptsBeforeCancellation === null ? null : $attemptsBeforeCancellation === $rows->map(static fn (object $row): array => (array) $row)->all(),
    'cancelAudits' => DB::table('audit_log')->where('action', 'commerce.order_cancelled')->count(),
    'createIdentities' => array_map(static fn (array $sent): array => ['key' => $sent['key'], 'hash' => hash('sha256', $sent['body'])], $createRequests),
    'storedUrlMatches' => $rows->count() === 1 && $networkSession !== null && $rows->first()->checkout_url === $networkSession['url'],
    'storedUrlLength' => $rows->count() === 1 && is_string($rows->first()->checkout_url) ? strlen($rows->first()->checkout_url) : null,
    'newRequestAfterRejection' => count($createRequests) < 2 ? null : $createRequests[0]['key'] !== $createRequests[1]['key'],
    'providerAfterSecond' => count($requests) - $beforeSecond,
    'allOutside' => array_filter($requests, static fn (array $sent): bool => $sent['level'] !== 0 || $sent['pdo']) === [],
    'allPinned' => array_filter($requests, static fn (array $sent): bool => $sent['version'] !== StripeCheckout::API_VERSION) === [],
    'gets' => count(array_filter($requests, static fn (array $sent): bool => $sent['method'] === 'GET' && str_starts_with($sent['path'], '/v1/checkout/sessions/'))),
    'expires' => count(array_filter($requests, static fn (array $sent): bool => str_ends_with($sent['path'], '/expire'))),
    'network' => count($requests), 'orderUnchanged' => $orderUnchanged, 'linesUnchanged' => $linesUnchanged,
    'outboundAmounts' => array_map(static fn (array $item): string => $item['price_data']['unit_amount'], $firstPayload['line_items'] ?? []),
    'outboundQuantities' => array_map(static fn (array $item): string => $item['quantity'], $firstPayload['line_items'] ?? []),
    'quotedTotal' => (string) $originalOrder['quoted_total_minor'], 'shippingCalls' => count($shippingRequests),
    'shippingOutside' => array_filter($shippingRequests, static fn (array $sent): bool => $sent['level'] !== 0 || $sent['pdo']) === [],
    'stock' => (string) DB::table('commerce_sellables')->where('id', $product->getKey())->value('stock'),
    'grants' => DB::table('entitlements')->count(), 'confirmed' => DB::table('commerce_orders')->where('id', $order->getKey())->value('confirmed_total_minor'),
    'level' => DB::connection()->transactionLevel(), 'pdoTransaction' => DB::connection()->getPdo()->inTransaction(),
    'audits' => DB::table('audit_log')->where('action', 'like', 'commerce.checkout_%')->pluck('action')->all()], JSON_THROW_ON_ERROR);
