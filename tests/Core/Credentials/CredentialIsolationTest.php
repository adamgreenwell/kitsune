<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Credentials\CredentialCipher;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialRefusal;
use Kitsune\Core\Credentials\CredentialRefused;
use Kitsune\Core\Credentials\CredentialUnavailability;
use Kitsune\Core\Credentials\CredentialUnavailable;
use Kitsune\Core\Models\Credential;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Fx;

/*
 * One org's credentials are nobody else's — ADR-040 and ADR-021, asserted from the side of the org that would be crossing.
 */

beforeEach(function (): void {
    Fx::boot();

    $this->a = Fx::org('alpha');
    Fx::member(email: 'alpha@kitsune.test');
    Fx::writer()->set(Fx::SHARED, null, $this->secretA = Fx::value('', 40));
    Fx::writer()->set(Fx::PAYMENT, CredentialMode::Test, Fx::value('fx_test_'));
    Fx::writer()->set(Fx::PAYMENT, CredentialMode::Live, Fx::value('fx_live_'));

    $this->b = Fx::org('beta');
    Fx::member(email: 'beta@kitsune.test');
});

afterEach(fn () => Fx::tearDown());

/** @param  Closure(): mixed  $read */
function credentialUnavailability(Closure $read): CredentialUnavailability
{
    try {
        $read();
    } catch (CredentialUnavailable $unavailable) {
        return $unavailable->reason;
    }

    throw new RuntimeException('it was read');
}

it('reads, lists and changes nothing of another org\'s', function (): void {
    expect(credentialUnavailability(fn () => Fx::reader()->secret(Fx::SHARED)))->toBe(CredentialUnavailability::NotSet)
        ->and(Credential::query()->count())->toBe(0)
        ->and(Fx::states()->of(Fx::SHARED)->rowId)->toBeNull();

    Fx::writer()->set(Fx::SHARED, null, $mine = Fx::value('', 40));
    Fx::writer()->remove(Fx::PAYMENT, CredentialMode::Test);

    app(Context::class)->setOrg($this->a);

    expect(Fx::reader()->secret(Fx::SHARED)->reveal())->toBe($this->secretA)
        ->and(Credential::query()->count())->toBe(3)
        ->and(Credential::query()->whereNull('ciphertext')->exists())->toBeFalse();

    app(Context::class)->setOrg($this->b);

    expect(Fx::reader()->secret(Fx::SHARED)->reveal())->toBe($mine);
});

it('sees nothing, writes nothing and reads nothing with no org in context', function (): void {
    app(Context::class)->forget();

    expect(Credential::query()->count())->toBe(0)
        ->and(credentialUnavailability(fn () => Fx::reader()->secret(Fx::SHARED)))->toBe(CredentialUnavailability::NoOrgContext);

    try {
        Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));
        $this->fail('a write with no org in context was let through');
    } catch (CredentialRefused $refused) {
        expect($refused->reason)->toBe(CredentialRefusal::NoOrgContext);
    }
});

it('refuses a row filed under another org, whether or not the scope is set aside', function (): void {
    $plant = fn () => Credential::create(['org_id' => $this->a->getKey(), 'slot' => Fx::SHARED, 'mode' => 'none', 'ciphertext' => 'x', 'key_id' => 'x', 'changed_at' => now()]);

    expect($plant)->toThrow(RuntimeException::class)
        ->and(fn () => Credential::withoutScopeBecause('a test crossing orgs', fn () => $plant()))
        ->toThrow(RuntimeException::class, 'Refusing insertGetId() on credentials: a stored credential changes only through');

    app(Context::class)->setOrg($this->a);

    expect(Credential::query()->count())->toBe(3);
});

it('refuses a ciphertext moved to another org, another credential or the other mode', function (string $from, string $to): void {
    app(Context::class)->setOrg($this->b);
    Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));
    Fx::writer()->set(Fx::PAYMENT, CredentialMode::Test, Fx::value('fx_test_'));

    // Below Eloquent, where nothing but the envelope stands.
    $rows = [
        'alpha shared' => ['org_id' => $this->a->getKey(), 'slot' => Fx::SHARED, 'mode' => 'none'],
        'alpha test' => ['org_id' => $this->a->getKey(), 'slot' => Fx::PAYMENT, 'mode' => 'test'],
        'alpha live' => ['org_id' => $this->a->getKey(), 'slot' => Fx::PAYMENT, 'mode' => 'live'],
        'beta shared' => ['org_id' => $this->b->getKey(), 'slot' => Fx::SHARED, 'mode' => 'none'],
        'beta test' => ['org_id' => $this->b->getKey(), 'slot' => Fx::PAYMENT, 'mode' => 'test'],
    ];
    $sealed = DB::table('credentials')->where($rows[$from])->first(['ciphertext', 'key_id']);
    DB::table('credentials')->where($rows[$to])->update(['ciphertext' => $sealed->ciphertext, 'key_id' => $sealed->key_id]);

    app(Context::class)->setOrg($rows[$to]['org_id'] === $this->a->getKey() ? $this->a : $this->b);

    if ($rows[$to]['mode'] === 'live') {
        Fx::member(email: 'switcher-'.$from.'@kitsune.test');
        Fx::writer()->switchTo(CredentialMode::Live);
    }

    expect(credentialUnavailability(fn () => Fx::reader()->secret($rows[$to]['slot'])))->toBe(CredentialUnavailability::Misfiled);
})->with([
    'another org' => ['alpha shared', 'beta shared'],
    'another credential' => ['beta test', 'beta shared'],
    'the other mode' => ['alpha test', 'alpha live'],
]);

it('opens no row of another org, even one handed to the cipher past the scope', function (): void {
    $theirs = Credential::withoutScopeBecause('a test handing the cipher another org\'s row', fn ($query) => $query->where('org_id', $this->a->getKey())->where('slot', Fx::SHARED)->firstOrFail());

    expect(credentialUnavailability(fn () => app(CredentialCipher::class)->open($theirs)))->toBe(CredentialUnavailability::Misfiled);
});

it('serves one value to every site of the org', function (): void {
    app(Context::class)->setOrg($this->a);
    $one = Site::create(['org_id' => $this->a->getKey(), 'handle' => 'one', 'slug' => 'alpha-one', 'name' => 'One', 'locale' => 'en']);
    $two = Site::create(['org_id' => $this->a->getKey(), 'handle' => 'two', 'slug' => 'alpha-two', 'name' => 'Two', 'locale' => 'fr']);

    app(Context::class)->setSite($one);
    $fromOne = Fx::reader()->secret(Fx::SHARED)->reveal();
    app(Context::class)->setSite($two);

    expect(Fx::reader()->secret(Fx::SHARED)->reveal())->toBe($fromOne)
        ->and($fromOne)->toBe($this->secretA);
});
