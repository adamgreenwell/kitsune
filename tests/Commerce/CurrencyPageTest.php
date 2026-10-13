<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Kitsune\Commerce\Filament\CurrencyResource;
use Kitsune\Commerce\Filament\CurrencySettings;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Fx;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    Fx::boot();
    $this->org = Fx::org('currency-page');
    $this->site = Site::create(['handle' => 'main', 'slug' => 'currency-page-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);
    $this->owner = Fx::member();
    Module::create(['handle' => 'kitsune/commerce', 'version' => ModuleDiscovery::version('kitsune/commerce'), 'is_enabled' => true, 'installed_at' => now()]);
    ModuleKernel::flush();
    ModuleKernel::boot(app());
});

afterEach(fn () => Fx::tearDown());

it('opens the currency resource only to a signed-in owner while commerce is enabled', function (): void {
    expect(CurrencyResource::canAccess())->toBeTrue();
    Fx::member(owner: false, email: 'editor@currency.test');
    expect(CurrencyResource::canAccess())->toBeFalse();
    Auth::guard('web')->logout();
    Permissions::forget();
    expect(CurrencyResource::canAccess())->toBeFalse();
    Auth::guard('web')->setUser($this->owner);
    Permissions::forget();
    $receipt = Module::query()->where('handle', 'kitsune/commerce')->firstOrFail();
    $receipt->version = 'stale';
    $receipt->save();
    expect(CurrencyResource::canAccess())->toBeFalse();
    $receipt->version = ModuleDiscovery::version('kitsune/commerce');
    $receipt->is_enabled = false;
    $receipt->save();
    expect(CurrencyResource::canAccess())->toBeFalse();
});

it('rechecks page action authority and refuses a stale page after commerce is disabled', function (): void {
    $page = app(CurrencySettings::class);
    $page->currency = 'USD';
    $receipt = Module::query()->where('handle', 'kitsune/commerce')->firstOrFail();
    $receipt->is_enabled = false;
    $receipt->save();
    expect(fn () => $page->saveCurrency())->toThrow(HttpException::class);
    expect($this->org->fresh()->currency)->toBeNull();
});

it('never accepts a currency from a non-owner or a missing browser actor', function (bool $signedIn): void {
    if ($signedIn) {
        Fx::member(owner: false, email: 'editor@currency.test');
    } else {
        Auth::guard('web')->logout();
        Permissions::forget();
    }

    $page = app(CurrencySettings::class);
    $page->currency = 'USD';
    expect(fn () => $page->saveCurrency())->toThrow(HttpException::class);
    expect($this->org->fresh()->currency)->toBeNull();
})->with([true, false]);
