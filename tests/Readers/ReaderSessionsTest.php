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
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Http\Middleware\ReaderArea;
use Kitsune\Core\Http\Middleware\ResolveSiteFromRequest;
use Kitsune\Core\Http\Middleware\SetSiteLocale;
use Kitsune\Core\Readers\ReaderRoutes;
use Kitsune\Core\Readers\ReaderSessions;
use Kitsune\Core\Readers\ReaderThrottle;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Kitsune\Core\Tests\Fixtures\ReaderMailbox;
use Kitsune\Core\Tests\Fixtures\RecordingTimebox;
use Kitsune\Core\Tests\Fixtures\ScopeStrippingReaderProvider;
use Kitsune\Core\Tests\Fixtures\TestUser;
use Kitsune\Core\Tests\Fixtures\UnscopedFindReader;

/*
 * A reader's session — ADR-037, as built: Laravel's session guard, a binding to the password, and a sign-out that
 * leaves a staff session in the same cookie alone.
 */

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);

    $this->world = ReaderFixture::world();
    ReaderFixture::mode($this->world['golfdom'], 'sign-in');
    ReaderFixture::mode($this->world['rival'], 'sign-in');
    $this->reader = ReaderFixture::reader($this->world['golfdom'], 'subscriber@kitsune.test');
});

/** The session's key for a guard. */
function sessionsKey(string $guard): string
{
    return Auth::guard($guard)->getName();
}

/** The same row as a second instance, as another request would load it — so a change elsewhere is a change elsewhere. */
function sessionsElsewhere(Reader $reader): Reader
{
    return Reader::withoutScopeBecause('another request\'s load of the same reader', static fn ($query) => $query->findOrFail($reader->getKey()));
}

/** An owner of Golfdom Media, signed in on `web` in this session, as the admin signs one in. */
function sessionsOwner(): TestUser
{
    app(Context::class)->forget()->setOrg(test()->world['golfdom']);
    $owner = CredentialFixture::member(owner: true, email: 'owner@kitsune.test');
    Auth::guard('web')->login($owner);
    ReaderFixture::forget();

    return $owner;
}

it('binds a reader\'s session to their password at sign-in, and keeps them signed in on the next request', function (): void {
    $this->readerPost('/golfdom/account/sign-in', ['email' => 'subscriber@kitsune.test', 'password' => ReaderFixture::PASSWORD])->assertStatus(303);

    expect(app('session.store')->get(ReaderSessions::BINDING))->toBe(ReaderSessions::binding(sessionsElsewhere($this->reader)))
        ->and(app('session.store')->get(ReaderSessions::BINDING))->not->toContain('$2y$');

    $this->readerGet('/golfdom/account')->assertOk()->assertSeeText('Signed in as subscriber@kitsune.test.');
});

it('ends a session when the password changes elsewhere', function (): void {
    ReaderFixture::signIn($this->reader);
    $this->readerGet('/golfdom/account')->assertOk();

    $elsewhere = sessionsElsewhere($this->reader);
    $elsewhere->forceFill(['password' => Hash::make('a-new-password-chosen-elsewhere')])->save();

    $this->readerGet('/golfdom/account')->assertStatus(303)->assertHeader('Location', '/golfdom/account/sign-in');

    expect(app('session.store')->has(sessionsKey('readers')))->toBeFalse()
        ->and(app('session.store')->has(ReaderSessions::BINDING))->toBeFalse();
});

it('ends a session when the remember token is cycled elsewhere — what "sign out everywhere" will do', function (): void {
    ReaderFixture::signIn($this->reader);

    $elsewhere = sessionsElsewhere($this->reader);
    $elsewhere->setRememberToken('cycled-elsewhere');
    $elsewhere->save();

    $this->readerGet('/golfdom/account')->assertStatus(303);
});

it('treats a reader in the session with no binding as nobody — never signed in by setUser() or a planted key', function (): void {
    app('session.store')->put(sessionsKey('readers'), $this->reader->getKey());

    $this->readerGet('/golfdom/account')->assertStatus(303);
    expect(app('session.store')->has(sessionsKey('readers')))->toBeFalse();

    Auth::guard('readers')->setUser($this->reader);
    expect(Auth::guard('readers')->user())->toBeNull();
});

it('leaves a reader model without the contract alone, so a host with its own login keeps working', function (): void {
    EntitlementFixture::declareReaders();
    $site = $this->world['sites']['golfdom'];
    app(Context::class)->forget()->setSite($site);
    $testReader = EntitlementFixture::reader($this->world['golfdom']);

    EntitlementFixture::signIn($testReader);

    expect(app(ReaderGuard::class)->current())->toBe((string) $testReader->getKey())
        ->and(app('session.store')->has(ReaderSessions::BINDING))->toBeFalse();
});

it('signs out of this session alone: the id changes, a staff session and the CSRF token stay', function (): void {
    sessionsOwner();
    ReaderFixture::signIn($this->reader);
    $token = ReaderFixture::token();
    $id = app('session.store')->getId();
    $web = app('session.store')->get(sessionsKey('web'));

    $this->readerPost('/golfdom/account/sign-out')->assertStatus(303)->assertHeader('Location', '/golfdom/account/sign-in');

    expect(app('session.store')->has(sessionsKey('readers')))->toBeFalse()
        ->and(app('session.store')->has(ReaderSessions::BINDING))->toBeFalse()
        ->and(app('session.store')->get(sessionsKey('web')))->toBe($web)
        ->and(app('session.store')->token())->toBe($token)
        ->and(app('session.store')->getId())->not->toBe($id);

    $this->readerGet('/golfdom/account/sign-in')
        ->assertOk()
        ->assertSee('<p class="status" role="status">You&#039;ve signed out.</p>', false);

    // Said once: the next visit says nothing.
    $this->readerGet('/golfdom/account/sign-in')->assertOk()->assertDontSeeText('signed out');
});

it('moves a signed-out session to a new id and leaves nothing under the old one', function (): void {
    ReaderFixture::signIn($this->reader);
    $session = app('session.store');
    $session->put(sessionsKey('web'), 42);
    $session->save();
    $old = $session->getId();

    app(Context::class)->forget()->setOrg($this->world['golfdom']);
    app(ReaderSessions::class)->signOut();
    $session->save();

    expect($session->getId())->not->toBe($old)
        ->and($session->getHandler()->read($old))->toBe('')
        ->and($session->get(sessionsKey('web')))->toBe(42)
        ->and($session->has(sessionsKey('readers')))->toBeFalse();
});

it('signs out a guest without complaint, and refuses a sign-out without the body\'s token', function (): void {
    $this->readerPost('/golfdom/account/sign-out')->assertStatus(303);

    ReaderFixture::signIn($this->reader);
    $this->readerPost('/golfdom/account/sign-out', token: false)->assertStatus(419);
    $this->readerGet('/golfdom/account')->assertOk();
});

it('rotates the session id and the CSRF token at sign-in — a client that sends no Fetch Metadata is refused once after it (a named residual)', function (): void {
    $token = ReaderFixture::token();
    $id = app('session.store')->getId();

    $this->readerPost('/golfdom/account/sign-in', ['email' => 'subscriber@kitsune.test', 'password' => ReaderFixture::PASSWORD])->assertStatus(303);

    expect(app('session.store')->getId())->not->toBe($id)
        ->and(app('session.store')->token())->not->toBe($token);
});

it('does not sign a member of staff in as a reader with their own address and password', function (): void {
    $owner = sessionsOwner();
    $owner->forceFill(['password' => Hash::make(ReaderFixture::PASSWORD)])->save();
    Auth::guard('web')->logout();
    ReaderFixture::forget();

    $this->readerPost('/golfdom/account/sign-in', ['email' => 'owner@kitsune.test', 'password' => ReaderFixture::PASSWORD])
        ->assertStatus(422)
        ->assertSeeText('That email address and password don\'t match an account here.');

    expect(app('session.store')->has(sessionsKey('readers')))->toBeFalse()
        ->and(app('session.store')->has(sessionsKey('web')))->toBeFalse();
});

it('never takes a staff user riding the session for a reader', function (): void {
    sessionsOwner();

    $this->readerGet('/golfdom/account')->assertStatus(303)->assertHeader('Location', '/golfdom/account/sign-in');
    $this->readerGet('/golfdom/account/sign-in')->assertOk();
});

it('makes the reader, or nobody, the current user on a reader route — never the owner riding along', function (): void {
    Route::get('{readerSite}/account/whoami', static fn (): array => [
        'current' => Permissions::currentUser()?->getAuthIdentifier(),
        'class' => Permissions::currentUser() !== null ? Permissions::currentUser()::class : null,
        'auth' => auth()->user() !== null ? auth()->user()::class : null,
    ])->middleware([ResolveSiteFromRequest::class, SetSiteLocale::class, ReaderArea::class])
        ->where(ReaderRoutes::SITE_PARAMETER, ReaderRoutes::sitePattern());

    sessionsOwner();

    $this->readerGet('/golfdom/account/whoami')->assertOk()->assertExactJson(['current' => null, 'class' => null, 'auth' => null]);

    ReaderFixture::signIn($this->reader);

    $this->readerGet('/golfdom/account/whoami')->assertOk()->assertExactJson([
        'current' => $this->reader->getKey(),
        'class' => Reader::class,
        'auth' => Reader::class,
    ]);
});

it('does not read a reader as an owner whose id they share', function (): void {
    $owner = sessionsOwner();
    app(Context::class)->forget()->setOrg($this->world['golfdom']);

    // The owner's own id: on an engine that does not restart its sequences per test, written below Eloquent.
    if (! DB::table('readers')->where('id', $owner->getKey())->exists()) {
        DB::table('readers')->insert(['id' => $owner->getKey(), 'org_id' => $this->world['golfdom']->getKey(), 'email' => 'twin@kitsune.test']);
    }

    $twin = Reader::query()->whereKey($owner->getKey())->firstOrFail();

    expect($twin->getKey())->toBe($owner->getKey())
        ->and(Permissions::isOwner($owner))->toBeTrue()
        ->and(Permissions::isOwner($twin))->toBeFalse();
});

// ---- Two orgs, two sites --------------------------------------------------------------------------------------------

it('keeps one address in two orgs as two readers with two passwords', function (): void {
    $rivalReader = ReaderFixture::reader($this->world['rival'], 'subscriber@kitsune.test', 'rival-password-is-different');

    $this->readerPost('/rival/account/sign-in', ['email' => 'subscriber@kitsune.test', 'password' => ReaderFixture::PASSWORD])->assertStatus(422);
    $this->readerPost('/rival/account/sign-in', ['email' => 'subscriber@kitsune.test', 'password' => 'rival-password-is-different'])->assertStatus(303);

    expect(app('session.store')->get(sessionsKey('readers')))->toBe($rivalReader->getKey());

    $this->readerGet('/rival/account')->assertOk();
    // One reader slot per browser: Rival's reader is nobody at Golfdom.
    $this->readerGet('/golfdom/account')->assertStatus(303);
});

it('treats a reader signed in at one org as a guest at another, and as nobody to the guard there', function (): void {
    ReaderFixture::signIn($this->reader);

    $this->readerGet('/rival/account')->assertStatus(303)->assertHeader('Location', '/rival/account/sign-in');
    $this->readerGet('/rival/account/sign-in')->assertOk();

    ReaderFixture::forget();
    app(Context::class)->setSite($this->world['sites']['rival']);
    expect(app(ReaderGuard::class)->current())->toBeNull();

    ReaderFixture::forget();
    app(Context::class)->setSite($this->world['sites']['golfdom']);
    expect(app(ReaderGuard::class)->current())->toBe((string) $this->reader->getKey());
});

it('fences a reader on their own org, even through a host provider that strips the org scope', function (): void {
    Auth::provider(ScopeStrippingReaderProvider::DRIVER, static fn ($app, array $config) => new ScopeStrippingReaderProvider($app['hash'], $config['model']));
    config(['auth.providers.readers.driver' => ScopeStrippingReaderProvider::DRIVER]);
    ReaderFixture::signIn($this->reader);

    $this->readerGet('/golfdom/account')->assertOk();
    $this->readerGet('/rival/account')->assertStatus(303)->assertHeader('Location', '/rival/account/sign-in');
});

it('fences a reader found by address on their own org, even when a host\'s lookup does not', function (): void {
    config(['auth.providers.readers.model' => UnscopedFindReader::class]);
    ReaderFixture::reader($this->world['rival'], 'rival-only@kitsune.test');

    $this->readerPost('/golfdom/account/sign-in', ['email' => 'rival-only@kitsune.test', 'password' => ReaderFixture::PASSWORD])->assertStatus(422);
    expect(app('session.store')->has(sessionsKey('readers')))->toBeFalse();
});

it('never finds another org\'s reader by address', function (): void {
    app(Context::class)->forget()->setOrg($this->world['rival']);

    expect(Reader::findByEmail('subscriber@kitsune.test'))->toBeNull();

    app(Context::class)->forget()->setOrg($this->world['golfdom']);

    expect(Reader::findByEmail('subscriber@kitsune.test')?->getKey())->toBe($this->reader->getKey());
});

it('keeps a reader signed in across the sites of one org', function (): void {
    $this->readerPost('/golfdom/account/sign-in', ['email' => 'subscriber@kitsune.test', 'password' => ReaderFixture::PASSWORD])->assertStatus(303);

    $this->readerGet('/golfdom-fr/account')->assertOk()->assertSeeText('Signed in as subscriber@kitsune.test.');
});

// ---- Nothing recorded -----------------------------------------------------------------------------------------------

it('audits nothing and logs nothing on any reader page', function (): void {
    $logged = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message;
    });
    $audited = DB::table('audit_log')->count();

    $this->readerGet('/golfdom/account/sign-in')->assertOk();
    $this->readerPost('/golfdom/account/sign-in', ['email' => 'subscriber@kitsune.test', 'password' => 'not-the-password-at-all'])->assertStatus(422);
    $this->readerPost('/golfdom/account/sign-in', ['email' => 'nobody@kitsune.test', 'password' => 'not-the-password-at-all'])->assertStatus(422);
    $this->readerPost('/golfdom/account/sign-in', ['email' => 'subscriber@kitsune.test', 'password' => ReaderFixture::PASSWORD])->assertStatus(303);
    $this->readerGet('/golfdom/account')->assertOk();
    $this->readerPost('/golfdom/account/sign-out')->assertStatus(303);

    expect(DB::table('audit_log')->count())->toBe($audited)
        ->and($logged)->toBe([]);
});

it('audits nothing and logs nothing on sign-up or recovery, whichever way each goes', function (): void {
    ReaderFixture::mode($this->world['golfdom'], 'open');
    RecordingTimebox::install();
    $logged = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message;
    });
    $audited = DB::table('audit_log')->count();

    $this->readerGet('/golfdom/account/register')->assertOk();
    $this->readerPost('/golfdom/account/register', ['email' => 'not-an-address'])->assertStatus(422);
    $this->readerPost('/golfdom/account/register', ['email' => 'subscriber@kitsune.test'])->assertStatus(303);
    $this->readerPost('/golfdom/account/register', ['email' => 'newcomer@kitsune.test'])->assertStatus(303);
    $this->readerGet((string) ReaderMailbox::link())->assertStatus(303);
    $this->readerPost('/golfdom/account/register/complete', ['password' => 'short', 'password_confirmation' => 'short'])->assertStatus(422);
    $this->readerPost('/golfdom/account/register/complete', ['password' => 'a long new password', 'password_confirmation' => 'a long new password'])->assertStatus(303);
    $this->readerPost('/golfdom/account/sign-out')->assertStatus(303);
    $this->readerGet('/golfdom/account/register/complete')->assertStatus(410);

    $this->readerPost('/golfdom/account/recover', ['email' => 'nobody@kitsune.test'])->assertStatus(303);
    $this->travel(ReaderThrottle::MAIL_GAP + 1)->seconds();
    $this->readerPost('/golfdom/account/recover', ['email' => 'subscriber@kitsune.test'])->assertStatus(303);
    $this->readerGet((string) ReaderMailbox::link())->assertStatus(303);
    $this->readerPost('/golfdom/account/reset', ['password' => 'a different long password', 'password_confirmation' => 'a different long password'])->assertStatus(303);

    expect(DB::table('audit_log')->count())->toBe($audited)
        ->and($logged)->toBe([]);
});
