<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialStatus;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Http\Controllers\CredentialModeController;
use Kitsune\Core\Http\Controllers\CredentialRemoveController;
use Kitsune\Core\Http\Controllers\CredentialSetController;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Fx;
use Kitsune\Core\Tests\Fixtures\PanelTenancy;

beforeEach(function (): void {
    Fx::boot();
    $this->org = Fx::org('native');
    $this->site = Site::create(['handle' => 'main', 'slug' => 'native-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);
    $this->owner = Fx::member(email: 'owner@native.test');
    $this->currentPassword = Fx::value('', 40);
    DB::table('users')->where('id', $this->owner->getKey())->update(['password' => Hash::make($this->currentPassword)]);
    PanelTenancy::enter($this->site);

    Route::get('/native-credentials/{tenant:slug}', static fn (): string => '')->name('filament.admin.pages.credentials');

    foreach (['set' => CredentialSetController::class, 'remove' => CredentialRemoveController::class, 'mode' => CredentialModeController::class] as $action => $controller) {
        Route::post('/native-credentials/{tenant:slug}/'.$action, $controller)
            ->middleware(StartSession::class)->name('filament.admin.credentials.'.$action);
    }

    app('router')->getRoutes()->refreshNameLookups();
    $this->token = 'tok-'.bin2hex(random_bytes(12));
    $this->postMutation = function (string $action, array $form, string $query = ''): TestResponse {
        $_POST = $form;
        parse_str(ltrim($query, '?'), $_GET);
        $_REQUEST = $_POST + $_GET;

        return $this->withSession(['_token' => $this->token])
            ->post('/native-credentials/native-main/'.$action.$query, ['_token' => $this->token, ...$form]);
    };
});

afterEach(function (): void {
    $_POST = $_GET = $_REQUEST = [];
    Fx::tearDown();
});

/** Check plaintext, raw content and the effective URL as well as the usual parsed bags and flashes. */
function nativeCredentialClean(TestResponse $response, string $secret): void
{
    $places = [
        json_encode(request()->all()), json_encode(request()->query->all()), request()->getContent(),
        request()->getRequestUri(), json_encode($_POST), json_encode($_GET), json_encode($_REQUEST),
        serialize(session()->all()), json_encode($response->headers->all()), (string) $response->getContent(),
    ];

    foreach ($places as $place) {
        foreach ([$secret, substr($secret, 0, 12), substr($secret, -12)] as $piece) {
            expect(str_contains((string) $place, $piece))->toBeFalse();
        }
    }

    expect(session('_old_input'))->toBeNull()->and(session('errors'))->toBeNull();
}

/** @return array<string, mixed> */
function nativeCredentialNotice(): array
{
    return (array) collect((array) session('filament.notifications', []))->last();
}

it('refuses missing, wrong and array fresh passwords on every live mutation', function (string $action, string $case): void {
    $value = Fx::value('fx_live_');

    if ($action === 'remove') {
        Fx::writer()->set(Fx::PAYMENT, CredentialMode::Live, $value, $this->currentPassword);
    }

    $before = DB::table('credentials')->get()->all();
    $password = match ($case) {
        'missing' => [],
        'wrong' => ['current_password' => Fx::value('', 40)],
        'array' => ['current_password' => [Fx::value('', 40)]],
    };
    $form = ['mode' => 'live', ...($action !== 'mode' ? ['slot' => Fx::PAYMENT] : []), ...($action === 'set' ? ['password' => $value] : []), ...$password];
    $response = ($this->postMutation)($action, $form);

    $response->assertStatus(303);
    expect(DB::table('credentials')->get()->all())->toEqual($before)
        ->and(Fx::states()->mode())->toBe(CredentialMode::Test)
        ->and(nativeCredentialNotice()['body'])->toContain('Fresh owner authentication');

    nativeCredentialClean($response, $value);

    if (isset($password['current_password'])) {
        nativeCredentialClean($response, is_array($password['current_password']) ? $password['current_password'][0] : $password['current_password']);
    }
})->with(['set', 'remove', 'mode'])->with(['missing', 'wrong', 'array']);

it('performs live native writes only with the current primary password and never saves that password', function (): void {
    $value = Fx::value('fx_live_');
    $response = ($this->postMutation)('set', ['slot' => Fx::PAYMENT, 'mode' => 'live', 'password' => $value, 'current_password' => $this->currentPassword]);
    $response->assertStatus(303);
    expect(Fx::states()->of(Fx::PAYMENT, CredentialMode::Live)->status)->toBe(CredentialStatus::Set);
    nativeCredentialClean($response, $value);
    nativeCredentialClean($response, $this->currentPassword);

    $response = ($this->postMutation)('mode', ['mode' => 'live', 'current_password' => $this->currentPassword]);
    expect(Fx::states()->mode())->toBe(CredentialMode::Live)->and(nativeCredentialNotice()['title'])->toBe('Now in live mode');
    nativeCredentialClean($response, $this->currentPassword);

    $response = ($this->postMutation)('remove', ['slot' => Fx::PAYMENT, 'mode' => 'live', 'current_password' => $this->currentPassword]);
    expect(Fx::states()->of(Fx::PAYMENT, CredentialMode::Live)->status)->toBe(CredentialStatus::Removed)
        ->and(nativeCredentialNotice()['title'])->toBe('Removed: Fixture payment key (live mode)');
    nativeCredentialClean($response, $this->currentPassword);
});

it('ignores an earlier session confirmation and stale guard password after the primary password changes', function (): void {
    $newPassword = Fx::value('', 40);
    $this->owner->setAttribute('password', Hash::make($this->currentPassword));
    DB::table('users')->where('id', $this->owner->getKey())->update(['password' => Hash::make($newPassword)]);
    $this->withSession(['auth.password_confirmed_at' => time()]);
    $value = Fx::value('fx_live_');

    $response = ($this->postMutation)('set', ['slot' => Fx::PAYMENT, 'mode' => 'live', 'password' => $value, 'current_password' => $this->currentPassword]);
    expect(Fx::states()->of(Fx::PAYMENT, CredentialMode::Live)->status)->toBe(CredentialStatus::NotSet)
        ->and(nativeCredentialNotice()['body'])->toContain('Fresh owner authentication');
    nativeCredentialClean($response, $this->currentPassword);

    $response = ($this->postMutation)('set', ['slot' => Fx::PAYMENT, 'mode' => 'live', 'password' => $value, 'current_password' => $newPassword]);
    expect(Fx::states()->of(Fx::PAYMENT, CredentialMode::Live)->status)->toBe(CredentialStatus::Set);
    nativeCredentialClean($response, $newPassword);
});

it('refuses a neutral mutation rendered before the organisation switched live', function (string $action): void {
    $original = Fx::value('', 40);
    Fx::writer()->set(Fx::SHARED, null, $original);
    Fx::writer()->switchTo(CredentialMode::Live, $this->currentPassword);
    $response = ($this->postMutation)($action, ['slot' => Fx::SHARED, ...($action === 'set' ? ['password' => Fx::value('', 40)] : [])]);

    $response->assertStatus(303);
    expect(Fx::reader()->secret(Fx::SHARED)->reveal())->toBe($original)
        ->and(nativeCredentialNotice()['body'])->toContain('Fresh owner authentication');
})->with(['set', 'remove']);

it('allows a test line and returning to test mode without a fresh password', function (): void {
    Fx::writer()->switchTo(CredentialMode::Live, $this->currentPassword);
    ($this->postMutation)('set', ['slot' => Fx::PAYMENT, 'mode' => 'test', 'password' => Fx::value('fx_test_')])->assertStatus(303);
    expect(Fx::states()->of(Fx::PAYMENT, CredentialMode::Test)->status)->toBe(CredentialStatus::Set);
    ($this->postMutation)('remove', ['slot' => Fx::PAYMENT, 'mode' => 'test'])->assertStatus(303);
    expect(Fx::states()->of(Fx::PAYMENT, CredentialMode::Test)->status)->toBe(CredentialStatus::Removed);
    ($this->postMutation)('mode', ['mode' => 'test'])->assertStatus(303);
    expect(Fx::states()->mode())->toBe(CredentialMode::Test);
});

it('rejects either sensitive field in a URL before inspecting a native mutation', function (string $action, string $field): void {
    $secret = Fx::value('', 40);
    $response = ($this->postMutation)($action, ['slot' => 'unknown', 'mode' => 'live', 'current_password' => $this->currentPassword], '?'.$field.'='.$secret);

    $response->assertStatus(303);
    expect(DB::table('credentials')->count())->toBe(0)
        ->and(Fx::states()->mode())->toBe(CredentialMode::Test)
        ->and(nativeCredentialNotice()['body'])->toContain('Treat it as exposed');
    nativeCredentialClean($response, $secret);
    nativeCredentialClean($response, $this->currentPassword);
})->with(['set', 'remove', 'mode'])->with(['password', 'current_password']);

it('checks native body CSRF on new handlers even when host middleware is absent', function (string $action, string $case): void {
    $form = ['slot' => Fx::PAYMENT, 'mode' => 'live', 'current_password' => $this->currentPassword];
    $_POST = $_REQUEST = $form;
    $pending = $this->withSession(['_token' => $this->token]);
    $path = '/native-credentials/native-main/'.$action;
    $response = match ($case) {
        'missing' => $pending->post($path, $form),
        'wrong' => $pending->post($path, ['_token' => 'wrong', ...$form]),
        'header' => $pending->withHeaders(['X-CSRF-TOKEN' => $this->token])->post($path, $form),
        'query' => $pending->post($path.'?_token='.$this->token, $form),
    };

    $response->assertStatus(419);
    nativeCredentialClean($response, $this->currentPassword);
})->with(['remove', 'mode'])->with(['missing', 'wrong', 'header', 'query']);

it('refuses anonymous and nonowner native mutations after scrubbing their passwords', function (string $action, bool $anonymous): void {
    if ($anonymous) {
        Auth::guard('web')->logout();
        Permissions::forget();
    } else {
        Fx::member(owner: false, email: 'member@native.test');
    }

    $response = ($this->postMutation)($action, ['slot' => Fx::PAYMENT, 'mode' => 'live', 'current_password' => $this->currentPassword]);
    $response->assertStatus(403);
    nativeCredentialClean($response, $this->currentPassword);
})->with(['remove', 'mode'])->with([true, false]);

it('empties parsed JSON and its cached raw body before refusing nonnative CSRF', function (): void {
    $response = $this->withSession(['_token' => $this->token])->postJson('/native-credentials/native-main/mode', [
        '_token' => $this->token, 'mode' => 'live', 'password' => Fx::value('', 40), 'current_password' => $this->currentPassword,
    ]);
    $response->assertStatus(419);
    nativeCredentialClean($response, $this->currentPassword);
});

it('resolves a failing writer only after the raw body and password have been removed', function (): void {
    $password = $this->currentPassword;
    $seen = null;
    app()->bind(CredentialWriter::class, function () use (&$seen, $password): never {
        $seen = [...request()->all(), ...$_POST, ...$_REQUEST];
        throw new RuntimeException('Do not echo '.$password);
    });

    $response = ($this->postMutation)('mode', ['mode' => 'live', 'current_password' => $password]);
    $response->assertStatus(303);
    expect($seen)->toBe([])->and(nativeCredentialNotice()['body'])->toContain('could not be completed');
    nativeCredentialClean($response, $password);
});

it('preserves a mounted front controller when clearing a sensitive URL and generating the native redirect', function (): void {
    $secret = Fx::value('', 40);
    $path = '/subdir/index.php/native-credentials/native-main/mode';
    $request = Request::create('https://example.test'.$path.'?current_password='.$secret, 'POST', [
        '_token' => $this->token, 'mode' => 'live', 'current_password' => $this->currentPassword,
    ], server: [
        'SCRIPT_NAME' => '/subdir/index.php',
        'SCRIPT_FILENAME' => '/var/www/subdir/index.php',
        'PHP_SELF' => $path,
    ]);
    $request->setLaravelSession(app('session')->driver());
    session()->put('_token', $this->token);
    app()->instance('request', $request);
    app('url')->setRequest($request);
    expect($request->getBaseUrl())->toBe('/subdir/index.php')
        ->and($request->root())->toBe('https://example.test/subdir/index.php');

    $response = app(CredentialModeController::class)($request);

    expect($response->getStatusCode())->toBe(303)
        ->and($response->headers->get('Location'))->toBe('https://example.test/subdir/index.php/native-credentials/native-main')
        ->and($request->getBaseUrl())->toBe('/subdir/index.php')
        ->and($request->root())->toBe('https://example.test/subdir/index.php')
        ->and($request->getRequestUri())->toBe($path)
        ->and($request->query->all())->toBe([])
        ->and($request->getContent())->toBe('')
        ->and($request->all())->toBe([])
        ->and(serialize(session()->all()))->not->toContain($secret, $this->currentPassword);
});

it('removes alternate raw URL aliases from the server bag and PHP globals', function (): void {
    $secret = Fx::value('', 40);
    $path = '/native-credentials/native-main/mode';
    $sensitive = $path.'?current_password='.$secret;
    $aliases = [
        'UNENCODED_URL' => $sensitive,
        'IIS_WasUrlRewritten' => '1',
        'ORIG_PATH_INFO' => $sensitive,
        'RAW_URI' => $sensitive,
        'HTTP_X_ORIGINAL_URL' => $sensitive,
        'HTTP_X_REWRITE_URL' => $sensitive,
        'HTTP_X_ORIGINAL_URI' => $sensitive,
        'REDIRECT_URL' => $sensitive,
        'REDIRECT_QUERY_STRING' => 'current_password='.$secret,
        'REDIRECT_REQUEST_URI' => $sensitive,
    ];
    $request = Request::create('https://example.test'.$sensitive, 'POST', [
        '_token' => $this->token, 'mode' => 'live', 'current_password' => $this->currentPassword,
    ], server: $aliases);
    $request->setLaravelSession(app('session')->driver());
    session()->put('_token', $this->token);
    app()->instance('request', $request);
    app('url')->setRequest($request);
    $_SERVER = [...$_SERVER, ...$aliases];

    try {
        $response = app(CredentialModeController::class)($request);

        expect($response->getStatusCode())->toBe(303)
            ->and($request->getRequestUri())->toBe($path)
            ->and(json_encode($request->server->all()))->not->toContain($secret)
            ->and(json_encode($_SERVER))->not->toContain($secret)
            ->and($response->headers->get('Location'))->toBe('https://example.test/native-credentials/native-main');
    } finally {
        foreach (array_keys($aliases) as $key) {
            unset($_SERVER[$key]);
        }
    }
});
