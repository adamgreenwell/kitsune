<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Routing\Route;
use Kitsune\Commerce\CommerceCustody;
use Kitsune\Commerce\CommerceServiceProvider;
use Kitsune\Commerce\Filament\CheckoutResource;
use Kitsune\Commerce\Filament\CurrencyResource;
use Kitsune\Commerce\Filament\OrderResource;
use Kitsune\Commerce\Filament\ProductResource;
use Kitsune\Commerce\Models\Cart;
use Kitsune\Commerce\Models\CartLine;
use Kitsune\Commerce\Models\CheckoutAttempt;
use Kitsune\Commerce\Models\CheckoutRoutingReceipt;
use Kitsune\Commerce\Models\CommerceSettings;
use Kitsune\Commerce\Models\Listing;
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Models\OrderAccessToken;
use Kitsune\Commerce\Models\OrderLine;
use Kitsune\Commerce\Models\SalesPolicy;
use Kitsune\Commerce\Models\Sellable;
use Kitsune\Commerce\Models\StripeEvent;
use Kitsune\Commerce\Models\TaxRate;
use Kitsune\Commerce\Payments\CheckoutRoutes;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Modules\AdminSurface;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Core\Modules\ModuleManifest;
use Kitsune\Core\Modules\ModuleVerifier;

const COMMERCE_MODULE = 'kitsune/commerce';

beforeEach(function (): void {
    ModuleKernel::flush();
    app(AdminSurface::class)->flush();
});

it('discovers the real optional commerce package without Laravel auto-discovery', function (): void {
    $manifest = ModuleDiscovery::read(COMMERCE_MODULE);
    $path = (string) ModuleDiscovery::installPath(COMMERCE_MODULE);
    $composer = json_decode((string) file_get_contents($path.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest)->toBeInstanceOf(ModuleManifest::class)
        ->and($manifest->provider)->toBe(CommerceServiceProvider::class)
        ->and($manifest->scoping)->toBe(['org', 'site'])
        ->and($composer['extra']['laravel']['providers'] ?? [])->toBe([]);

    $verified = ModuleVerifier::verify($manifest, $path);

    expect($verified->passed())->toBeTrue($verified->refusal ?? '')
        ->and($verified->models)->toEqualCanonicalizing([Sellable::class, Listing::class, Cart::class, CartLine::class, CommerceSettings::class, TaxRate::class, SalesPolicy::class, Order::class, OrderLine::class, OrderAccessToken::class, CheckoutAttempt::class, CheckoutRoutingReceipt::class, StripeEvent::class])
        ->and($verified->examined)->toContain(CommerceServiceProvider::class, CommerceCustody::class);
});

it('refuses commerce provider registration outside the kernel', function (): void {
    expect(fn () => app()->register(CommerceServiceProvider::class))
        ->toThrow(RuntimeException::class, 'outside the module kernel');
});

it('installs commerce disabled without creating a product type or runtime surface', function (): void {
    $types = EntryType::query()->count();

    expect(ModuleLifecycle::install(app(), COMMERCE_MODULE))->toContain('not enabled yet');

    ModuleKernel::boot(app());
    $receipt = Module::query()->where('handle', COMMERCE_MODULE)->firstOrFail();

    expect($receipt->is_enabled)->toBeFalse()
        ->and($receipt->version)->toBe(ModuleDiscovery::version(COMMERCE_MODULE))
        ->and(app()->getProviders(CommerceServiceProvider::class))->toBe([])
        ->and(EntryType::query()->count())->toBe($types)
        ->and(app(AdminSurface::class)->resources())->toBe([])
        ->and(app(AdminSurface::class)->navigationItems())->toBe([]);
});

it('hands the tracked commerce migration directory to the kernel migrator', function (): void {
    $path = (new CommerceServiceProvider(app()))->migrationPath();

    expect($path)->not->toBeNull()
        ->and(is_dir($path))->toBeTrue()
        ->and(glob($path.'/*.php'))->toHaveCount(8);

    // Real catalogue DDL is fixture-migrated before the wrapper on engine legs; the kernel still owns its path.
    $migrator = Mockery::mock(Migrator::class);
    $migrator->shouldReceive('repositoryExists')->once()->andReturnTrue();
    $migrator->shouldReceive('run')->once()->with([$path])->andReturn([]);
    app()->instance('migrator', $migrator);

    expect(ModuleLifecycle::install(app(), COMMERCE_MODULE))->toContain('not enabled yet');
});

it('registers enabled commerce on a headless host without needing a panel or service', function (): void {
    ModuleLifecycle::install(app(), COMMERCE_MODULE);
    ModuleLifecycle::enable(app(), COMMERCE_MODULE);
    ModuleKernel::boot(app());

    expect(app()->getProviders(CommerceServiceProvider::class))->toHaveCount(1)
        ->and(Module::isEnabled(COMMERCE_MODULE))->toBeTrue()
        ->and(app(AdminSurface::class)->resources())->toBe([ProductResource::class, CurrencyResource::class, CheckoutResource::class, OrderResource::class])
        ->and(app(AdminSurface::class)->navigationItems())->toHaveCount(4);
    expect(collect(app('router')->getRoutes()->getRoutes())->filter(static fn (Route $route): bool => str_starts_with($route->getName() ?? '', 'commerce.payments.')))->toBeEmpty()
        ->and((new ReflectionClass(CheckoutRoutes::class))->getDocComment())->toContain('@internal');
});

it('does not boot commerce when disabled before the next application boot', function (): void {
    ModuleLifecycle::install(app(), COMMERCE_MODULE);
    ModuleLifecycle::enable(app(), COMMERCE_MODULE);
    ModuleLifecycle::disable(app(), COMMERCE_MODULE);
    ModuleKernel::boot(app());

    expect(app()->getProviders(CommerceServiceProvider::class))->toBe([])
        ->and(Module::isEnabled(COMMERCE_MODULE))->toBeFalse();
});

it('requires upgrade for a stale commerce receipt and keeps the switch disabled', function (): void {
    ModuleLifecycle::install(app(), COMMERCE_MODULE);
    $receipt = Module::query()->where('handle', COMMERCE_MODULE)->firstOrFail();
    $receipt->version = '0.0.0-stale';
    $receipt->save();

    expect(fn () => ModuleLifecycle::enable(app(), COMMERCE_MODULE))
        ->toThrow(RuntimeException::class, 'kitsune:module upgrade');

    expect(ModuleLifecycle::upgrade(app(), COMMERCE_MODULE))->toContain('Upgraded')
        ->and(Module::query()->where('handle', COMMERCE_MODULE)->value('version'))->toBe(ModuleDiscovery::version(COMMERCE_MODULE))
        ->and(Module::isEnabled(COMMERCE_MODULE))->toBeFalse()
        ->and(ModuleLifecycle::upgrade(app(), COMMERCE_MODULE))->toContain('already at');
});

it('refuses to uninstall enabled commerce before touching its receipt', function (): void {
    ModuleLifecycle::install(app(), COMMERCE_MODULE);
    ModuleLifecycle::enable(app(), COMMERCE_MODULE);

    expect(fn () => ModuleLifecycle::uninstall(app(), COMMERCE_MODULE))
        ->toThrow(RuntimeException::class, 'Run `kitsune:module disable');

    expect(Module::isEnabled(COMMERCE_MODULE))->toBeTrue();
});

it('drives the empty commerce lifecycle through the operator command', function (): void {
    $this->artisan('kitsune:module install '.COMMERCE_MODULE)->assertSuccessful();
    $this->artisan('kitsune:module enable '.COMMERCE_MODULE)->assertSuccessful();
    $this->artisan('kitsune:module status')->expectsOutputToContain(COMMERCE_MODULE)->assertSuccessful();
    $this->artisan('kitsune:module disable '.COMMERCE_MODULE)->assertSuccessful();
    $this->artisan('kitsune:module uninstall '.COMMERCE_MODULE)->assertSuccessful();

    expect(Module::query()->where('handle', COMMERCE_MODULE)->exists())->toBeFalse();
});
