<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

[$script, $root, $temporary, $mode] = $argv;
require $root.'/vendor/autoload.php';

use Composer\InstalledVersions;
use Illuminate\Contracts\Console\Kernel;
use Kitsune\Commerce\CommerceServiceProvider;
use Kitsune\Commerce\Filament\CurrencyResource;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Core\Tests\Fixtures\ModuleRouteCacheHost;

if (str_starts_with($mode, 'seed-')) {
    foreach (['bootstrap/cache', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs'] as $path) {
        mkdir($temporary.'/'.$path, 0700, true);
    }

    symlink($root.'/vendor', $temporary.'/vendor');
    file_put_contents($temporary.'/bootstrap/app.php', '<?php return \\Kitsune\\Core\\Tests\\Fixtures\\ModuleRouteCacheHost::application('
        .var_export($root, true).', '.var_export($temporary, true).');');
    $pdo = new PDO('sqlite:'.$temporary.'/modules.sqlite');
    $pdo->exec('CREATE TABLE modules (id INTEGER PRIMARY KEY, handle TEXT, version TEXT, is_enabled INTEGER, installed_at TEXT)');
    $insert = $pdo->prepare('INSERT INTO modules (handle, version, is_enabled) VALUES (?, ?, ?)');
    $insert->execute(['kitsune/commerce', InstalledVersions::getPrettyVersion('kitsune/commerce'), $mode === 'seed-enabled' ? 1 : 0]);
}

$app = ModuleRouteCacheHost::application($root, $temporary);
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$cacheCommand = null;

if (str_starts_with($mode, 'seed-') || $mode === 'cache') {
    // The real command boots another application through the disposable bootstrap/app.php above.
    $cacheCommand = $kernel->call('route:cache');
    require $app->getCachedRoutesPath();
} elseif ($mode === 'enable') {
    ModuleLifecycle::enable($app, 'kitsune/commerce');
} elseif ($mode === 'disable') {
    ModuleLifecycle::disable($app, 'kitsune/commerce');
} elseif ($mode === 'stale') {
    Module::query()->where('handle', 'kitsune/commerce')->firstOrFail()->update(['version' => 'old-version']);
}

$route = $app->make('router')->getRoutes()->getByName('filament.admin.resources.storefront.currency.index');

echo json_encode([
    'cache_command' => $cacheCommand,
    'cache_file' => is_file($app->getCachedRoutesPath()),
    'cache_memo' => $app->routesAreCached(),
    'enabled' => Module::isEnabled('kitsune/commerce'),
    'provider_registered' => $app->getProviders(CommerceServiceProvider::class) !== [],
    'currency_route' => $route?->uri(),
    'currency_url' => $route === null ? null : CurrencyResource::getUrl('index', ['tenant' => 'golfdom'], panel: 'admin'),
    'dashboard_route' => $app->make('router')->getRoutes()->getByName('filament.admin.pages.dashboard')?->uri(),
], JSON_THROW_ON_ERROR);
