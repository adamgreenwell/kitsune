<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Credentials\CredentialCipher;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Fx;

/*
 * Encrypted at rest, under a key of its own — ADR-040, asked of the column as the database holds it.
 */

beforeEach(function (): void {
    Fx::boot();
    $this->org = Fx::org('acme');
    Fx::member();
});

afterEach(fn () => Fx::tearDown());

it('stores no piece of the value in the column', function (): void {
    $value = Fx::value('', 64);

    Fx::writer()->set(Fx::SHARED, null, $value);
    $stored = (string) DB::table('credentials')->value('ciphertext');

    for ($at = 0; $at + 8 <= strlen($value); $at++) {
        expect(str_contains($stored, substr($value, $at, 8)))->toBeFalse("the column holds the value's characters from {$at}");
    }

    expect(str_contains($stored, Fx::SHARED))->toBeFalse()
        ->and(str_contains($stored, CredentialCipher::ENVELOPE))->toBeFalse();
});

it('cannot be opened with the application key itself, which is a different key', function (): void {
    Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));

    expect(fn () => Crypt::decryptString((string) DB::table('credentials')->value('ciphertext')))->toThrow(DecryptException::class);
});

it('opens to the envelope naming its org, credential and mode under the derived key', function (): void {
    $value = Fx::value('fx_live_');
    Fx::writer()->set(Fx::PAYMENT, CredentialMode::Live, $value);

    $raw = (string) config('app.key');
    $key = hash_hkdf('sha256', base64_decode(substr($raw, 7), true), 32, CredentialCipher::INFO);
    $plain = (new Encrypter($key, 'aes-256-gcm'))->decryptString((string) DB::table('credentials')->value('ciphertext'));

    expect($plain)->toBe(implode("\n", [CredentialCipher::ENVELOPE, (string) $this->org->getKey(), Fx::PAYMENT, 'live', $value]));
});

it('seals the same value differently every time, under the current key\'s id', function (): void {
    $value = Fx::value('', 40);

    Fx::writer()->set(Fx::SHARED, null, $value);
    $first = (string) DB::table('credentials')->value('ciphertext');
    Fx::writer()->set(Fx::SHARED, null, $value);

    expect((string) DB::table('credentials')->value('ciphertext'))->not->toBe($first)
        ->and(DB::table('credentials')->value('key_id'))->toBe(app(CredentialCipher::class)->currentKeyId())
        ->and(strlen((string) app(CredentialCipher::class)->currentKeyId()))->toBe(16);
});

it('names the key by a tag that depends on the key alone, never on a value', function (): void {
    Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));
    $first = DB::table('credentials')->value('key_id');
    Fx::writer()->set(Fx::SHARED, null, Fx::value('', 200));

    expect(DB::table('credentials')->value('key_id'))->toBe($first);

    config(['app.key' => Fx::appKey()]);
    Fx::forget();

    expect(app(CredentialCipher::class)->currentKeyId())->not->toBe($first);
});
