<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Entitlements\UtcInstant;
use Kitsune\Core\Models\ReaderToken;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use LogicException;
use PDOException;
use RuntimeException;

/**
 * The links core mails to readers, kept as hashes — ADR-037, reader accounts' second part, as built.
 *
 * ⚠️ THE SECRET IS IN THE MAIL AND NOWHERE ELSE. `mint()` makes 43 random characters (256 bits), stores their SHA-256
 * and hands the secret back once, to be mailed; the session later holds the hash, never the secret, so neither a copy
 * of the table nor a session file opens anything.
 *
 * ⚠️ A LINK IS USED BY DELETING IT. `claim()` deletes the row with every condition it was found by, and succeeds only
 * when exactly one row went — the statement is the transaction's first, so two simultaneous uses cannot both win on any
 * engine, SQLite included, where `lockForUpdate()` does nothing.
 *
 * ⚠️ ONE SITE. The site comes from `Context` and nowhere else, for `EntitlementWriter`'s reason: a parameter is one a
 * caller can get wrong. A link mailed from Golfdom is dead at Golfdom FR, and at another org's site it does not exist.
 *
 * ⚠️ THE SQLSTATE ALONE on any failure, as `ReaderAccounts::mapped()`: a query binds an address or a reader's key.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class ReaderTokens
{
    /** Finishing a sign-up: the subject is the normalised address, because no account exists yet. */
    public const REGISTER = 'register';

    /** Choosing a new password: the subject is the reader's key, as `ReaderGuard::key()` spells it. */
    public const RECOVER = 'recover';

    /** How long a link works. */
    public const LIFETIME_MINUTES = 60;

    /** What a secret is: exactly what `mint()` makes. Anything else is refused before a query runs. */
    private const SECRET = '/^[A-Za-z0-9]{43}$/D';

    public function __construct(private readonly Context $context) {}

    /** The stored hash of a secret, or null for anything that is not one — a value from a URL, so untrusted. */
    public static function hashOf(#[\SensitiveParameter] mixed $secret): ?string
    {
        return is_string($secret) && preg_match(self::SECRET, $secret) === 1 ? hash('sha256', $secret) : null;
    }

    /**
     * A new link for this subject on the site in context, replacing any it already had — and its secret, to be mailed.
     *
     * ⚠️ NULL WHEN A SIMULTANEOUS REQUEST MINTED FIRST. Both deleted nothing and both inserted; the unique index lets one
     * row in, and the other request mails nothing. The reader gets one link, not two that race.
     *
     * ⚠️ THE DELETE IS ITS OWN STATEMENT, NOT THE INSERT'S TRANSACTION (review). On InnoDB at REPEATABLE READ a delete
     * that finds no row takes a gap lock, and two transactions that each held one and then inserted into the gap
     * deadlocked — two new addresses at once, even in two organisations, since the gap spans them. Committed at once, the
     * delete's lock is gone before the insert; the insert keeps a transaction of its own, so a unique violation inside a
     * caller's transaction rolls back to its savepoint and leaves PostgreSQL's usable.
     */
    public function mint(string $purpose, #[\SensitiveParameter] string $subject): ?string
    {
        $siteId = $this->context->siteId() ?? throw new LogicException('A reader link is minted on the site in context, and there is none.');
        $secret = Str::random(43);
        $hash = hash('sha256', $secret);

        self::mapped(static fn () => ReaderToken::query()->where('purpose', $purpose)->where('subject', $subject)->delete());

        try {
            self::mapped(static fn () => TransactionRecovery::run((new ReaderToken)->getConnection(), static function () use ($siteId, $purpose, $subject, $hash): void {
                ReaderToken::query()->forceCreate([
                    'site_id' => $siteId,
                    'purpose' => $purpose,
                    'subject' => $subject,
                    'token_hash' => $hash,
                    'expires_at' => CarbonImmutable::now('UTC')->startOfSecond()->addMinutes(self::LIFETIME_MINUTES),
                ]);
            }), passing: UniqueConstraintViolationException::class);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return $secret;
    }

    /** The live link with this hash for this purpose on the site in context, or null. */
    public function live(string $purpose, #[\SensitiveParameter] string $hash): ?ReaderToken
    {
        $siteId = $this->context->siteId();

        if ($siteId === null) {
            return null;
        }

        return self::mapped(static fn () => ReaderToken::query()
            ->where('site_id', $siteId)
            ->where('purpose', $purpose)
            ->where('token_hash', $hash)
            ->where('expires_at', '>', self::now())
            ->first());
    }

    /**
     * Uses the link up: true only when this request deleted it, still live, by every condition it was found by.
     *
     * Run it first in the transaction that acts on it, so a failure after it puts the link back.
     */
    public function claim(#[\SensitiveParameter] ReaderToken $token): bool
    {
        $siteId = $this->context->siteId();

        return $siteId !== null && self::mapped(static fn () => ReaderToken::query()
            ->whereKey($token->getKey())
            ->where('site_id', $siteId)
            ->where('purpose', $token->purpose)
            ->where('token_hash', $token->token_hash)
            ->where('expires_at', '>', self::now())
            ->delete()) === 1;
    }

    /** Deletes this subject's link for this purpose, live or not — how many went. */
    public function forgetSubject(string $purpose, #[\SensitiveParameter] string $subject): int
    {
        return self::mapped(static fn () => ReaderToken::query()->where('purpose', $purpose)->where('subject', $subject)->delete());
    }

    /**
     * The links waiting for a reader of the org in context — by key, by address, or both — for an export. The hash, the
     * subject and the secret never leave; an expired link not yet swept is listed, because it is still held.
     *
     * @return list<array{purpose: string, site: ?string, expires_at: string}>
     */
    public function pending(#[\SensitiveParameter] ?string $key, #[\SensitiveParameter] ?string $address): array
    {
        $subjects = array_filter([self::RECOVER => $key, self::REGISTER => $address], static fn (#[\SensitiveParameter] ?string $subject): bool => $subject !== null);

        if ($subjects === []) {
            return [];
        }

        $rows = self::mapped(static fn () => ReaderToken::query()
            ->where(static function ($query) use ($subjects): void {
                foreach ($subjects as $purpose => $subject) {
                    $query->orWhere(static fn ($one) => $one->where('purpose', $purpose)->where('subject', $subject));
                }
            })
            ->orderBy('id')
            ->get(['site_id', 'purpose', 'expires_at']));

        $handles = Site::query()->whereKey($rows->pluck('site_id')->unique()->all())->pluck('handle', 'id');

        return array_values($rows->map(static fn (ReaderToken $row): array => [
            'purpose' => $row->purpose,
            'site' => $handles[$row->site_id] ?? null,
            'expires_at' => $row->expires_at->format('Y-m-d\TH:i:s\Z'),
        ])->all());
    }

    /**
     * Deletes the org's expired links — run after the response, and only while that org is still the one in context.
     *
     * ⚠️ THROUGH THE SCOPE, NOT AROUND IT: a deferred callback runs before the application's own terminate, so the
     * request's context is still bound (verified in the kernel). The check makes a context that changed sweep nothing.
     */
    public function sweep(int $orgId): int
    {
        if ($this->context->orgId() !== $orgId) {
            return 0;
        }

        return self::mapped(static fn () => ReaderToken::query()->where('expires_at', '<=', self::now())->delete());
    }

    /** Now, as the table stores an instant: UTC wall clock in whole seconds. */
    private static function now(): string
    {
        return UtcInstant::stored(CarbonImmutable::now('UTC'));
    }

    /**
     * A read or write of the link table, failing with its SQLSTATE alone.
     *
     * @template T
     *
     * @param  Closure(): T  $query
     * @param  class-string<\Throwable>|null  $passing  an exception the caller handles itself, passed on untouched
     * @return T
     */
    private static function mapped(Closure $query, ?string $passing = null): mixed
    {
        try {
            return $query();
        } catch (PDOException $e) {
            if ($passing !== null && $e instanceof $passing) {
                throw $e;
            }

            $state = isset($e->errorInfo[0]) && is_string($e->errorInfo[0])
                ? $e->errorInfo[0]
                : (preg_match('/SQLSTATE\[(\w{5})\]/', $e->getMessage(), $match) === 1 ? $match[1] : (string) $e->getCode());

            throw new RuntimeException("The reader link table could not be read or written (SQLSTATE {$state}).");
        }
    }
}
