<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\ReaderConsentEvent;
use Kitsune\Core\Readers\ConsentPurpose;
use Kitsune\Core\Readers\GlobalPrivacyControl;
use Kitsune\Core\Readers\ReaderConsents;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture;
use Kitsune\Core\Tests\Fixtures\FoldingAccount;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;

beforeEach(function (): void {
    $this->world = ReaderFixture::world();
    ReaderFixture::mode($this->world['golfdom'], 'sign-in');
    ReaderFixture::mode($this->world['rival'], 'sign-in');
    $site = $this->world['sites']['golfdom'];
    $site->settings = [
        'reader_consent_profiling' => ['notice_version' => '2026-11', 'retention_days' => 30],
        'reader_consent_support_widget' => ['notice_version' => '2026-11', 'retention_days' => 7],
    ];
    $site->save();
    $this->reader = ReaderFixture::reader($this->world['golfdom'], 'reader-consent@kitsune.test');
    ReaderFixture::signIn($this->reader);
});

it('records each purpose from an explicit form and withdraws it on the next request', function (): void {
    $path = '/golfdom/account/privacy';
    $this->readerGet('/golfdom/account')->assertOk()->assertSee($path);
    $this->readerGet($path)->assertOk()->assertSee('These choices apply only to Golfdom.')
        ->assertHeader('Cache-Control', 'no-store, private')->assertSee('name="notice" value="2026-11"', false);

    $grant = ['purpose' => 'profiling', 'decision' => 'grant', 'notice' => '2026-11'];
    $this->readerPost($path, $grant)->assertStatus(303)->assertRedirect($path);
    $this->readerPost($path, $grant)->assertStatus(303);
    $this->readerGet($path)->assertOk()->assertSee('Withdraw permission')->assertSee('Allowed');
    $this->readerGet('/golfdom-fr/account/privacy')->assertOk()->assertDontSee('Allowed');
    $this->readerGet('/rival/account/privacy')->assertRedirect('/rival/account/sign-in');
    $this->readerPost($path, ['purpose' => 'profiling', 'decision' => 'withdraw'])->assertStatus(303);
    $this->readerGet($path)->assertOk()->assertDontSee('Allowed');

    expect(DB::table('reader_consent_events')->orderBy('id')->pluck('granted')->map(fn ($value): bool => (bool) $value)->all())
        ->toBe([true, false]);
});

it('refuses forged or stale choices and missing CSRF tokens', function (): void {
    $payload = ['purpose' => 'profiling', 'decision' => 'grant', 'notice' => '2026-11'];
    $this->readerPost('/golfdom-fr/account/privacy', $payload)->assertStatus(409);
    $this->readerPost('/golfdom/account/privacy', ['notice' => 'old'] + $payload)->assertStatus(409);
    $this->readerPost('/golfdom/account/privacy', ['purpose' => 'undefined'] + $payload)->assertStatus(422);
    $this->readerPost('/golfdom/account/privacy', $payload, token: false)->assertStatus(419);

    expect(DB::table('reader_consent_events')->exists())->toBeFalse();
});

it('honors GPC alongside an invalid duplicate and keeps the saved state off the shared web', function (): void {
    $path = '/golfdom/account/privacy';
    $payload = ['purpose' => 'support_widget', 'decision' => 'grant', 'notice' => '2026-11'];
    $this->readerPost($path, $payload)->assertStatus(303);
    $this->readerGet($path, ['Sec-GPC' => '0, 1'])->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('Global Privacy Control is on')->assertDontSee('Allowed')->assertSee('Withdraw permission');
    $this->readerPost($path, $payload, headers: ['Sec-GPC' => '1'])->assertStatus(409);
    $this->readerGet($path)->assertOk()->assertSee('Allowed');

    expect(DB::table('reader_consent_events')->count())->toBe(1);
});

it('prevents pruning a newer short-lived grant from reactivating an earlier grant', function (): void {
    $path = '/golfdom/account/privacy';
    $this->freezeTime();
    $this->readerPost($path, ['purpose' => 'profiling', 'decision' => 'grant', 'notice' => '2026-11'])->assertStatus(303);
    $site = $this->world['sites']['golfdom'];
    $site->settings = ['reader_consent_profiling' => ['notice_version' => '2026-12', 'retention_days' => 1]];
    $site->save();
    $this->readerPost($path, ['purpose' => 'profiling', 'decision' => 'grant', 'notice' => '2026-12'])->assertStatus(303);
    $this->travel(2)->days();
    app(Context::class)->forget()->setOrg($this->world['golfdom']);
    expect(app(ReaderConsents::class)->sweep($this->world['golfdom']->id))->toBe(0);
    expect(ReaderConsentEvent::query()->count())->toBe(2);
    ReaderFixture::forget();
    $site->settings = ['reader_consent_profiling' => ['notice_version' => '2026-11', 'retention_days' => 30]];
    $site->save();
    $this->readerGet($path)->assertOk()->assertDontSee('Allowed');
});

it('exports scoped events and erases all of the subjects Sites without touching a matching foreign key', function (): void {
    $site = $this->world['sites']['golfdom-fr'];
    $site->settings = ['reader_consent_profiling' => ['notice_version' => 'v1', 'retention_days' => 30]];
    $site->save();
    $readerKey = (string) $this->reader->getKey();
    $this->readerPost('/golfdom-fr/account/privacy', ['purpose' => 'profiling', 'decision' => 'grant', 'notice' => 'v1'])->assertStatus(303);
    $this->readerPost('/golfdom/account/privacy', ['purpose' => 'profiling', 'decision' => 'grant', 'notice' => '2026-11'])->assertStatus(303);
    $this->readerPost('/golfdom/account/privacy', ['purpose' => 'profiling', 'decision' => 'withdraw'])->assertStatus(303);
    $row = (array) DB::table('reader_consent_events')->first();
    unset($row['id']);
    DB::table('reader_consent_events')->insert([
        'org_id' => $this->world['rival']->id,
        'site_id' => $this->world['sites']['rival']->id,
    ] + $row);

    app('session.store')->flush();
    ReaderFixture::forget();
    $console = app(Kernel::class);
    $arguments = ['--org' => 'golfdom', '--reader' => $readerKey];
    $exit = $console->call('kitsune:readers', ['action' => 'export'] + $arguments);
    $output = $console->output();
    expect($exit)->toBe(0, $output);
    $export = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    expect(array_column($export['consents'], 'site'))->toBe([$site->id, $this->world['sites']['golfdom']->id, $this->world['sites']['golfdom']->id])
        ->and(array_column($export['consents'], 'granted'))->toBe([true, true, false]);
    expect($console->call('kitsune:readers', ['action' => 'erase', '--force' => true] + $arguments))->toBe(0, $console->output())
        ->and(DB::table('reader_consent_events')->pluck('org_id')->all())->toBe([$this->world['rival']->id]);
});

it('rolls erasure back if a host observer recreates consent custody', function (): void {
    $this->readerPost('/golfdom/account/privacy', ['purpose' => 'profiling', 'decision' => 'grant', 'notice' => '2026-11'])->assertStatus(303);
    $row = (array) DB::table('reader_consent_events')->first();
    $recreated = false;
    $this->reader::deleted(static function () use ($row, &$recreated): void {
        $recreated = DB::table('reader_consent_events')->insert($row);
    });

    app('session.store')->flush();
    ReaderFixture::forget();
    $console = app(Kernel::class);
    expect($console->call('kitsune:readers', ['action' => 'erase', '--org' => 'golfdom', '--reader' => (string) $this->reader->getKey(), '--force' => true]))
        ->toBe(1)
        ->and($recreated)->toBeTrue()
        ->and(DB::table('readers')->where('id', $this->reader->getKey())->exists())->toBeTrue()
        ->and(DB::table('reader_consent_events')->count())->toBe(1);
});

it('honors a valid field from separate GPC header values', function (array $values, bool $expected): void {
    $request = Request::create('/');
    $request->headers->set('Sec-GPC', $values);

    expect(GlobalPrivacyControl::requested($request))->toBe($expected);
})->with([
    [['0', '1'], true],
    [['true', '0'], false],
    [['01'], false],
    [['1, 0'], true],
]);

it('lets operators set and disable one Sites policy and explicitly sweep expired events', function (): void {
    $console = app(Kernel::class);
    $arguments = ['action' => 'consent', 'value' => 'profiling', '--org' => 'golfdom', '--site' => 'golfdom-fr'];
    expect($console->call('kitsune:readers', $arguments + ['--notice' => 'first']))->toBe(1)
        ->and($console->call('kitsune:readers', $arguments + ['--notice' => 'first', '--retention-days' => '1']))->toBe(0);
    $this->freezeTime();
    $this->readerPost('/golfdom-fr/account/privacy', ['purpose' => 'profiling', 'decision' => 'grant', 'notice' => 'first'])->assertStatus(303);
    $this->readerGet('/golfdom/account/privacy')->assertOk()->assertDontSee('Allowed');
    expect($console->call('kitsune:readers', $arguments + ['--disable' => true]))->toBe(0);
    $this->readerGet('/golfdom-fr/account/privacy')->assertOk()->assertDontSee('Allowed')->assertSee('Withdraw permission');

    $this->travel(2)->days();
    $sweep = ['action' => 'consent-sweep', '--org' => 'golfdom'];
    expect($console->call('kitsune:readers', $sweep))->toBe(1)
        ->and(DB::table('reader_consent_events')->count())->toBe(1)
        ->and($console->call('kitsune:readers', $sweep + ['--force' => true]))->toBe(0)
        ->and(DB::table('reader_consent_events')->count())->toBe(0);
});

it('uses the stored spelling of a case-folded host reader key for export and erasure', function (): void {
    app('session.store')->flush();
    ReaderFixture::forget();
    EntitlementFixture::declareReaders(FoldingAccount::class);
    app(Context::class)->setOrg($this->world['golfdom']);
    FoldingAccount::query()->forceCreate(['id' => 'abc', 'email' => 'folding@kitsune.test']);
    DB::table('reader_consent_events')->insert([
        'org_id' => $this->world['golfdom']->id,
        'site_id' => $this->world['sites']['golfdom']->id,
        'reader_key' => 'abc',
        'purpose' => 'profiling',
        'notice_version' => 'first',
        'granted' => true,
        'recorded_at' => '2026-11-26 00:00:00',
        'expires_at' => '2026-12-01 00:00:00',
        'retain_until' => '2026-12-01 00:00:00',
    ]);

    $console = app(Kernel::class);
    $arguments = ['--org' => 'golfdom', '--reader' => 'ABC'];
    expect($console->call('kitsune:readers', ['action' => 'export'] + $arguments))->toBe(0);
    $export = json_decode($console->output(), true, flags: JSON_THROW_ON_ERROR);
    expect($export['account']['reader'])->toBe('abc')->and($export['consents'])->toHaveCount(1)
        ->and($console->call('kitsune:readers', ['action' => 'erase', '--force' => true] + $arguments))->toBe(0)
        ->and(DB::table('reader_consent_events')->count())->toBe(0);
});

it('refuses an already loaded reader from another Org even without the privacy route middleware', function (): void {
    $site = $this->world['sites']['rival'];
    $site->settings = ['reader_consent_profiling' => ['notice_version' => 'first', 'retention_days' => 30]];
    $site->save();
    DB::table('reader_consent_events')->insert([
        'org_id' => $site->org_id,
        'site_id' => $site->id,
        'reader_key' => (string) $this->reader->getKey(),
        'purpose' => 'profiling',
        'notice_version' => 'first',
        'granted' => true,
        'recorded_at' => '2026-11-25 00:00:00',
        'expires_at' => '2030-01-01 00:00:00',
        'retain_until' => '2030-01-01 00:00:00',
    ]);
    app(Context::class)->forget()->setSite($site);
    Auth::guard(ReaderFixture::GUARD)->setUser($this->reader);

    expect(app(ReaderConsents::class)->allows(Request::create('/rival'), ConsentPurpose::Profiling))
        ->toBeFalse();
});
