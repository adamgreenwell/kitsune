<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Filament;

use Closure;
use Filament\Panel;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\Route;
use Kitsune\Commerce\Http\Controllers\CheckoutRecoveryController;
use Kitsune\Commerce\Http\Controllers\OrderExportController;
use Kitsune\Commerce\Models\Order;
use Kitsune\Core\Tenancy\Context;

/** @extends resource<Order>
 * @internal Historical orders stay reachable independently of current product content and availability.
 */
final class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $slug = 'storefront/orders';

    protected static bool $isScopedToTenant = false;

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return CurrencyResource::canAccess() && app(Context::class)->siteId() !== null;
    }

    public static function canViewAny(): bool
    {
        return self::canAccess();
    }

    public static function routes(Panel $panel, ?Closure $registerPageRoutes = null): void
    {
        parent::routes($panel, function () use ($registerPageRoutes): void {
            $registerPageRoutes?->__invoke();
            Route::post('/historical-recovery', CheckoutRecoveryController::class)->name('historical-recovery');
            Route::post('/export', OrderExportController::class)->name('export');
        });
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return ['index' => OrderList::route('/'), 'detail' => OrderList::route('/{reference}')];
    }

    public static function getPluralModelLabel(): string
    {
        return __('commerce::checkout.orders_title');
    }
}
