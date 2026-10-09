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
 * | T2 | sign-in POST | the org and the address | 10 failures in 15 minutes, counted for unknown addresses too; cleared on success, a finished sign-up and a reset |
 * | T3 | sign-up and recovery POSTs, together | the IP, as T1 | 5 a minute, every POST counted; 30 a day, counted for each POST the minute let through |
 * | T4 | the mail itself, sign-up and recovery together | the org and the address | 1 every 5 minutes; 5 a day, counted for each the 5 minutes let through — silent (Adam, 2026-10-09) |
 *
 * ⚠️ T4 IS SILENT. Over it, the page answers exactly as it does when a mail goes, and no mail goes: a different answer
 * would say the address had been asked for. A named residual: anyone can spend an address's five a day, and so keep its
 * recovery mail from arriving until the day is out. One shared IPv4 address — an office, carrier NAT — shares T3's 30.
 *
 * ⚠️ NO T5, ON USING A LINK. A link is 256 random bits; guessing one is not a rate problem, and using one costs a single
 * hash, which only someone holding a live link can make the server do.
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

    public const LINK_IP_LIMIT = 5;

    public const LINK_IP_DECAY = 60;

    public const LINK_IP_DAY_LIMIT = 30;

    public const MAIL_GAP = 300;

    public const MAIL_DAY_LIMIT = 5;

    public const DAY = 86400;

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

    /**
     * T3: this sign-up or recovery POST counted for this connection, then why it may not go on — the copy key and, for
     * the day's limit, the whole hours to wait — or null when it may.
     *
     * @return array{0: string, 1: ?int}|null
     */
    public static function linkRequestRefusal(#[\SensitiveParameter] ?string $ip): ?array
    {
        $network = self::network($ip);

        if (RateLimiter::hit(self::key('link-ip', $network), self::LINK_IP_DECAY) > self::LINK_IP_LIMIT) {
            return ['throttle.ip', null];
        }

        $day = self::key('link-ip-day', $network);

        return RateLimiter::hit($day, self::DAY) > self::LINK_IP_DAY_LIMIT
            ? ['throttle.ip_day', max(1, (int) ceil(RateLimiter::availableIn($day) / 3600))]
            : null;
    }

    /** T4: this mail counted for this org's address, then whether it may go — silently refused when it may not. */
    public static function mayMail(int $orgId, #[\SensitiveParameter] string $address): bool
    {
        if (RateLimiter::hit(self::key('mail', $orgId.'|'.$address), self::MAIL_GAP) > 1) {
            return false;
        }

        return RateLimiter::hit(self::key('mail-day', $orgId.'|'.$address), self::DAY) <= self::MAIL_DAY_LIMIT;
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
