<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers;

use Illuminate\Http\Request;

/**
 * What every reader form's POST does before anything else.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class ReaderForm
{
    /**
     * The body's token against the session's: never a header's alone, and never the query string's.
     *
     * ⚠️ CHECKED HERE AS WELL AS BY THE HOST, as `CredentialSetController` checks its own: Laravel's check passes any POST
     * under the PHP suite and any POST marked `Sec-Fetch-Site: same-origin`, and core does not own the host's middleware.
     */
    public static function tokenMatches(Request $request): bool
    {
        $token = $request->request->all()['_token'] ?? null;

        return $request->hasSession()
            && is_string($token)
            && $token !== ''
            && hash_equals((string) $request->session()->token(), $token);
    }

    /**
     * A field from the parsed body alone — never the query string, so a password in an address is never read — and only
     * when it is a string.
     *
     * ⚠️ `all()[…]`, NEVER `get()` or `input()`: Symfony's `InputBag::get()` throws on an array (`password[]=…`), and
     * `input()` reads the query string too.
     */
    public static function field(Request $request, string $name): ?string
    {
        $value = $request->request->all()[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Takes the password out of every bag the request parsed it into, so an error page, a reporter or a later middleware
     * that prints the request finds nothing — and returns it.
     */
    public static function takePassword(Request $request): ?string
    {
        return self::take($request, 'password');
    }

    /** Takes one secret field out of every bag the request parsed it into, as `takePassword()` does — and returns it. */
    public static function take(Request $request, string $name): ?string
    {
        $value = self::field($request, $name);

        $request->request->remove($name);
        $request->query->remove($name);
        unset($_POST[$name], $_GET[$name], $_REQUEST[$name]);

        return $value;
    }
}
