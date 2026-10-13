<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

// A fresh process matters: an existing Testbench application's Eloquent resolver conceals the booting boundary.
[$script, $root, $temporary, $mode] = $argv;
require $root.'/vendor/autoload.php';

use App\Providers\Filament\AdminPanelProvider;
use Composer\InstalledVersions;
use Filament\PanelRegistry;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Kitsune\Commerce\Filament\CurrencyResource;
use Kitsune\Core\Modules\AdminSurface;
use Kitsune\Fixture\Module\FixtureModuleServiceProvider;

foreach (['bootstrap/cache', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs'] as $path) {
    mkdir($temporary.'/'.$path, 0700, true);
}

$database = $temporary.'/boot.sqlite';
$pdo = new PDO('sqlite:'.$database);
$pdo->exec('CREATE TABLE modules (id INTEGER PRIMARY KEY, handle TEXT, version TEXT, is_enabled INTEGER, installed_at TEXT)');
$insert = $pdo->prepare('INSERT INTO modules (handle, version, is_enabled) VALUES (?, ?, ?)');

foreach (['kitsune/commerce', 'kitsune/fixture-module'] as $package) {
    $insert->execute([$package, InstalledVersions::getPrettyVersion($package), $mode === 'disabled' ? 0 : 1]);
}

$resolverWasNull = Model::getConnectionResolver() === null;
symlink($root.'/vendor', $temporary.'/vendor');
$app = Application::configure(basePath: $temporary)
    ->withProviders($mode === 'headless' ? [] : [AdminPanelProvider::class])
    ->withRouting(using: static function (): void {})
    ->withExceptions()
    ->create();
$app->useConfigPath($root.'/skeleton/config');
$app->beforeBootstrapping(RegisterProviders::class, static function (Application $app) use ($database): void {
    $app->make('config')->set('database.default', 'boot_probe');
    $app->make('config')->set('database.connections.boot_probe', [
        'driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true,
    ]);
});
$app->make(Kernel::class)->bootstrap();

$panel = $app->make(PanelRegistry::class)->get('admin');
$routes = $app->make('router')->getRoutes();

echo json_encode([
    'resolver_was_null' => $resolverWasNull,
    'fixture_calls' => FixtureModuleServiceProvider::$calls,
    'surface_has_currency' => in_array(CurrencyResource::class, $app->make(AdminSurface::class)->resources(), true),
    'panel_has_currency' => $panel !== null && in_array(CurrencyResource::class, $panel->getResources(), true),
    'currency_route' => $routes->getByName('filament.admin.resources.storefront.currency.index')?->uri(),
    'dashboard_route' => $routes->getByName('filament.admin.pages.dashboard')?->uri(),
    'roles_route' => $routes->getByName('filament.admin.resources.roles.index')?->uri(),
    'currency_translations_loaded' => $app->make('translator')->has('commerce::catalogue.currency_title'),
    'panels' => array_keys($app->make(PanelRegistry::class)->all()),
], JSON_THROW_ON_ERROR);
