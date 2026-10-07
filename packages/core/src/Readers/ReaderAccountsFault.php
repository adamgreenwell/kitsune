<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers;

/**
 * Why core cannot offer reader accounts here — every reader page is a 404 while there is one, and
 * `kitsune:readers status` says which. `ReaderAccounts::fault()` asks in this order.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
enum ReaderAccountsFault
{
    /** `ReaderGuard::fault()` is not null: no usable reader guard, so no reader can be resolved at all. */
    case Guard;

    /** The guard's model does not implement `ReaderAccount`, so core cannot find, create or erase a reader. */
    case NotAnAccount;

    /** The guard is not Laravel's `session` driver, so a sign-in would not outlive the request. */
    case NotSession;

    /** The fault in words, for the console. The guard's name and the model's class are configuration, never personal. */
    public function sentence(string $guard, ?string $model): string
    {
        return match ($this) {
            self::Guard => 'no usable reader guard is declared, so no reader can sign in',
            self::NotAnAccount => "the reader guard's model [{$model}] does not implement Kitsune\\Core\\Readers\\Contracts\\ReaderAccount, so core's sign-in cannot use it",
            self::NotSession => "the reader guard [{$guard}] is not a session guard, so a sign-in would not outlive the request",
        };
    }
}
