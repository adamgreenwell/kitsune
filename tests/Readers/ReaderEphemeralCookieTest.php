<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Models\Reader;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Kitsune\Commerce\CommerceServiceProvider;
use Kitsune\Core\Readers\ReaderSessions;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Kitsune\Core\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);
    $this->world = ReaderFixture::world();
    $this->reader = ReaderFixture::reader($this->world['golfdom'], 'ephemeral-reader@example.test');
    ReaderFixture::signIn($this->reader);
    app(Context::class)->setOrg($this->world['golfdom']);
    $this->staff = CredentialFixture::member(owner: true, email: 'ephemeral-staff@example.test');
    Auth::guard('web')->login($this->staff);
    Cookie::flushQueuedCookies();
    $this->seed = bin2hex(random_bytes(32));
    app('request')->cookies->set(ReaderSessions::EPHEMERAL, $this->seed);
    app('session.store')->put(ReaderSessions::EPHEMERAL.'.optional.handle', 'retained-guest-handle');
    Cookie::queue(ReaderSessions::EPHEMERAL, $this->seed, 43200);
});

afterEach(function (): void {
    Cookie::flushQueuedCookies();
    ReaderFixture::forget();
});

it('expires the optional cookie and rotates only anonymous generation on either plain reader logout', function (string $method): void {
    $id = app('session.store')->getId();
    $token = app('session.store')->token();
    expect(app()->getProviders(CommerceServiceProvider::class))->toBe([]);
    Auth::guard(ReaderFixture::GUARD)->{$method}();

    expect(app('session.store')->has(ReaderSessions::EPHEMERAL))->toBeFalse()
        ->and(app('session.store')->get(ReaderSessions::LOGOUT_GENERATION))->toMatch('/\A[a-f0-9]{64}\z/D')
        ->and(app('session.store')->getId())->toBe($id)->and(app('session.store')->token())->toBe($token)
        ->and(app('request')->cookie(ReaderSessions::EPHEMERAL))->toBeNull()
        ->and(Cookie::queued(ReaderSessions::EPHEMERAL)->getExpiresTime())->toBeLessThan(time())
        ->and(Auth::guard('web')->id())->toBe($this->staff->getKey());
})->with(['logout', 'logoutCurrentDevice']);

it('expires the optional cookie on password-binding logout without a commerce provider', function (): void {
    Reader::withoutScopeBecause('a password changed on another request', fn ($query) => $query->whereKey($this->reader->getKey())->firstOrFail())
        ->forceFill(['password' => Hash::make('changed-on-another-request')])->saveOrFail();
    Auth::forgetGuards();
    app(Context::class)->setOrg($this->world['golfdom']);

    expect(Auth::guard(ReaderFixture::GUARD)->user())->toBeNull()
        ->and(app('session.store')->has(ReaderSessions::EPHEMERAL))->toBeFalse()
        ->and(Cookie::queued(ReaderSessions::EPHEMERAL)->getExpiresTime())->toBeLessThan(time())
        ->and(Auth::guard('web')->id())->toBe($this->staff->getKey());
});

it('leaves the optional cookie and generation untouched when staff sign out', function (): void {
    Auth::guard('web')->logoutCurrentDevice();

    expect(app('session.store')->get(ReaderSessions::EPHEMERAL.'.optional.handle'))->toBe('retained-guest-handle')
        ->and(app('session.store')->has(ReaderSessions::LOGOUT_GENERATION))->toBeFalse()
        ->and(Cookie::queued(ReaderSessions::EPHEMERAL)->getValue())->toBe($this->seed)
        ->and(Auth::guard(ReaderFixture::GUARD)->id())->toBe($this->reader->getKey());
});
