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
use Kitsune\Commerce\Models\RetentionPolicy;

/** @extends resource<RetentionPolicy>
 * @internal Owner-only Org policy administration, independent of product content routes.
 */
final class RetentionResource extends Resource
{
    protected static ?string $model = RetentionPolicy::class;

    protected static ?string $slug = 'storefront/retention';

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
        return ['index' => RetentionSettings::route('/')];
    }

    public static function getPluralModelLabel(): string
    {
        return __('commerce::retention.title');
    }
}
