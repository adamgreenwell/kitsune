<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Payments;

use Illuminate\Database\Connection;
use Kitsune\Core\Tenancy\Context;

/** Explicit provider mode and unchanged original API-key custody. Admission is a separate new-sale gate. @internal */
final class StripeMode
{
    public static function isTest(mixed $mode): bool
    {
        if (! in_array($mode, ['test', 'live'], true)) {
            throw new CheckoutRefused;
        }

        return $mode === 'test';
    }

    public static function fromTest(mixed $test): string
    {
        if (! is_bool($test)) {
            throw new CheckoutRefused;
        }

        return $test ? 'test' : 'live';
    }

    public static function session(mixed $id, mixed $mode): bool
    {
        self::isTest($mode);

        return is_string($id) && preg_match('/\Acs_'.$mode.'_[A-Za-z0-9_]{1,240}\z/D', $id) === 1;
    }

    public static function current(Connection $connection, int $org): string
    {
        if (app(Context::class)->orgId() !== $org || $connection->transactionLevel() < 1) {
            throw new CheckoutRefused;
        }
        $row = $connection->table('org_credential_modes')->useWritePdo()->where('org_id', $org)->lockForUpdate()->first();
        $mode = $row->mode ?? 'test';
        self::isTest($mode);

        return $mode;
    }

    public static function witness(Connection $connection, int $org, string $mode): string
    {
        if (self::current($connection, $org) !== $mode) {
            throw new CheckoutRefused('changed');
        }
        $row = $connection->table('credentials')->useWritePdo()->where('org_id', $org)
            ->where('slot', StripeCheckout::SLOT)->where('mode', $mode)->lockForUpdate()->first();
        if ($row === null || ! is_string($row->ciphertext) || $row->ciphertext === '' || ! is_string($row->key_id)) {
            throw new CheckoutRefused('setup');
        }

        // This exact encoding preserves every already-recorded test checkout witness.
        return hash('sha256', implode("\0", [(string) $row->id, (string) $row->org_id, $row->slot, $row->mode,
            $row->ciphertext, $row->key_id, $row->changed_at]));
    }
}
