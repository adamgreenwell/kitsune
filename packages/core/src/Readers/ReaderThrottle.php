<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers;

use Illuminate\Support\Facades\RateLimiter;

/**
 * How often the reader doors may be knocked on — ADR-037, as built, on Laravel's `RateLimiter` and the default cache
 * store (`file` at the floor; no Redis).
 *
 * | | Door | Key | Limit |
 * |---|---|---|---|
 * | T1 | sign-in POST | the IP (an IPv6 address by its /64) | 5 a minute, every POST counted, success included |
 * | T2 | sign-in POST | the org and the address | 10 failures in 15 minutes, counted for unknown addresses too; cleared on success |
 *
 * ⚠️ NO ADDRESS AND NO IP IN A CACHE KEY. Each key is an HMAC with the app key, so a cache file says nothing about who
 * knocked, and a key cannot be linked to an address without the app key.
 *
 * ⚠️ T1 BEFORE ANYTHING IS CHECKED: over its limit, nobody is signed in, even with the right password. T2 counts
 * whether or not the address exists, so its answer says nothing about whether it does.
 *
 * ⚠️ COUNTED FIRST, THEN DECIDED (review): each door adds the attempt and refuses on the count that comes back. Asking
 * first and adding after the hash check let every attempt that arrived during one check's quarter of a second through.
 * T2 adds the attempt before the hash, so a success clears it again.
 *
 * ⚠️ A NAMED RESIDUAL OF THE FLOOR'S `file` STORE: its increment is a read and a write with no lock, so attempts that
 * land at the same instant can be counted once. A store whose increment is atomic — `database`, `redis` — has no such
 * gap; at the floor, a burst across many workers gets a few attempts past either limit before the count catches up.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class ReaderThrottle
{
    public const SIGN_IN_IP_LIMIT = 5;

    public const SIGN_IN_IP_DECAY = 60;

    public const ACCOUNT_LIMIT = 10;

    public const ACCOUNT_DECAY = 900;

    /** T1: this attempt counted, then true when it is over this connection's limit. */
    public static function signInFromIpRefused(#[\SensitiveParameter] ?string $ip): bool
    {
        return RateLimiter::hit(self::key('sign-in-ip', self::network($ip)), self::SIGN_IN_IP_DECAY) > self::SIGN_IN_IP_LIMIT;
    }

    /**
     * T2: this attempt counted for this org's address, then the whole minutes until it may try again when it is over
     * the limit — or null when it may go on to the hash. A success clears the count (`accountCleared()`), so what stays
     * counted is failures.
     */
    public static function accountAttempt(int $orgId, #[\SensitiveParameter] string $address): ?int
    {
        $key = self::accountKey($orgId, $address);

        return RateLimiter::hit($key, self::ACCOUNT_DECAY) > self::ACCOUNT_LIMIT
            ? max(1, (int) ceil(RateLimiter::availableIn($key) / 60))
            : null;
    }

    /** T2: the count for this org's address starts again. */
    public static function accountCleared(int $orgId, #[\SensitiveParameter] string $address): void
    {
        RateLimiter::clear(self::accountKey($orgId, $address));
    }

    /** The cache key for an org's address: the org and the address HMAC'd together, never either in the clear. */
    public static function accountKey(int $orgId, #[\SensitiveParameter] string $address): string
    {
        return self::key('account', $orgId.'|'.$address);
    }

    /**
     * The network an address is counted under: an IPv4 address as itself, an IPv6 address by its first 64 bits —
     * one subscriber's allocation, which a single machine can walk through without end.
     *
     * ⚠️ AN IPv4-MAPPED ADDRESS IS ITS IPv4 ADDRESS (review). A dual-stack listener reports every IPv4 visitor as
     * `::ffff:a.b.c.d`, whose first 64 bits are all zero: grouped by /64, every IPv4 visitor shared one bucket, and one
     * of them could refuse sign-in to all the others.
     */
    public static function network(#[\SensitiveParameter] ?string $ip): string
    {
        $packed = is_string($ip) ? @inet_pton($ip) : false;

        if (! is_string($packed)) {
            return 'unknown';
        }

        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return bin2hex(substr($packed, 12));
        }

        return strlen($packed) === 16 ? bin2hex(substr($packed, 0, 8)).'/64' : bin2hex($packed);
    }

    private static function key(string $door, #[\SensitiveParameter] string $subject): string
    {
        return 'kitsune:readers:'.$door.':'.hash_hmac('sha256', $subject, (string) config('app.key'));
    }
}
