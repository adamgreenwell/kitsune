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

/** Remove through a native POST so fresh authentication never enters Livewire's state. @internal */
final class CredentialRemoveController
{
    public const ROUTE = 'credentials.remove';

    public function __invoke(#[\SensitiveParameter] Request $request): RedirectResponse
    {
        $input = CredentialMutationRequest::take($request);

        if (! CredentialMutationRequest::authorize($input, __('kitsune::credentials.remove.refused_plain'))) {
            return redirect()->to(Credentials::getUrl(), 303);
        }

        [$declared, $mode] = CredentialMutationRequest::line($input);
        $line = Credentials::labelOf($declared, $mode);

        try {
            $hadValue = Credentials::holdsValue(app(CredentialStates::class)->of($declared->name, $mode));
            // Ask the writer even when the render found nothing: it owns current-state and fresh-authentication checks.
            app(CredentialWriter::class)->remove($declared->name, $mode, $input['password']);

            $notice = Notification::make()->title(e(__($hadValue ? 'kitsune::credentials.remove.removed' : 'kitsune::credentials.remove.nothing', ['line' => $line])));
            ($hadValue ? $notice->success() : $notice->info())->send();
        } catch (CredentialRefused $refused) {
            CredentialMutationRequest::refused(__('kitsune::credentials.remove.refused', ['line' => $line]), CredentialMutationRequest::message($refused));
        } catch (CredentialAuthenticationRefused) {
            CredentialMutationRequest::refused(__('kitsune::credentials.remove.refused', ['line' => $line]), __('kitsune::credentials.authentication.refused'));
        } catch (Throwable) {
            CredentialMutationRequest::refused(__('kitsune::credentials.remove.refused', ['line' => $line]));
        } finally {
            $input = [];
        }

        return redirect()->to(Credentials::getUrl(), 303);
    }
}
