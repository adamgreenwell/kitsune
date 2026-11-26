<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Console;

use Illuminate\Console\Command;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Themes\ThemeRegistry;
use RuntimeException;
use Throwable;

final class SiteThemeCommand extends Command
{
    protected $signature = 'kitsune:theme
        {action : list, select or clear}
        {theme? : A discovered theme handle}
        {--org= : The organisation slug}
        {--site= : The Site handle}';

    protected $description = 'Discover Blade themes or choose one for a Site';

    public function handle(ThemeRegistry $registry, Context $context, Auditor $auditor): int
    {
        $action = $this->argument('action');
        if ($action === 'list') {
            foreach ($registry->all() as $theme) {
                $this->line($theme->handle);
            }

            return self::SUCCESS;
        }

        $name = $this->argument('theme');
        $theme = $action === 'select' && is_string($name) ? $registry->find($name) : null;
        if (($action === 'select' && $theme === null) || ($action !== 'select' && ($action !== 'clear' || $name !== null))) {
            $this->error('Choose a discovered theme with select, or use clear without a theme.');

            return self::FAILURE;
        }

        $slug = $this->option('org');
        $handle = $this->option('site');
        if (! is_string($slug) || $slug === '' || ! is_string($handle) || $handle === '') {
            $this->error('Theme selection requires --org and --site.');

            return self::FAILURE;
        }

        $previousOrg = $context->org();
        $previousSite = $context->site();

        try {
            $org = Org::query()->useWritePdo()->where('slug', $slug)->first();
            if ($org === null) {
                $this->error('The selected organisation is unavailable.');

                return self::FAILURE;
            }

            $context->forget()->setOrg($org);
            $site = Site::query()->useWritePdo()->where('handle', $handle)->first();
            if ($site === null) {
                $this->error('The selected Site is unavailable in this organisation.');

                return self::FAILURE;
            }

            $site->getConnection()->transaction(function () use ($site, $theme, $auditor): void {
                // Site::save() owns the host-claim lock order.
                $current = $site->newQuery()->useWritePdo()->whereKey($site->id)->firstOrFail();
                $current->theme = $theme?->handle;

                if (! $current->save()) {
                    throw new RuntimeException('Theme selection was refused.');
                }

                if ($current->wasChanged('theme')) {
                    $auditor->recordOrFail('site.theme_changed', $current);
                }
            });

            $this->info($theme === null ? 'Theme cleared for the selected Site.' : 'Theme selected for the Site.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('The Site theme could not be changed.');

            return self::FAILURE;
        } finally {
            $context->forget();
            $previousSite === null ? $context->setOrg($previousOrg) : $context->setSite($previousSite);
        }
    }
}
