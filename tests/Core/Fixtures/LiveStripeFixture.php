<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Payments\LiveStripeAdmission;
use Kitsune\Commerce\Payments\StripeCheckout;
use Kitsune\Commerce\Payments\StripeWebhook;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Fx;
use Psr\Http\Message\RequestInterface;

function liveAdmissionSeed(): array
{
    Fx::boot();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-16 12:00:00', 'UTC'));
    ModuleKernel::flush();
    ModuleLifecycle::install(app(), 'kitsune/commerce');
    ModuleLifecycle::enable(app(), 'kitsune/commerce');
    ModuleKernel::boot(app());
    $org = Fx::org('live-admission');
    $site = Site::create(['handle' => 'main', 'slug' => 'live-main', 'name' => 'Live main', 'locale' => 'en', 'base_url' => 'https://live.example.test']);
    app(Context::class)->setSite($site);
    $owner = Fx::member();
    $key = Fx::value('sk_live_');
    $hook = Fx::value('whsec_');
    $writer = app(CredentialWriter::class);
    $writer->set(StripeCheckout::SLOT, CredentialMode::Live, $key, Fx::PASSWORD);
    $writer->set(StripeWebhook::SLOT, CredentialMode::Live, $hook, Fx::PASSWORD);
    $writer->switchTo(CredentialMode::Live, Fx::PASSWORD);
    $provider = (object) ['calls' => [], 'hook' => null, 'body' => ['object' => 'account', 'id' => 'acct_LiveFixture',
        'charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true, 'email' => 'private@example.test']];
    $http = static function (RequestInterface $request) use ($provider, $key): Response {
        expect(DB::connection()->transactionLevel())->toBe(0)->and(DB::connection()->getPdo()->inTransaction())->toBeFalse()
            ->and($request->getMethod())->toBe('GET')->and((string) $request->getUri())->toBe('https://api.stripe.com/v1/account')
            ->and($request->getHeaderLine('Authorization'))->toBe('Bearer '.$key)
            ->and($request->getHeaderLine('Stripe-Version'))->toBe(StripeCheckout::API_VERSION);
        $provider->calls[] = 'account';
        if ($provider->hook !== null) {
            ($provider->hook)();
        }

        return new Response(200, ['Content-Type' => 'application/json'], json_encode($provider->body, JSON_THROW_ON_ERROR));
    };
    app()->instance(StripeCheckout::class, new StripeCheckout(new Client(['handler' => HandlerStack::create(new MockHandler(array_fill(0, 10, $http)))])));
    app()->forgetInstance(LiveStripeAdmission::class);

    return compact('org', 'site', 'owner', 'key', 'hook', 'provider');
}

function liveAdmissionGate(?string $account = null): string
{
    return app(OrderLocks::class)->run(fn (): string => app(LiveStripeAdmission::class)->forNewCheckout($account));
}
