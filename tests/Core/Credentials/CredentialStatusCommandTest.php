<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Fx;

/*
 * `kitsune:credentials status` — ADR-040: counts by the app key that can open them, and nothing a deploy log should
 * not hold.
 */

beforeEach(function (): void {
    Fx::boot();
});

afterEach(fn () => Fx::tearDown());

it('counts what is stored by key, across orgs, naming nothing', function (): void {
    $k1 = (string) config('app.key');

    $a = Fx::org('alpha');
    Fx::member(email: 'alpha@kitsune.test');
    Fx::writer()->set(Fx::SHARED, null, $value = Fx::value('', 40));
    Fx::writer()->set(Fx::PAYMENT, CredentialMode::Test, Fx::value('fx_test_'));
    Fx::writer()->set(Fx::PAYMENT, CredentialMode::Live, Fx::value('fx_live_'));
    Fx::writer()->remove(Fx::PAYMENT, CredentialMode::Live);

    $k2 = Fx::appKey();
    config(['app.key' => $k2]);
    Fx::forget();

    Fx::org('beta');
    Fx::member(email: 'beta@kitsune.test');
    Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));

    app(Context::class)->forget();
    config(['app.key' => Fx::appKey(), 'app.previous_keys' => [$k2]]);
    Fx::forget();

    expect(Artisan::call('kitsune:credentials', ['action' => 'status']))->toBe(0);
    $out = Artisan::output();

    expect($out)->toBe(implode("\n", [
        'Credentials: 3 stored, across 2 organisations.',
        '  0 under the current app key.',
        '  1 under a previous app key (APP_PREVIOUS_KEYS): keep that key until this reads 0. Replacing a credential re-encrypts it.',
        '  2 under no key this installation has: unreadable until set again.',
    ])."\n");

    foreach ([$value, substr($value, 0, 12), Fx::SHARED, Fx::PAYMENT, 'alpha', 'beta'] as $needle) {
        expect(str_contains($out, $needle))->toBeFalse("the output holds {$needle}");
    }

    expect(Artisan::call('kitsune:credentials', ['action' => 'status', '--strict' => true]))->toBe(1);

    config(['app.key' => $k1, 'app.previous_keys' => [$k2]]);
    Fx::forget();

    expect(Artisan::call('kitsune:credentials', ['action' => 'status', '--strict' => true]))->toBe(0);
});

it('reads an installation with no credentials table as holding none', function (): void {
    Schema::drop('credentials');

    expect(Artisan::call('kitsune:credentials', ['action' => 'status', '--strict' => true]))->toBe(0)
        ->and(Artisan::output())->toStartWith('Credentials: 0 stored, across 0 organisations.');
});

it('refuses any other action without repeating it', function (): void {
    $typed = Fx::value('fx_live_');

    expect(Artisan::call('kitsune:credentials', ['action' => $typed]))->toBe(1);

    $out = Artisan::output();

    expect($out)->toContain('Refusing: kitsune:credentials has one action, status.')
        ->and(str_contains($out, substr($typed, 0, 12)))->toBeFalse();
});
