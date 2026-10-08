<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Models\Reader;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Hashing\HashManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Kitsune\Core\Http\Middleware\RequireReader;
use Kitsune\Core\Readers\ReaderSessions;
use Kitsune\Core\Readers\ReaderThrottle;
use Kitsune\Core\Tests\Fixtures\CountingHashManager;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;

/*
 * Signing in — ADR-037, as built. `POST {prefix}/account/sign-in`.
 *
 * ⚠️ AN UNKNOWN ADDRESS, A WRONG PASSWORD AND A READER WITH NO PASSWORD ARE ONE ANSWER: the same status, body and
 * headers, and exactly one hash check each. A stranger learns nothing about which addresses have accounts here.
 */

const SIGN_IN = '/golfdom/account/sign-in';

const SIGN_IN_FAILED = 'That email address and password don\'t match an account here. Check both and try again.';

beforeEach(function (): void {
    $this->world = ReaderFixture::world();
    ReaderFixture::mode($this->world['golfdom'], 'sign-in');
    $this->reader = ReaderFixture::reader($this->world['golfdom'], 'subscriber@kitsune.test');
    $this->passwordless = ReaderFixture::reader($this->world['golfdom'], 'nopassword@kitsune.test', null);
});

/** The reader signed in on the reader guard in this session, by key — or null. */
function signInSignedIn(): ?string
{
    $key = Auth::guard(ReaderFixture::GUARD)->getName();
    $id = app('session.store')->get($key);

    return $id === null ? null : (string) $id;
}

/** A new bcrypt cost, as a deploy that changes it starts the next process with. */
function signInCost(int $rounds): void
{
    config(['hashing.bcrypt.rounds' => $rounds]);
    app('hash')->forgetDrivers();
}

/** Everything a response says but its cookies and its date: what an observer compares. */
function signInVisible(TestResponse $response): array
{
    $headers = $response->headers->all();
    unset($headers['set-cookie'], $headers['date']);

    return [$response->getStatusCode(), $headers, $response->getContent()];
}

it('signs a reader in and sends them to their account page', function (): void {
    $response = $this->readerPost(SIGN_IN, ['email' => 'subscriber@kitsune.test', 'password' => ReaderFixture::PASSWORD]);

    $response->assertStatus(303)->assertHeader('Location', '/golfdom/account');
    expect(signInSignedIn())->toBe((string) $this->reader->getKey());

    $this->readerGet('/golfdom/account')->assertOk()->assertSeeText('Signed in as subscriber@kitsune.test.');
});

it('finds the reader whatever the case or padding of the typed address', function (string $typed): void {
    $this->readerPost(SIGN_IN, ['email' => $typed, 'password' => ReaderFixture::PASSWORD])->assertStatus(303);

    expect(signInSignedIn())->toBe((string) $this->reader->getKey());
})->with([
    'upper case' => ['SUBSCRIBER@Kitsune.TEST'],
    'padded' => ['  subscriber@kitsune.test  '],
]);

it('gives an unknown address, a wrong password and a reader with no password the same answer', function (): void {
    $wrongPassword = $this->readerPost(SIGN_IN, ['email' => 'subscriber@kitsune.test', 'password' => 'not-the-password-at-all']);
    $noPassword = $this->readerPost(SIGN_IN, ['email' => 'nopassword@kitsune.test', 'password' => 'not-the-password-at-all']);
    $nobody = $this->readerPost(SIGN_IN, ['email' => 'nobody-here@kitsune.test', 'password' => 'not-the-password-at-all']);

    foreach ([$wrongPassword, $noPassword, $nobody] as $response) {
        $response->assertStatus(422)->assertSeeText(SIGN_IN_FAILED);
    }

    // Byte for byte once the typed address is taken out: only the echo of what was typed differs.
    $strip = static fn (TestResponse $response): string => str_replace(
        ['subscriber@kitsune.test', 'nopassword@kitsune.test', 'nobody-here@kitsune.test'],
        'TYPED',
        (string) json_encode(signInVisible($response)),
    );

    expect($strip($wrongPassword))->toBe($strip($noPassword))->toBe($strip($nobody))
        ->and(signInSignedIn())->toBeNull();
});

it('checks exactly one hash on every path, and makes the timing hash once', function (): void {
    $hasher = CountingHashManager::install();

    foreach ([
        ['subscriber@kitsune.test', 'not-the-password-at-all'],
        ['nopassword@kitsune.test', 'not-the-password-at-all'],
        ['nobody-here@kitsune.test', 'not-the-password-at-all'],
        ['nobody-else@kitsune.test', 'not-the-password-at-all'],
    ] as [$email, $password]) {
        $before = $hasher->checks;
        $this->readerPost(SIGN_IN, ['email' => $email, 'password' => $password])->assertStatus(422);

        expect($hasher->checks - $before)->toBe(1);
    }

    // The unknown and passwordless paths share one cached hash, made at the first of them.
    expect($hasher->makes)->toBe(1);

    $before = $hasher->checks;
    $this->readerPost(SIGN_IN, ['email' => 'subscriber@kitsune.test', 'password' => ReaderFixture::PASSWORD])->assertStatus(303);

    expect($hasher->checks - $before)->toBe(1);
});

it('checks against a real hash at the configured cost, made again when the cost changes', function (): void {
    $first = ReaderSessions::timingHash();

    expect(ReaderSessions::timingHash())->toBe($first)
        ->and(Hash::info($first)['options']['cost'])->toBe(4);

    signInCost(5);
    $second = ReaderSessions::timingHash();

    expect($second)->not->toBe($first)
        ->and(Hash::info($second)['options']['cost'])->toBe(5)
        ->and(Cache::get(ReaderSessions::TIMING_HASH.sha1((string) json_encode([config('hashing.driver'), config('hashing.bcrypt'), config('hashing.argon')]))))->toBe($second);
});

it('runs no query on the readers table for an address the grammar refuses', function (string $typed): void {
    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->readerPost(SIGN_IN, ['email' => $typed, 'password' => ReaderFixture::PASSWORD])
        ->assertStatus(422)
        ->assertSeeText(SIGN_IN_FAILED);

    expect(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'readers')))->toBe([]);
})->with([
    'no at sign' => ['subscriber.kitsune.test'],
    'not ASCII' => ['jané@kitsune.test'],
    'empty' => [''],
]);

it('asks for a password that was not given, and checks nothing', function (array $body): void {
    // A host may drop Laravel's ConvertEmptyStringsToNull, so an empty password must be refused as it arrives.
    $this->withoutMiddleware(ConvertEmptyStringsToNull::class);
    $hasher = CountingHashManager::install();

    $this->readerPost(SIGN_IN, ['email' => 'subscriber@kitsune.test'] + $body)
        ->assertStatus(422)
        ->assertSeeText('Enter your password.');

    expect($hasher->checks)->toBe(0)
        ->and(signInSignedIn())->toBeNull();
})->with(['absent' => [[]], 'empty' => [['password' => '']]]);

it('rehashes a password whose cost is out of date, and keeps the reader signed in', function (): void {
    signInCost(5);

    $this->readerPost(SIGN_IN, ['email' => 'subscriber@kitsune.test', 'password' => ReaderFixture::PASSWORD])->assertStatus(303);

    $stored = (string) Reader::withoutScopeBecause('a test reads the row back', fn ($query) => $query->whereKey($this->reader->getKey())->value('password'));

    expect(Hash::info($stored)['options']['cost'])->toBe(5)
        ->and(Hash::check(ReaderFixture::PASSWORD, $stored))->toBeTrue();

    $this->readerGet('/golfdom/account')->assertOk();
});

it('re-renders a refusal in place: the address from this request, never the session, and never the password', function (): void {
    $response = $this->readerPost(SIGN_IN, ['email' => 'Stranger@Kitsune.test', 'password' => 'a-password-nobody-should-see']);

    $response->assertStatus(422)
        ->assertSee('value="Stranger@Kitsune.test"', false)
        ->assertDontSee('a-password-nobody-should-see');

    $stored = json_encode(app('session.store')->all());

    expect($stored)->not->toContain('tranger')
        ->and($stored)->not->toContain('a-password-nobody-should-see');
});

it('returns a reader to the page they were sent from, and only to one of this site\'s reader pages', function (?string $intended, string $expected): void {
    app('session.store')->put(RequireReader::INTENDED, $intended);

    $this->readerPost(SIGN_IN, ['email' => 'subscriber@kitsune.test', 'password' => ReaderFixture::PASSWORD])
        ->assertStatus(303)
        ->assertHeader('Location', $expected);

    expect(app('session.store')->has(RequireReader::INTENDED))->toBeFalse();
})->with([
    'a later page of its own' => ['/golfdom/account/some-later-page', '/golfdom/account/some-later-page'],
    'another site of the org' => ['/golfdom-fr/account', '/golfdom/account'],
    'another org' => ['/rival/account', '/golfdom/account'],
    'another host' => ['//evil.test/golfdom/account', '/golfdom/account'],
    'a URL' => ['https://evil.test/golfdom/account', '/golfdom/account'],
    'not as the site spells it' => ['/GOLFDOM/account', '/golfdom/account'],
    'a query' => ['/golfdom/account?next=https://evil.test', '/golfdom/account'],
    'nothing' => [null, '/golfdom/account'],
]);

it('keeps the page a guest asked for, when it is one of this site\'s reader pages', function (): void {
    $this->readerGet('/golfdom/account')->assertStatus(303)->assertHeader('Location', SIGN_IN);

    expect(app('session.store')->get(RequireReader::INTENDED))->toBe('/golfdom/account');

    // A spelling that is not the site's own is still sent to sign in, and nothing is kept.
    app('session.store')->forget(RequireReader::INTENDED);
    $this->readerGet('/GOLFDOM/account')->assertStatus(303)->assertHeader('Location', SIGN_IN);

    expect(app('session.store')->has(RequireReader::INTENDED))->toBeFalse();
});

it('sends a signed-in reader from the sign-in page to their account', function (): void {
    ReaderFixture::signIn($this->reader);

    $this->readerGet(SIGN_IN)->assertStatus(303)->assertHeader('Location', '/golfdom/account');
    $this->readerPost(SIGN_IN, ['email' => 'subscriber@kitsune.test', 'password' => ReaderFixture::PASSWORD])
        ->assertStatus(303)
        ->assertHeader('Location', '/golfdom/account');
});

it('refuses a POST whose body does not carry the session\'s token, and signs nobody in', function (array $body): void {
    $this->readerPost(SIGN_IN, $body + ['email' => 'subscriber@kitsune.test', 'password' => ReaderFixture::PASSWORD], token: false)
        ->assertStatus(419);

    expect(signInSignedIn())->toBeNull();
})->with([
    'no token' => [[]],
    'another token' => [['_token' => str_repeat('x', 40)]],
    'an empty token' => [['_token' => '']],
    'a token in an array' => [['_token' => ['x']]],
]);

it('refuses a token sent only in a header', function (): void {
    $this->readerPost(SIGN_IN, ['email' => 'subscriber@kitsune.test', 'password' => ReaderFixture::PASSWORD], token: false, headers: ['X-CSRF-TOKEN' => ReaderFixture::token()])
        ->assertStatus(419);

    expect(signInSignedIn())->toBeNull();
});

// ---- Throttles ------------------------------------------------------------------------------------------------------

it('lets an address knock five times a minute, then refuses the sixth before checking anything — even the right password', function (): void {
    $hasher = CountingHashManager::install();

    for ($i = 0; $i < 5; $i++) {
        $this->readerPost(SIGN_IN, ['email' => 'nobody-here@kitsune.test', 'password' => 'not-the-password-at-all'])->assertStatus(422);
    }

    $checks = $hasher->checks;

    $this->readerPost(SIGN_IN, ['email' => 'subscriber@kitsune.test', 'password' => ReaderFixture::PASSWORD])
        ->assertStatus(429)
        ->assertSeeText('Too many attempts from your connection. Please wait a minute, then try again. Nothing was checked or sent.');

    expect($hasher->checks)->toBe($checks)
        ->and(signInSignedIn())->toBeNull();

    // Another connection is not refused.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
    $this->readerPost(SIGN_IN, ['email' => 'subscriber@kitsune.test', 'password' => ReaderFixture::PASSWORD])->assertStatus(303);
});

it('counts a success against the connection too', function (): void {
    for ($i = 0; $i < 5; $i++) {
        $this->readerPost(SIGN_IN, ['email' => 'subscriber@kitsune.test', 'password' => ReaderFixture::PASSWORD])->assertStatus(303);
        Auth::guard(ReaderFixture::GUARD)->logoutCurrentDevice();
    }

    $this->readerPost(SIGN_IN, ['email' => 'subscriber@kitsune.test', 'password' => ReaderFixture::PASSWORD])->assertStatus(429);
});

it('counts an IPv6 connection by its /64, and an IPv4-mapped one as its IPv4 address', function (): void {
    expect(ReaderThrottle::network('::ffff:203.0.113.5'))->toBe(ReaderThrottle::network('203.0.113.5'))
        ->and(ReaderThrottle::network('::ffff:203.0.113.5'))->not->toBe(ReaderThrottle::network('::ffff:198.51.100.7'))
        ->and(ReaderThrottle::network('2001:db8:0:0:1::1'))->toBe(ReaderThrottle::network('2001:db8::ffff:2'))
        ->and(ReaderThrottle::network('2001:db8:0:1::1'))->not->toBe(ReaderThrottle::network('2001:db8::1'))
        ->and(ReaderThrottle::network('203.0.113.9'))->not->toBe(ReaderThrottle::network('203.0.113.10'))
        ->and(ReaderThrottle::network(null))->toBe('unknown');

    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::1']);

    for ($i = 0; $i < 5; $i++) {
        $this->readerPost(SIGN_IN, ['email' => 'nobody-here@kitsune.test', 'password' => 'not-the-password-at-all'])->assertStatus(422);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::ab:cd']);
    $this->readerPost(SIGN_IN, ['email' => 'nobody-here@kitsune.test', 'password' => 'not-the-password-at-all'])->assertStatus(429);
});

it('refuses an address after ten failures in fifteen minutes, from any connection, known or not', function (string $email): void {
    foreach (['203.0.113.1', '203.0.113.2'] as $ip) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);

        for ($i = 0; $i < 5; $i++) {
            $this->readerPost(SIGN_IN, ['email' => $email, 'password' => 'not-the-password-at-all'])->assertStatus(422)->assertSeeText(SIGN_IN_FAILED);
        }
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.3']);
    $hasher = CountingHashManager::install();

    $this->readerPost(SIGN_IN, ['email' => strtoupper($email), 'password' => ReaderFixture::PASSWORD])
        ->assertStatus(422)
        ->assertSeeText('Too many attempts to sign in to this account. Please wait 15 minutes, or choose a new password.');

    expect($hasher->checks)->toBe(0)
        ->and(signInSignedIn())->toBeNull();
})->with([
    'a reader\'s' => ['subscriber@kitsune.test'],
    'nobody\'s' => ['nobody-here@kitsune.test'],
]);

it('says "a minute" when a minute is what is left', function (): void {
    $key = ReaderThrottle::accountKey($this->world['golfdom']->getKey(), 'subscriber@kitsune.test');

    for ($i = 0; $i < ReaderThrottle::ACCOUNT_LIMIT; $i++) {
        RateLimiter::hit($key, 50);
    }

    $this->readerPost(SIGN_IN, ['email' => 'subscriber@kitsune.test', 'password' => ReaderFixture::PASSWORD])
        ->assertStatus(422)
        ->assertSeeText('Too many attempts to sign in to this account. Please wait a minute, or choose a new password.');
});

it('counts an attempt before its hash is checked, so attempts that arrive together cannot all pass the count', function (): void {
    $account = ReaderThrottle::accountKey($this->world['golfdom']->getKey(), 'subscriber@kitsune.test');
    $seen = [];
    $hasher = new class(app()) extends HashManager
    {
        /** @var Closure(): void */
        public Closure $during;

        public function check(#[SensitiveParameter] $value, $hashedValue, array $options = [])
        {
            ($this->during)();

            return parent::check($value, $hashedValue, $options);
        }
    };
    $connection = 'kitsune:readers:sign-in-ip:'.hash_hmac('sha256', ReaderThrottle::network('127.0.0.1'), (string) config('app.key'));
    $hasher->during = static function () use (&$seen, $account, $connection): void {
        $seen[] = [RateLimiter::attempts($connection), RateLimiter::attempts($account)];
    };
    app()->instance('hash', $hasher);
    Hash::clearResolvedInstance('hash');

    $this->readerPost(SIGN_IN, ['email' => 'subscriber@kitsune.test', 'password' => 'not-the-password-at-all'])->assertStatus(422);
    $this->readerPost(SIGN_IN, ['email' => 'subscriber@kitsune.test', 'password' => 'not-the-password-at-all'])->assertStatus(422);

    // Each attempt is already in the count while its hash is being checked.
    expect($seen)->toBe([[1, 1], [2, 2]]);
});

it('answers a hash another driver made with the generic refusal, at the cost of one hash, rather than an error', function (): void {
    config(['hashing.driver' => 'argon2id']);
    app('hash')->forgetDrivers();
    $hasher = CountingHashManager::install();

    $known = $this->readerPost(SIGN_IN, ['email' => 'subscriber@kitsune.test', 'password' => ReaderFixture::PASSWORD]);
    $checks = $hasher->checks;
    $unknown = $this->readerPost(SIGN_IN, ['email' => 'nobody-here@kitsune.test', 'password' => ReaderFixture::PASSWORD]);

    $known->assertStatus(422)->assertSeeText(SIGN_IN_FAILED);
    $unknown->assertStatus(422)->assertSeeText(SIGN_IN_FAILED);

    // The bcrypt hash refused before hashing, then the timing hash: two calls, one of them a real hash; the unknown, one.
    expect($checks)->toBe(2)
        ->and($hasher->checks - $checks)->toBe(1)
        ->and(signInSignedIn())->toBeNull();
})->skip(! defined('PASSWORD_ARGON2ID'), 'PHP without Argon2');

it('counts an address in one org only, and clears it on success', function (): void {
    $golfdom = ReaderThrottle::accountKey($this->world['golfdom']->getKey(), 'subscriber@kitsune.test');
    $rival = ReaderThrottle::accountKey($this->world['rival']->getKey(), 'subscriber@kitsune.test');

    $this->readerPost(SIGN_IN, ['email' => 'subscriber@kitsune.test', 'password' => 'not-the-password-at-all'])->assertStatus(422);

    expect(RateLimiter::attempts($golfdom))->toBe(1)
        ->and(RateLimiter::attempts($rival))->toBe(0);

    $this->readerPost(SIGN_IN, ['email' => 'subscriber@kitsune.test', 'password' => ReaderFixture::PASSWORD])->assertStatus(303);

    expect(RateLimiter::attempts($golfdom))->toBe(0);
});

it('keeps no address and no connection in a cache key', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77']);
    $this->readerPost(SIGN_IN, ['email' => 'subscriber@kitsune.test', 'password' => 'not-the-password-at-all'])->assertStatus(422);

    $keys = array_keys((fn (): array => $this->storage)->call(Cache::store()->getStore()));

    expect($keys)->not->toBeEmpty()
        ->and(implode("\n", $keys))->not->toContain('subscriber')
        ->and(implode("\n", $keys))->not->toContain('203.0.113.77')
        ->and(implode("\n", $keys))->not->toContain((string) $this->world['golfdom']->getKey().'|');
});
