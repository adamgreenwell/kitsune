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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Payments\StripeCheckout;
use Kitsune\Commerce\Payments\StripeEventInbox;
use Kitsune\Core\Credentials\CredentialConsoleAuthority;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\KitsuneServiceProvider;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\PaidOrderFixture;
use Kitsune\Core\Tests\Fixtures\StripeInboxFixture;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;
use Psr\Http\Message\RequestInterface;

[$script, $root, $directory, $replayScenario] = $argv;
if (str_starts_with($replayScenario, 'resume')) {
    require $root.'/vendor/autoload.php';
    $path = $directory.'/resume-app';
    foreach (['bootstrap/cache', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs'] as $part) {
        mkdir($path.'/'.$part, 0700, true);
    }
    $application = new class($path) extends TestbenchApplication
    {
        public string $database;

        protected function defineEnvironment($app): void
        {
            $app->detectEnvironment(static fn (): string => 'testing');
            $app['config']->set('database.default', 'replay');
            $app['config']->set('database.connections.replay', ['driver' => 'sqlite', 'database' => $this->database,
                'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 5000]);
            $app['config']->set('cache.default', 'array');
            $app['config']->set('session.driver', 'array');
            $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('w', 32)));
        }
    };
    $application->database = $directory.'/writer.sqlite';
    $app = $application->configure(['extra' => ['providers' => [BladeIconsServiceProvider::class, BladeHeroiconsServiceProvider::class,
        KitsuneServiceProvider::class], 'dont-discover' => ['*']]])->createApplication();
    Fx::boot();
    CarbonImmutable::setTestNow('2026-10-14 12:00:31 UTC');
    $seed = json_decode(file_get_contents($directory.'/replay-seed.json'), true, flags: JSON_THROW_ON_ERROR);
    $org = Org::query()->findOrFail($seed['org']);
    app(Context::class)->setOrg($org);
    $site = Site::query()->findOrFail($seed['site']);
    app(Context::class)->setSite($site);
    ModuleKernel::boot($app);
    $order = Order::query()->where('reference', $seed['orderReference'])->firstOrFail();
    $attempt = DB::table(CheckoutAttempts::TABLE)->where('order_id', $order->getKey())->sole();
    $networkSession = $seed['networkSession'];
    $beforeOrder = $seed['beforeOrder'];
    $beforeLines = $seed['beforeLines'];
    $productId = $seed['product'];
    $receiptId = $seed['receipt'];
} else {
    // Actual #197 creation/commits are fixture setup; replay uses the real inbox and concrete Stripe GET parser.
    $argv[3] = 'duplicate';
    ob_start();
    require __DIR__.'/CheckoutWriterProbe.php';
    ob_end_clean();
    $beforeOrder = (array) DB::table('commerce_orders')->where('id', $order->getKey())->first();
    $beforeLines = DB::table('commerce_order_lines')->where('order_id', $order->getKey())->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $productId = $product->getKey();
    $event = ['id' => 'evt_ReplayFixture', 'type' => $replayScenario === 'expiry-first' ? 'checkout.session.expired' : 'checkout.session.completed',
        'apiVersion' => StripeCheckout::API_VERSION, 'sessionId' => 'cs_test_FixtureA', 'orderReference' => $order->reference,
        'attemptReference' => $attempt->reference, 'test' => true];
    $receipt = app(StripeEventInbox::class)->capture($org->getKey(), $event, '{"private":"verified replay fixture"}', CarbonImmutable::now('UTC'));
    $receiptId = $receipt['id'];
    if (in_array($replayScenario, ['kill', 'command-seed'], true)) {
        file_put_contents($directory.'/replay-seed.json', json_encode(['org' => $org->getKey(), 'site' => $site->getKey(),
            'orderReference' => $order->reference, 'product' => $productId, 'receipt' => $receiptId,
            'networkSession' => $networkSession, 'beforeOrder' => $beforeOrder, 'beforeLines' => $beforeLines], JSON_THROW_ON_ERROR));
        if ($replayScenario === 'command-seed') {
            echo '{"seeded":true}';
            exit;
        }
    }
    if ($replayScenario === 'expired-raw') {
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDays(8));
        app(StripeEventInbox::class)->prune(1);
    }
}
$calls = [];
$sessionMap = [];
if ($replayScenario === 'fairness') {
    CredentialConsoleAuthority::run(app(Context::class), 'set', StripeCheckout::SLOT, CredentialMode::Test, null,
        static fn (CredentialConsoleAuthority $authority) => app(CredentialWriter::class)->set(StripeCheckout::SLOT, CredentialMode::Test,
            'sk_test_FakeReplay', console: $authority));
    $secondAttempt = StripeInboxFixture::attempt($site, route: false);
    $credential = DB::table('credentials')->where('org_id', $org->getKey())->where('slot', StripeCheckout::SLOT)->where('mode', 'test')->sole();
    $witness = hash('sha256', implode("\0", [(string) $credential->id, (string) $credential->org_id, $credential->slot,
        $credential->mode, $credential->ciphertext, $credential->key_id, $credential->changed_at]));
    DB::table(CheckoutAttempts::TABLE)->where('id', $secondAttempt->id)->update(['credential_witness' => $witness, 'account_id' => 'acct_FixtureA']);
    $secondAttempt = DB::table(CheckoutAttempts::TABLE)->where('id', $secondAttempt->id)->sole();
    DB::transaction(fn () => app(StripeEventInbox::class)->registerAttempt(DB::connection(), $secondAttempt));
    $secondEvent = StripeInboxFixture::event($secondAttempt, 'evt_FairLaterReceipt');
    $secondReceipt = app(StripeEventInbox::class)->capture($org->getKey(), $secondEvent, '{"verified":"later receipt"}', CarbonImmutable::now('UTC'));
    $secondPayload = CheckoutAttempts::request($secondAttempt);
    $sessionMap[$secondAttempt->session_id] = [...$networkSession, 'id' => $secondAttempt->session_id,
        'client_reference_id' => $secondPayload['client_reference_id'], 'metadata' => $secondPayload['metadata'],
        'expires_at' => $secondPayload['expires_at'], 'amount_total' => array_sum(array_column(array_column($secondPayload['line_items'], 'price_data'), 'unit_amount'))];
}
$nested = null;
$failOnce = $replayScenario === 'provider-failure';
$http = static function (RequestInterface $request) use (&$calls, &$nested, &$failOnce, $networkSession, $sessionMap, $replayScenario, $directory, $receiptId): Response {
    $calls[] = ['method' => $request->getMethod(), 'path' => $request->getUri()->getPath(),
        'level' => DB::connection()->transactionLevel(), 'pdo' => DB::connection()->getPdo()->inTransaction()];
    if ($request->getUri()->getPath() === '/v1/account') {
        return new Response(200, ['Content-Type' => 'application/json'], '{"object":"account","id":"acct_FixtureA"}');
    }
    if ($replayScenario === 'kill') {
        file_put_contents($directory.'/processing-ready', 'ready');
        sleep(60);
    }
    if ($failOnce) {
        $failOnce = false;
        throw new ConnectException('private provider outage', $request);
    }
    if ($replayScenario === 'nested') {
        $nested = app(StripeEventInbox::class)->process($receiptId);
    }
    $selected = $sessionMap[basename($request->getUri()->getPath())] ?? $networkSession;
    $session = [...$selected, 'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_ReplayFixture', 'url' => null];

    return new Response(200, ['Content-Type' => 'application/json'], json_encode(PaidOrderFixture::expanded($session), JSON_THROW_ON_ERROR));
};
$app->instance(StripeCheckout::class, new StripeCheckout(new Client(['handler' => HandlerStack::create(new MockHandler(array_fill(0, 20, $http)))])));
$inbox = app(StripeEventInbox::class);
$initialState = DB::table(StripeEventInbox::TABLE)->where('id', $receiptId)->value('state');
$states = [];
$commandStatus = null;
if ($replayScenario === 'fairness') {
    $inbox->replay(1);
    $states[] = DB::table(StripeEventInbox::TABLE)->where('id', $receiptId)->value('state');
    $inbox->replay(1);
    $states[] = DB::table(StripeEventInbox::TABLE)->where('id', $secondReceipt['id'])->value('state');
} elseif ($replayScenario === 'resume-command') {
    $commandStatus = Artisan::call('kitsune:stripe-replay', ['--org' => $org->slug, '--limit' => '1', '--seconds' => '1']);
    $states[] = DB::table(StripeEventInbox::TABLE)->where('id', $receiptId)->value('state');
} else {
    $states[] = $inbox->process($receiptId);
    if ($replayScenario === 'provider-failure' || $replayScenario === 'duplicate') {
        $states[] = $inbox->process($receiptId);
    }
    if (in_array($replayScenario, ['different-events', 'expiry-first'], true)) {
        $other = [...$event, 'id' => 'evt_ReplaySecond', 'type' => $event['type'] === 'checkout.session.expired' ? 'checkout.session.completed' : 'checkout.session.expired'];
        $second = $inbox->capture($org->getKey(), $other, '{"verified":"second event"}', CarbonImmutable::now('UTC'));
        $states[] = $inbox->process($second['id']);
    }
}
echo json_encode(['initial' => $initialState, 'states' => $states, 'nested' => $nested, 'commandStatus' => $commandStatus,
    'calls' => $calls, 'receiptStates' => DB::table(StripeEventInbox::TABLE)->orderBy('id')->pluck('state')->all(),
    'rawCleared' => DB::table(StripeEventInbox::TABLE)->where('id', $receiptId)->value('raw_payload') === null,
    'attemptState' => DB::table(CheckoutAttempts::TABLE)->where('id', $attempt->id)->value('state'),
    'orderHistoryUnchanged' => array_diff_key($beforeOrder, array_flip(['state', 'active_cart_key', 'confirmed_total_minor'])) == array_diff_key((array) DB::table('commerce_orders')->where('id', $order->getKey())->first(), array_flip(['state', 'active_cart_key', 'confirmed_total_minor'])),
    'linesUnchanged' => $beforeLines == DB::table('commerce_order_lines')->where('order_id', $order->getKey())->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
    'stock' => (string) DB::table('commerce_sellables')->where('id', $productId)->value('stock'), 'grants' => DB::table('entitlements')->count(),
    'paymentReceipts' => DB::table('commerce_payment_receipts')->count(),
    'orderState' => DB::table('commerce_orders')->where('id', $order->getKey())->value('state'),
    'confirmed' => DB::table('commerce_orders')->where('id', $order->getKey())->value('confirmed_total_minor'),
    'level' => DB::connection()->transactionLevel(), 'pdo' => DB::connection()->getPdo()->inTransaction()], JSON_THROW_ON_ERROR);
