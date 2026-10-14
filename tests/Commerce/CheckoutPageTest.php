<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Kitsune\Commerce\Catalogue\CatalogueWriter;
use Kitsune\Commerce\Checkout\CommerceSetupWriter;
use Kitsune\Commerce\Filament\CheckoutResource;
use Kitsune\Commerce\Filament\CheckoutSettings;
use Kitsune\Commerce\Models\CommerceSettings;
use Kitsune\Commerce\Models\SalesPolicy;
use Kitsune\Commerce\Models\TaxRate;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as CheckoutFx;
use Livewire\Mechanisms\DataStore;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    CheckoutFx::boot();
    app()->singleton(DataStore::class);
    $this->org = CheckoutFx::org('checkout-admin');
    $this->org->update(['currency' => 'USD']);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'checkout-admin-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);
    $this->owner = CheckoutFx::member();
    Module::create(['handle' => 'kitsune/commerce', 'version' => ModuleDiscovery::version('kitsune/commerce'), 'is_enabled' => true, 'installed_at' => now()]);
    ModuleKernel::flush();
    ModuleKernel::boot(app());
    $this->product = app(CatalogueWriter::class)->createSellable('BOARD', '2000', null, 'USD');
});

afterEach(fn () => CheckoutFx::tearDown());

function checkoutAdminSettings(bool $origin = false): array
{
    return ['tax_mode' => 'exclusive', 'shipping_tax_mode' => 'inclusive', 'shipping_tax_class' => 'shipping',
        'ship_from_name' => $origin ? 'Dispatch desk' : '',
        'ship_from_address' => $origin ? ['line1' => '10 Ocean Road', 'line2' => '', 'city' => 'Honolulu', 'region' => 'HI', 'postal_code' => '96801', 'country' => 'US']
            : array_fill_keys(['line1', 'line2', 'city', 'region', 'postal_code', 'country'], '')];
}

it('opens checkout setup without a Storefront type but only to a current signed-in owner', function (string $denial): void {
    expect(CheckoutResource::canAccess())->toBeTrue();
    match ($denial) {
        'member' => CheckoutFx::member(owner: false, email: 'editor@checkout.test'),
        'no actor' => Auth::guard('web')->logout(),
        'disabled' => Module::query()->where('handle', 'kitsune/commerce')->firstOrFail()->update(['is_enabled' => false]),
        'stale' => Module::query()->where('handle', 'kitsune/commerce')->firstOrFail()->update(['version' => 'old']),
    };
    Permissions::forget();
    expect(CheckoutResource::canAccess())->toBeFalse();
    $page = app(CheckoutSettings::class);
    foreach (['saveSettings' => [], 'newTaxRate' => [], 'editTaxRate' => [1], 'saveTaxRate' => [],
        'removeTaxRate' => [1, 1], 'editPolicy' => [$this->product->id], 'savePolicy' => [], 'removePolicy' => [],
        'taxRates' => [], 'products' => []] as $action => $arguments) {
        expect(fn () => $page->{$action}(...$arguments))->toThrow(HttpException::class);
    }
    expect(CommerceSettings::query()->count())->toBe(0)->and(TaxRate::query()->count())->toBe(0)->and(SalesPolicy::query()->count())->toBe(0);
})->with(['member', 'no actor', 'disabled', 'stale']);

it('saves explicit modes and an optional dispatch address through the checked writer', function (): void {
    $page = app(CheckoutSettings::class);
    $page->mount();
    expect($page->settings['tax_mode'])->toBe('')->and($page->settingsVersion)->toBeNull();
    $page->settings = checkoutAdminSettings();
    $page->saveSettings();
    $row = CommerceSettings::query()->firstOrFail();
    expect($page->getErrorBag()->isEmpty())->toBeTrue()->and($page->settingsVersion)->toBe(1)
        ->and($row->tax_mode)->toBe('exclusive')->and($row->shipping_tax_mode)->toBe('inclusive')->and($row->ship_from_address)->toBeNull();
    $page->settings = checkoutAdminSettings(true);
    $page->saveSettings();
    expect($row->fresh()->ship_from_address['line1'])->toBe('10 Ocean Road')->and($page->settingsVersion)->toBe(2);
});

it('refuses a stale settings tab without overwriting or advancing its version', function (): void {
    $first = app(CheckoutSettings::class);
    $first->mount();
    $first->settings = checkoutAdminSettings();
    $first->saveSettings();
    $stale = app(CheckoutSettings::class);
    $stale->mount();
    $first->settings['tax_mode'] = 'inclusive';
    $first->saveSettings();
    $stale->settings['shipping_tax_mode'] = 'exclusive';
    $stale->saveSettings();
    expect($stale->getErrorBag()->has('settings'))->toBeTrue()->and($stale->settingsVersion)->toBe(1)
        ->and(CommerceSettings::query()->firstOrFail()->shipping_tax_mode)->toBe('inclusive');
});

it('does not expose arbitrary input or database bindings in form errors', function (): void {
    $page = app(CheckoutSettings::class);
    $page->settings = checkoutAdminSettings() + ['private' => 'private-address-and-secret'];
    $page->saveSettings();
    expect($page->getErrorBag()->has('settings'))->toBeTrue()
        ->and(json_encode($page->getErrorBag()->toArray()))->not->toContain('private-address-and-secret')
        ->and(CommerceSettings::query()->count())->toBe(0);
});

it('creates edits and removes a tax rule using exact decimal percentages and the displayed version', function (): void {
    $page = app(CheckoutSettings::class);
    $page->newTaxRate();
    $page->taxRate = ['country' => 'US', 'region' => '*', 'tax_class' => 'standard', 'percentage' => '7.3125'];
    $page->saveTaxRate();
    $row = TaxRate::query()->firstOrFail();
    expect($row->rate_parts)->toBe(73125)->and($page->taxRateId)->toBeNull();
    $page->editTaxRate($row->id);
    expect($page->taxRate['percentage'])->toBe('7.3125');
    $page->taxRate['percentage'] = '0';
    $page->saveTaxRate();
    expect($row->fresh()->rate_parts)->toBe(0)->and($row->fresh()->version)->toBe(2);
    $page->removeTaxRate($row->id, 1);
    expect($page->getErrorBag()->has('taxRate'))->toBeTrue()->and(TaxRate::query()->count())->toBe(1);
    $page->removeTaxRate($row->id, 2);
    expect(TaxRate::query()->count())->toBe(0);
});

it('rejects malformed percentage input without silently treating it as zero', function (mixed $percentage): void {
    $page = app(CheckoutSettings::class);
    $page->taxRate = ['country' => 'US', 'region' => '*', 'tax_class' => 'standard', 'percentage' => $percentage];
    $page->saveTaxRate();
    expect($page->getErrorBag()->has('taxRate'))->toBeTrue()->and(TaxRate::query()->count())->toBe(0);
})->with([[7.25], ['7.25001'], [''], [null], ['-1']]);

it('configures physical packing then explicitly switches the shared product to digital', function (): void {
    $page = app(CheckoutSettings::class);
    $page->settings = checkoutAdminSettings(true);
    $page->saveSettings();
    $page->editPolicy($this->product->id);
    $page->policy = ['tax_class' => 'standard', 'delivery_kind' => 'physical', 'weight_grams' => '500', 'length_mm' => '300',
        'width_mm' => '200', 'height_mm' => '100', 'tare_grams' => '125', 'units_per_parcel' => '3'];
    $page->savePolicy();
    expect($page->getErrorBag()->isEmpty())->toBeTrue()->and($page->policyVersion)->toBe(1)
        ->and(SalesPolicy::query()->firstOrFail()->weight_grams)->toBe(500);
    $page->policy['delivery_kind'] = 'digital';
    $page->savePolicy();
    expect(SalesPolicy::query()->firstOrFail()->weight_grams)->toBeNull()->and($page->policyVersion)->toBe(2);
    $page->removePolicy();
    expect(SalesPolicy::query()->count())->toBe(0)->and($page->policyVersion)->toBeNull();
});

it('refuses stale delivery edits and another orgs product and tax rule identifiers', function (): void {
    $page = app(CheckoutSettings::class);
    $page->editPolicy($this->product->id);
    $page->policy = ['tax_class' => 'standard', 'delivery_kind' => 'digital'];
    $page->savePolicy();
    $stale = app(CheckoutSettings::class);
    $stale->editPolicy($this->product->id);
    $page->policy['tax_class'] = 'reduced';
    $page->savePolicy();
    $stale->savePolicy();
    expect($stale->getErrorBag()->has('policy'))->toBeTrue()->and(SalesPolicy::query()->firstOrFail()->tax_class)->toBe('reduced');
    $foreign = CheckoutFx::org('foreign-checkout');
    $foreign->update(['currency' => 'USD']);
    CheckoutFx::member(email: 'foreign@checkout.test');
    $product = app(CatalogueWriter::class)->createSellable('FOREIGN', '100', null, 'USD');
    $rate = app(CommerceSetupWriter::class)->saveTaxRate(null, ['country' => 'US', 'region' => '*', 'tax_class' => 'standard', 'rate_parts' => 0]);
    app(Context::class)->setSite($this->site);
    Auth::guard('web')->setUser($this->owner);
    Permissions::forget();
    expect(fn () => $page->editPolicy($product->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $page->editTaxRate($rate->id))->toThrow(ModelNotFoundException::class);
    $page->removeTaxRate($rate->id, 1);
    expect($page->getErrorBag()->has('taxRate'))->toBeTrue();
});

it('rolls back configuration when an audit observer cancels and leaves only a generic failure', function (): void {
    $events = AuditLog::getEventDispatcher();
    $name = 'eloquent.creating: '.AuditLog::class;
    $old = $events->getRawListeners()[$name] ?? [];
    AuditLog::creating(static fn (): bool => false);
    try {
        $page = app(CheckoutSettings::class);
        $page->settings = checkoutAdminSettings(true);
        $page->saveSettings();
        expect($page->getErrorBag()->has('settings'))->toBeTrue()->and(CommerceSettings::query()->count())->toBe(0)->and($page->settingsVersion)->toBeNull();
    } finally {
        $events->forget($name);
        foreach ($old as $listener) {
            $events->listen($name, $listener);
        }
    }
});
