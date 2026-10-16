<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Http\Controllers;

use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Kitsune\Commerce\Filament\OrderResource;
use Kitsune\Commerce\Payments\CheckoutRefused;
use Kitsune\Commerce\Payments\LiveStripeAdmission;
use Kitsune\Core\Http\Controllers\CredentialMutationRequest;
use Throwable;

/** Native-body owner consent; neither password nor provider data enters Livewire state. @internal */
final class LiveStripeAdmissionController
{
    public function __invoke(#[\SensitiveParameter] Request $request): RedirectResponse
    {
        $body = $request->request->all();
        $action = $body['action'] ?? null;
        $hasVersion = array_key_exists('version', $body);
        $version = $body['version'] ?? null;
        $hasExtra = array_diff(array_keys($body), ['_token', 'action', 'version', 'current_password']) !== [];
        $isPost = $request->isMethod('POST');
        $hasQuery = $request->query->count() !== 0;
        unset($body);
        $input = CredentialMutationRequest::take($request);
        abort_unless($input['tokenMatches'], 419);
        abort_unless(OrderResource::canAccess(), 403);
        try {
            if (! $isPost || $hasExtra || $hasQuery || $input['inAddress'] || ! in_array($action, ['admit', 'revoke'], true)
                || ! $hasVersion || ($version !== null && (! is_string($version) || ($version !== '' && preg_match('/\A[a-f0-9]{32}\z/D', $version) !== 1)))
                || ! is_string($input['password']) || $input['password'] === '' || strlen($input['password']) > 4096) {
                throw new CheckoutRefused;
            }
            $admission = app(LiveStripeAdmission::class);
            if ($action === 'admit') {
                $admission->admit($version === '' || $version === null ? null : $version, $input['password']);
            } else {
                $admission->revoke($version === '' || $version === null ? null : $version, $input['password']);
            }
            Notification::make()->success()->title(__('commerce::admission.saved'))->send();
        } catch (Throwable) {
            Notification::make()->danger()->title(__('commerce::admission.refused'))->send();
        } finally {
            $input = [];
        }

        return new RedirectResponse(OrderResource::getUrl(), 303);
    }
}
