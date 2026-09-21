<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Fixture\Module;

use Filament\Navigation\NavigationItem;
use Kitsune\Core\Modules\AdminSurface;
use Kitsune\Core\Modules\ModuleServiceProvider;
use RuntimeException;

/**
 * A module provider that records being registered and booted, so a test can assert the kernel reached it
 * rather than assert that the kernel thinks it did.
 */
final class FixtureModuleServiceProvider extends ModuleServiceProvider
{
    /** @var list<string> */
    public static array $calls = [];

    protected function registerModule(): void
    {
        self::$calls[] = 'register';

        /*
         * The seam, filled in registerModule() — in time because the kernel runs inside $app->booted(), and
         * KitsunePanel::apply() does not run until PanelRegistry is first resolved.
         */
        $this->app->make(AdminSurface::class)
            ->resource(FixtureThingResource::class)
            ->navigationItem(
                NavigationItem::make('Fixture things')
                    ->group('Structure')
                    ->url(fn (): string => '/admin/fixture-things'),
            );
    }

    protected function bootModule(): void
    {
        self::$calls[] = 'boot';
    }

    public function migrationPath(): ?string
    {
        return __DIR__.'/../database/migrations';
    }

    public function install(): void
    {
        self::$calls[] = 'install';
    }

    /**
     * ⚠️ THE MODULE'S OWN REFUSAL. Core cannot know what this module's content is, so uninstall asks the
     * module and the module throws. Nothing is rolled back before this runs.
     */
    public function uninstall(): void
    {
        self::$calls[] = 'uninstall';

        if (FixtureThing::query()->exists()) {
            throw new RuntimeException('kitsune/fixture-module still holds things. Delete them first.');
        }
    }
}
