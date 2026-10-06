<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Http\Controllers;

use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialRefused;
use Kitsune\Core\Credentials\CredentialSlots;
use Kitsune\Core\Credentials\CredentialStates;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Filament\Pages\Credentials;

/**
 * The one path a credential's value takes into the admin — ADR-040, its admin half: the body of the plain form in the
 * credentials page's Set modal, POSTed here rather than to Livewire.
 *
 * ⚠️ THE VALUE LEAVES EVERY BAG FIRST, before anything that can throw: the parsed body, the query, and PHP's own
 * superglobals, so an error page, a reporter or a later middleware that prints the request finds nothing. What it
 * cannot reach is the raw body, which the request caches — a host whose error reporter or dev tool keeps raw request
 * bodies excludes this route's (`…/credentials/set`) from it (ADR-040, R2).
 *
 * ⚠️ THEN THE SESSION TOKEN, CHECKED HERE AS WELL AS BY THE HOST. Core does not own the panel's middleware list; the
 * host's check passes a same-origin POST with no token, and is skipped in the PHP suite. A forged set is a key
 * substituted — money routed elsewhere — so the body's token is compared again, and a token in a header alone is not
 * enough.
 *
 * ⚠️ THEN THE OWNER, AND A MISSING USER IS REFUSED HERE. The writer trusts its caller when nobody is signed in; this is
 * the guard that keeps "no web path reaches it without a signed-in owner" true.
 *
 * ⚠️ NOTHING IS ECHOED. A value in the address is refused and called exposed, whatever else the request says. An
 * undeclared credential or a mode that does not fit is a 404 that names nothing. The store's refusal is shown in its
 * own sentence, which holds no value. The answer is a 303 to the page, so a reload is a GET; nothing is flashed, and
 * no old input is kept.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class CredentialSetController
{
    /** Relative to the panel: `filament.{panel}.credentials.set`, as `MediaDelivery::ROUTE` is. */
    public const ROUTE = 'credentials.set';

    /** Named `password` on purpose: Laravel's `$dontFlash` and `TrimStrings` skip it, and reporters commonly scrub it. */
    public const FIELD = 'password';

    public function __invoke(Request $request, CredentialSlots $slots, CredentialStates $states, CredentialWriter $writer): RedirectResponse
    {
        [$value, $inAddress] = self::take($request);

        abort_unless(self::tokenMatches($request), 419);
        abort_unless(Credentials::canAccess(), 403);

        if ($inAddress) {
            $value = null;

            Notification::make()->danger()->persistent()
                ->title(e(__('kitsune::credentials.set.in_address_title')))
                ->body(e(__('kitsune::credentials.set.in_address')))
                ->send();

            return redirect()->to(Credentials::getUrl(), 303);
        }

        // Untrusted input (AGENTS.md §6): a declared credential, and a mode that fits it, or a 404 that echoes nothing.
        $body = $request->request->all();
        $name = $body['slot'] ?? null;
        $declared = is_string($name) ? $slots->find($name) : null;
        $modeIn = $body['mode'] ?? null;
        $mode = is_string($modeIn) ? CredentialMode::tryFrom($modeIn) : null;

        abort_unless($declared !== null && ($declared->moded ? $mode !== null : $modeIn === null), 404);

        $line = Credentials::labelOf($declared, $mode);
        $replacing = Credentials::holdsValue($states->of($declared->name, $mode));

        try {
            // The DECLARED name, never the client's string; ASCII whitespace trimmed, which `TrimStrings` skips here.
            $writer->set($declared->name, $mode, trim($value ?? '', " \t\r\n"));

            Notification::make()->success()
                ->title(e(__($replacing ? 'kitsune::credentials.set.replaced' : 'kitsune::credentials.set.saved', ['line' => $line])))
                ->body(e(__($replacing ? 'kitsune::credentials.set.replaced_body' : 'kitsune::credentials.set.saved_body')))
                ->send();
        } catch (CredentialRefused $refused) {
            Notification::make()->danger()->persistent()
                ->title(e(__('kitsune::credentials.set.refused', ['line' => $line])))
                ->body(e($refused->getMessage()))
                ->send();
        } finally {
            $value = null;
        }

        return redirect()->to(Credentials::getUrl(), 303);
    }

    /**
     * The value, and whether the address carried one — taken out of every place the request parsed it into.
     *
     * ⚠️ `all()[…]`, NEVER `get()`: Symfony's `InputBag::get()` throws on an array (`password[]=…`), which would leave
     * the value where it was. An array is no value, and the store refuses it as empty.
     *
     * @return array{0: ?string, 1: bool}
     */
    private static function take(#[\SensitiveParameter] Request $request): array
    {
        $inAddress = array_key_exists(self::FIELD, $request->query->all());
        $source = $request->isJson() ? $request->json() : $request->request;
        $value = $source->all()[self::FIELD] ?? $request->query->all()[self::FIELD] ?? null;

        $source->remove(self::FIELD);
        $request->request->remove(self::FIELD);
        $request->query->remove(self::FIELD);
        unset($_POST[self::FIELD], $_GET[self::FIELD], $_REQUEST[self::FIELD]);

        return [is_string($value) ? $value : null, $inAddress];
    }

    /** The body's token against the session's: never a header's alone, and never the query string's. */
    private static function tokenMatches(Request $request): bool
    {
        $token = $request->request->all()['_token'] ?? null;

        return $request->hasSession()
            && is_string($token)
            && $token !== ''
            && hash_equals((string) $request->session()->token(), $token);
    }
}
