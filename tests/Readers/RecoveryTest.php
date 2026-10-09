<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Models\Reader;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Kitsune\Core\Http\Controllers\Readers\SignInController;
use Kitsune\Core\Readers\ReaderThrottle;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Kitsune\Core\Tests\Fixtures\ReaderMailbox;
use Kitsune\Core\Tests\Fixtures\RecordingTimebox;
use Kitsune\Core\Tests\Fixtures\ShoutingReader;

/*
 * Choosing a new password by email — ADR-037's second part, as built. `POST {prefix}/account/recover` asks for a link;
 * `{prefix}/account/reset` is the link, and its POST changes the password and signs every other session out.
 *
 * ⚠️ ONE ANSWER, WHATEVER THE ADDRESS, and every request that passes the mail limit sends one mail: a link for an
 * address with an account, a note for one without (Adam, 2026-10-09).
 */

const RECOVER = '/golfdom/account/recover';

const RECOVER_RESET = '/golfdom/account/reset';

const RECOVER_PASSWORD = 'a different long password';

beforeEach(function (): void {
    $this->world = ReaderFixture::world();
    ReaderFixture::mode($this->world['golfdom'], 'sign-in');
    $this->reader = ReaderFixture::reader($this->world['golfdom'], 'subscriber@kitsune.test');
    $this->passwordless = ReaderFixture::reader($this->world['golfdom'], 'nopassword@kitsune.test', null);
    $this->timebox = RecordingTimebox::install();
});

/** What an observer sees of a response — all but its cookies and date — and what it left for the next page to say. */
function recoverSeen(TestResponse $response): array
{
    $headers = $response->headers->all();
    unset($headers['set-cookie'], $headers['date']);

    return [$response->getStatusCode(), $headers, $response->getContent(), app('session.store')->get(SignInController::STATUS)];
}

/** Asks for a recovery link for this address and opens it, as a reader's click does. */
function recoverOpened(string $email = 'subscriber@kitsune.test', string $prefix = '/golfdom'): string
{
    test()->readerPost($prefix.'/account/recover', ['email' => $email])->assertStatus(303);
    $link = ReaderMailbox::link() ?? throw new LogicException('No link was mailed.');
    test()->readerGet($link)->assertStatus(303)->assertHeader('Location', $prefix.'/account/reset');

    return $link;
}

/** Chooses the new password on the opened link's page, typed twice. */
function recoverChoose(string $password = RECOVER_PASSWORD, ?string $again = null): TestResponse
{
    return test()->readerPost(RECOVER_RESET, ['password' => $password, 'password_confirmation' => $again ?? $password]);
}

/** The row as another request would load it. */
function recoverRow(Reader $reader): ?Reader
{
    return Reader::withoutScopeBecause('a test reads the row', static fn ($query) => $query->find($reader->getKey()));
}

// ---- Asking for a link ----------------------------------------------------------------------------------------------

it('answers an address with an account, one with no password, one with none and one over the limit alike', function (): void {
    ReaderThrottle::mayMail((int) $this->world['golfdom']->getKey(), 'throttled@kitsune.test');

    $seen = array_map(
        fn (string $email): array => recoverSeen($this->readerPost(RECOVER, ['email' => $email])),
        ['subscriber@kitsune.test', 'nopassword@kitsune.test', 'nobody@kitsune.test', 'throttled@kitsune.test'],
    );

    expect($seen[0][0])->toBe(303)
        ->and($seen[0][1]['location'])->toBe([RECOVER])
        ->and($seen[0][3])->toBe('sent.recover')
        ->and($seen[1])->toBe($seen[0])
        ->and($seen[2])->toBe($seen[0])
        ->and($seen[3])->toBe($seen[0])
        ->and(ReaderMailbox::sent())->toBe([
            ['subscriber@kitsune.test', 'Choose a new password for Golfdom'],
            ['nopassword@kitsune.test', 'Choose a new password for Golfdom'],
            ['nobody@kitsune.test', 'No Golfdom account uses this address'],
        ])
        ->and(array_map(static fn (array $call): array => [$call['microseconds'], $call['early'], $call['lookups']], $this->timebox->calls))
        ->toBe([[200_000, false, 1], [200_000, false, 1], [200_000, false, 1], [200_000, false, 0]])
        ->and($this->timebox->lookupsOutside)->toBe(0);
});

it('mails an address with no account a note, and mints nothing for it', function (): void {
    $this->readerPost(RECOVER, ['email' => 'nobody@kitsune.test'])->assertStatus(303);

    expect(DB::table('reader_tokens')->count())->toBe(0)
        ->and(ReaderMailbox::link())->toBeNull()
        ->and((string) ReaderMailbox::last()?->getTextBody())->toContain('no account there uses it');
});

it('says the message is on its way without saying whether the address has an account', function (): void {
    $this->readerPost(RECOVER, ['email' => 'nobody@kitsune.test'])->assertStatus(303);

    $this->readerGet(RECOVER)
        ->assertOk()
        ->assertSee('<title>Check your email — Golfdom</title>', false)
        ->assertSeeText('If that address can receive email, we\'ve sent it a message. If it has an account here, the message has a link to choose a new password, which works once, for 60 minutes.');
});

it('serves recovery wherever a reader can sign in, and nowhere accounts are off', function (): void {
    ReaderFixture::mode($this->world['golfdom'], 'open');
    $this->readerGet(RECOVER)->assertOk();

    ReaderFixture::mode($this->world['golfdom'], 'off');
    $this->readerGet(RECOVER)->assertNotFound();
    $this->readerPost(RECOVER, ['email' => 'subscriber@kitsune.test'])->assertNotFound();

    expect(ReaderMailbox::messages())->toBe([]);
});

// ---- Choosing the password ------------------------------------------------------------------------------------------

it('changes the password, keeps this browser signed in, and says so', function (): void {
    recoverOpened();
    $before = recoverRow($this->reader);

    $this->readerGet(RECOVER_RESET)
        ->assertOk()
        ->assertSee('<title>Choose a new password — Golfdom</title>', false)
        ->assertSee('value="subscriber@kitsune.test"', false);

    recoverChoose()->assertStatus(303)->assertHeader('Location', '/golfdom/account');

    $after = recoverRow($this->reader);

    expect(Hash::check(RECOVER_PASSWORD, $after->getAuthPassword()))->toBeTrue()
        ->and(Hash::check(ReaderFixture::PASSWORD, $after->getAuthPassword()))->toBeFalse()
        ->and($after->getRememberToken())->not->toBe($before->getRememberToken())
        ->and(strlen((string) $after->getRememberToken()))->toBe(60)
        ->and(DB::table('reader_tokens')->count())->toBe(0);

    // The next request too: the session holds the binding of the row as written, not of the row as it was.
    $this->readerGet('/golfdom/account')
        ->assertOk()
        ->assertSee('<p class="status" role="status">Your password has been changed, and you&#039;ve been signed out everywhere else.</p>', false);
    $this->readerGet('/golfdom/account')->assertOk()->assertDontSeeText('changed');
});

it('signs every other session out', function (): void {
    ReaderFixture::signIn($this->reader);
    $this->readerGet('/golfdom/account')->assertOk();
    $elsewhere = app('session.store')->all();

    // This browser starts with nothing; the other keeps what it had.
    app('session.store')->flush();
    recoverOpened();
    recoverChoose()->assertStatus(303);

    app('session.store')->flush();
    app('session.store')->put($elsewhere);

    $this->readerGet('/golfdom/account')->assertStatus(303)->assertHeader('Location', '/golfdom/account/sign-in');
});

it('lets the new password sign in and refuses the old one', function (): void {
    recoverOpened();
    recoverChoose()->assertStatus(303);
    $this->readerPost('/golfdom/account/sign-out')->assertStatus(303);

    $this->readerPost('/golfdom/account/sign-in', ['email' => 'subscriber@kitsune.test', 'password' => ReaderFixture::PASSWORD])->assertStatus(422);
    $this->readerPost('/golfdom/account/sign-in', ['email' => 'subscriber@kitsune.test', 'password' => RECOVER_PASSWORD])->assertStatus(303);
});

it('lifts the account\'s sign-in limit, so a reader locked out by a stranger gets back in', function (): void {
    $orgId = (int) $this->world['golfdom']->getKey();

    foreach (range(1, ReaderThrottle::ACCOUNT_LIMIT + 1) as $attempt) {
        ReaderThrottle::accountAttempt($orgId, 'subscriber@kitsune.test');
    }

    expect(ReaderThrottle::accountAttempt($orgId, 'subscriber@kitsune.test'))->not->toBeNull();

    recoverOpened();
    recoverChoose()->assertStatus(303);

    expect(RateLimiter::attempts(ReaderThrottle::accountKey($orgId, 'subscriber@kitsune.test')))->toBe(0);
});

it('lifts the limit sign-in counts, even when the host hands the address back in another case', function (): void {
    config(['auth.providers.readers.model' => ShoutingReader::class]);
    $orgId = (int) $this->world['golfdom']->getKey();

    foreach (range(1, ReaderThrottle::ACCOUNT_LIMIT + 1) as $attempt) {
        ReaderThrottle::accountAttempt($orgId, 'subscriber@kitsune.test');
    }

    recoverOpened();
    recoverChoose()->assertStatus(303);

    expect(RateLimiter::attempts(ReaderThrottle::accountKey($orgId, 'subscriber@kitsune.test')))->toBe(0);
});

it('gives a reader with no password one', function (): void {
    recoverOpened('nopassword@kitsune.test');
    recoverChoose()->assertStatus(303);

    expect(Hash::check(RECOVER_PASSWORD, (string) recoverRow($this->passwordless)->getAuthPassword()))->toBeTrue();
});

it('refuses a password in place and keeps both the old one and the link', function (): void {
    recoverOpened();

    recoverChoose(RECOVER_PASSWORD, 'a different long passwort')
        ->assertStatus(422)
        ->assertSee('<title>Error: Choose a new password — Golfdom</title>', false)
        ->assertSeeText('The two passwords don\'t match.');
    recoverChoose('subscriber@kitsune.test')->assertStatus(422)->assertSeeText('Your password can\'t be your email address.');

    expect(Hash::check(ReaderFixture::PASSWORD, recoverRow($this->reader)->getAuthPassword()))->toBeTrue()
        ->and(DB::table('reader_tokens')->count())->toBe(1);

    recoverChoose()->assertStatus(303);
});

it('replaces another reader signed in on this browser with the one whose password changed', function (): void {
    $other = ReaderFixture::reader($this->world['golfdom'], 'other@kitsune.test');
    recoverOpened();
    ReaderFixture::signIn($other);

    recoverChoose()->assertStatus(303);

    expect((string) app('session.store')->get(Auth::guard(ReaderFixture::GUARD)->getName()))->toBe((string) $this->reader->getKey());
    $this->readerGet('/golfdom/account')->assertOk()->assertSeeText('Signed in as subscriber@kitsune.test.');
});

it('works once', function (): void {
    $link = recoverOpened();
    recoverChoose()->assertStatus(303);
    $this->readerPost('/golfdom/account/sign-out')->assertStatus(303);

    $this->readerGet($link)->assertStatus(303);
    $this->readerGet(RECOVER_RESET)->assertStatus(410)->assertSee('<a href="/golfdom/account/recover">Ask for a new link</a>', false);
    recoverChoose('yet another long password')->assertStatus(410);

    expect(Hash::check(RECOVER_PASSWORD, recoverRow($this->reader)->getAuthPassword()))->toBeTrue();
});

it('answers 410 when the reader was erased after the link was mailed, and the link goes with them', function (): void {
    recoverOpened();
    Reader::withoutScopeBecause('the host deleting its own row', fn ($query) => $query->whereKey($this->reader->getKey())->delete());

    $this->readerGet(RECOVER_RESET)->assertStatus(410);
    recoverChoose()->assertStatus(410);

    expect(Reader::withoutScopeBecause('a test counts', static fn ($query) => $query->count()))->toBe(1);
});

it('does not open a sign-up link as a recovery link, nor a recovery link as a sign-up link', function (): void {
    ReaderFixture::mode($this->world['golfdom'], 'open');

    $this->readerPost('/golfdom/account/register', ['email' => 'newcomer@kitsune.test'])->assertStatus(303);
    $this->travel(ReaderThrottle::MAIL_GAP + 1)->seconds();
    $signUp = (string) ReaderMailbox::secret();

    $this->readerGet(RECOVER_RESET.'?token='.$signUp)->assertStatus(303);
    $this->readerGet(RECOVER_RESET)->assertStatus(410);

    $this->readerPost(RECOVER, ['email' => 'subscriber@kitsune.test'])->assertStatus(303);
    $recovery = (string) ReaderMailbox::secret();

    $this->readerGet('/golfdom/account/register/complete?token='.$recovery)->assertStatus(303);
    $this->readerGet('/golfdom/account/register/complete')->assertStatus(410);
});

it('works on the site that mailed it and no other — not a sibling, not another org\'s', function (): void {
    ReaderFixture::mode($this->world['rival'], 'sign-in');
    $this->readerPost(RECOVER, ['email' => 'subscriber@kitsune.test'])->assertStatus(303);
    $secret = (string) ReaderMailbox::secret();

    foreach (['/golfdom-fr', '/rival'] as $prefix) {
        $this->readerGet($prefix.'/account/reset?token='.$secret)->assertStatus(303);
        $this->readerGet($prefix.'/account/reset')->assertStatus(410);
        $this->readerPost($prefix.'/account/reset', ['password' => RECOVER_PASSWORD, 'password_confirmation' => RECOVER_PASSWORD])->assertStatus(410);
    }

    expect(DB::table('reader_tokens')->count())->toBe(1);

    $this->readerGet(RECOVER_RESET.'?token='.$secret)->assertStatus(303);
    recoverChoose()->assertStatus(303);
});
