<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Filament;

use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Kitsune\Commerce\Inventory\StockWriter;
use Kitsune\Commerce\Models\StockMovement;

/**
 * The narrow shared-stock surface. Access grants no catalogue, order, billing or credential administration.
 *
 * @extends resource<StockMovement>
 *
 * @internal
 */
final class StockResource extends Resource
{
    // ProductResource keeps Sellable's only resource mapping; the stock page reads through StockWriter.
    protected static ?string $model = StockMovement::class;

    protected static ?string $slug = 'storefront/stock';

    protected static bool $isScopedToTenant = false;

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return app(StockWriter::class)->canAccess();
    }

    public static function canViewAny(): bool
    {
        return self::canAccess();
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return ['index' => StockList::route('/'), 'detail' => StockList::route('/{reference}')];
    }

    public static function getPluralModelLabel(): string
    {
        return __('commerce::stock.title');
    }
}
