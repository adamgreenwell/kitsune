<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Models\Reader;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Kitsune\Core\Http\Controllers\Readers\LinkRequestController;
use Kitsune\Core\Http\Controllers\Readers\LinkUseController;
use Kitsune\Core\Http\Controllers\Readers\SignInController;
use Kitsune\Core\Readers\ReaderThrottle;
use Kitsune\Core\Tests\Fixtures\BlindFindReader;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Kitsune\Core\Tests\Fixtures\ReaderMailbox;
use Kitsune\Core\Tests\Fixtures\RecordingTimebox;
use Kitsune\Core\Tests\Fixtures\UnscopedFindReader;
use Kitsune\Core\Tests\Fixtures\UsernameClashReader;

/*
 * Creating an account by email — ADR-037's second part, as built. `POST {prefix}/account/register` asks for a link;
 * `{prefix}/account/register/complete` is the link, and its POST chooses the password and makes the account.
 *
 * ⚠️ ONE ANSWER, WHATEVER THE ADDRESS: a stranger cannot learn from this page which addresses have accounts here.
 */

const SIGN_UP = '/golfdom/account/register';

const SIGN_UP_COMPLETE = '/golfdom/account/register/complete';

const SIGN_UP_PASSWORD = 'a long new password for golfdom';

beforeEach(function (): void {
    $this->world = ReaderFixture::world();
    ReaderFixture::mode($this->world['golfdom'], 'open');
    ReaderFixture::mode($this->world['rival'], 'open');
    $this->reader = ReaderFixture::reader($this->world['golfdom'], 'subscriber@kitsune.test');
    ReaderFixture::reader($this->world['golfdom'], 'nopassword@kitsune.test', null);
    // Every budget recorded, and none slept: a test that asks thirty times would otherwise wait six seconds.
    $this->timebox = RecordingTimebox::install();
});

/** What an observer sees of a response — all but its cookies and date — and what it left for the next page to say. */
function signUpSeen(TestResponse $response): array
{
    $headers = $response->headers->all();
    unset($headers['set-cookie'], $headers['date']);

    return [$response->getStatusCode(), $headers, $response->getContent(), app('session.store')->get(SignInController::STATUS)];
}

/** Asks for a sign-up link for this address and opens it, as a reader's click does: its hash is stashed. */
function signUpOpened(string $email = 'newcomer@kitsune.test'): string
{
    test()->readerPost(SIGN_UP, ['email' => $email])->assertStatus(303);
    $link = ReaderMailbox::link() ?? throw new LogicException('No link was mailed.');
    test()->readerGet($link)->assertStatus(303)->assertHeader('Location', SIGN_UP_COMPLETE);

    return $link;
}

/** Chooses the password on the opened link's page, typed twice. */
function signUpFinish(string $password = SIGN_UP_PASSWORD, ?string $again = null, array $extra = []): TestResponse
{
    return test()->readerPost(SIGN_UP_COMPLETE, ['password' => $password, 'password_confirmation' => $again ?? $password, ...$extra]);
}

/** Readers of every org. */
function signUpReaders(): int
{
    return Reader::withoutScopeBecause('a test counts', static fn ($query) => $query->count());
}

/** The reader signed in on the reader guard in this session, by key — or null. */
function signUpSignedIn(): ?string
{
    $id = app('session.store')->get(Auth::guard(ReaderFixture::GUARD)->getName());

    return $id === null ? null : (string) $id;
}

// ---- Asking for a link ----------------------------------------------------------------------------------------------

it('answers a new address, one with an account, one with no password and one over the mail limit alike', function (): void {
    expect(ReaderThrottle::mayMail((int) $this->world['golfdom']->getKey(), 'throttled@kitsune.test'))->toBeTrue();

    $seen = array_map(
        fn (string $email): array => signUpSeen($this->readerPost(SIGN_UP, ['email' => $email])),
        ['newcomer@kitsune.test', 'subscriber@kitsune.test', 'nopassword@kitsune.test', 'throttled@kitsune.test'],
    );

    expect($seen[0][0])->toBe(303)
        ->and($seen[0][1]['location'])->toBe([SIGN_UP])
        ->and($seen[0][3])->toBe('sent.register')
        ->and($seen[1])->toBe($seen[0])
        ->and($seen[2])->toBe($seen[0])
        ->and($seen[3])->toBe($seen[0])
        ->and(ReaderMailbox::sent())->toBe([
            ['newcomer@kitsune.test', 'Finish creating your Golfdom account'],
            ['subscriber@kitsune.test', 'You already have a Golfdom account'],
            ['nopassword@kitsune.test', 'You already have a Golfdom account'],
        ])
        // The same budget on every branch, never cut short, with the reader lookup inside it past the mail limit.
        ->and(array_map(static fn (array $call): array => [$call['microseconds'], $call['early'], $call['lookups']], $this->timebox->calls))
        ->toBe([[200_000, false, 1], [200_000, false, 1], [200_000, false, 1], [200_000, false, 0]])
        ->and($this->timebox->lookupsOutside)->toBe(0)
        ->and(LinkRequestController::TIMEBOX)->toBe(200_000);
});

it('says the link is on its way, once, on the page the request came from', function (): void {
    $this->readerPost(SIGN_UP, ['email' => 'newcomer@kitsune.test'])->assertStatus(303);

    $this->readerGet(SIGN_UP)
        ->assertOk()
        ->assertSee('<title>Check your email — Golfdom</title>', false)
        ->assertSeeText('If that address can receive email, we\'ve sent it a message. If it can be used for a new account here, the message has a link that works once, for 60 minutes. Nothing arrived? Check your spam folder, or ask again after 5 minutes.')
        ->assertDontSee('newcomer@kitsune.test');

    $this->readerGet(SIGN_UP)->assertOk()->assertSee('<title>Create an account — Golfdom</title>', false);
});

it('makes no account when a link is asked for — only when it is used', function (): void {
    $this->readerPost(SIGN_UP, ['email' => 'newcomer@kitsune.test'])->assertStatus(303);

    expect(signUpReaders())->toBe(2)
        ->and(DB::table('reader_tokens')->count())->toBe(1);
});

it('mails the link to the address as normalised, whatever was typed', function (): void {
    $this->readerPost(SIGN_UP, ['email' => '  NewComer@Kitsune.TEST '])->assertStatus(303);

    expect(ReaderMailbox::sent())->toBe([['newcomer@kitsune.test', 'Finish creating your Golfdom account']])
        ->and(DB::table('reader_tokens')->value('subject'))->toBe('newcomer@kitsune.test');
});

it('refuses an address no reader could have, in place, and mails nothing', function (string $typed): void {
    $this->readerPost(SIGN_UP, ['email' => $typed])
        ->assertStatus(422)
        ->assertSee('<title>Error: Create an account — Golfdom</title>', false)
        ->assertSeeText('Enter an email address, like name@example.com.');

    expect(ReaderMailbox::messages())->toBe([])
        ->and(DB::table('reader_tokens')->count())->toBe(0);
})->with([
    'nothing' => [''],
    'not an address' => ['not-an-address'],
    'not ASCII' => ['jané@kitsune.test'],
]);

it('refuses a request without the body\'s token, before anything is counted', function (): void {
    $this->readerPost(SIGN_UP, ['email' => 'newcomer@kitsune.test'], token: false)->assertStatus(419);

    expect(ReaderMailbox::messages())->toBe([]);

    // Not counted against the connection: five more still pass.
    foreach (range(1, ReaderThrottle::LINK_IP_LIMIT) as $n) {
        $this->readerPost(SIGN_UP, ['email' => "newcomer{$n}@kitsune.test"])->assertStatus(303);
    }
});

it('refuses a sixth request in a minute from one connection, and sends nothing for it', function (): void {
    foreach (range(1, 5) as $n) {
        $this->readerPost(SIGN_UP, ['email' => "newcomer{$n}@kitsune.test"])->assertStatus(303);
    }

    // Recovery shares the count.
    $this->readerPost('/golfdom/account/recover', ['email' => 'subscriber@kitsune.test'])
        ->assertStatus(429)
        ->assertSeeText('Too many attempts from your connection. Please wait a minute, then try again. Nothing was checked or sent.');

    expect(ReaderMailbox::messages())->toHaveCount(5);

    $this->travel(61)->seconds();
    $this->readerPost(SIGN_UP, ['email' => 'newcomer6@kitsune.test'])->assertStatus(303);
});

it('refuses a connection\'s thirty-first request in a day, saying how many hours remain', function (): void {
    foreach (range(1, ReaderThrottle::LINK_IP_DAY_LIMIT) as $n) {
        $this->readerPost(SIGN_UP, ['email' => "newcomer{$n}@kitsune.test"])->assertStatus(303);

        if ($n % 5 === 0) {
            $this->travel(61)->seconds();
        }
    }

    $this->readerPost(SIGN_UP, ['email' => 'late@kitsune.test'])
        ->assertStatus(429)
        ->assertSeeText('Too many requests from your connection today. Please try again in 24 hours. Nothing was sent.');

    $this->travel(23)->hours();
    $this->readerPost(SIGN_UP, ['email' => 'late@kitsune.test'])->assertSeeText('Please try again in an hour.');

    expect(ReaderMailbox::messages())->toHaveCount(30);
});

it('mails one address once in five minutes, and five times in a day, whichever door it is asked at', function (): void {
    $this->readerPost(SIGN_UP, ['email' => 'subscriber@kitsune.test'])->assertStatus(303);
    $this->readerPost('/golfdom/account/recover', ['email' => 'subscriber@kitsune.test'])->assertStatus(303);

    expect(ReaderMailbox::messages())->toHaveCount(1);

    foreach (range(2, 7) as $n) {
        $this->travel(ReaderThrottle::MAIL_GAP + 1)->seconds();
        $this->readerPost(SIGN_UP, ['email' => 'subscriber@kitsune.test'])->assertStatus(303);
    }

    expect(ReaderMailbox::messages())->toHaveCount(5);

    // Per org: the same address at Rival is another reader's.
    $this->readerPost('/rival/account/register', ['email' => 'subscriber@kitsune.test'])->assertStatus(303);

    expect(ReaderMailbox::messages())->toHaveCount(6);
});

it('sends a signed-in reader to their account instead', function (): void {
    ReaderFixture::signIn($this->reader);

    $this->readerGet(SIGN_UP)->assertStatus(303)->assertHeader('Location', '/golfdom/account');
    $this->readerPost(SIGN_UP, ['email' => 'newcomer@kitsune.test'])->assertStatus(303)->assertHeader('Location', '/golfdom/account');

    expect(ReaderMailbox::messages())->toBe([]);
});

// ---- Opening the link -----------------------------------------------------------------------------------------------

it('keeps the link\'s hash in the session and answers 303 to the same page with no query', function (string $prefix): void {
    $this->readerPost(SIGN_UP, ['email' => 'newcomer@kitsune.test'])->assertStatus(303);
    $secret = (string) ReaderMailbox::secret();

    $response = $this->readerGet($prefix.'/account/register/complete?token='.$secret)
        ->assertStatus(303)
        ->assertHeader('Location', SIGN_UP_COMPLETE)
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Cache-Control', 'no-store, private');

    expect(app('session.store')->get(LinkUseController::STASH.'.register.'.$this->world['sites']['golfdom']->getKey()))->toBe(hash('sha256', $secret))
        // `_previous.url` included: Laravel writes a GET's full URL there after the answer is made.
        ->and(serialize(app('session.store')->all()))->not->toContain($secret)
        ->and(app('session.store')->previousUrl())->toBe('http://localhost'.$prefix.'/account/register/complete')
        ->and((string) $response->getContent())->not->toContain($secret);

    $this->readerGet(SIGN_UP_COMPLETE)
        ->assertOk()
        ->assertSee('<title>Choose your password — Golfdom</title>', false)
        ->assertSeeText('You\'re creating an account for newcomer@kitsune.test.');
})->with(['as mailed' => ['/golfdom'], 'in another case' => ['/GOLFDOM']]);

it('moves the session to a new id before it holds the link, so an id planted in the browser never holds it', function (): void {
    $this->readerPost(SIGN_UP, ['email' => 'newcomer@kitsune.test'])->assertStatus(303);
    $session = app('session.store');
    $session->save();
    $planted = $session->getId();

    // The browser presents the planted id, as a cookie set by someone else would make it.
    $this->withCookie((string) config('session.cookie'), $planted)->readerGet((string) ReaderMailbox::link())->assertStatus(303);

    expect($session->getId())->not->toBe($planted)
        ->and($session->getHandler()->read($planted))->toBe('')
        ->and($session->get(LinkUseController::STASH.'.register.'.$this->world['sites']['golfdom']->getKey()))->not->toBeNull();
});

it('leaves no secret in the session when a link is refused before its page is reached', function (string $why): void {
    $this->readerPost(SIGN_UP, ['email' => 'newcomer@kitsune.test'])->assertStatus(303);
    $secret = (string) ReaderMailbox::secret();

    match ($why) {
        'sign-up closed' => ReaderFixture::mode($this->world['golfdom'], 'sign-in'),
        'accounts off' => ReaderFixture::mode($this->world['golfdom'], 'off'),
        'accounts unusable' => config(['kitsune.readers.guard' => null]),
    };

    $this->readerGet((string) ReaderMailbox::link())->assertNotFound();

    expect(serialize(app('session.store')->all()))->not->toContain($secret)
        ->and(app('session.store')->previousUrl())->toBe('http://localhost/golfdom/account/register/complete');
})->with(['sign-up closed', 'accounts off', 'accounts unusable']);

it('drops an opened link at sign-out, so the next person at the browser cannot use it', function (): void {
    $signedIn = ReaderFixture::reader($this->world['golfdom'], 'other@kitsune.test');
    signUpOpened();
    ReaderFixture::signIn($signedIn);

    $this->readerPost('/golfdom/account/sign-out')->assertStatus(303);

    expect(app('session.store')->has(LinkUseController::STASH))->toBeFalse();
    $this->readerGet(SIGN_UP_COMPLETE)->assertStatus(410);
    signUpFinish()->assertStatus(410);
});

it('uses nothing up when a link is only opened, as a mail scanner would', function (): void {
    $link = signUpOpened();
    $this->readerGet($link)->assertStatus(303);
    $this->readerGet(SIGN_UP_COMPLETE)->assertOk();
    $this->readerGet(SIGN_UP_COMPLETE)->assertOk();

    expect(DB::table('reader_tokens')->count())->toBe(1)
        ->and(signUpReaders())->toBe(2);

    signUpFinish()->assertStatus(303);
});

it('forgets the stashed link when a query names none, or a malformed one', function (string $query): void {
    signUpOpened();

    $this->readerGet(SIGN_UP_COMPLETE.$query)->assertStatus(303)->assertHeader('Location', SIGN_UP_COMPLETE);
    $this->readerGet(SIGN_UP_COMPLETE)->assertStatus(410);

    expect(DB::table('reader_tokens')->count())->toBe(1);
})->with([
    'another parameter' => ['?utm_source=mail'],
    'too short' => ['?token='.str_repeat('a', 42)],
    'not the alphabet' => ['?token='.str_repeat('-', 43)],
    'a list' => ['?token[]='.str_repeat('a', 43)],
]);

it('answers 410 for a link that never was, with a way to ask for another and nothing to submit', function (): void {
    $this->readerGet(SIGN_UP_COMPLETE.'?token='.str_repeat('a', 43))->assertStatus(303);

    $this->readerGet(SIGN_UP_COMPLETE)
        ->assertStatus(410)
        ->assertSeeText('This link doesn\'t work any more. A link works once, for 60 minutes, and a newer link replaces an older one.')
        ->assertSee('<a href="/golfdom/account/register">Ask for a new link</a>', false)
        ->assertDontSee('<form', false);

    signUpFinish()->assertStatus(410);

    expect(signUpReaders())->toBe(2);
});

// ---- Choosing the password ------------------------------------------------------------------------------------------

it('makes the account, signs its reader in on a new session id, and says so', function (): void {
    signUpOpened();
    $id = app('session.store')->getId();

    signUpFinish()->assertStatus(303)->assertHeader('Location', '/golfdom/account');

    $reader = Reader::withoutScopeBecause('a test reads the row', static fn ($query) => $query->where('email', 'newcomer@kitsune.test')->firstOrFail());

    expect($reader->org_id)->toBe($this->world['golfdom']->getKey())
        ->and(Hash::check(SIGN_UP_PASSWORD, $reader->getAuthPassword()))->toBeTrue()
        ->and($reader->email_verified_at)->not->toBeNull()
        ->and(signUpSignedIn())->toBe((string) $reader->getKey())
        ->and(app('session.store')->getId())->not->toBe($id)
        ->and(app('session.store')->get(LinkUseController::STASH.'.register.'.$this->world['sites']['golfdom']->getKey()))->toBeNull()
        ->and(DB::table('reader_tokens')->count())->toBe(0);

    $this->readerGet('/golfdom/account')
        ->assertOk()
        ->assertSee('<p class="status" role="status">Your account is ready.</p>', false)
        ->assertSeeText('Signed in as newcomer@kitsune.test.');
});

it('takes the address from the link alone — never from the query or the form', function (): void {
    signUpOpened();

    $this->readerPost(SIGN_UP_COMPLETE.'?token='.str_repeat('a', 43), [
        'password' => SIGN_UP_PASSWORD,
        'password_confirmation' => SIGN_UP_PASSWORD,
        'email' => 'attacker@kitsune.test',
        'username' => 'attacker@kitsune.test',
    ])->assertStatus(303);

    expect(Reader::withoutScopeBecause('a test reads the row', static fn ($query) => $query->pluck('email')->sort()->values()->all()))
        ->toBe(['newcomer@kitsune.test', 'nopassword@kitsune.test', 'subscriber@kitsune.test']);
});

it('refuses a password it would refuse anywhere, in place, and keeps the link', function (string $password, ?string $again, string $field, string $copy): void {
    signUpOpened();

    $response = signUpFinish($password, $again)
        ->assertStatus(422)
        ->assertSee('<title>Error: Choose your password — Golfdom</title>', false)
        ->assertSeeText($copy);

    expect($response->getContent())->toContain('id="'.$field.'-error"')
        ->and(signUpReaders())->toBe(2)
        ->and(DB::table('reader_tokens')->count())->toBe(1);

    signUpFinish()->assertStatus(303);
})->with([
    'none' => ['', null, 'password', 'Choose a password.'],
    'too short' => ['fourteen chars', null, 'password', 'Your password must be at least 15 characters.'],
    'too long' => [str_repeat('a', 73), null, 'password', 'Your password is longer than 72 bytes, and only the first 72 would count.'],
    'a space at its edge' => [' '.SIGN_UP_PASSWORD, null, 'password', 'Your password can\'t begin or end with a space or an invisible character.'],
    'the address' => ['newcomer@kitsune.test', null, 'password', 'Your password can\'t be your email address.'],
    'a control character' => ["a long new\x07password for golfdom", null, 'password', 'Your password contains a character this form can\'t send.'],
    'not typed again' => [SIGN_UP_PASSWORD, '', 'password_confirmation', 'Type your new password again.'],
    'typed differently' => [SIGN_UP_PASSWORD, SIGN_UP_PASSWORD.'!', 'password_confirmation', 'The two passwords don\'t match.'],
]);

it('refuses a password POST without the body\'s token, and keeps the link', function (): void {
    signUpOpened();

    $this->readerPost(SIGN_UP_COMPLETE, ['password' => SIGN_UP_PASSWORD, 'password_confirmation' => SIGN_UP_PASSWORD], token: false)->assertStatus(419);

    expect(signUpReaders())->toBe(2);
    signUpFinish()->assertStatus(303);
});

it('works once: the link is dead after it is used', function (): void {
    $link = signUpOpened();
    signUpFinish()->assertStatus(303);
    $this->readerPost('/golfdom/account/sign-out')->assertStatus(303);

    $this->readerGet($link)->assertStatus(303);
    $this->readerGet(SIGN_UP_COMPLETE)->assertStatus(410);
    signUpFinish()->assertStatus(410);

    expect(signUpReaders())->toBe(3);
});

it('changes nothing when the address has an account by the time the link is used, and uses the link up', function (): void {
    signUpOpened();
    ReaderFixture::reader($this->world['golfdom'], 'newcomer@kitsune.test', 'the-password-chosen-elsewhere');

    signUpFinish()->assertStatus(303)->assertHeader('Location', '/golfdom/account/sign-in');

    $this->readerGet('/golfdom/account/sign-in')
        ->assertOk()
        ->assertSeeText('An account with this address already exists here. Sign in, or choose a new password if you\'ve forgotten it. Nothing was changed.');

    $reader = Reader::withoutScopeBecause('a test reads the row', static fn ($query) => $query->where('email', 'newcomer@kitsune.test')->firstOrFail());

    expect(Hash::check('the-password-chosen-elsewhere', $reader->getAuthPassword()))->toBeTrue()
        ->and(signUpSignedIn())->toBeNull()
        ->and(DB::table('reader_tokens')->count())->toBe(0);
});

it('uses the link up and logs nothing when the insert meets the unique index', function (): void {
    config(['auth.providers.readers.model' => BlindFindReader::class]);
    signUpOpened();
    ReaderFixture::reader($this->world['golfdom'], 'newcomer@kitsune.test', 'the-password-chosen-elsewhere');

    $logged = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message;
    });

    // The lookup before the insert misses, as one a moment before the other sign-up committed would; the look after finds it.
    BlindFindReader::$misses = 1;
    signUpFinish()->assertStatus(303)->assertHeader('Location', '/golfdom/account/sign-in');

    expect($logged)->toBe([])
        ->and(BlindFindReader::$misses)->toBe(0)
        ->and(signUpSignedIn())->toBeNull()
        ->and(DB::table('reader_tokens')->count())->toBe(0)
        ->and(signUpReaders())->toBe(3);

    signUpFinish()->assertStatus(410);
});

it('says nothing is taken when the host refuses the insert for a reason of its own, and keeps the link', function (): void {
    Exceptions::fake();
    config(['auth.providers.readers.model' => UsernameClashReader::class]);
    signUpOpened();

    signUpFinish()->assertStatus(500)->assertDontSeeText('already exists');

    Exceptions::assertReported(static fn (RuntimeException $refused): bool => $refused->getMessage() === 'The readers table could not be read or written (SQLSTATE 23000).');

    expect(DB::table('reader_tokens')->count())->toBe(1)
        ->and(signUpReaders())->toBe(2)
        ->and(signUpSignedIn())->toBeNull()
        ->and(app('session.store')->get(SignInController::STATUS))->toBeNull();

    // Once the host's own constraint lets it, the same link makes the account.
    config(['auth.providers.readers.model' => Reader::class]);
    signUpFinish()->assertStatus(303)->assertHeader('Location', '/golfdom/account');
});

it('leaves a reader signed in at another org alone, and signs the new one in here', function (): void {
    $rival = ReaderFixture::reader($this->world['rival'], 'rival@kitsune.test');
    ReaderFixture::signIn($rival);
    $rivalKey = app('session.store')->get(Auth::guard(ReaderFixture::GUARD)->getName());

    signUpOpened();
    signUpFinish()->assertStatus(303);

    expect(signUpSignedIn())->not->toBe((string) $rivalKey);
    $this->readerGet('/golfdom/account')->assertOk()->assertSeeText('Signed in as newcomer@kitsune.test.');
});

it('fences on the row\'s own org: a host lookup that finds another org\'s reader mails nobody about them', function (): void {
    config(['auth.providers.readers.model' => UnscopedFindReader::class]);

    $this->readerPost('/rival/account/register', ['email' => 'subscriber@kitsune.test'])->assertStatus(303);
    $this->travel(ReaderThrottle::MAIL_GAP + 1)->seconds();
    $this->readerPost('/rival/account/recover', ['email' => 'subscriber@kitsune.test'])->assertStatus(303);

    expect(ReaderMailbox::sent())->toBe([
        ['subscriber@kitsune.test', 'Finish creating your Rival account'],
        ['subscriber@kitsune.test', 'No Rival account uses this address'],
    ]);
});

it('makes the account in the link\'s org, even when a host lookup finds the address in another', function (): void {
    config(['auth.providers.readers.model' => UnscopedFindReader::class]);

    $this->readerPost('/rival/account/register', ['email' => 'subscriber@kitsune.test'])->assertStatus(303);
    $this->readerGet((string) ReaderMailbox::link())->assertStatus(303)->assertHeader('Location', '/rival/account/register/complete');
    $this->readerPost('/rival/account/register/complete', ['password' => SIGN_UP_PASSWORD, 'password_confirmation' => SIGN_UP_PASSWORD])
        ->assertStatus(303)
        ->assertHeader('Location', '/rival/account');

    expect(Reader::withoutScopeBecause('a test reads the rows', static fn ($query) => $query->where('email', 'subscriber@kitsune.test')->pluck('org_id')->sort()->values()->all()))
        ->toBe([$this->world['golfdom']->getKey(), $this->world['rival']->getKey()]);
});

// ---- Closed ---------------------------------------------------------------------------------------------------------

it('answers 404 on every sign-up page while the site is sign-in only', function (): void {
    ReaderFixture::mode($this->world['golfdom'], 'sign-in');

    $this->readerGet(SIGN_UP)->assertNotFound();
    $this->readerPost(SIGN_UP, ['email' => 'newcomer@kitsune.test'])->assertNotFound();
    $this->readerGet(SIGN_UP_COMPLETE.'?token='.str_repeat('a', 43))->assertNotFound();
    signUpFinish()->assertNotFound();

    expect(ReaderMailbox::messages())->toBe([]);
});

it('makes no account from a link mailed before the site stopped taking sign-ups', function (): void {
    signUpOpened();
    ReaderFixture::mode($this->world['golfdom'], 'sign-in');

    signUpFinish()->assertNotFound();

    expect(signUpReaders())->toBe(2);
});

it('answers 503 on both sides of the page when no mail can be sent, reading nothing and sending nothing', function (): void {
    app()->detectEnvironment(static fn (): string => 'production');
    config(['app.env' => 'production', 'mail.default' => 'log']);

    $this->readerGet(SIGN_UP)
        ->assertStatus(503)
        ->assertSeeText('Accounts can\'t be created here right now, because this site can\'t send email yet. Signing in still works.')
        ->assertSee('<a href="/golfdom/account/sign-in">Go to sign in</a>', false)
        ->assertDontSee('<form', false);

    $this->readerPost(SIGN_UP, ['email' => 'newcomer@kitsune.test'])->assertStatus(503);

    expect(DB::table('reader_tokens')->count())->toBe(0)
        ->and(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0);
});
