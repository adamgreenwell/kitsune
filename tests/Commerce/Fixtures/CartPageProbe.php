<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Kitsune\Commerce\Blueprints\StorefrontBlueprint;
use Kitsune\Commerce\Catalogue\ProductWriter;
use Kitsune\Commerce\CommerceServiceProvider;
use Kitsune\Commerce\Http\Controllers\CartController;
use Kitsune\Commerce\Http\Controllers\CatalogueController;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Core\Readers\ReaderSessions;
use Kitsune\Core\Settings\SettingsResolver;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;

define('KITSUNE_CATALOGUE_PROBE_CLASSES_ONLY', true);
require __DIR__.'/PublicCataloguePageProbe.php';

/** A cacheable ordinary host route, declared after the fallback routes it must outrank. @internal */
final class CartProbeHostController
{
    public function __invoke(): Response
    {
        return response('The host owns this cart address.');
    }
}

/** Pause two real POSTs after their shared session is loaded, before either initializes guest ownership. @internal */
final class CartFirstRequestBarrier
{
    public static string $directory;

    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();
        file_put_contents(self::$directory.'/arrived-'.getmypid().'.json', json_encode([
            'session' => hash('sha256', $session->getId()),
            'guestStateAbsent' => ! $session->has(ReaderSessions::EPHEMERAL),
            'rememberCookieAbsent' => $request->cookie(ReaderSessions::EPHEMERAL) === null,
        ], JSON_THROW_ON_ERROR));
        $deadline = microtime(true) + 15;

        while (count(glob(self::$directory.'/arrived-*.json')) < 2) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('The first cart POST barrier timed out.');
            }

            usleep(5000);
        }

        return $next($request);
    }
}

/** The catalogue probe's native host, with independently chosen public placement. @internal */
final class CartProbeApplication
{
    public static function make(string $root, string $temporary, string $scenario, bool $seed = false): Application
    {
        $app = PublicCatalogueProbeApplication::make($root, $temporary, $scenario, $seed);
        $app->beforeBootstrapping(RegisterProviders::class, static function (Application $app) use ($temporary, $scenario): void {
            if ($scenario === 'first-concurrent' || str_starts_with($scenario, 'cookie-')) {
                $app->make('config')->set('session.driver', 'file');
                $app->make('config')->set('session.files', $temporary.'/storage/framework/sessions');
                $app->make('config')->set('session.lottery', [0, 100]);
                $app->make('config')->set('database.connections.catalogue_page_probe.busy_timeout', 5000);
            }

            if ($scenario === 'cookie-configured') {
                $app->make('config')->set('session.path', '/brand');
                $app->make('config')->set('session.domain', 'localhost');
                $app->make('config')->set('session.secure', true);
            }
        });
        CartFirstRequestBarrier::$directory = $temporary;
        $app->booted(static function () use ($scenario): void {
            Route::get('host/cart', CartProbeHostController::class)->middleware('web')->name('probe.host.cart');

            if ($scenario === 'first-concurrent') {
                foreach (Route::getRoutes()->getRoutes() as $route) {
                    if ($route->getActionName() === CartController::class.'@add') {
                        $route->middleware(CartFirstRequestBarrier::class);
                    }
                }
            }

            if (in_array($scenario, ['catalogue-only', 'cart-only'], true)) {
                $remove = $scenario === 'catalogue-only' ? CartController::class : CatalogueController::class;
                $routes = new RouteCollection;

                foreach (Route::getRoutes()->getRoutes() as $route) {
                    if (! str_starts_with($route->getActionName(), $remove)) {
                        $routes->add($route);
                    }
                }

                Route::setRoutes($routes);
            }
        });

        return $app;
    }
}

if ($step === 'seed') {
    foreach (['bootstrap/cache', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs'] as $path) {
        mkdir($temporary.'/'.$path, 0700, true);
    }

    symlink($root.'/vendor', $temporary.'/vendor');
    symlink($root.'/skeleton/app', $temporary.'/app');
    file_put_contents($temporary.'/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]], JSON_THROW_ON_ERROR));
    touch($temporary.'/catalogue.sqlite');
    file_put_contents($temporary.'/app-key', 'base64:'.base64_encode(random_bytes(32)));
    file_put_contents($temporary.'/bootstrap/app.php', '<?php return CartProbeApplication::make('.var_export($root, true).', '.var_export($temporary, true).', '.var_export($scenario, true).');');
}

if ($step === 'request' && in_array($scenario, ['disabled-at-boot', 'missing-at-boot', 'stale-at-boot'], true)) {
    $database = new PDO('sqlite:'.$temporary.'/catalogue.sqlite');
    $database->exec(match ($scenario) {
        'disabled-at-boot' => "update modules set is_enabled = 0 where handle = 'kitsune/commerce'",
        'missing-at-boot' => "delete from modules where handle = 'kitsune/commerce'",
        'stale-at-boot' => "update modules set version = 'old-version' where handle = 'kitsune/commerce'",
    });
    unset($database);
}

$app = CartProbeApplication::make($root, $temporary, $scenario, $step === 'seed');
$console = $app->make(ConsoleKernel::class);
$console->bootstrap();

if ($step === 'request' && $scenario === 'csrf-host') {
    // Laravel skips its host CSRF layer in testing; this one proves the non-testing ordering too.
    $app->detectEnvironment(static fn (): string => 'local');
}

if ($step === 'seed') {
    $exit = $console->call('migrate', ['--path' => [$root.'/packages/core/database/migrations', $root.'/tests/Commerce/migrations', $root.'/skeleton/database/migrations/0001_01_01_000020_create_readers_table.php'], '--realpath' => true, '--force' => true]);

    if ($exit !== 0) {
        throw new RuntimeException($console->output());
    }

    $org = Org::create(['slug' => 'cart-page', 'name' => 'Cart org', 'currency' => 'USD']);
    app(Context::class)->setOrg($org);
    $base = match ($scenario) {
        'root-host' => 'https://cart.example.test',
        'deep-prefix' => '/news/fr/x/y',
        'panel-path', 'panel-domain' => '/cp/admin/site',
        default => '/brand',
    };
    $site = Site::create(['handle' => 'main', 'slug' => 'cart-main', 'name' => 'Brand & <site>', 'locale' => $scenario === 'arabic' ? 'ar' : 'en', 'base_url' => $base]);
    $sibling = Site::create(['handle' => 'sibling', 'slug' => 'cart-sibling', 'name' => 'Sibling', 'locale' => 'en', 'base_url' => '/sibling']);
    app(Context::class)->setSite($site);
    ModuleLifecycle::install(app(), 'kitsune/commerce');
    ModuleLifecycle::enable(app(), 'kitsune/commerce');
    ModuleKernel::boot(app());
    BlueprintApplier::apply(new StorefrontBlueprint);
    $writer = app(ProductWriter::class);
    $row = $writer->createProduct(['sku' => 'SURF', 'price_minor' => '2000', 'stock' => '5'], 'USD', [
        'title' => 'Surf course', 'slug' => 'surf', 'status' => 'published', 'values' => [],
    ], [], true);
    $writer->createProduct(['sku' => 'BOARD', 'price_minor' => '1250', 'stock' => null], 'USD', [
        'title' => 'Board guide', 'slug' => 'board', 'status' => 'published', 'values' => [],
    ], [], true);
    app(Context::class)->setSite($sibling);
    $writer->updateProduct($row->id, ['sku' => 'SURF', 'price_minor' => '2000', 'stock' => '5'], 'USD', [
        'title' => 'Sibling course', 'slug' => 'sibling-surf', 'status' => 'published', 'values' => [],
    ], [], true);
    ReaderFixture::reader($org, 'cart-private-reader@example.test');
    echo json_encode(['seeded' => true], JSON_THROW_ON_ERROR);
    exit;
}

if ($step === 'cache') {
    if ($console->call('route:cache') !== 0 || ! $app->routesAreCached()) {
        throw new RuntimeException('The cart probe could not cache its routes: '.$console->output());
    }

    echo json_encode(['cached' => true], JSON_THROW_ON_ERROR);
    exit;
}

$site = DB::table('sites')->where('slug', 'cart-main')->first();
$prefix = $site->path_prefix ?? '';
$cartPath = $prefix.'/cart';
$origin = match ($scenario) {
    'root-host' => 'https://cart.example.test',
    'cookie-https' => 'https://localhost',
    default => 'http://localhost',
};
$listing = DB::table('commerce_listings')->where('site_id', $site->id)->orderBy('id')->value('id');
$jar = [];
$responses = [];
$token = '';
$evidence = [];

/** Follow the real encrypted session cookie between native HTTP requests, as a browser does. */
$send = static function (string $method, string $path, array $body = [], array $server = []) use ($app, &$jar, &$responses, &$token, $origin): array {
    ReaderFixture::forget();
    // A native host normally ends here; reset only request-local instances while retaining the actual session handler.
    $app->make('session.store')->flush();

    foreach (Cookie::getQueuedCookies() as $queued) {
        Cookie::unqueue($queued->getName(), $queued->getPath());
    }

    $request = Request::create(str_starts_with($path, 'http') ? $path : $origin.$path, $method, $body, $jar, [], $server);
    $http = $app->make(HttpKernel::class);
    $response = $http->handle($request);
    $http->terminate($request, $response);

    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() <= time()) {
            unset($jar[$cookie->getName()]);
        } else {
            $jar[$cookie->getName()] = $cookie->getValue();
        }
    }

    if ($request->hasSession()) {
        $token = (string) $request->session()->token();
    }

    $headers = $response->headers->all();
    unset($headers['set-cookie']);
    $cookies = array_map(static fn ($cookie): array => ['name' => $cookie->getName(), 'expires' => $cookie->getExpiresTime(),
        'httpOnly' => $cookie->isHttpOnly(), 'secure' => $cookie->isSecure(), 'sameSite' => $cookie->getSameSite(),
        'path' => $cookie->getPath(), 'domain' => $cookie->getDomain()], $response->headers->getCookies());
    $result = ['status' => $response->getStatusCode(), 'body' => $response->getContent(), 'headers' => $headers, 'cookies' => $cookies,
        'query' => $request->query->all(), 'input' => $request->request->all(), 'rawBody' => $request->getContent(),
        'previous' => $request->hasSession() ? $request->session()->get('_previous.url') : null,
        'oldInput' => $request->hasSession() ? $request->session()->get('_old_input') : null];
    $responses[] = $result;

    return $result;
};

$add = static function (string $quantity = '2', ?string $path = null, ?string $id = null) use ($send, &$token, $listing, $cartPath): array {
    return $send('POST', $path ?? $cartPath.'/add', ['_token' => $token, 'listing' => $id ?? (string) $listing, 'quantity' => $quantity]);
};
$line = static fn (): string => (string) DB::table('commerce_cart_lines')->orderBy('id')->value('id');
$version = static fn (): string => (string) DB::table('commerce_carts')->orderBy('id')->value('version');

if ($step === 'race-request') {
    $transport = json_decode((string) file_get_contents($temporary.'/transport.json'), true, flags: JSON_THROW_ON_ERROR);
    $jar = $transport['cookies'];
    $token = $transport['token'];
    $response = $add('1', id: $argv[5]);
    echo json_encode(['status' => $response['status']], JSON_THROW_ON_ERROR);
    exit;
}

$send('GET', $cartPath);

if (in_array($scenario, ['settings-outage', 'settings-expired-token'], true)) {
    app(SettingsResolver::class)->forget();
    DB::connection()->beforeExecuting(static function (string $query, array $bindings): void {
        if (str_starts_with(strtolower($query), 'select') && str_contains(strtolower($query), 'settings')) {
            $failure = new PDOException('cart-private-reader@example.test settings fixture failure');
            $failure->errorInfo = ['HY000', 1, 'cart-private-reader@example.test settings fixture failure'];
            throw new QueryException('catalogue_page_probe', $query, $bindings, $failure);
        }
    });
}

switch ($scenario) {
    case 'first-concurrent':
        // Only the host's ordinary CSRF session exists. No random cart secret is pre-seeded as in writer probes.
        file_put_contents($temporary.'/transport.json', json_encode(['cookies' => $jar, 'token' => $token], JSON_THROW_ON_ERROR));
        $processes = [];

        foreach (DB::table('commerce_listings')->where('site_id', $site->id)->orderBy('id')->pluck('id') as $id) {
            $process = new Process([PHP_BINARY, __FILE__, $root, $temporary, 'race-request', $scenario, (string) $id], $root);
            $process->setTimeout(30);
            $process->start();
            $processes[] = $process;
        }

        foreach ($processes as $process) {
            $process->wait();

            if (! $process->isSuccessful()) {
                throw new RuntimeException('A first cart POST process failed: '.substr($process->getErrorOutput(), -2000));
            }

            $evidence['postStatuses'][] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'];
        }

        $arrivals = array_map(static fn (string $path): array => json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR), glob($temporary.'/arrived-*.json'));
        $evidence += ['sameSession' => count(array_unique(array_column($arrivals, 'session'))) === 1,
            'guestStateAbsent' => array_column($arrivals, 'guestStateAbsent'),
            'rememberCookieAbsent' => array_column($arrivals, 'rememberCookieAbsent'),
            'cartCount' => DB::table('commerce_carts')->count()];
        $send('GET', $cartPath);
        break;
    case 'cookie-recovery':
    case 'cookie-missing':
    case 'cookie-forged':
    case 'cookie-expired-cart':
    case 'cookie-https':
    case 'cookie-configured':
        $add();
        $evidence['expiryBefore'] = DB::table('commerce_carts')->value('expires_at');
        $evidence['cookieEncrypted'] = isset($jar[ReaderSessions::EPHEMERAL]) && preg_match('/\A[a-f0-9]{64}\z/D', $jar[ReaderSessions::EPHEMERAL]) !== 1;
        // Exceed the normal two-hour auth session, delete its backing file and retain only the encrypted cart cookie.
        $evidence['sessionLifetime'] = (int) config('session.lifetime');
        foreach (glob($temporary.'/storage/framework/sessions/*') as $path) {
            unlink($path);
        }
        unset($jar[config('session.cookie')]);

        if ($scenario === 'cookie-missing') {
            unset($jar[ReaderSessions::EPHEMERAL]);
        } elseif ($scenario === 'cookie-forged') {
            $jar[ReaderSessions::EPHEMERAL] = str_repeat('a', 64);
        }

        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHours($scenario === 'cookie-expired-cart' ? 31 * 24 : 3));
        $send('GET', $cartPath);
        $evidence['expiryAfter'] = DB::table('commerce_carts')->value('expires_at');
        CarbonImmutable::setTestNow();
        break;
    case 'guest-flow':
        $send('GET', $prefix.'/catalogue');
        $add();
        $send('GET', $cartPath);
        $add('1');
        $send('GET', $cartPath);
        $send('POST', $cartPath.'/update', ['_token' => $token, 'line' => $line(), 'quantity' => '3', 'version' => $version()]);
        $send('GET', $cartPath);
        $send('POST', $cartPath.'/remove', ['_token' => $token, 'line' => $line(), 'version' => $version()]);
        $send('GET', $cartPath);
        break;
    case 'csrf':
    case 'csrf-host':
        foreach ([[], ['_token' => 'wrong-token'], ['_token' => [$token]]] as $body) {
            $server = $scenario === 'csrf-host' ? [] : ['HTTP_X_CSRF_TOKEN' => $token, 'HTTP_SEC_FETCH_SITE' => 'same-origin'];
            $send('POST', $cartPath.'/add?_token='.urlencode($token), [...$body, 'listing' => (string) $listing, 'quantity' => '1'], $server);
        }
        break;
    case 'invalid':
        foreach (['0', '01', '1.0', '100', '1e0', ['1']] as $quantity) {
            $send('POST', $cartPath.'/add', ['_token' => $token, 'listing' => (string) $listing, 'quantity' => $quantity, 'password' => 'private-body-secret']);
        }
        break;
    case 'query-input':
        $send('GET', $cartPath.'?reader=cart-private-reader%40example.test&cart=private-cart-id');
        $send('POST', $cartPath.'/add?listing='.$listing.'&quantity=1&password=private-query-secret', ['_token' => $token, 'password' => 'private-body-secret']);
        break;
    case 'cross-site':
        $add();
        $send('GET', '/sibling/cart');
        $add('1', '/sibling/cart/add');
        $send('GET', $cartPath);
        break;
    case 'stale':
        $add();
        $oldVersion = $version();
        $send('POST', $cartPath.'/update', ['_token' => $token, 'line' => $line(), 'quantity' => '3', 'version' => $oldVersion]);
        $send('POST', $cartPath.'/update', ['_token' => $token, 'line' => $line(), 'quantity' => '4', 'version' => $oldVersion]);
        $send('POST', $cartPath.'/remove', ['_token' => $token, 'line' => $line(), 'version' => $oldVersion]);
        $send('GET', $cartPath);
        break;
    case 'unavailable':
        $add();
        $type = DB::table('entry_types')->where('org_id', $site->org_id)->where('handle', 'product')->first();
        app(Context::class)->setSite(Site::withoutScopeBecause('a native cart page fixture', fn ($q) => $q->findOrFail($site->id)));
        EntryTypeAvailability::create(['entry_type_id' => $type->id, 'scope_type' => 'site', 'scope_id' => $site->id, 'is_enabled' => false]);
        $send('GET', $cartPath);
        $send('POST', $cartPath.'/update', ['_token' => $token, 'line' => $line(), 'quantity' => '3', 'version' => $version()]);
        $send('POST', $cartPath.'/remove', ['_token' => $token, 'line' => $line(), 'version' => $version()]);
        $send('GET', $cartPath);
        break;
    case 'get-mutations':
        foreach (['add', 'update', 'remove'] as $action) {
            $send('GET', $cartPath.'/'.$action.'?listing='.$listing.'&quantity=1');
        }
        break;
    case 'disabled':
        DB::table('modules')->where('handle', 'kitsune/commerce')->update(['is_enabled' => false]);
        $send('GET', $cartPath);
        break;
    case 'cart-outage':
        $add();
        DB::statement('alter table commerce_carts rename to commerce_carts_elsewhere');
        $send('GET', $cartPath.'?reader=cart-private-reader%40example.test');
        break;
    case 'corrupt-child':
        $add();
        $other = Org::create(['slug' => 'corrupt-rival', 'name' => 'Corrupt rival']);
        DB::table('commerce_cart_lines')->where('id', $line())->update(['org_id' => $other->id]);
        $send('GET', $cartPath);
        break;
    case 'site-outage':
        DB::statement('alter table sites rename to sites_elsewhere');
        $send('GET', $cartPath.'?reader=cart-private-reader%40example.test');
        break;
    case 'module-outage':
        DB::statement('alter table modules rename to modules_elsewhere');
        $send('GET', $cartPath.'?reader=cart-private-reader%40example.test');
        break;
    case 'settings-outage':
        $send('GET', $cartPath);
        break;
    case 'settings-expired-token':
        $send('POST', $cartPath.'/add', ['listing' => (string) $listing, 'quantity' => '1']);
        break;
    case 'root-host':
        $send('GET', 'https://elsewhere.example.test/cart');
        break;
    case 'host-route':
        $send('GET', '/host/cart');
        $send('GET', '/admin/cart-main');
        break;
    case 'catalogue-only':
        $send('GET', $prefix.'/catalogue');
        break;
    case 'cart-only':
        break;
    case 'arabic':
        $add();
        $send('GET', $cartPath);
        break;
}

echo json_encode(['responses' => $responses, 'cached' => $app->routesAreCached(), 'provider' => count($app->getProviders(CommerceServiceProvider::class)),
    'evidence' => $evidence, 'rememberCookieName' => ReaderSessions::EPHEMERAL,
    'lines' => DB::table('commerce_cart_lines')->get(['id', 'site_id', 'listing_id', 'quantity'])->all(),
    'logs' => is_file($temporary.'/storage/logs/probe.log') ? file_get_contents($temporary.'/storage/logs/probe.log') : ''], JSON_THROW_ON_ERROR);
