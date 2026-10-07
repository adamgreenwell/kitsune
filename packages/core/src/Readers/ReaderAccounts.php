<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers;

use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Readers\Contracts\ReaderAccount;
use Kitsune\Core\Settings\SettingsGuard;
use Kitsune\Core\Settings\SettingsResolver;
use PDOException;
use RuntimeException;

/**
 * Whether reader accounts can work here, and in which mode on a site — ADR-037, as built.
 *
 * ⚠️ FAIL CLOSED, TWICE. A fault (no usable guard, a model without the contract, a guard that is not a session's) makes
 * every reader page a 404, and so does any mode but `sign-in` or `open` — a host `settings` map that dropped the key,
 * or a value written past `SettingsGuard`, reads as `off`. The console says why; a stranger learns nothing.
 *
 * ⚠️ NO MEMO, for `ReaderGuard`'s reason: a provider change in a test or a long-lived worker must be seen.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class ReaderAccounts
{
    public function __construct(
        private readonly ReaderGuard $guard,
        private readonly SettingsResolver $settings,
    ) {}

    /** Why no reader can use an account here, or null when core's front door can open. */
    public function fault(): ?ReaderAccountsFault
    {
        return $this->inspect()[0];
    }

    /** The fault in words, or null when there is none. */
    public function faultSentence(): ?string
    {
        [$fault, $name, $model] = $this->inspect();

        return $fault?->sentence($name ?? '', $model);
    }

    /** The declared reader guard's name, when accounts can work. */
    public function guardName(): ?string
    {
        [$fault, $name] = $this->inspect();

        return $fault === null ? $name : null;
    }

    /**
     * The reader model, when accounts can work.
     *
     * @return class-string<Model&Authenticatable&ReaderAccount>|null
     */
    public function model(): ?string
    {
        [$fault, , $model] = $this->inspect();

        return $fault === null && is_subclass_of($model, ReaderAccount::class) ? $model : null;
    }

    /** The site's mode: `sign-in` or `open` as stored or inherited, and `off` for anything else. */
    public function mode(Site $site): ReaderMode
    {
        $value = $this->settings->get($site, SettingsGuard::READER_ACCOUNTS);

        return is_string($value) ? (ReaderMode::tryFrom($value) ?? ReaderMode::Off) : ReaderMode::Off;
    }

    /**
     * A read or write of the host's reader table, failing with its SQLSTATE alone.
     *
     * ⚠️ A `QueryException`'s message interpolates its bindings, and a reader query binds an address or a reader's key,
     * so neither the message nor the exception is passed on: a log line names no reader.
     *
     * @template T
     *
     * @param  Closure(): T  $query
     * @return T
     */
    public static function mapped(Closure $query): mixed
    {
        try {
            return $query();
        } catch (PDOException $e) {
            // `QueryException` is a PDOException, and so is Laravel's `DeadlockException`.
            $state = isset($e->errorInfo[0]) && is_string($e->errorInfo[0])
                ? $e->errorInfo[0]
                : (preg_match('/SQLSTATE\[(\w{5})\]/', $e->getMessage(), $match) === 1 ? $match[1] : (string) $e->getCode());

            throw new RuntimeException("The readers table could not be read or written (SQLSTATE {$state}).");
        }
    }

    /**
     * The fault, the guard's name and its model.
     *
     * @return array{0: ?ReaderAccountsFault, 1: ?string, 2: ?class-string<Model&Authenticatable>}
     */
    private function inspect(): array
    {
        $name = $this->guard->name();
        $model = $this->guard->model();

        if ($name === null || $model === null) {
            return [ReaderAccountsFault::Guard, null, null];
        }

        if (! is_subclass_of($model, ReaderAccount::class)) {
            return [ReaderAccountsFault::NotAnAccount, $name, $model];
        }

        // `ReaderGuard` has already built it, so this neither throws nor queries.
        if (! Auth::guard($name) instanceof SessionGuard) {
            return [ReaderAccountsFault::NotSession, $name, $model];
        }

        return [null, $name, $model];
    }
}
