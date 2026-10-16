<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\FilamentManager;
use Filament\Panel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Kitsune\Commerce\Http\Controllers\LiveStripeAdmissionController;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Payments\CheckoutRefused;
use Kitsune\Commerce\Payments\LiveStripeAdmission;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Fx;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

beforeEach(function (): void {
    Fx::boot();
    $this->world = ReaderFixture::world();
    $this->site = $this->world['sites']['golfdom'];
    app(Context::class)->setSite($this->site);
    $this->owner = Fx::member();
    Module::create(['handle' => 'kitsune/commerce', 'version' => ModuleDiscovery::version('kitsune/commerce'), 'is_enabled' => true, 'installed_at' => now()]);
    ModuleKernel::flush();
    ModuleKernel::boot(app());
    $panel = Panel::make()->id('admin');
    $manager = Mockery::mock(FilamentManager::class);
    $manager->shouldReceive('getCurrentOrDefaultPanel')->andReturn($panel);
    $manager->shouldReceive('getCurrentResourceConfigurationKey')->andReturnNull();
    $manager->shouldReceive('getCurrentPanel')->andReturnNull();
    $manager->shouldReceive('getTenant')->andReturn($this->site);
    Filament::swap($manager);
    Route::get('/admin/{tenant}/storefront/orders', static fn () => 'fixture')->name('filament.admin.resources.storefront.orders.index');
});

afterEach(fn () => Fx::tearDown());

function liveAdmissionHttp(array $body = [], array $query = [], bool $token = true, bool $json = false): Request
{
    $session = app('session.store');
    $session->regenerateToken();
    $request = Request::create('https://localhost/admin/golfdom/storefront/orders/live-admission?'.http_build_query($query), 'POST',
        ($token ? ['_token' => $session->token()] : []) + ['action' => 'revoke', 'version' => '', 'current_password' => Fx::PASSWORD] + $body,
        [], [], $json ? ['CONTENT_TYPE' => 'application/json'] : []);
    // Test overrides must actually replace defaults.
    $request->request->replace([...$request->request->all(), ...$body]);
    $request->setLaravelSession($session);

    return $request;
}

it('accepts native fresh-owner revocation and scrubs every request representation', function (mixed $emptyVersion): void {
    $request = liveAdmissionHttp(['version' => $emptyVersion]);
    $response = app(LiveStripeAdmissionController::class)($request);
    expect($response->getStatusCode())->toBe(303)->and($request->request->all())->toBe([])
        ->and($request->query->all())->toBe([])->and($request->getContent())->toBe('')
        ->and($request->session()->all())->not->toContain(Fx::PASSWORD);
    $row = DB::table(LiveStripeAdmission::TABLE)->sole();
    expect($row->state)->toBe('revoked')->and((string) $row->org_id)->toBe((string) $this->site->org_id)
        ->and(DB::table(AuditLog::TABLE)->where('action', 'commerce.live_stripe_revoked')->count())->toBe(1);
})->with(['', null]);

it('requires native body CSRF and removes password input even on refusal', function (string $kind): void {
    $request = liveAdmissionHttp(query: $kind === 'query' ? ['_token' => 'private-token', 'current_password' => 'private-address-password'] : [],
        token: $kind === 'json', json: $kind === 'json');
    try {
        app(LiveStripeAdmissionController::class)($request);
        test()->fail('Untrusted CSRF source accepted.');
    } catch (HttpExceptionInterface $failure) {
        expect($failure->getStatusCode())->toBe(419);
    }
    expect($request->request->all())->toBe([])->and($request->query->all())->toBe([])->and($request->getContent())->toBe('')
        ->and(DB::table(LiveStripeAdmission::TABLE)->count())->toBe(0);
})->with(['missing', 'query', 'json']);

it('refuses malformed unknown query-carried and stale-version input without a mutation', function (array $body, array $query): void {
    $request = liveAdmissionHttp($body, $query);
    expect(app(LiveStripeAdmissionController::class)($request)->getStatusCode())->toBe(303)
        ->and(DB::table(LiveStripeAdmission::TABLE)->count())->toBe(0)
        ->and($request->request->all())->toBe([])->and($request->query->all())->toBe([]);
})->with([
    [['action' => 'unknown'], []], [['version' => str_repeat('a', 32)], []], [['version' => ['nested']], []],
    [['current_password' => 'wrong'], []], [['org_id' => 'another'], []], [[], ['action' => 'revoke']],
]);

it('refuses cached owners whose current membership or owner role was removed', function (string $kind): void {
    if ($kind === 'membership') {
        DB::table('org_user')->where('user_id', $this->owner->id)->delete();
    } else {
        DB::table('role_user')->where('user_id', $this->owner->id)->delete();
    }
    $request = liveAdmissionHttp();
    try {
        app(LiveStripeAdmissionController::class)($request);
    } catch (HttpExceptionInterface $failure) {
        expect($failure->getStatusCode())->toBe(403);
    }
    expect(DB::table(LiveStripeAdmission::TABLE)->count())->toBe(0);
})->with(['membership', 'role']);

it('keeps admission history Org-scoped and refuses malformed rows or stale module receipts', function (string $kind): void {
    app(LiveStripeAdmission::class)->revoke(null, Fx::PASSWORD);
    $row = DB::table(LiveStripeAdmission::TABLE)->sole();
    if ($kind === 'scope') {
        app(Context::class)->setSite($this->world['sites']['rival']);
    } elseif ($kind === 'module') {
        DB::table('modules')->where('handle', 'kitsune/commerce')->update(['version' => 'stale']);
    } else {
        DB::table(LiveStripeAdmission::TABLE)->where('org_id', $this->site->org_id)->update(['state' => 'invalid']);
    }
    expect(fn () => app(OrderLocks::class)->run(fn () => app(LiveStripeAdmission::class)->forNewCheckout()))->toThrow(CheckoutRefused::class);
    if ($kind === 'scope') {
        expect(fn () => app(LiveStripeAdmission::class)->revoke($row->version, Fx::PASSWORD))->toThrow(CheckoutRefused::class);
    }
})->with(['scope', 'module', 'row']);
