<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Console;

use Kitsune\Core\Credentials\CredentialConsoleAuthority;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Models\Credential;
use Kitsune\Core\Tenancy\Context;
use Throwable;

/** Bounded key rotation, one durable audited row at a time, including undeclared and disabled slots. @internal */
final class CredentialReencryptCommand extends CredentialOperatorCommand
{
    protected $signature = 'kitsune:credentials:reencrypt
        {--org= : The organisation slug, including a soft-deleted org whose keys must be preserved}
        {--limit=100 : Maximum rows inspected, from 1 through 1000}
        {--after=0 : Resume after this completed credential row ID}';

    protected $description = 'Re-encrypt a bounded org credential batch under the current app key';

    public function handle(Context $context, CredentialWriter $writer): int
    {
        return $this->forOrg($context, function () use ($context, $writer): int {
            $limit = $this->integer($this->option('limit'), 1000, false);
            $after = $this->integer($this->option('after'), PHP_INT_MAX, true);
            if ($limit === null || $after === null) {
                return $this->refuse('Use --limit=1..1000 and a nonnegative --after row ID.');
            }
            $rows = Credential::query()->useWritePdo()->where('id', '>', $after)->orderBy('id')->limit($limit + 1)->get(['id']);
            $changed = $unchanged = 0;
            $cursor = $after;
            foreach ($rows->take($limit) as $row) {
                $id = (int) $row->getKey();
                try {
                    $rotated = CredentialConsoleAuthority::run($context, 'reencrypt', null, null, $id,
                        fn (CredentialConsoleAuthority $console): bool => $writer->reencrypt($id, $console));
                } catch (Throwable) {
                    $this->line(sprintf('Re-encrypted %d; unchanged %d. Last completed row ID: %d.', $changed, $unchanged, $cursor));

                    return $this->refuse('Rotation stopped on an unreadable or unwritable credential. Earlier completed rows remain; retain previous keys, repair the failure and retry after the last completed row.');
                }
                $rotated ? $changed++ : $unchanged++;
                $cursor = $id;
            }
            $this->line(sprintf('Re-encrypted %d; unchanged %d. Last completed row ID: %d.', $changed, $unchanged, $cursor));
            $this->line($rows->count() > $limit
                ? sprintf('More rows remain. Repeat with --after=%d and the same org.', $cursor)
                : 'This batch reached the end of the org records. Run credentials status --strict before removing any previous key.');

            return self::SUCCESS;
        }, withDeleted: true);
    }

    private function integer(mixed $value, int $max, bool $zero): ?int
    {
        if (! is_string($value) || preg_match($zero ? '/\A(?:0|[1-9][0-9]*)\z/D' : '/\A[1-9][0-9]*\z/D', $value) !== 1
            || strlen($value) > strlen((string) $max) || (strlen($value) === strlen((string) $max) && strcmp($value, (string) $max) > 0)) {
            return null;
        }

        return (int) $value;
    }
}
