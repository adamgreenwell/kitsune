<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Console;

use Closure;
use Illuminate\Console\Command;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialSlot;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;
use Throwable;

/** Shared explicit-org bootstrap and scope restoration for credential maintenance. @internal */
abstract class CredentialOperatorCommand extends Command
{
    /** @param Closure(): int $operation */
    protected function forOrg(Context $context, Closure $operation, bool $withDeleted = false): int
    {
        $beforeOrg = $context->org();
        $beforeSite = $context->site();

        try {
            $slug = $this->option('org');
            if (! is_string($slug) || $slug === '' || strlen($slug) > 255 || preg_match('/[\x00-\x20\x7f]/', $slug) === 1) {
                return $this->refuse('An explicit --org=<slug> is required.');
            }
            // Org is the declared unscoped bootstrap: its slug establishes the context, never a credential row.
            $query = $withDeleted ? Org::withTrashed() : Org::query();
            $org = $query->useWritePdo()->where('slug', $slug)->first();
            if ($org === null) {
                return $this->refuse('The selected organisation is unavailable.');
            }
            $context->forget()->setOrg($org);

            return $operation();
        } catch (Throwable) {
            // SQL, decryption and host listeners can carry secrets or ciphertext. No raw error escapes the command.
            return $this->refuse('The credential operation could not complete. Its error is not repeated; check configuration and retry.');
        } finally {
            $context->forget();
            $beforeSite !== null ? $context->setSite($beforeSite) : $context->setOrg($beforeOrg);
        }
    }

    protected function slot(mixed $slot): string
    {
        if (! is_string($slot) || ! CredentialSlot::isName($slot)) {
            throw new RuntimeException('A canonical declared credential name is required.');
        }

        return $slot;
    }

    protected function mode(mixed $value): ?CredentialMode
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || ($mode = CredentialMode::tryFrom($value)) === null) {
            throw new RuntimeException('Mode must be test or live; omit it for a mode-neutral credential.');
        }

        return $mode;
    }

    protected function refuse(string $message): int
    {
        $this->error('Refusing: '.$message);

        return self::FAILURE;
    }
}
