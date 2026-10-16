<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\CommerceServiceProvider;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Tenancy\Context;

it('refuses a cached cold retention command after its installed receipt is removed', function (): void {
    $org = Org::create(['slug' => 'cached-retention', 'name' => 'Cached retention']);
    app(Context::class)->setOrg($org);
    $site = Site::create(['handle' => 'cached', 'slug' => 'cached-retention', 'name' => 'Cached']);
    app(Context::class)->setSite($site);
    Module::create(['handle' => 'kitsune/commerce', 'version' => 'stale', 'is_enabled' => false, 'installed_at' => now()]);
    ConsoleApplication::forgetBootstrappers();
    ModuleKernel::flush();
    ModuleKernel::boot(app());
    try {
        $console = new ConsoleApplication(app(), app('events'), 'test');
        expect($console->has('kitsune:commerce-retention'))->toBeTrue();
        DB::table('modules')->where('handle', 'kitsune/commerce')->delete();
        DB::connection()->enableQueryLog();
        try {
            expect($console->call('kitsune:commerce-retention', ['--org' => $org->slug, '--apply' => true]))->toBe(Command::FAILURE)
                ->and($console->output())->toContain('Commerce retention could not complete.');
            $queries = DB::connection()->getQueryLog();
        } finally {
            DB::connection()->disableQueryLog();
        }
        expect($console->has('kitsune:commerce-retention'))->toBeTrue()
            ->and(app()->getProviders(CommerceServiceProvider::class))->toBe([])
            ->and(app(Context::class)->site())->toBe($site)->and(app(Context::class)->orgId())->toBe($org->id);
        foreach ($queries as $query) {
            expect(strtolower($query['query']))->not->toContain('commerce_orders')->not->toContain('commerce_order_privacy')
                ->not->toContain('commerce_privacy_');
        }
    } finally {
        ConsoleApplication::forgetBootstrappers();
    }
});
