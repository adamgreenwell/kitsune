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

/** Explicit operator recovery, with a hidden or piped value and a single exact write capability. @internal */
final class CredentialSetCommand extends CredentialOperatorCommand
{
    protected $signature = 'kitsune:credentials:set
        {slot : The declared credential name, never its value}
        {--org= : The organisation slug}
        {--mode= : test or live; omit for a mode-neutral credential}
        {--stdin : Read the first bounded line of standard input instead of a hidden prompt}';

    protected $description = 'Set one encrypted credential using a hidden prompt or standard input';

    public function handle(Context $context, CredentialWriter $writer): int
    {
        return $this->forOrg($context, function () use ($context, $writer): int {
            $slot = $this->slot($this->argument('slot'));
            $mode = $this->mode($this->option('mode'));
            $value = CredentialInput::read($this->input, $this->output, (bool) $this->option('stdin'));
            try {
                CredentialConsoleAuthority::run($context, CredentialRefused::SET, $slot, $mode, null,
                    fn (CredentialConsoleAuthority $console) => $writer->set($slot, $mode, $value->reveal(), console: $console));
            } finally {
                unset($value);
            }
            $this->line('Credential saved. Its value is never shown.');

            return self::SUCCESS;
        });
    }
}
