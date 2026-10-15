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
use Kitsune\Commerce\Payments\AccessAdjustments;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\CheckoutReconciler;
use Kitsune\Commerce\Payments\FinancialReconciler;
use Kitsune\Commerce\Payments\RefundRecovery;
use Kitsune\Core\Credentials\Secret;
use Kitsune\Core\Http\Controllers\CredentialMutationRequest;
use Throwable;

/** Fresh-owner, native-body recovery. The transient key is never installed or returned to Livewire. @internal */
final class CheckoutRecoveryController
{
    public function __invoke(#[\SensitiveParameter] Request $request): RedirectResponse
    {
        $body = $request->request->all();
        $order = $body['order_reference'] ?? null;
        $attempt = $body['attempt_reference'] ?? null;
        $session = $body['session_id'] ?? '';
        $kind = $body['recovery_kind'] ?? 'checkout';
        unset($body);
        $input = CredentialMutationRequest::take($request);
        abort_unless($input['tokenMatches'], 419);
        abort_unless(OrderResource::canAccess(), 403);
        $redirect = OrderResource::getUrl();
        if (is_string($order) && preg_match('/\A[a-f0-9]{32}\z/D', $order) === 1) {
            $redirect = OrderResource::getUrl('detail', ['reference' => $order]);
        }
        try {
            if ($input['inAddress'] || ! is_string($order) || ! is_string($attempt) || ! is_string($session)
                || ! in_array($kind, ['checkout', 'refund_reconcile', 'refund_retry', 'financial_graph', 'financial_refund'], true)
                || ! is_string($input['value']) || ! is_string($input['password'])
                || strlen($input['value']) > 255 || strlen($input['password']) > 4096) {
                throw new \RuntimeException;
            }
            if ($kind === 'checkout') {
                app(CheckoutReconciler::class)->recoverAsOwner($order, $attempt, $session,
                    new Secret($input['value']), $input['password']);
            } elseif ($kind === 'refund_reconcile') {
                app(RefundRecovery::class)->recoverAsOwner($order, $attempt, new Secret($input['value']), $input['password']);
            } elseif ($kind === 'refund_retry') {
                $created = app(RefundRecovery::class)->retryAsOwner($order, new Secret($input['value']), $input['password']);
                app(RefundRecovery::class)->recoverAsOwner($order, $created['reference'], new Secret($input['value']), $input['password']);
            } else {
                app(AccessAdjustments::class)->reconcile($order);
                app(FinancialReconciler::class)->recoverAsOwner($order, new Secret($input['value']), $input['password'],
                    $kind === 'financial_refund' ? $attempt : null);
                app(AccessFulfillment::class)->reconcile($order);
                app(AccessAdjustments::class)->reconcile($order);
            }
            Notification::make()->success()->title(__('commerce::payments.recovered'))->send();
        } catch (Throwable) {
            Notification::make()->danger()->title(__('commerce::payments.unavailable'))->send();
        } finally {
            $input = [];
        }

        return new RedirectResponse($redirect, 303);
    }
}
