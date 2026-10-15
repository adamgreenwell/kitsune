<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

[$script, $root, $temporary, $step, $scenario] = $argv;
require $root.'/vendor/autoload.php';

use App\Models\Reader;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use Carbon\CarbonImmutable;
use Filament\Panel;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Kitsune\Commerce\Blueprints\StorefrontBlueprint;
use Kitsune\Commerce\Catalogue\ProductWriter;
use Kitsune\Commerce\CommerceServiceProvider;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Entitlements\UtcInstant;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Symfony\Component\HttpFoundation\Response;

/** Real Filament routes and registry, with the host's chosen path/domain. @internal */
final class PublicCatalogueProbePanelProvider extends AdminPanelProvider
{
    public static string $scenario;

    public function panel(Panel $panel): Panel
    {
        $panel = parent::panel($panel);

        return match (self::$scenario) {
            'panel-path' => $panel->path('cp/admin'),
            'panel-domain' => $panel->path('cp/admin')->domain('admin.example.test'),
            'panel-root' => $panel->path(''),
            default => $panel,
        };
    }
}

/** A serializable ordinary host route, deliberately declared after the catalogue fallback. @internal */
final class PublicCatalogueProbeHostController
{
    public function __invoke(): Response
    {
        return response('The host owns this route.');
    }
}

/** A complete, temporary native application: route:cache boots it again through bootstrap/app.php. @internal */
final class PublicCatalogueProbeApplication
{
    public static function make(string $root, string $temporary, string $scenario, bool $seed = false): Application
    {
        PublicCatalogueProbePanelProvider::$scenario = $scenario;
        $app = Application::configure(basePath: $temporary)
            ->withProviders($seed ? [AppServiceProvider::class] : [AppServiceProvider::class, PublicCatalogueProbePanelProvider::class])
            ->withRouting(using: static function () use ($root): void {
                Route::middleware('web')->group($root.'/skeleton/routes/web.php');
                Route::get('host/catalogue', PublicCatalogueProbeHostController::class)->middleware('web')->name('probe.host.catalogue');
            })
            ->withMiddleware()
            ->withExceptions()
            ->create();
        $app->useConfigPath($root.'/skeleton/config');
        $app->beforeBootstrapping(RegisterProviders::class, static function (Application $app) use ($root, $temporary): void {
            $app->detectEnvironment(static fn (): string => 'testing');
            $app->make('config')->set('database.default', 'catalogue_page_probe');
            $app->make('config')->set('database.connections.catalogue_page_probe', [
                'driver' => 'sqlite', 'database' => $temporary.'/catalogue.sqlite', 'prefix' => '', 'foreign_key_constraints' => true,
            ]);
            $app->make('config')->set('app.key', file_get_contents($temporary.'/app-key'));
            $app->make('config')->set('app.debug', false);
            $app->make('config')->set('app.url', 'https://application.example.test');
            $app->make('config')->set('cache.default', 'array');
            $app->make('config')->set('session.driver', 'array');
            $app->make('config')->set('hashing.bcrypt.rounds', 4);
            $app->make('config')->set('logging.default', 'probe');
            $app->make('config')->set('logging.channels.probe', ['driver' => 'single', 'path' => $temporary.'/storage/logs/probe.log', 'level' => 'debug']);
            $app->make('config')->set('view.paths', [$root.'/skeleton/resources/views']);
            $app->make('config')->set('filesystems.disks.local.root', $temporary.'/storage/private');
            $app->make('config')->set('filesystems.disks.public', ['driver' => 'local', 'root' => $temporary.'/storage/public', 'url' => 'https://application.example.test/storage', 'visibility' => 'public']);
        });

        return $app;
    }
}

// Cart HTTP coverage reuses this real host bootstrap without executing this probe's catalogue scenarios.
if (defined('KITSUNE_CATALOGUE_PROBE_CLASSES_ONLY') && KITSUNE_CATALOGUE_PROBE_CLASSES_ONLY === true) {
    return;
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
    file_put_contents($temporary.'/bootstrap/app.php', '<?php return PublicCatalogueProbeApplication::make('.var_export($root, true).', '.var_export($temporary, true).', '.var_export($scenario, true).');');
}

if ($step === 'request' && in_array($scenario, ['disabled-at-boot', 'stale-at-boot', 'missing-at-boot'], true)) {
    $database = new PDO('sqlite:'.$temporary.'/catalogue.sqlite');
    $database->exec(match ($scenario) {
        'disabled-at-boot' => "update modules set is_enabled = 0 where handle = 'kitsune/commerce'",
        'stale-at-boot' => "update modules set version = '0.0.0-stale' where handle = 'kitsune/commerce'",
        'missing-at-boot' => "delete from modules where handle = 'kitsune/commerce'",
    });
    unset($database);
}

$app = PublicCatalogueProbeApplication::make($root, $temporary, $scenario, $step === 'seed');
$console = $app->make(ConsoleKernel::class);
$console->bootstrap();

if ($step === 'seed') {
    $exit = $console->call('migrate', ['--path' => [$root.'/packages/core/database/migrations', $root.'/tests/Commerce/migrations', $root.'/skeleton/database/migrations/0001_01_01_000020_create_readers_table.php'], '--realpath' => true, '--force' => true]);

    if ($exit !== 0) {
        throw new RuntimeException($console->output());
    }

    $org = Org::create(['slug' => 'catalogue-page-probe', 'name' => 'Catalogue org', 'currency' => 'USD']);
    app(Context::class)->setOrg($org);
    $baseUrl = match ($scenario) {
        'root-host' => 'https://catalogue.example.test',
        'named-host' => 'https://brand.example.test/shop',
        'deep-prefix' => '/news/fr/x/y',
        'panel-path', 'panel-domain' => '/cp/admin/site',
        default => '/brand',
    };
    $site = Site::create(['handle' => 'main', 'slug' => 'catalogue-main', 'name' => 'Brand & <site>', 'locale' => $scenario === 'arabic' ? 'ar' : 'en', 'base_url' => $baseUrl]);
    app(Context::class)->setSite($site);
    ModuleLifecycle::install(app(), 'kitsune/commerce');
    ModuleLifecycle::enable(app(), 'kitsune/commerce');
    // The module is installed after this fresh application's boot; register its real provider through its kernel.
    ModuleKernel::boot(app());
    BlueprintApplier::apply(new StorefrontBlueprint);
    $writer = app(ProductWriter::class);
    $count = in_array($scenario, ['pagination', 'unknown-query'], true) ? 25 : 1;

    foreach (range(1, $count) as $number) {
        $row = $writer->createProduct([
            'sku' => sprintf('SURF-%02d', $number), 'price_minor' => '2000', 'stock' => $scenario === 'sold-out' ? '0' : null,
            'entitlement_name' => $scenario === 'unmapped' ? null : 'course.surf-101',
            'offer_terms' => match ($scenario) {
                'unmapped', 'unknown-offer' => null,
                'fixed-offer' => ['version' => 1, 'kind' => 'fixed', 'days' => 30],
                default => ['version' => 1, 'kind' => 'permanent'],
            },
        ], 'USD', [
            'title' => $scenario === 'escaped' ? 'Surf <script>alert("title")</script>' : 'Surf course '.$number,
            'slug' => 'surf-'.$number, 'status' => 'published',
            'values' => ['product_description' => match ($scenario) {
                'escaped' => '<p>Safe &amp; sound</p><script>alert("description")</script>',
                'bounded-description' => '<p>BEGIN'.str_repeat('a', 10000).'END</p>',
                default => '<p>Catch a wave.</p>',
            }],
        ], [], true);
    }

    if ($scenario === 'image') {
        $type = EntryType::query()->where('org_id', $org->getKey())->where('handle', 'product_image')->firstOrFail();
        $images = [];

        foreach (['private', 'public'] as $visibility) {
            $image = Entry::create(['entry_type_id' => $type->getKey(), 'site_id' => null, 'title' => $visibility.' image']);
            MediaFile::create(['entry_id' => $image->getKey(), 'disk' => $visibility === 'public' ? 'public' : MediaDisks::PRIVATE,
                'path' => 'media/catalogue-'.$visibility.'.png', 'mime' => 'image/png', 'size_bytes' => 1, 'checksum' => str_repeat('a', 64), 'visibility' => $visibility]);
            $images[] = $image->getKey();
        }

        $field = FieldStorage::query()->where('org_id', $org->getKey())->where('handle', 'product_images')->firstOrFail();
        $row->listings()->firstOrFail()->entry()->firstOrFail()->syncFieldRelations($field, $images);
    }

    $reader = ReaderFixture::reader($org, 'private-reader@example.test');
    DB::table('entitlements')->insert(['org_id' => $org->getKey(), 'site_id' => $site->getKey(), 'reader_id' => (string) $reader->getKey(),
        'entitlement' => 'course.surf-101', 'source' => 'commerce.order:private-order', 'expires_at' => null, 'revoked_at' => null,
        'changed_at' => UtcInstant::stored(CarbonImmutable::now('UTC'))]);

    echo json_encode(['seeded' => true], JSON_THROW_ON_ERROR);
    exit;
}

if ($step === 'cache') {
    $exit = $console->call('route:cache');

    if ($exit !== 0 || ! $app->routesAreCached()) {
        throw new RuntimeException('The catalogue probe could not cache its real routes: '.$console->output());
    }

    echo json_encode(['cached' => true], JSON_THROW_ON_ERROR);
    exit;
}

$org = Org::query()->where('slug', 'catalogue-page-probe')->firstOrFail();
app(Context::class)->setOrg($org);
$site = Site::query()->where('slug', 'catalogue-main')->firstOrFail();
app(Context::class)->setSite($site);

if (in_array($scenario, ['held', 'entitlement-outage', 'reader-outage'], true)) {
    $org->update(['settings' => ['reader_accounts' => 'sign-in']]);
    $reader = Reader::query()->firstOrFail();
    ReaderFixture::signIn($reader);
}

if ($scenario === 'disabled') {
    DB::table('modules')->where('handle', 'kitsune/commerce')->update(['is_enabled' => false]);
} elseif ($scenario === 'missing-module') {
    DB::table('modules')->where('handle', 'kitsune/commerce')->delete();
} elseif ($scenario === 'stale-version') {
    DB::table('modules')->where('handle', 'kitsune/commerce')->update(['version' => '0.0.0-stale']);
} elseif ($scenario === 'entitlement-outage') {
    DB::statement('alter table entitlements rename to entitlements_elsewhere');
} elseif ($scenario === 'reader-outage') {
    DB::statement('alter table readers rename to readers_elsewhere');
    Auth::forgetGuards();
} elseif ($scenario === 'site-outage') {
    DB::statement('alter table sites rename to sites_elsewhere');
} elseif ($scenario === 'module-outage') {
    DB::statement('alter table modules rename to modules_elsewhere');
} elseif ($scenario === 'catalogue-outage') {
    DB::statement('alter table commerce_listings rename to commerce_listings_elsewhere');
} elseif ($scenario === 'disabled-type') {
    $type = EntryType::query()->where('org_id', $org->getKey())->where('handle', 'product')->firstOrFail();
    EntryTypeAvailability::create(['entry_type_id' => $type->getKey(), 'scope_type' => 'site', 'scope_id' => $site->getKey(), 'is_enabled' => false]);
}

$paths = match ($scenario) {
    'root-host' => ['https://catalogue.example.test/catalogue', 'https://elsewhere.example.test/catalogue'],
    'named-host' => ['https://brand.example.test/shop/catalogue', 'https://elsewhere.example.test/shop/catalogue'],
    'deep-prefix' => ['/news/fr/x/y/catalogue'],
    'panel-path' => ['/cp/admin/site/catalogue'],
    'panel-domain' => ['http://localhost/cp/admin/site/catalogue', 'https://admin.example.test/cp/admin/site/catalogue'],
    'host-route' => ['/host/catalogue', '/admin/catalogue-main'],
    'unknown-query' => ['/BRAND/catalogue?page=1&reader=private-reader%40example.test&source=commerce.order%3Aprivate-order'],
    'site-outage', 'module-outage' => ['/brand/catalogue?reader=private-reader%40example.test&source=commerce.order%3Aprivate-order'],
    'invalid-page' => ['/brand/catalogue?page=0', '/brand/catalogue?page=01', '/brand/catalogue?page=1.0', '/brand/catalogue?page=-1', '/brand/catalogue?page=10001', '/brand/catalogue?page[]=1'],
    'pagination' => ['/brand/catalogue', '/brand/catalogue?page=2', '/brand/catalogue?page=10000'],
    'missing-site' => ['/missing/catalogue', '/catalogue-main/catalogue', '/brand/deeper/catalogue'],
    default => ['/brand/catalogue'],
};
$responses = [];

foreach ($paths as $path) {
    ReaderFixture::forget();
    $request = Request::create($path, 'GET');
    $http = $app->make(HttpKernel::class);
    $response = $http->handle($request);
    $http->terminate($request, $response);
    $responses[] = ['status' => $response->getStatusCode(), 'headers' => $response->headers->all(), 'body' => $response->getContent(),
        'previous' => app('session.store')->get('_previous.url'), 'query' => $request->query->all(), 'requestUri' => $request->server->get('REQUEST_URI')];
}

echo json_encode(['responses' => $responses, 'cached' => $app->routesAreCached(), 'provider' => count($app->getProviders(CommerceServiceProvider::class)),
    'retained_products' => DB::table('commerce_sellables')->count(), 'logs' => is_file($temporary.'/storage/logs/probe.log') ? file_get_contents($temporary.'/storage/logs/probe.log') : ''], JSON_THROW_ON_ERROR);
