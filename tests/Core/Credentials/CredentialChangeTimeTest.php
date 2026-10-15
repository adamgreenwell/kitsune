<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Credentials\CredentialConsoleAuthority;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Fx;

beforeEach(function (): void {
    Fx::boot();
    Fx::org('explicit-credential-time');
    Auth::guard('web')->logout();
    Permissions::forget();
    // Deliberately far from the database server clock, and identical on consecutive writes.
    Carbon::setTestNow(Carbon::parse('2001-02-03 04:05:06', 'UTC'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2001-02-03 04:05:06', 'UTC'));
});

afterEach(function (): void {
    DB::connection()->disableQueryLog();
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
    Fx::tearDown();
});

/** A real guarded credential write under one exact operator capability. */
function credentialFrozenSeed(): string
{
    $value = Fx::value('', 40);
    CredentialConsoleAuthority::run(app(Context::class), 'set', Fx::SHARED, null, null,
        static fn (CredentialConsoleAuthority $console) => Fx::writer()->set(Fx::SHARED, null, $value, console: $console));

    return $value;
}

/**
 * MariaDB 10.6 may add implicit ON UPDATE to the first TIMESTAMP. This SQL assertion proves the portable
 * override even on SQLite, where omission would otherwise appear to work; real engine legs assert the same result.
 */
function credentialTimeWasExplicit(string $table): void
{
    $updates = array_values(array_filter(DB::connection()->getQueryLog(), static fn (array $query): bool => preg_match('/\Aupdate\s+[`"]?'.preg_quote($table, '/').'[`"]?\s+set\b/i', $query['query']) === 1));
    expect($updates)->toHaveCount(1);
    $set = preg_split('/\s+where\s+/i', $updates[0]['query'])[0];
    expect(preg_match('/[`"]?changed_at[`"]?\s*=\s*\?/i', $set))->toBe(1);
    expect(DB::table($table)->value('changed_at'))->toBe('2001-02-03 04:05:06');
}

it('keeps the intended time explicit when replacing or removing a credential within the same frozen second', function (string $operation): void {
    credentialFrozenSeed();
    DB::connection()->enableQueryLog();
    $action = $operation === 'replace' ? 'set' : 'remove';
    $value = Fx::value('', 40);
    CredentialConsoleAuthority::run(app(Context::class), $action, Fx::SHARED, null, null,
        static fn (CredentialConsoleAuthority $console) => $operation === 'replace'
            ? Fx::writer()->set(Fx::SHARED, null, $value, console: $console)
            : Fx::writer()->remove(Fx::SHARED, null, console: $console));
    credentialTimeWasExplicit('credentials');
    expect(DB::table('audit_log')->where('action', $operation === 'replace' ? CredentialWriter::REPLACED : CredentialWriter::REMOVED)->count())->toBe(1);
    if ($operation === 'replace') {
        expect(Fx::reader()->secret(Fx::SHARED)->reveal())->toBe($value);
    } else {
        expect(DB::table('credentials')->value('ciphertext'))->toBeNull()->and(DB::table('credentials')->value('key_id'))->toBeNull();
    }
})->with(['replace', 'remove']);

it('keeps the intended mode-change time explicit on consecutive frozen mode switches', function (): void {
    CredentialConsoleAuthority::run(app(Context::class), 'switch', null, CredentialMode::Live, null,
        static fn (CredentialConsoleAuthority $console) => Fx::writer()->switchTo(CredentialMode::Live, console: $console));
    DB::connection()->enableQueryLog();
    CredentialConsoleAuthority::run(app(Context::class), 'switch', null, CredentialMode::Test, null,
        static fn (CredentialConsoleAuthority $console) => Fx::writer()->switchTo(CredentialMode::Test, console: $console));
    credentialTimeWasExplicit('org_credential_modes');
    expect(DB::table('org_credential_modes')->value('mode'))->toBe('test')
        ->and(DB::table('audit_log')->where('action', CredentialWriter::MODE_TEST)->count())->toBe(1);
});

it('preserves an old credential change time explicitly while resealing under a new application key', function (): void {
    $value = credentialFrozenSeed();
    $before = DB::table('credentials')->sole();
    Carbon::setTestNow(Carbon::parse('2002-03-04 05:06:07', 'UTC'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2002-03-04 05:06:07', 'UTC'));
    config(['app.previous_keys' => [config('app.key')], 'app.key' => Fx::appKey()]);
    Fx::forget();
    DB::connection()->enableQueryLog();
    $changed = CredentialConsoleAuthority::run(app(Context::class), 'reencrypt', null, null, (int) $before->id,
        static fn (CredentialConsoleAuthority $console) => Fx::writer()->reencrypt((int) $before->id, $console));
    credentialTimeWasExplicit('credentials');
    $after = DB::table('credentials')->sole();
    expect($changed)->toBeTrue()->and(hash_equals($before->ciphertext, $after->ciphertext))->toBeFalse()
        ->and($after->changed_at)->toBe($before->changed_at)->and(Fx::reader()->secret(Fx::SHARED)->reveal())->toBe($value)
        ->and(DB::table('audit_log')->where('action', CredentialWriter::REENCRYPTED)->count())->toBe(1);
});
