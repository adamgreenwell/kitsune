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
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialRefused;
use Kitsune\Core\Credentials\CredentialSlots;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Filament\Pages\Credentials;
use Throwable;

/** Enter live mode only through a native password POST; test mode remains an explicit fixed choice. @internal */
final class CredentialModeController
{
    public const ROUTE = 'credentials.mode';

    public function __invoke(#[\SensitiveParameter] Request $request): RedirectResponse
    {
        $input = CredentialMutationRequest::take($request);

        if (! CredentialMutationRequest::authorize($input, __('kitsune::credentials.switch.refused'))) {
            return redirect()->to(Credentials::getUrl(), 303);
        }

        $to = is_string($input['mode']) ? CredentialMode::tryFrom($input['mode']) : null;
        abort_unless($to !== null && app(CredentialSlots::class)->hasModed(), 404);
        $live = $to === CredentialMode::Live;

        try {
            $changed = app(CredentialWriter::class)->switchTo($to, $input['password']);
            $key = $changed ? ($live ? 'now_live' : 'now_test') : ($live ? 'already_live' : 'already_test');
            $notice = Notification::make()->title(e(__('kitsune::credentials.switch.'.$key)));
            ($changed ? $notice->success() : $notice->info())->send();
        } catch (CredentialRefused $refused) {
            CredentialMutationRequest::refused(__('kitsune::credentials.switch.refused'), CredentialMutationRequest::message($refused));
        } catch (CredentialAuthenticationRefused) {
            CredentialMutationRequest::refused(__('kitsune::credentials.switch.refused'), __('kitsune::credentials.authentication.refused'));
        } catch (Throwable) {
            CredentialMutationRequest::refused(__('kitsune::credentials.switch.refused'));
        } finally {
            $input = [];
        }

        return redirect()->to(Credentials::getUrl(), 303);
    }
}
