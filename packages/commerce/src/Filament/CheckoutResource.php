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
use Kitsune\Commerce\Models\CommerceSettings;

/** @extends resource<CommerceSettings>
 * @internal Owner-only calculator administration, independent of the site's product content type.
 */
final class CheckoutResource extends Resource
{
    protected static ?string $model = CommerceSettings::class;

    protected static ?string $slug = 'storefront/checkout';

    protected static bool $isScopedToTenant = false;

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return CurrencyResource::canAccess();
    }

    public static function canViewAny(): bool
    {
        return self::canAccess();
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return ['index' => CheckoutSettings::route('/')];
    }

    public static function getPluralModelLabel(): string
    {
        return __('commerce::checkout.title');
    }
}
