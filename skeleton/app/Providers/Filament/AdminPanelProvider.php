<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Kitsune\Core\Filament\Colors\ContrastSafeRamp;
use Kitsune\Core\Filament\Panels\KitsunePanel;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        // Everything Kitsune-specific lives in KitsunePanel so an operator can
        // see exactly what the platform adds, and add their own alongside it.
        return KitsunePanel::apply(
            $panel
                ->default()
                ->id('admin')
                ->path('admin')
                ->login()
                // ⚠️ Amber's stock 600-on-50 badge pairing is 3.08:1 against a 4.5:1 threshold
                // (issue #55, measured by axe). `ContrastSafeRamp` darkens that one shade to
                // a value Filament already ships, and `ContrastSafeRampTest` checks the
                // arithmetic so a palette change cannot reintroduce it silently.
                ->colors(['primary' => ContrastSafeRamp::for(Color::Amber)])
                ->middleware([
                    EncryptCookies::class,
                    AddQueuedCookiesToResponse::class,
                    StartSession::class,
                    AuthenticateSession::class,
                    ShareErrorsFromSession::class,
                    PreventRequestForgery::class,
                    SubstituteBindings::class,
                    DisableBladeIconComponents::class,
                    DispatchServingFilamentEvent::class,
                ])
                ->authMiddleware([Authenticate::class])
        );
    }
}
