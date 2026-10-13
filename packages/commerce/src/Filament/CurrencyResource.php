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
use Kitsune\Commerce\CommerceServiceProvider;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Tenancy\Context;

/**
 * Only the currency chooser; product administration belongs to #191.
 *
 * @extends resource<Org>
 *
 * @internal First-party only; no extension contract before v1.2.
 */
final class CurrencyResource extends Resource
{
    protected static ?string $model = Org::class;

    protected static ?string $slug = 'storefront/currency';

    protected static bool $isScopedToTenant = false;

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        $user = Permissions::currentUser();

        if (app(Context::class)->orgId() === null || $user === null || ! Permissions::isOwner($user)
            || app()->getProviders(CommerceServiceProvider::class) === []) {
            return false;
        }

        // A cached route can outlive eligibility: an enabled but stale receipt is declined by the kernel.
        $version = ModuleDiscovery::version('kitsune/commerce');
        $receipt = Module::query()->where('handle', 'kitsune/commerce')->where('is_enabled', true)->first();

        return $version !== null && $receipt !== null && $receipt->version === $version;
    }

    public static function canViewAny(): bool
    {
        return self::canAccess();
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return ['index' => CurrencySettings::route('/')];
    }

    public static function getPluralModelLabel(): string
    {
        return __('commerce::catalogue.currency_title');
    }
}
