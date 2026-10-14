<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce;

use Filament\Navigation\NavigationItem;
use Illuminate\Contracts\Events\Dispatcher;
use Kitsune\Commerce\Blueprints\StorefrontBlueprint;
use Kitsune\Commerce\Carts\CartSessions;
use Kitsune\Commerce\Console\CartPruneCommand;
use Kitsune\Commerce\Filament\CheckoutResource;
use Kitsune\Commerce\Filament\CurrencyResource;
use Kitsune\Commerce\Filament\OrderResource;
use Kitsune\Commerce\Filament\ProductResource;
use Kitsune\Commerce\Orders\CommerceReaderData;
use Kitsune\Commerce\Orders\OrderQuotes;
use Kitsune\Core\Blueprints\BlueprintRegistry;
use Kitsune\Core\Credentials\CredentialSlot;
use Kitsune\Core\Credentials\CredentialSlots;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Modules\AdminSurface;
use Kitsune\Core\Modules\ModuleServiceProvider;
use Kitsune\Core\Readers\Contracts\ModuleReaderData;

/**
 * Commerce enters through the module kernel, and stays optional (ADR-040).
 *
 * @internal First-party only; no extension contract before v1.2.
 *
 * Sellables are org-owned, listings are site-owned. Storefront is applied explicitly into each org.
 * The host opts into CatalogueRoutes separately; no public route or payment client is installed automatically.
 */
final class CommerceServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->make(CredentialSlots::class)->register(new CredentialSlot(
            OrderQuotes::SHIPPO_SLOT, 'commerce::orders.shippo_label', 'commerce::orders.shippo_help', true,
            ['test' => ['shippo_test_'], 'live' => ['shippo_live_']], minLength: 16, maxLength: 255,
        ));
        $this->app->make(BlueprintRegistry::class)->register(new StorefrontBlueprint);
        $this->app->make(AdminSurface::class)->resource(ProductResource::class)->navigationItem(
            NavigationItem::make('Products')
                ->label(fn (): string => __('commerce::catalogue.products'))
                ->group(fn (): string => __('commerce::catalogue.storefront'))
                ->icon('heroicon-o-shopping-bag')
                ->url(fn (): string => ProductResource::getUrl())
                ->visible(fn (): bool => ProductResource::canAccess()),
        );
        $this->app->make(AdminSurface::class)->resource(CurrencyResource::class)->navigationItem(
            NavigationItem::make('Catalogue currency')
                ->label(fn (): string => __('commerce::catalogue.currency_title'))
                ->group(fn (): string => __('commerce::catalogue.storefront'))
                ->icon('heroicon-o-currency-dollar')
                ->url(fn (): string => CurrencyResource::getUrl())
                ->visible(fn (): bool => CurrencyResource::canAccess()),
        );
        foreach ([CheckoutResource::class => ['Checkout settings', 'heroicon-o-calculator'], OrderResource::class => ['Orders', 'heroicon-o-document-text']] as $resource => [$label, $icon]) {
            $this->app->make(AdminSurface::class)->resource($resource)->navigationItem(
                NavigationItem::make($label)->label(fn (): string => $resource::getPluralModelLabel())
                    ->group(fn (): string => __('commerce::catalogue.storefront'))->icon($icon)
                    ->url(fn (): string => $resource::getUrl())->visible(fn (): bool => $resource::canAccess()),
            );
        }
    }

    protected function bootModule(): void
    {
        $this->commands([CartPruneCommand::class]);
        CartSessions::listen($this->app->make(Dispatcher::class));
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'commerce');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'commerce');
    }

    public function migrationPath(): string
    {
        return __DIR__.'/../database/migrations';
    }

    public function readerData(): ModuleReaderData
    {
        return new CommerceReaderData;
    }

    public function uninstall(): void
    {
        CommerceCustody::refuseKeptRows((new Module)->getConnection());
    }
}
