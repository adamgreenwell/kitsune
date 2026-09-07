<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core;

use Illuminate\Support\ServiceProvider;

final class KitsuneServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Kitsune::class, static fn (): Kitsune => new Kitsune);
    }

    public function boot(): void
    {
        //
    }
}
