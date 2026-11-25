<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Fixture\Module\FixtureModuleServiceProvider;

/*
 * `modules.installed_at` records when a module was installed, and enabling, disabling or upgrading it keeps it.
 *
 * ⚠️ MARIADB BEFORE 10.10 MOVES IT, as it moved `media_files.created_at`: it is the table's first TIMESTAMP, which
 * `explicit_defaults_for_timestamp=0` gives `ON UPDATE CURRENT_TIMESTAMP`, so a receipt save that leaves it out stores the
 * server's clock (measured on 10.6.28). The clock is frozen far from the server's and moved on before each write, and each
 * save is also read from the query log — it names `installed_at` — for the engines with no such clause.
 */

const STAMPED_MODULE = 'kitsune/fixture-module';

const STAMPED_INSTALL = '2001-02-03 04:05:06';

beforeEach(function (): void {
    FixtureModuleServiceProvider::$calls = [];
    FixtureModuleServiceProvider::$installFailsAfterWriting = false;
    FixtureModuleServiceProvider::$withoutMigrations = false;
    FixtureModuleServiceProvider::$installIssuesDdl = false;
    ModuleKernel::flush();

    stampedModuleClock(STAMPED_INSTALL);
    ModuleLifecycle::install(app(), STAMPED_MODULE);
    expect(stampedInstalledAt())->toBe(STAMPED_INSTALL);
    stampedModuleClock('2002-03-04 05:06:07');
});

afterEach(function (): void {
    DB::connection()->disableQueryLog();
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function stampedModuleClock(string $at): void
{
    Carbon::setTestNow(Carbon::parse($at, 'UTC'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse($at, 'UTC'));
}

function stampedInstalledAt(): string
{
    return (string) DB::table('modules')->where('handle', STAMPED_MODULE)->value('installed_at');
}

/** The install time is still the module's, and the one receipt save the write sent named it. */
function stampedInstallKept(Closure $write): void
{
    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();
    $write();
    DB::connection()->disableQueryLog();

    expect(stampedInstalledAt())->toBe(STAMPED_INSTALL);

    $updates = array_values(array_filter(DB::connection()->getQueryLog(), static fn (array $query): bool => preg_match('/\Aupdate\s+[`"]?modules[`"]?\s+set\b/i', $query['query']) === 1));
    expect($updates)->toHaveCount(1);
    $set = preg_split('/\s+where\s+/i', $updates[0]['query'])[0];
    expect(preg_match('/[`"]?installed_at[`"]?\s*=\s*\?/i', $set))->toBe(1);
}

it('keeps when a module was installed through enable and disable', function (): void {
    stampedInstallKept(static fn () => ModuleLifecycle::enable(app(), STAMPED_MODULE));
    expect(Module::isEnabled(STAMPED_MODULE))->toBeTrue();

    stampedInstallKept(static fn () => ModuleLifecycle::disable(app(), STAMPED_MODULE));
    expect(Module::isEnabled(STAMPED_MODULE))->toBeFalse();
});

it('keeps when a module was installed through an upgrade', function (): void {
    // Stale by hand, below the model, keeping the time as it was stored.
    DB::table('modules')->where('handle', STAMPED_MODULE)->update(['version' => '0.0.1-stale', 'installed_at' => STAMPED_INSTALL]);

    stampedInstallKept(static fn () => expect(ModuleLifecycle::upgrade(app(), STAMPED_MODULE))->toContain('Upgraded'));
    expect(DB::table('modules')->where('handle', STAMPED_MODULE)->value('version'))->toBe(ModuleDiscovery::version(STAMPED_MODULE));
});
