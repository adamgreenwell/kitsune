<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Auth\ReaderGuardFault;
use Kitsune\Core\Entitlements\EntitlementRefusal;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Entitlement;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\AttributeOnlyReader;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\PanelTenancy;
use Kitsune\Core\Tests\Fixtures\ScopeStrippingReaderProvider;
use Kitsune\Core\Tests\Fixtures\TestReader;
use Kitsune\Core\Tests\Fixtures\TestUlidReader;
use Kitsune\Core\Tests\Fixtures\TestUser;
use Kitsune\Core\Tests\Fixtures\UnscopedUser;

/*
 * ADR-037's core half, from the side where nothing is declared — ADR-040's *When it lands*: "an entitlement check that
 * fails closed with no reader guard declared, asserted from the side where nothing is declared".
 *
 * ⚠️ EACH CASE STARTS FROM ABSENCE AND ADDS ONLY WHAT IT ATTACKS. It first asserts that nothing declared a guard, so it
 * cannot pass because something else did; and where a fallback could fail open, the fallback is PRESENT AND VISIBLY NOT
 * TAKEN: an owner signed in on `web` whose id is the id of a reader holding a row, planted below Eloquent.
 */

beforeEach(function (): void {
    Fx::boot();

    expect(config(ReaderGuard::CONFIG))->toBeNull();

    $this->org = Fx::org('acme');
    $this->site = Fx::site($this->org, 'main');
});

afterEach(function (): void {
    Fx::tearDown();
});

/** An owner on `web`, and a row for a reader with the owner's own id — the fallback a guess would take. */
function ownerWithAHeldRow(object $test, string $name = 'course.advanced-php'): TestUser
{
    $owner = Fx::owner();
    Fx::plant($test->site, (string) $owner->getKey(), $name);

    return $owner;
}

/** The query log while the callback runs, as SQL strings. */
function entitlementQueries(Closure $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $callback();
    } finally {
        DB::disableQueryLog();
    }

    return array_column(DB::getQueryLog(), 'query');
}

function refusedWith(Closure $write): EntitlementRefused
{
    try {
        $write();
    } catch (EntitlementRefused $refused) {
        return $refused;
    }

    throw new RuntimeException('The write was not refused.');
}

it('ships with no reader guard declared', function (): void {
    $shipped = require dirname(__DIR__, 3).'/packages/core/config/kitsune.php';

    expect($shipped)->toHaveKey('readers')
        ->and($shipped['readers'])->toBe(['guard' => null])
        ->and(Fx::guard()->fault())->toBe(ReaderGuardFault::NotDeclared);
});

it('fails closed with no guard declared, though an owner whose id holds a row is signed in, and answers once one is', function (): void {
    $owner = ownerWithAHeldRow($this);
    $id = (int) $owner->getKey();
    $audited = AuditLog::query()->count();

    $queries = entitlementQueries(fn () => expect(Fx::check()->holds('course.advanced-php'))->toBeFalse());

    expect(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'entitlements')))->toBe([])
        ->and(Fx::guard()->current())->toBeNull();

    $refused = refusedWith(fn () => Fx::writer()->grant($id, 'course.advanced-php', 'test.order:2', null));

    expect($refused->reason)->toBe(EntitlementRefusal::NoReaderGuard)
        ->and($refused->getMessage())->toContain('this installation declares no reader guard (kitsune.readers.guard)')
        ->and(DB::table('entitlements')->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe($audited);

    // One row, two answers, only the declaration differing: a reader with that id, declared and signed in, holds it.
    DB::table('test_readers')->insert(['id' => $id, 'org_id' => $this->org->getKey(), 'email' => 'reader@example.test']);
    Fx::declareReaders();
    Fx::signIn(TestReader::query()->findOrFail($id));

    expect(Fx::check()->holds('course.advanced-php'))->toBeTrue();

    config([ReaderGuard::CONFIG => null]);
    Fx::forget();

    expect(Fx::check()->holds('course.advanced-php'))->toBeFalse();
});

it('reads an absent, empty or non-string declaration as none declared', function (Closure $declare): void {
    $owner = ownerWithAHeldRow($this);
    $declare();
    Fx::forget();

    expect(Fx::guard()->fault())->toBe(ReaderGuardFault::NotDeclared)
        ->and(Fx::check()->holds('course.advanced-php'))->toBeFalse()
        ->and(refusedWith(fn () => Fx::writer()->grant((int) $owner->getKey(), 'course.advanced-php', 'test.order:2', null))->reason)
        ->toBe(EntitlementRefusal::NoReaderGuard);
})->with([
    'absent' => [fn () => config(['kitsune.readers' => []])],
    'no readers map' => [fn () => config(['kitsune' => array_diff_key(config('kitsune'), ['readers' => true])])],
    'null' => [fn () => config([ReaderGuard::CONFIG => null])],
    'empty' => [fn () => config([ReaderGuard::CONFIG => ''])],
    'an array' => [fn () => config([ReaderGuard::CONFIG => ['web']])],
    'an integer' => [fn () => config([ReaderGuard::CONFIG => 7])],
    'true' => [fn () => config([ReaderGuard::CONFIG => true])],
]);

it('refuses a declared name that is no guard, without asking Laravel for it', function (): void {
    $owner = ownerWithAHeldRow($this);
    config([ReaderGuard::CONFIG => 'nope']);
    Fx::forget();

    expect(Fx::guard()->fault())->toBe(ReaderGuardFault::UnknownGuard)
        ->and(Fx::check()->holds('course.advanced-php'))->toBeFalse();

    $refused = refusedWith(fn () => Fx::writer()->grant((int) $owner->getKey(), 'course.advanced-php', 'test.order:2', null));

    expect($refused->reason)->toBe(EntitlementRefusal::NoReaderGuard)
        ->and($refused->getMessage())->toContain('the declared reader guard [nope] is not a guard with a provider and a driver Laravel can build in its auth configuration');
});

it('refuses a dotted name, though config would resolve it to a usable guard', function (): void {
    config(['auth.guards.a' => ['b' => ['driver' => 'session', 'provider' => Fx::GUARD]]]);
    Fx::declareReaders();
    config([ReaderGuard::CONFIG => 'a.b']);
    Fx::forget();

    expect(config('auth.guards.a.b.provider'))->toBe(Fx::GUARD)
        ->and(Fx::guard()->fault())->toBe(ReaderGuardFault::UnknownGuard);
});

it('refuses a guard that names no provider', function (): void {
    config(['auth.guards.bare' => ['driver' => 'session'], ReaderGuard::CONFIG => 'bare']);
    Fx::forget();

    expect(Fx::guard()->fault())->toBe(ReaderGuardFault::UnknownGuard);
});

it('refuses a guard whose driver Laravel cannot build, answering no rather than throwing', function (Closure $driver): void {
    $owner = ownerWithAHeldRow($this);
    Fx::declareReaders();
    $driver();
    Auth::forgetGuards();
    Fx::forget();

    expect(Fx::guard()->fault())->toBe(ReaderGuardFault::UnknownGuard)
        ->and(Fx::guard()->name())->toBeNull()
        ->and(Fx::guard()->signedIn())->toBeFalse()
        ->and(Fx::check()->holds('course.advanced-php'))->toBeFalse()
        ->and(refusedWith(fn () => Fx::writer()->grant((int) $owner->getKey(), 'course.advanced-php', 'test.order:2', null))->reason)
        ->toBe(EntitlementRefusal::NoReaderGuard);
})->with([
    'a driver nobody installed' => [fn () => config(['auth.guards.'.Fx::GUARD.'.driver' => 'jwt'])],
    'no driver at all' => [fn () => config(['auth.guards.'.Fx::GUARD => ['provider' => Fx::GUARD]])],
]);

it('refuses a panel\'s guard, though the panel\'s owner holds a row under their own id', function (): void {
    ownerWithAHeldRow($this);
    PanelTenancy::enter($this->site);
    config([ReaderGuard::CONFIG => 'web']);
    Fx::forget();

    expect(Fx::guard()->fault())->toBe(ReaderGuardFault::PanelGuard)
        ->and(Fx::guard()->current())->toBeNull()
        ->and(Fx::check()->holds('course.advanced-php'))->toBeFalse();
});

it('refuses a guard whose model is a panel user', function (): void {
    $owner = ownerWithAHeldRow($this);
    Fx::declareReaders(TestUser::class);
    Fx::signIn($owner);

    expect(Fx::guard()->fault())->toBe(ReaderGuardFault::PanelShaped)
        ->and(Fx::guard()->model())->toBeNull()
        ->and(Fx::check()->holds('course.advanced-php'))->toBeFalse()
        // Whoever is on the declared guard is the request's own reader, usable model or not.
        ->and(refusedWith(fn () => Fx::writer()->grant((int) $owner->getKey(), 'course.advanced-php', 'test.order:2', null))->reason)
        ->toBe(EntitlementRefusal::ReaderActing);

    Auth::guard(Fx::GUARD)->logout();
    Fx::forget();

    expect(refusedWith(fn () => Fx::writer()->grant((int) $owner->getKey(), 'course.advanced-php', 'test.order:2', null))->reason)
        ->toBe(EntitlementRefusal::NoReaderGuard);
});

it('refuses a model that declares the org scope without registering it, or declares none', function (string $model): void {
    $reader = Fx::reader();
    Fx::plant($this->site, (string) $reader->getKey(), 'course.advanced-php');
    Fx::declareReaders($model);
    Fx::signIn((new $model)->forceFill($reader->getAttributes()));

    expect(Fx::guard()->fault())->toBe(ReaderGuardFault::NotOrgScoped)
        ->and(Fx::check()->holds('course.advanced-php'))->toBeFalse();
})->with([
    'the attribute alone' => AttributeOnlyReader::class,
    'nothing at all' => UnscopedUser::class,
]);

it('refuses a provider that loads no Eloquent model', function (Closure $provider): void {
    Fx::declareReaders();
    $provider();
    Fx::forget();

    expect(Fx::guard()->fault())->toBe(ReaderGuardFault::NotEloquent)
        ->and(Fx::check()->holds('course.advanced-php'))->toBeFalse();
})->with([
    'the database driver' => [fn () => config(['auth.providers.'.Fx::GUARD => ['driver' => 'database', 'table' => 'test_readers']])],
    'a driver nobody defined' => [fn () => config(['auth.providers.'.Fx::GUARD => ['driver' => 'nope']])],
    'a provider not configured' => [fn () => config(['auth.guards.'.Fx::GUARD.'.provider' => 'missing'])],
    'a model that is no model' => [fn () => config(['auth.providers.'.Fx::GUARD.'.model' => stdClass::class])],
]);

it('answers no with nobody signed in on the reader guard, though an owner whose id holds a row is on web', function (): void {
    Fx::declareReaders();
    $owner = ownerWithAHeldRow($this);
    DB::table('test_readers')->insert(['id' => $owner->getKey(), 'org_id' => $this->org->getKey(), 'email' => 'reader@example.test']);

    expect(Fx::guard()->fault())->toBeNull()
        ->and(Auth::guard('web')->user())->toBe($owner)
        ->and(Fx::guard()->current())->toBeNull()
        ->and(Fx::check()->holds('course.advanced-php'))->toBeFalse();
});

it('answers no without a site in context, whoever is signed in', function (): void {
    Fx::declareReaders();
    $reader = Fx::reader();
    Fx::plant($this->site, (string) $reader->getKey(), 'course.advanced-php');
    Fx::signIn($reader);

    expect(Fx::guard()->current())->toBe((string) $reader->getKey());

    // The org alone, then nothing at all.
    app(Context::class)->setSite(null)->setOrg($this->org);

    expect(app(Context::class)->orgId())->toBe($this->org->getKey())
        ->and(Fx::guard()->current())->toBeNull()
        ->and(Fx::check()->holds('course.advanced-php'))->toBeFalse();

    app(Context::class)->forget();

    expect(Fx::guard()->current())->toBeNull()
        ->and(Fx::check()->holds('course.advanced-php'))->toBeFalse();
});

it('loads nobody when a reader\'s session reaches another org\'s site, and the org fence holds when a provider strips the scope', function (): void {
    Fx::declareReaders();
    $reader = Fx::reader();
    $guard = Auth::guard(Fx::GUARD);
    session()->put($guard->getName(), $reader->getKey());

    $other = Fx::org('other');
    $otherSite = Fx::site($other, 'main');
    Fx::plant($otherSite, (string) $reader->getKey(), 'course.advanced-php');
    Auth::forgetGuards();
    Fx::forget();

    // The ordinary provider: the reader's org scope, under the other org, finds nobody.
    expect(Fx::guard()->current())->toBeNull()
        ->and(Fx::check()->holds('course.advanced-php'))->toBeFalse();

    // A provider that strips the scope loads them anyway, and the row's own org_id is the fence.
    Fx::declareReaders(TestReader::class, ScopeStrippingReaderProvider::DRIVER);
    session()->put(Auth::guard(Fx::GUARD)->getName(), $reader->getKey());

    expect(Auth::guard(Fx::GUARD)->user())->toBeInstanceOf(TestReader::class)
        ->and(Fx::guard()->current())->toBeNull()
        ->and(Fx::check()->holds('course.advanced-php'))->toBeFalse();

    // And on the reader's own org's site, the same session is the reader.
    Fx::site($this->org, 'second');
    Auth::forgetGuards();
    Fx::forget();

    expect(Fx::guard()->current())->toBe((string) $reader->getKey());
});

it('normalises a reader\'s key to the model\'s key type, as printable ASCII of at most 255 bytes', function (string $model, int|string $id, ?string $key): void {
    Fx::declareReaders($model);

    expect(Fx::guard()->key($id))->toBe($key);
})->with([
    'int: an int' => [TestReader::class, 7, '7'],
    'int: its decimal' => [TestReader::class, '7', '7'],
    'int: zero-padded' => [TestReader::class, '007', null],
    'int: trailing letters' => [TestReader::class, '5abc', null],
    'int: overflow' => [TestReader::class, '99999999999999999999', null],
    'int: empty' => [TestReader::class, '', null],
    'string: a ULID' => [TestUlidReader::class, '01JABC5X7K9M2N4P6Q8R0S1T3V', '01JABC5X7K9M2N4P6Q8R0S1T3V'],
    'string: an SSO subject' => [TestUlidReader::class, 'auth0|abc123', 'auth0|abc123'],
    'string: an int' => [TestUlidReader::class, 7, '7'],
    'string: 255 bytes' => [TestUlidReader::class, str_repeat('a', 255), str_repeat('a', 255)],
    'string: 256 bytes' => [TestUlidReader::class, str_repeat('a', 256), null],
    'string: a trailing space' => [TestUlidReader::class, 'ab ', null],
    'string: a space' => [TestUlidReader::class, 'a b', null],
    'string: a line break' => [TestUlidReader::class, "ab\n", null],
    'string: a NUL' => [TestUlidReader::class, "ab\0", null],
    'string: non-ASCII' => [TestUlidReader::class, 'é', null],
    'string: empty' => [TestUlidReader::class, '', null],
]);

it('answers no for a signed-in reader whose key cannot be stored, and refuses a key the model cannot have', function (string $key): void {
    Fx::declareReaders(TestUlidReader::class);
    Fx::signIn((new TestUlidReader)->forceFill(['id' => $key, 'org_id' => $this->org->getKey(), 'email' => 'r@example.test']));

    expect(Fx::guard()->current())->toBeNull()
        ->and(Fx::check()->holds('course.advanced-php'))->toBeFalse();
})->with([
    '256 bytes' => str_repeat('a', 256),
    'a space' => 'a b',
]);

it('refuses to grant to a key an integer host cannot have', function (): void {
    Fx::declareReaders();
    Fx::reader();
    Fx::owner();

    expect(refusedWith(fn () => Fx::writer()->grant('007', 'course.advanced-php', 'test.order:1', null))->reason)
        ->toBe(EntitlementRefusal::NotAReader)
        ->and(Entitlement::query()->count())->toBe(0);
});

it('describes each fault in its own words', function (ReaderGuardFault $fault, string $sentence): void {
    expect($fault->sentence('readers', 'App\\Models\\Reader'))->toBe($sentence);
})->with([
    [ReaderGuardFault::NotDeclared, 'this installation declares no reader guard (kitsune.readers.guard)'],
    [ReaderGuardFault::UnknownGuard, 'the declared reader guard [readers] is not a guard with a provider and a driver Laravel can build in its auth configuration'],
    [ReaderGuardFault::PanelGuard, 'the declared reader guard [readers] is a panel\'s guard, and a reader is not a panel user'],
    [ReaderGuardFault::NotEloquent, 'the declared reader guard [readers] does not load an Eloquent model'],
    [ReaderGuardFault::PanelShaped, 'the declared reader guard\'s model [App\\Models\\Reader] is scoped through membership, as a panel user is'],
    [ReaderGuardFault::NotOrgScoped, 'the declared reader guard\'s model [App\\Models\\Reader] is not scoped to an organisation (#[OrgScoped] with EnforcesScope)'],
]);

it('is usable once a host declares a guard as ADR-037 describes it', function (): void {
    Fx::declareReaders();

    expect(Fx::guard()->fault())->toBeNull()
        ->and(Fx::guard()->faultSentence())->toBeNull()
        ->and(Fx::guard()->name())->toBe(Fx::GUARD)
        ->and(Fx::guard()->model())->toBe(TestReader::class);
});

/**
 * ⚠️ NEVER ANOTHER GUARD. On a public route the default guard, `currentUser()` and the `users` provider all answer with
 * the STAFF user from the shared session, so a fallback to any of them reads staff as readers.
 */
it('asks nobody but the declared guard', function (string $file): void {
    $code = '';

    foreach (token_get_all((string) file_get_contents(dirname(__DIR__, 3).'/packages/core/src/'.$file)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    foreach (['currentUser(', 'auth()->', 'Auth::user(', 'Auth::guard()', 'auth.defaults', 'auth.providers.users', 'Auth::id(', 'Auth::check('] as $fallback) {
        expect(str_contains($code, $fallback))->toBeFalse("{$file} reaches for {$fallback}");
    }
})->with([
    'Auth/ReaderGuard.php',
    'Entitlements/EntitlementCheck.php',
]);
