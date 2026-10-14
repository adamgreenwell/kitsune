<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Console;

use Kitsune\Core\Credentials\CredentialConsoleAuthority;
use Kitsune\Core\Credentials\CredentialRefused;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Tenancy\Context;

/** Remove one explicitly named credential; no value or actor is inferred from the shell. @internal */
final class CredentialRemoveCommand extends CredentialOperatorCommand
{
    protected $signature = 'kitsune:credentials:remove
        {slot : The declared credential name}
        {--org= : The organisation slug}
        {--mode= : test or live; omit for a mode-neutral credential}
        {--force : Confirm removal of this credential}';

    protected $description = 'Remove one org credential while retaining its audited tombstone';

    public function handle(Context $context, CredentialWriter $writer): int
    {
        return $this->forOrg($context, function () use ($context, $writer): int {
            if (! $this->option('force')) {
                return $this->refuse('Removal requires --force.');
            }
            $slot = $this->slot($this->argument('slot'));
            $mode = $this->mode($this->option('mode'));
            CredentialConsoleAuthority::run($context, CredentialRefused::REMOVE, $slot, $mode, null,
                fn (CredentialConsoleAuthority $console) => $writer->remove($slot, $mode, console: $console));
            $this->line('Credential removed, or already absent.');

            return self::SUCCESS;
        });
    }
}
