<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialSlot;
use Kitsune\Core\Credentials\CredentialSlots;
use Kitsune\Core\Credentials\CredentialStatus;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Http\Controllers\CredentialSetController;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Credential;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Fx;
use Kitsune\Core\Tests\Fixtures\PanelTenancy;

/*
 * The one path a credential's value takes into the admin — ADR-040, its admin half.
 *
 * ⚠️ EVERY CASE ENDS WITH THE SAME CHECK: the value is in no request bag, no superglobal, no session key, no response
 * header and no response body, and nothing was flashed. The superglobals are filled first, as PHP-FPM fills them, so
 * the controller's own unset is what empties them.
 *
 * ⚠️ AND THE TOKEN IS THE CONTROLLER'S CHECK HERE: the host's middleware is skipped under unit tests, which is one of
 * the reasons the controller compares it itself.
 */

beforeEach(function (): void {
    Fx::boot();
    $this->org = Fx::org('acme');
    $this->site = Site::create(['handle' => 'main', 'slug' => 'acme-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);
    $this->owner = Fx::member(email: 'owner@acme.test');
    PanelTenancy::enter($this->site);

    Route::get('/test-credentials/{tenant:slug}', static fn (): string => '')->name('filament.admin.pages.credentials');
    Route::post('/test-credentials/{tenant:slug}/set', CredentialSetController::class)
        ->middleware(StartSession::class)
        ->name('filament.admin.credentials.set');
    app('router')->getRoutes()->refreshNameLookups();

    $this->token = 'tok-'.bin2hex(random_bytes(8));
    // As a browser's form would send it, with PHP's superglobals filled as PHP-FPM fills them.
    $this->post = function (array $form, string $query = '') {
        $_POST = $form;
        parse_str(ltrim($query, '?'), $_GET);
        $_REQUEST = $_POST + $_GET;

        return $this->withSession(['_token' => $this->token])
            ->post('/test-credentials/acme-main/set'.$query, ['_token' => $this->token, ...$form]);
    };
});

afterEach(function (): void {
    $_POST = $_GET = $_REQUEST = [];
    Fx::tearDown();
});

/** Nothing of the value anywhere the request left it. */
function credentialClean(TestResponse $response, string $value): void
{
    $pieces = [$value, substr($value, 0, 12), substr($value, -8)];
    $places = [
        'the request' => json_encode(request()->all()),
        'the request\'s query' => json_encode(request()->query->all()),
        '$_POST' => json_encode($_POST),
        '$_GET' => json_encode($_GET),
        '$_REQUEST' => json_encode($_REQUEST),
        'the session' => serialize(session()->all()),
        'the headers' => json_encode($response->headers->all()),
        'the body' => (string) $response->getContent(),
    ];

    foreach ($places as $where => $haystack) {
        foreach ($pieces as $piece) {
            expect(str_contains((string) $haystack, $piece))->toBeFalse("{$where} holds a piece of the value");
        }
    }

    expect(session('_old_input'))->toBeNull()
        ->and(session('errors'))->toBeNull();
}

/** @return list<array<string, mixed>> */
function credentialSetNotices(): array
{
    return array_values((array) session('filament.notifications', []));
}

function credentialRows(): int
{
    return DB::table('credentials')->whereNotNull('ciphertext')->count();
}

it('stores a value for an owner, answers 303 to the page, and says so', function (): void {
    $value = Fx::value('', 40);
    $response = ($this->post)(['slot' => Fx::SHARED, 'password' => $value]);

    $response->assertStatus(303);

    expect($response->headers->get('Location'))->toEndWith('/test-credentials/acme-main')
        ->and(Fx::states()->of(Fx::SHARED)->status)->toBe(CredentialStatus::Set)
        ->and(Fx::reader()->secret(Fx::SHARED)->reveal())->toBe($value)
        ->and(AuditLog::query()->where('action', CredentialWriter::SET)->count())->toBe(1)
        ->and(credentialSetNotices()[0]['title'])->toBe('Saved: Fixture shared secret');

    credentialClean($response, $value);

    $again = Fx::value('', 40);
    $response = ($this->post)(['slot' => Fx::SHARED, 'password' => $again]);

    expect(AuditLog::query()->where('action', CredentialWriter::REPLACED)->count())->toBe(1)
        ->and(collect(credentialSetNotices())->pluck('title')->last())->toBe('Replaced: Fixture shared secret');

    credentialClean($response, $again);
});

it('refuses a missing, wrong, header-only or query-only token with 419, storing nothing', function (string $case): void {
    $value = Fx::value('', 40);
    $form = ['slot' => Fx::SHARED, 'password' => $value];
    $_POST = $_REQUEST = $form;
    $request = $this->withSession(['_token' => $this->token]);

    $response = match ($case) {
        'none' => $request->post('/test-credentials/acme-main/set', $form),
        'wrong' => $request->post('/test-credentials/acme-main/set', ['_token' => 'not-the-token', ...$form]),
        'header' => $request->withHeaders(['X-CSRF-TOKEN' => $this->token])->post('/test-credentials/acme-main/set', $form),
        'query' => $request->post('/test-credentials/acme-main/set?_token='.$this->token, $form),
    };

    $response->assertStatus(419);

    expect(credentialRows())->toBe(0)
        ->and(credentialSetNotices())->toBe([]);

    credentialClean($response, $value);
})->with(['none', 'wrong', 'header', 'query']);

it('refuses a member who is not an owner with 403, storing nothing', function (): void {
    Fx::member(owner: false, email: 'member@acme.test');
    $value = Fx::value('', 40);

    $response = ($this->post)(['slot' => Fx::SHARED, 'password' => $value]);

    $response->assertStatus(403);
    expect(credentialRows())->toBe(0);
    credentialClean($response, $value);
});

it('refuses nobody signed in with 403 — the writer would have stored it as the system\'s', function (): void {
    Auth::guard('web')->logout();
    Permissions::forget();
    $value = Fx::value('', 40);

    $response = ($this->post)(['slot' => Fx::SHARED, 'password' => $value]);

    $response->assertStatus(403);
    expect(DB::table('credentials')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'like', 'credential.%')->count())->toBe(0);
    credentialClean($response, $value);
});

it('refuses an owner of another org with this org in context', function (): void {
    $other = Org::create(['slug' => 'other', 'name' => 'Other']);
    app(Context::class)->setOrg($other);
    Site::create(['handle' => 'main', 'slug' => 'other-main', 'name' => 'Main', 'locale' => 'en']);
    $rival = Fx::member(email: 'owner@other.test');
    PanelTenancy::moveTo($this->site);
    Auth::guard('web')->setUser($rival);
    Permissions::forget();
    $value = Fx::value('', 40);

    $response = ($this->post)(['slot' => Fx::SHARED, 'password' => $value]);

    $response->assertStatus(403);
    expect(credentialRows())->toBe(0);
});

it('answers 404 to an undeclared credential or a mode that does not fit, naming neither', function (array $form): void {
    $value = Fx::value('fx_live_');
    $response = ($this->post)([...$form, 'password' => $value]);

    $response->assertStatus(404);
    expect(DB::table('credentials')->count())->toBe(0);

    foreach ($form as $field) {
        if (is_string($field) && $field !== '') {
            expect(str_contains((string) $response->getContent(), $field))->toBeFalse();
        }
    }

    credentialClean($response, $value);
})->with([
    'undeclared' => [['slot' => 'fx.nowhere']],
    'a value where the name goes' => [['slot' => 'fx_live_'.str_repeat('Q', 40)]],
    'kept per mode, with no mode' => [['slot' => Fx::PAYMENT]],
    'kept once, with a mode' => [['slot' => Fx::SHARED, 'mode' => 'test']],
    'a mode that is not one' => [['slot' => Fx::PAYMENT, 'mode' => 'production']],
    'an array for a name' => [['slot' => [Fx::SHARED]]],
]);

it('refuses a value that arrived in the address and calls it exposed, before asking anything else', function (string $slot): void {
    $value = Fx::value('', 40);
    $response = ($this->post)(['slot' => $slot], '?password='.$value);

    $response->assertStatus(303);

    expect(credentialRows())->toBe(0)
        ->and(credentialSetNotices()[0]['title'])->toBe('Not saved')
        ->and((string) credentialSetNotices()[0]['body'])->toContain('Treat it as exposed');

    credentialClean($response, $value);
})->with([
    'a declared credential' => [Fx::SHARED],
    'an undeclared one' => ['fx.nowhere'],
]);

it('trims surrounding ASCII whitespace, and leaves the rest to the store', function (): void {
    $value = Fx::value('', 40);

    ($this->post)(['slot' => Fx::SHARED, 'password' => "  \t{$value}\r\n"]);

    expect(Fx::reader()->secret(Fx::SHARED)->reveal())->toBe($value);

    $inner = substr($value, 0, 20)."\u{00A0}".substr($value, 20);
    $response = ($this->post)(['slot' => Fx::SHARED, 'password' => $inner]);

    expect((string) collect(credentialSetNotices())->last()['body'])->toContain('was not saved')
        ->and(Fx::reader()->secret(Fx::SHARED)->reveal())->toBe($value);

    credentialClean($response, $inner);
});

it('shows an empty, absent or array value as the store\'s empty refusal', function (array $form): void {
    $response = ($this->post)(['slot' => Fx::SHARED, ...$form]);

    $response->assertStatus(303);

    expect(credentialRows())->toBe(0)
        ->and(credentialSetNotices()[0]['title'])->toBe('Not saved: Fixture shared secret')
        ->and((string) credentialSetNotices()[0]['body'])->toBe('Fixture shared secret was not saved: no value was given. To take it away, remove it instead. Nothing was written.');
})->with([
    'empty' => [['password' => '']],
    'absent' => [[]],
    'an array' => [['password' => ['x'.str_repeat('y', 40)]]],
]);

it('refuses a test key for a live org\'s live line in the store\'s own words, keeping the live value (ADR-040)', function (): void {
    Fx::writer()->switchTo(CredentialMode::Live);
    Fx::writer()->set(Fx::PAYMENT, CredentialMode::Live, $live = Fx::value('fx_live_'));
    $before = AuditLog::query()->count();
    $test = Fx::value('fx_test_');

    $response = ($this->post)(['slot' => Fx::PAYMENT, 'mode' => 'live', 'password' => $test]);

    $response->assertStatus(303);
    $notice = credentialSetNotices()[0];

    expect($notice['title'])->toBe('Not saved: Fixture payment key (live mode)')
        ->and($notice['status'])->toBe('danger')
        ->and($notice['duration'])->toBe('persistent')
        ->and((string) $notice['body'])->toBe('Fixture payment key was not saved for live mode: it is a test-mode key (it begins fx_test_). A test key in live mode takes no money while appearing to (ADR-040). Nothing was written.')
        ->and(Fx::reader()->secret(Fx::PAYMENT)->reveal())->toBe($live)
        ->and(AuditLog::query()->count())->toBe($before);

    credentialClean($response, $test);
});

it('escapes what a module declares in every notice it sends', function (): void {
    app(CredentialSlots::class)->register(new CredentialSlot('fx.marked-up', '<b>Marked</b>', 'Help', false, minLength: 32));

    ($this->post)(['slot' => 'fx.marked-up', 'password' => '']);
    ($this->post)(['slot' => 'fx.marked-up', 'password' => Fx::value('', 40)]);

    $titles = array_map(static fn (array $notice): string => (string) $notice['title'], credentialSetNotices());

    expect($titles)->toBe(['Not saved: &lt;b&gt;Marked&lt;/b&gt;', 'Saved: &lt;b&gt;Marked&lt;/b&gt;'])
        ->and((string) credentialSetNotices()[0]['body'])->toContain('&lt;b&gt;Marked&lt;/b&gt; was not saved');
});

it('takes the line the owner pasted into, where nothing in the value says which', function (): void {
    $value = Fx::value('fxhook_', 30);

    ($this->post)(['slot' => Fx::HOOK, 'mode' => 'live', 'password' => $value]);

    expect(Fx::states()->of(Fx::HOOK, CredentialMode::Live)->status)->toBe(CredentialStatus::Set)
        ->and(Fx::states()->of(Fx::HOOK, CredentialMode::Test)->status)->toBe(CredentialStatus::NotSet);
});

it('has emptied the request before anything else can throw', function (): void {
    $value = Fx::value('', 40);
    $seen = null;
    Credential::saving(static function () use (&$seen): void {
        $seen = json_encode(request()->all()).json_encode($_POST).json_encode($_REQUEST);

        throw new RuntimeException('a listener failed');
    });

    try {
        ($this->post)(['slot' => Fx::SHARED, 'password' => $value]);
    } finally {
        Credential::flushEventListeners();
        Credential::clearBootedModels();
    }

    expect($seen)->not->toBeNull()
        ->and(str_contains((string) $seen, $value))->toBeFalse();
});
