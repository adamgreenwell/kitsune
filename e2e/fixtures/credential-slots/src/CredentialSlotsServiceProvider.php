<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\E2e\CredentialSlots;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Kitsune\Commerce\Payments\StripeCheckout;
use Kitsune\Core\Credentials\CredentialSlot;
use Kitsune\Core\Credentials\CredentialSlots;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Modules\ModuleServiceProvider;
use Kitsune\Core\Tenancy\Context;
use Psr\Http\Message\RequestInterface;

/**
 * The browser suite's credentials — ADR-040's admin half has nothing to set until a module declares something.
 *
 * ⚠️ TEST-ONLY, AND NEVER PUBLISHED. `composer skeleton:install` puts it in the skeleton's vendor switched off, and only
 * `e2e/global-setup.js` enables it. It lives under `e2e/`, which no release, package split or floor benchmark copies.
 *
 * ⚠️ IT MIRRORS `tests/Core/Fixtures/CredentialFixture.php`'s THREE SHAPES, kept in step by review because the PHP suite
 * cannot load it: prefixes that decide the mode, one prefix that decides nothing (the line decides), and one value kept
 * once. The prefixes are invented, so nothing committed matches a real provider's key pattern.
 */
final class CredentialSlotsServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        // Only this synthetic Org receives fake account verification. No production package includes this module.
        $this->app->bind(StripeCheckout::class, function (): StripeCheckout {
            if ($this->app->make(Context::class)->org()?->slug !== 'live-stripe-e2e') {
                return new StripeCheckout;
            }
            $http = static function (RequestInterface $request): Response {
                $authorization = $request->getHeaderLine('Authorization');
                $expected = array_map(static fn (string $tail): string => 'Bearer sk_'.'live_'.str_repeat($tail, 32), ['b', 'c']);
                $connection = (new Org)->getConnection();
                if ($request->getMethod() !== 'GET' || (string) $request->getUri() !== 'https://api.stripe.com/v1/account'
                    || ! in_array($authorization, $expected, true) || $connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
                    throw new \RuntimeException('Browser fixture refuses unexpected provider operation.');
                }

                return new Response(200, ['Content-Type' => 'application/json'], json_encode(['object' => 'account', 'id' => 'acct_LiveBrowser',
                    'charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true, 'email' => 'private-fixture@example.test'], JSON_THROW_ON_ERROR));
            };

            return new StripeCheckout(new Client(['handler' => HandlerStack::create(new MockHandler(array_fill(0, 5, $http)))]));
        });

        $help = 'Declared by the browser suite\'s test module so the credentials page has something to set. Nothing reads it.';

        $this->app->make(CredentialSlots::class)
            ->register(new CredentialSlot(
                name: 'e2e.payment-key',
                label: 'Browser-test payment key',
                help: $help,
                moded: true,
                prefixes: ['test' => ['fx_test_'], 'live' => ['fx_live_']],
                minLength: 32,
            ))
            ->register(new CredentialSlot(
                name: 'e2e.webhook-secret',
                label: 'Browser-test webhook secret',
                help: $help,
                moded: true,
                prefixes: ['test' => ['fxhook_'], 'live' => ['fxhook_']],
                minLength: 24,
            ))
            ->register(new CredentialSlot(
                name: 'e2e.shared-secret',
                label: 'Browser-test shared secret',
                help: $help,
                moded: false,
                minLength: 32,
            ));
    }
}
