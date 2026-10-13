<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use App\Providers\Filament\AdminPanelProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;

/** A disposable real application, also used by Laravel's route:cache fresh-application boot. */
final class ModuleRouteCacheHost
{
    public static function application(string $root, string $temporary): Application
    {
        $app = Application::configure(basePath: $temporary)
            ->withProviders([AdminPanelProvider::class])
            ->withRouting(using: static function (): void {})
            ->withExceptions()
            ->create();
        $app->useConfigPath($root.'/skeleton/config');
        $app->beforeBootstrapping(RegisterProviders::class, static function (Application $app) use ($temporary): void {
            $app->make('config')->set('database.default', 'module_cache_probe');
            $app->make('config')->set('database.connections.module_cache_probe', [
                'driver' => 'sqlite', 'database' => $temporary.'/modules.sqlite', 'prefix' => '', 'foreign_key_constraints' => true,
            ]);
        });

        return $app;
    }
}
