<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Console;

use Kitsune\Core\Credentials\CredentialConsoleAuthority;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialRefused;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Tenancy\Context;

/** Passwordless-host recovery is an explicit console operation, never a web bypass. @internal */
final class CredentialModeCommand extends CredentialOperatorCommand
{
    protected $signature = 'kitsune:credentials:mode
        {mode : test or live}
        {--org= : The organisation slug}
        {--force : Confirm changing the org credential mode}';

    protected $description = 'Explicitly select an org test/live credential mode';

    public function handle(Context $context, CredentialWriter $writer): int
    {
        return $this->forOrg($context, function () use ($context, $writer): int {
            $mode = CredentialMode::tryFrom($this->argument('mode'));
            if ($mode === null || ! $this->option('force')) {
                return $this->refuse('An explicit test or live mode and --force are required.');
            }
            CredentialConsoleAuthority::run($context, CredentialRefused::SWITCH, null, $mode, null,
                fn (CredentialConsoleAuthority $console) => $writer->switchTo($mode, console: $console));
            $this->line('Organisation credential mode selected. This does not qualify a provider or enable payments.');

            return self::SUCCESS;
        });
    }
}
