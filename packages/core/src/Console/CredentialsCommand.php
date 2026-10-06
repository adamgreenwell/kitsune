<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Kitsune\Core\Credentials\CredentialCipher;
use Kitsune\Core\Models\Credential;

/**
 * How many credentials this installation's app key can open — ADR-040, and read-only.
 *
 * ⚠️ COUNTS AND NOTHING ELSE. Its output lands in deploy logs, so it names no org, no credential and no value, and it
 * decrypts nothing: a key id says which derived key sealed a row. `--strict` makes it a canary for a wrong or lost
 * `APP_KEY`, exiting 1 while anything stored is unreadable — not wired into `deploy/release.sh` in this slice.
 *
 * ⚠️ NO WRITE ACTION (Adam, 2026-10-05: the console tools come with payments). When one comes, it reads a value from a
 * hidden prompt or the first line of standard input only (ADR-026's rule), never from an argument or the environment.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class CredentialsCommand extends Command
{
    protected $signature = 'kitsune:credentials
        {action : status}
        {--strict : Exit non-zero while any stored credential is unreadable, for use as a deployment gate}';

    protected $description = 'Count stored credentials by the app key that can open them (ADR-040)';

    public function handle(CredentialCipher $cipher): int
    {
        if ($this->argument('action') !== 'status') {
            // Not echoed: whatever was typed in its place may have been a value.
            $this->error('Refusing: kitsune:credentials has one action, status.');

            return self::FAILURE;
        }

        [$byKey, $orgs] = Schema::hasTable('credentials') ? self::counts() : [[], 0];

        $current = $cipher->currentKeyId();
        $previous = $cipher->previousKeyIds();
        $underCurrent = $underPrevious = $underNone = 0;

        foreach ($byKey as $keyId => $count) {
            match (true) {
                $current !== null && $keyId === $current => $underCurrent += $count,
                in_array($keyId, $previous, true) => $underPrevious += $count,
                default => $underNone += $count,
            };
        }

        $this->line(sprintf('Credentials: %d stored, across %d organisations.', $underCurrent + $underPrevious + $underNone, $orgs));
        $this->line(sprintf('  %d under the current app key.', $underCurrent));
        $this->line(sprintf('  %d under a previous app key (APP_PREVIOUS_KEYS): keep that key until this reads 0. Replacing a credential re-encrypts it.', $underPrevious));
        $this->line(sprintf('  %d under no key this installation has: unreadable until set again.', $underNone));

        return $this->option('strict') && $underNone > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{0: array<string, int>, 1: int} stored values by the key id that sealed them, and the orgs holding any */
    private static function counts(): array
    {
        return Credential::withoutScopeBecause(
            'the installation-wide count of credentials by app key, which names no organisation',
            static function ($query): array {
                $byKey = [];

                foreach ((clone $query)->toBase()->whereNotNull('ciphertext')->selectRaw('key_id, count(*) as n')->groupBy('key_id')->get() as $row) {
                    $byKey[(string) $row->key_id] = (int) $row->n;
                }

                return [$byKey, (clone $query)->toBase()->whereNotNull('ciphertext')->distinct()->count('org_id')];
            },
        );
    }
}
