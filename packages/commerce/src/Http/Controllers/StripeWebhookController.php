<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Kitsune\Commerce\Http\Middleware\CartArea;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Payments\CheckoutRefused;
use Kitsune\Commerce\Payments\PaymentMaintenance;
use Kitsune\Commerce\Payments\StripeEventInbox;
use Kitsune\Commerce\Payments\StripeWebhook;
use Kitsune\Core\Credentials\CredentialReader;
use Kitsune\Core\Credentials\CredentialUnavailability;
use Kitsune\Core\Credentials\CredentialUnavailable;
use Kitsune\Core\Http\BoundedWebhookRequest;
use Kitsune\Core\Http\Middleware\ResolveSiteFromRequest;
use Kitsune\Core\Models\Credential;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Tenancy\Context;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Durable signature-verified capture; no raw input, exception text or buyer session escapes this boundary. @internal */
final class StripeWebhookController
{
    public function __invoke(#[\SensitiveParameter] Request $request): Response
    {
        $bounded = BoundedWebhookRequest::isBounded($request);
        $raw = $request->getContent();
        $header = $request->headers->get('Stripe-Signature', '');
        $hasQuery = $request->query->count() !== 0;
        self::clear($request);
        // A host middleware may already have set context; this receiver derives it solely from its own URL.
        app(Context::class)->forget();
        if (! $bounded || ! is_string($header) || $hasQuery) {
            return self::response(400);
        }
        try {
            return app(ResolveSiteFromRequest::class)->handle($request, fn (Request $request): Response => app(CartArea::class)->handle($request, fn (): Response => $this->capture($raw, $header)));
        } catch (Throwable) {
            return self::response(400);
        } finally {
            unset($raw, $header);
            app(Context::class)->forget();
        }
    }

    private function capture(#[\SensitiveParameter] string $raw, #[\SensitiveParameter] string $header): Response
    {
        $verified = false;
        try {
            $receipt = app(OrderLocks::class)->run(function () use ($raw, $header, &$verified): array {
                $context = app(Context::class);
                $org = $context->orgId() ?? throw new CheckoutRefused;
                $connection = (new Credential)->getConnection();
                $mode = $connection->table('org_credential_modes')->useWritePdo()->where('org_id', $org)->lockForUpdate()->value('mode');
                $module = Module::query()->useWritePdo()->where('handle', 'kitsune/commerce')->lockForUpdate()->first();
                if (($mode !== null && $mode !== 'test') || $module === null || ! $module->is_enabled
                    || $module->version !== ModuleDiscovery::version('kitsune/commerce')) {
                    throw new CheckoutRefused;
                }
                $rows = $connection->table('credentials')->useWritePdo()->where('org_id', $org)->where('mode', 'test')
                    ->whereIn('slot', [StripeWebhook::SLOT, StripeWebhook::PREVIOUS_SLOT])->orderBy('slot')->lockForUpdate()->get();
                $before = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
                $reader = app(CredentialReader::class);
                $secrets = [$reader->secret(StripeWebhook::SLOT)];
                try {
                    $secrets[] = $reader->secret(StripeWebhook::PREVIOUS_SLOT);
                } catch (CredentialUnavailable $failure) {
                    if ($failure->reason !== CredentialUnavailability::NotSet) {
                        throw new CheckoutRefused;
                    }
                }
                $now = CarbonImmutable::now('UTC')->startOfSecond();
                $event = app(StripeWebhook::class)->verify($raw, $header, $secrets, $now->timestamp);
                unset($secrets);
                $verified = true;
                $receipt = app(StripeEventInbox::class)->capture($org, $event, $raw, $now);
                $after = $connection->table('credentials')->useWritePdo()->where('org_id', $org)->where('mode', 'test')
                    ->whereIn('slot', [StripeWebhook::SLOT, StripeWebhook::PREVIOUS_SLOT])->orderBy('slot')->lockForUpdate()->get();
                $currentMode = $connection->table('org_credential_modes')->useWritePdo()->where('org_id', $org)->lockForUpdate()->value('mode');
                if (! hash_equals($before, hash('sha256', json_encode($after, JSON_THROW_ON_ERROR))) || $currentMode !== $mode
                    || $context->orgId() !== $org) {
                    throw new CheckoutRefused;
                }

                return $receipt;
            });
        } catch (Throwable) {
            return self::response($verified ? 503 : 400);
        }

        // Laravel sends Response (Symfony calls fastcgi_finish_request) before invoking termination callbacks.
        // Non-FPM hosts deliberately leave the committed receipt for the bounded operator replay command.
        if ($receipt['id'] > 0 && $receipt['state'] !== 'erased' && PHP_SAPI === 'fpm-fcgi' && function_exists('fastcgi_finish_request')) {
            $org = app(Context::class)->org();
            $site = app(Context::class)->site();
            app()->terminating(static function () use ($receipt, $org, $site): void {
                $context = app(Context::class);
                try {
                    $context->setOrg($org);
                    $context->setSite($site);
                    app(StripeEventInbox::class)->process($receipt['id']);
                    app(PaymentMaintenance::class)->afterEvent($receipt['id']);
                } catch (Throwable) {
                    // The durable pending/leased receipt is the retry record; never report raw provider exceptions.
                } finally {
                    $context->forget();
                }
            });
        }

        return self::response(200);
    }

    private static function clear(Request $request): void
    {
        $server = $request->server->all();
        $server['REQUEST_URI'] = explode('?', $request->getRequestUri(), 2)[0];
        foreach (['QUERY_STRING', 'UNENCODED_URL', 'IIS_WasUrlRewritten', 'ORIG_PATH_INFO', 'RAW_URI', 'HTTP_STRIPE_SIGNATURE',
            'HTTP_X_ORIGINAL_URL', 'HTTP_X_REWRITE_URL', 'HTTP_X_ORIGINAL_URI', 'REDIRECT_URL', 'REDIRECT_QUERY_STRING', 'REDIRECT_REQUEST_URI'] as $key) {
            unset($server[$key], $_SERVER[$key]);
        }
        $request->initialize([], [], $request->attributes->all(), [], [], $server, '');
        $request->setJson(new InputBag);
        $_GET = $_POST = $_REQUEST = [];
        if (isset($_SERVER['REQUEST_URI'])) {
            $_SERVER['REQUEST_URI'] = $server['REQUEST_URI'];
        }
    }

    private static function response(int $status): Response
    {
        return new Response('', $status, ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'", 'Referrer-Policy' => 'no-referrer']);
    }
}
