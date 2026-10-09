<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Models\ReaderToken;
use Kitsune\Core\Readers\Contracts\ReaderAccount;
use Kitsune\Core\Tenancy\Concerns\ReadsWrittenKeys;
use Kitsune\Core\Tenancy\Context;
use LogicException;
use PDOException;

/**
 * What using a mailed link does — finishing a sign-up, or choosing a new password — in one transaction with the link's
 * use. ADR-037, reader accounts' second part, as built. No HTTP request is needed, so the LevelZero tests drive it.
 *
 * ⚠️ THE LINK GOES FIRST, THEN THE READER. Each transaction opens by deleting the link (`ReaderTokens::claim()`), so two
 * uses of one link cannot both win, and a failure after it puts the link back. Locks are taken in one order everywhere
 * a link and a reader meet — the link's row, then the reader's — erasure included.
 *
 * ⚠️ A HOST WHOSE READER MODEL LIVES ON ANOTHER DATABASE gets the link's deletion and the reader's write in two
 * transactions, as `ReaderErasure` says of entitlements; the link still goes first.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class ReaderLinkUse
{
    use ReadsWrittenKeys;

    /** The link was used up, or was never live. */
    public const DEAD = 'link-dead';

    /** Someone already has an account with this address; the link is used up, and nothing else changed. */
    public const EXISTS = 'address-taken';

    /** The account was made. */
    public const CREATED = 'account-made';

    public function __construct(
        private readonly ReaderTokens $tokens,
        private readonly ReaderAccounts $accounts,
        private readonly ReaderExport $readers,
        private readonly Context $context,
    ) {}

    /**
     * Finishes a sign-up: the link used up and the account made, together — or the link used up alone, when the
     * address has an account by now.
     *
     * ⚠️ A SIMULTANEOUS SIGN-UP OF THE SAME ADDRESS (two links cannot exist, but a host's own import can make the row)
     * reaches the unique index, which rolls the whole transaction back — the link's deletion with it. So the link is
     * deleted again on its own, and the answer is `EXISTS`: a link works once, whatever happened.
     *
     * @return array{0: self::DEAD|self::EXISTS|self::CREATED, 1: (Model&Authenticatable&ReaderAccount)|null}
     */
    public function complete(#[\SensitiveParameter] ReaderToken $token, #[\SensitiveParameter] string $passwordHash): array
    {
        $model = $this->accounts->model() ?? throw new LogicException('Reader accounts are not usable here; a reader route should have answered 404.');
        $orgId = $this->context->orgId();

        try {
            return TransactionRecovery::run($token->getConnection(), function () use ($token, $passwordHash, $model, $orgId): array {
                if (! $this->tokens->claim($token)) {
                    return [self::DEAD, null];
                }

                $found = ReaderAccounts::mapped(static fn () => $model::findByEmail($token->subject));

                if ($found !== null && self::writtenKey($found->getAttributes()['org_id'] ?? null) === $orgId) {
                    return [self::EXISTS, null];
                }

                try {
                    // ⚠️ NOT through `mapped()`, which would turn the unique violation into a refusal like any other.
                    $reader = $model::createReader($token->subject, $passwordHash, now());
                } catch (UniqueConstraintViolationException $violation) {
                    throw $violation;
                } catch (PDOException $failure) {
                    throw ReaderAccounts::refusal($failure);
                }

                return [self::CREATED, $reader];
            });
        } catch (UniqueConstraintViolationException) {
            $this->tokens->forgetSubject(ReaderTokens::REGISTER, $token->subject);

            return [self::EXISTS, null];
        }
    }

    /**
     * Chooses a new password: the link used up and the password changed, together. Null when the link is dead or its
     * reader no longer exists.
     *
     * ⚠️ THE REMEMBER TOKEN CYCLES TOO, so the session binding of every other session changes (`ReaderSessions`) and,
     * once remember-me lands, no recaller cookie outlives a reset. The instance handed back is the one written, so the
     * session signed in with it holds the new binding.
     *
     * @return (Model&Authenticatable&ReaderAccount)|null
     */
    public function reset(#[\SensitiveParameter] ReaderToken $token, #[\SensitiveParameter] string $passwordHash): ?Model
    {
        return TransactionRecovery::run($token->getConnection(), function () use ($token, $passwordHash): ?Model {
            if (! $this->tokens->claim($token)) {
                return null;
            }

            $reader = $this->readers->find($token->subject, lock: true);

            if ($reader === null) {
                return null;
            }

            ReaderAccounts::mapped(static function () use ($reader, $passwordHash): void {
                $reader->forceFill([$reader->getAuthPasswordName() => $passwordHash]);
                $reader->setRememberToken(Str::random(60));
                $reader->save();
            });

            return $reader;
        });
    }
}
