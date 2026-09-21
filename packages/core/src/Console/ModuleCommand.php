<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Console;

use Illuminate\Console\Command;
use Kitsune\Core\Modules\ModuleLifecycle;
use Throwable;

/**
 * Install, enable, disable, upgrade and uninstall a module — ADR-038.
 *
 * ⚠️ ONE COMMAND, NOT SIX, and the repo's own precedent is the reason: `kitsune:audit-patterns` reads and
 * `kitsune:schema-sync` writes, so the split that exists here is by blast radius rather than by verb. Six
 * module commands would be six names to freeze at v1.2 for one subject.
 *
 * The work lives in `ModuleLifecycle`, so it is testable without a console and this class is argument
 * handling. Every refusal arrives as an exception carrying its reason, which is printed rather than
 * swallowed — a lifecycle step that fails half-way is worse than one that never started, and the reason is
 * the only thing that tells an operator which of those happened.
 */
final class ModuleCommand extends Command
{
    private const ACTIONS = ['status', 'install', 'enable', 'disable', 'upgrade', 'uninstall'];

    protected $signature = 'kitsune:module {action=status : status, install, enable, disable, upgrade or uninstall} {package? : the Composer package, e.g. kitsune/person}';

    protected $description = 'Install, enable, disable, upgrade or uninstall a Kitsune module (ADR-038)';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        if (! in_array($action, self::ACTIONS, true)) {
            $this->error("`{$action}` is not a module action. Use: ".implode(', ', self::ACTIONS).'.');

            return self::FAILURE;
        }

        if ($action === 'status') {
            return $this->status();
        }

        $package = $this->argument('package');

        if (! is_string($package) || $package === '') {
            $this->error("`kitsune:module {$action}` needs a package, e.g. `kitsune:module {$action} kitsune/person`.");

            return self::FAILURE;
        }

        try {
            $this->info(match ($action) {
                'install' => ModuleLifecycle::install($this->laravel, $package),
                'enable' => ModuleLifecycle::enable($this->laravel, $package),
                'disable' => ModuleLifecycle::disable($this->laravel, $package),
                'upgrade' => ModuleLifecycle::upgrade($this->laravel, $package),
                'uninstall' => ModuleLifecycle::uninstall($this->laravel, $package),
            });
        } catch (Throwable $e) {
            /* The reason, not "it failed": it is the only thing that says how far the step got. */
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function status(): int
    {
        $rows = ModuleLifecycle::status();

        if ($rows === []) {
            $this->line('No modules are installed.');

            return self::SUCCESS;
        }

        $this->table(
            ['Module', 'Version', 'Enabled', 'Status'],
            array_map(static fn (array $row): array => [
                $row['handle'],
                $row['version'],
                $row['enabled'] ? 'yes' : 'no',
                $row['status'],
            ], $rows),
        );

        return self::SUCCESS;
    }
}
