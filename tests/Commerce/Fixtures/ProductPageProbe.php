<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

[$script, $root, $temporary, $mode] = $argv;
require $root.'/vendor/autoload.php';

use App\Providers\Filament\AdminPanelProvider;
use Filament\Facades\Filament;
use Filament\Resources\Events\RecordCreated;
use Filament\Resources\Events\RecordSaved;
use Filament\Resources\Events\RecordUpdated;
use Filament\Schemas\Components\Section;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Kitsune\Commerce\Blueprints\StorefrontBlueprint;
use Kitsune\Commerce\Catalogue\ProductWriter;
use Kitsune\Commerce\Filament\Products\CreateProduct;
use Kitsune\Commerce\Filament\Products\EditProduct;
use Kitsune\Commerce\Filament\Products\ListProducts;
use Kitsune\Commerce\Models\Sellable;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Schema\RecordedRevisions;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Fx;
use Kitsune\Core\Tests\Fixtures\TestUser;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

if ($mode === 'seed') {
    foreach (['bootstrap/cache', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs'] as $path) {
        mkdir($temporary.'/'.$path, 0700, true);
    }

    symlink($root.'/vendor', $temporary.'/vendor');
    symlink($root.'/skeleton/app', $temporary.'/app');
    file_put_contents($temporary.'/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]], JSON_THROW_ON_ERROR));
    touch($temporary.'/products.sqlite');
    file_put_contents($temporary.'/app-key', 'base64:'.base64_encode(random_bytes(32)));
}

$app = Application::configure(basePath: $temporary)
    ->withProviders($mode === 'seed' ? [] : [AdminPanelProvider::class])
    ->withRouting(using: static function (): void {})
    ->withExceptions()
    ->create();
$app->useConfigPath($root.'/skeleton/config');
$app->beforeBootstrapping(RegisterProviders::class, static function (Application $app) use ($temporary): void {
    $app->detectEnvironment(static fn (): string => 'testing');
    $app->make('config')->set('database.default', 'product_page_probe');
    $app->make('config')->set('database.connections.product_page_probe', [
        'driver' => 'sqlite', 'database' => $temporary.'/products.sqlite', 'prefix' => '', 'foreign_key_constraints' => true,
    ]);
    $app->make('config')->set('app.key', file_get_contents($temporary.'/app-key'));
    $app->make('config')->set('app.debug', true);
    $app->make('config')->set('auth.providers.users.model', TestUser::class);
    $app->make('config')->set('cache.default', 'array');
    $app->make('config')->set('session.driver', 'array');
    $app->make('config')->set('filesystems.disks.local.root', $temporary.'/storage/private');
});
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

if ($mode === 'seed') {
    $exit = $kernel->call('migrate', ['--path' => [$root.'/packages/core/database/migrations', $root.'/tests/Commerce/migrations'], '--realpath' => true, '--force' => true]);

    if ($exit !== 0) {
        throw new RuntimeException($kernel->output());
    }

    $org = Fx::org('product-page-probe');
    $org->update(['currency' => 'USD']);
    $site = Site::create(['handle' => 'main', 'slug' => 'product-page-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($site);
    Fx::member()->update(['name' => 'Product owner']);
    Module::create(['handle' => 'kitsune/commerce', 'version' => ModuleDiscovery::version('kitsune/commerce'), 'is_enabled' => true, 'installed_at' => now()]);
    BlueprintApplier::apply(new StorefrontBlueprint);
    echo json_encode(['seeded' => true], JSON_THROW_ON_ERROR);
    exit;
}

$org = Org::query()->where('slug', 'product-page-probe')->firstOrFail();
app(Context::class)->setOrg($org);
$site = Site::query()->where('slug', 'product-page-main')->firstOrFail();
app(Context::class)->setSite($site);
$owner = TestUser::query()->where('email', 'owner@kitsune.test')->firstOrFail();
Auth::guard('web')->setUser($owner);
Permissions::forget();
Filament::setCurrentPanel(Filament::getPanel('admin'));
Filament::setTenant($site, isQuiet: true);
Filament::bootCurrentPanel();
app('url')->defaults(['tenant' => $site->slug]);

$tables = ['commerce_sellables', 'commerce_listings', 'entries', 'entry_relations', 'entry_revisions', 'audit_log'];
$state = static fn (): array => array_map(static fn (string $table): array => DB::table($table)->orderBy('id')->get()->all(), $tables);
$commerce = ['sku' => 'BOARD', 'price_minor' => '2000', 'stock' => '5'];
$content = ['title' => 'Board', 'slug' => 'board', 'status' => 'draft', 'values' => ['product_description' => '<p>A board</p>']];
$form = ['sku' => 'BOARD', 'price' => '20.00', 'stock_mode' => 'finite', 'stock' => '5', 'is_available' => false, 'content' => $content];

if (str_starts_with($mode, 'bad-offer-')) {
    $updating = str_starts_with($mode, 'bad-offer-update-');
    $malformed = match (true) {
        str_ends_with($mode, '-array') => ['course.untrusted'],
        str_ends_with($mode, '-bool') => true,
        str_ends_with($mode, '-numeric') => 81234,
    };

    if ($updating) {
        $sellable = app(ProductWriter::class)->createProduct([...$commerce, 'entitlement_name' => 'course.kept'], 'USD', $content);
        $component = Livewire::test(EditProduct::class, ['record' => $sellable->getKey()]);
        $form['price'] = '25.00';
        $form['content']['title'] = 'This must not be saved';
    } else {
        $component = Livewire::test(CreateProduct::class);
    }

    $before = $state();
    $component->fillForm($form)->set('data.entitlement_name', $malformed)->call($updating ? 'save' : 'create')
        ->assertStatus(200)->assertHasFormErrors(['entitlement_name']);

    echo json_encode(['unchanged' => $state() == $before, 'errors' => $component->errors(),
        'mapping' => Sellable::query()->first()?->entitlement_name, 'level' => DB::connection()->transactionLevel(),
        'pdo_transaction' => DB::connection()->getPdo()->inTransaction(), 'redirect' => $component->effects['redirect'] ?? null,
        'notifications' => [...session()->get('filament.notifications', []), ...session()->get('filament.claimed_notifications', [])]], JSON_THROW_ON_ERROR);
    exit;
}

if (str_starts_with($mode, 'denied-')) {
    match ($mode) {
        'denied-no-actor' => Auth::guard('web')->logout(),
        'denied-member' => Fx::member(owner: false, email: 'member@product-page.test'),
        'denied-module' => Module::query()->where('handle', 'kitsune/commerce')->firstOrFail()->update(['is_enabled' => false]),
        'denied-version' => Module::query()->where('handle', 'kitsune/commerce')->firstOrFail()->update(['version' => 'stale']),
    };
    Permissions::forget();
    $before = $state();
    $statuses = [];

    foreach ([
        static fn () => app(CreateProduct::class)->create(),
        static fn () => app(EditProduct::class)->save(),
        static fn () => app(EditProduct::class)->saveFormComponentOnly(Section::make('partial')),
        static fn () => app(ListProducts::class)->setupStorefront(),
    ] as $action) {
        try {
            $action();
            $statuses[] = 200;
        } catch (HttpExceptionInterface $failure) {
            $statuses[] = $failure->getStatusCode();
        }
    }

    echo json_encode(['statuses' => $statuses, 'unchanged' => $state() == $before, 'level' => DB::connection()->transactionLevel()], JSON_THROW_ON_ERROR);
    exit;
}

if (in_array($mode, ['bad-record-key', 'foreign-record'], true)) {
    $key = '1.5';

    if ($mode === 'foreign-record') {
        $other = Org::create(['slug' => 'rival', 'name' => 'Rival', 'currency' => 'USD']);
        app(Context::class)->setOrg($other);
        $key = Sellable::create($commerce)->getKey();
        app(Context::class)->setSite($site);
    }

    $before = $state();

    try {
        $component = Livewire::test(EditProduct::class, ['record' => $key]);
        $component->assertStatus(404);
        $status = 404;
    } catch (HttpExceptionInterface $failure) {
        $status = $failure->getStatusCode();
    } catch (ModelNotFoundException) {
        $status = 404;
    }

    echo json_encode(['status' => $status, 'unchanged' => $state() == $before], JSON_THROW_ON_ERROR);
    exit;
}

if (in_array($mode, ['halt-update', 'updated-event', 'commit-failure-update', 'stale-stock-update'], true)) {
    $sellable = app(ProductWriter::class)->createProduct($commerce, 'USD', $content);
    $component = Livewire::test(EditProduct::class, ['record' => $sellable->getKey()]);
    $form['sku'] = 'CHANGED';
    $form['price'] = '25.00';
    $form['stock'] = '6';
    $form['content']['title'] = 'Changed board';
    if ($mode === 'stale-stock-update') {
        DB::table('commerce_sellables')->where('id', $sellable->getKey())->update(['stock' => '2']);
    }
} else {
    $component = Livewire::test(CreateProduct::class);
}

$before = $state();
$observed = null;
$eventReached = false;

if (in_array($mode, ['halt-create', 'halt-create-retry', 'halt-update'], true)) {
    Event::listen($mode === 'halt-update' ? RecordUpdated::class : RecordCreated::class, static function () use (&$eventReached): void {
        $eventReached = true;
        throw (new Halt)->rollBackDatabaseTransaction();
    });
} elseif ($mode === 'updated-event') {
    Event::listen(RecordSaved::class, static function (Sellable $record) use (&$observed): void {
        $observed = [$record->sku, $record->price_minor, $record->stock];
    });
} elseif (in_array($mode, ['commit-failure', 'commit-failure-update', 'commit-failure-retry'], true)) {
    Event::listen(TransactionCommitting::class, static function (): void {
        throw new RuntimeException('Fixture outer commit failed.');
    });
}

$component->fillForm($form)->call(in_array($mode, ['halt-update', 'updated-event', 'commit-failure-update', 'stale-stock-update'], true) ? 'save' : 'create');
$unchanged = $state() == $before;

if ($mode === 'halt-create-retry') {
    Event::forget(RecordCreated::class);
    $component->call('create');
} elseif ($mode === 'commit-failure-retry') {
    Event::forget(TransactionCommitting::class);
    $component->call('create');
}

echo json_encode([
    'unchanged' => $unchanged,
    'observed' => $observed,
    'event_reached' => $eventReached,
    'persisted' => Sellable::query()->first()?->only(['sku', 'price_minor', 'stock']),
    'displayed_stock' => $component->get('data.stock'),
    'level' => DB::connection()->transactionLevel(),
    'pdo_transaction' => DB::connection()->getPdo()->inTransaction(),
    'revision_notes' => app(RecordedRevisions::class)->held(),
    'errors' => $component->errors(),
    'redirect' => $component->effects['redirect'] ?? null,
    'notifications' => [...session()->get('filament.notifications', []), ...session()->get('filament.claimed_notifications', [])],
    'dispatches' => $component->effects['dispatches'] ?? [],
], JSON_THROW_ON_ERROR);
