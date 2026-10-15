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
use Kitsune\Core\Credentials\CredentialAuthenticationRefused;
use Kitsune\Core\Credentials\CredentialRefused;
use Kitsune\Core\Credentials\CredentialStates;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Filament\Pages\Credentials;
use Throwable;

/**
 * The one path a credential's value takes into the admin — ADR-040, its admin half: the body of the plain form in the
 * credentials page's Set modal, POSTed here rather than to Livewire.
 *
 * ⚠️ THE VALUE LEAVES EVERY BAG FIRST, before anything that can throw: the parsed body, the query, and PHP's own
 * superglobals, so an error page, a reporter or a later middleware that prints the request finds nothing. The
 * cached raw body and URL are cleared too. A host whose reporter records requests before controller dispatch still
 * excludes credential mutation routes from raw-body capture (ADR-040, R2).
 *
 * ⚠️ THEN THE SESSION TOKEN, CHECKED HERE AS WELL AS BY THE HOST. Core does not own the panel's middleware list; the
 * host's check passes a same-origin POST with no token, and is skipped in the PHP suite. A forged set is a key
 * substituted — money routed elsewhere — so the body's token is compared again, and a token in a header alone is not
 * enough.
 *
 * ⚠️ THEN THE OWNER, AND A MISSING USER IS REFUSED HERE. The writer independently proves current ownership and
 * fresh authentication under its transaction lock; a native POST never supplies console authority.
 *
 * ⚠️ NOTHING IS ECHOED. A value in the address, from a signed-in owner with a valid token, is refused and called exposed,
 * whatever credential or mode it names (from anyone else, the 419 or 403 comes first, and stores nothing either). An
 * undeclared credential or a mode that does not fit is a 404 that names nothing. The store's refusal is shown in its
 * own sentence, which holds no value. Fresh authentication uses a generic translated notice. The answer is a 303
 * to the page, so a reload is a GET; submitted values are never flashed and no old input is kept.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class CredentialSetController
{
    /** Relative to the panel: `filament.{panel}.credentials.set`, as `MediaDelivery::ROUTE` is. */
    public const ROUTE = 'credentials.set';

    /** Named `password` on purpose: Laravel's `$dontFlash` and `TrimStrings` skip it, and reporters commonly scrub it. */
    public const FIELD = 'password';

    public function __invoke(#[\SensitiveParameter] Request $request): RedirectResponse
    {
        $input = self::take($request);

        if (! CredentialMutationRequest::authorize($input, __('kitsune::credentials.set.in_address_title'))) {
            return redirect()->to(Credentials::getUrl(), 303);
        }

        [$declared, $mode] = CredentialMutationRequest::line($input);
        $line = Credentials::labelOf($declared, $mode);

        try {
            $replacing = Credentials::holdsValue(app(CredentialStates::class)->of($declared->name, $mode));
            // The declared name, never the client's string; only a credential's surrounding ASCII whitespace is trimmed.
            app(CredentialWriter::class)->set($declared->name, $mode, trim($input['value'] ?? '', " \t\r\n"), $input['password']);

            Notification::make()->success()
                ->title(e(__($replacing ? 'kitsune::credentials.set.replaced' : 'kitsune::credentials.set.saved', ['line' => $line])))
                ->body(e(__($replacing ? 'kitsune::credentials.set.replaced_body' : 'kitsune::credentials.set.saved_body')))
                ->send();
        } catch (CredentialRefused $refused) {
            CredentialMutationRequest::refused(__('kitsune::credentials.set.refused', ['line' => $line]), CredentialMutationRequest::message($refused));
        } catch (CredentialAuthenticationRefused) {
            CredentialMutationRequest::refused(__('kitsune::credentials.set.refused', ['line' => $line]), __('kitsune::credentials.authentication.refused'));
        } catch (Throwable) {
            CredentialMutationRequest::refused(__('kitsune::credentials.set.refused', ['line' => $line]));
        } finally {
            $input = [];
        }

        return redirect()->to(Credentials::getUrl(), 303);
    }

    /** @return array{value: ?string, password: ?string, inAddress: bool, tokenMatches: bool, slot: mixed, mode: mixed} */
    private static function take(#[\SensitiveParameter] Request $request): array
    {
        return CredentialMutationRequest::take($request);
    }
}
