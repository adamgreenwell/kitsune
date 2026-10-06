<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Credentials\CredentialRefusal;
use Kitsune\Core\Credentials\CredentialRefused;
use Kitsune\Core\Credentials\CredentialStatus;
use Kitsune\Core\Credentials\CredentialUnavailability;
use Kitsune\Core\Credentials\CredentialUnavailable;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Fx;

/*
 * The app key: rotated through APP_PREVIOUS_KEYS, lost, or never set — ADR-040, as built (§7 of its amendment).
 */

beforeEach(function (): void {
    Fx::boot();
    $this->org = Fx::org('acme');
    Fx::member();

    $this->k1 = (string) config('app.key');
    Fx::writer()->set(Fx::SHARED, null, $this->value = Fx::value('', 40));
});

afterEach(fn () => Fx::tearDown());

function credentialRekey(string $current, array $previous = []): void
{
    config(['app.key' => $current, 'app.previous_keys' => $previous]);
    Fx::forget();
}

it('reads a value sealed under a previous key, and says so without decrypting', function (): void {
    credentialRekey(Fx::appKey(), [$this->k1]);

    expect(Fx::states()->of(Fx::SHARED)->status)->toBe(CredentialStatus::SetUnderPreviousKey)
        ->and(Fx::reader()->secret(Fx::SHARED)->reveal())->toBe($this->value);
});

it('re-encrypts a replaced value under the current key', function (): void {
    credentialRekey(Fx::appKey(), [$this->k1]);

    Fx::writer()->set(Fx::SHARED, null, $replaced = Fx::value('', 40));

    expect(Fx::states()->of(Fx::SHARED)->status)->toBe(CredentialStatus::Set)
        ->and(Fx::reader()->secret(Fx::SHARED)->reveal())->toBe($replaced);
});

it('reads as unreadable once its key is gone, refusing in its own words and chaining nothing', function (): void {
    $ciphertext = (string) DB::table('credentials')->value('ciphertext');
    credentialRekey(Fx::appKey());

    expect(Fx::states()->of(Fx::SHARED)->status)->toBe(CredentialStatus::Unreadable);

    try {
        Fx::reader()->secret(Fx::SHARED);
        $this->fail('a value sealed under a lost key was read');
    } catch (CredentialUnavailable $unavailable) {
        expect($unavailable->reason)->toBe(CredentialUnavailability::Unreadable)
            ->and($unavailable->getPrevious())->toBeNull()
            ->and($unavailable->getMessage())->toBe("Credential [fx.shared-secret] for organisation acme cannot be decrypted with this installation's APP_KEY or APP_PREVIOUS_KEYS: it was stored under a key this installation no longer has, or what is stored there is damaged. An owner must set it again.")
            ->and(str_contains($unavailable->getMessage(), substr($ciphertext, 0, 16)))->toBeFalse();
    }
});

it('refuses a value damaged under the current key as unreadable, though its tag still reads as set', function (): void {
    // One bit of the sealed bytes flipped, below Eloquent: the key id is untouched, so only opening it can tell.
    $payload = json_decode((string) base64_decode((string) DB::table('credentials')->value('ciphertext'), true), true);
    $bytes = (string) base64_decode($payload['value'], true);
    $bytes[0] = chr(ord($bytes[0]) ^ 1);
    $payload['value'] = base64_encode($bytes);
    DB::table('credentials')->update(['ciphertext' => base64_encode((string) json_encode($payload))]);

    expect(Fx::states()->of(Fx::SHARED)->status)->toBe(CredentialStatus::Set);

    try {
        Fx::reader()->secret(Fx::SHARED);
        $this->fail('a damaged value was read');
    } catch (CredentialUnavailable $unavailable) {
        expect($unavailable->reason)->toBe(CredentialUnavailability::Unreadable)
            ->and($unavailable->getPrevious())->toBeNull()
            ->and($unavailable->getMessage())->toContain('or what is stored there is damaged');
    }
});

it('stores nothing when there is no app key, and says who must set one', function (): void {
    credentialRekey('');

    try {
        Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));
        $this->fail('a value was stored with no app key');
    } catch (CredentialRefused $refused) {
        expect($refused->reason)->toBe(CredentialRefusal::NoAppKey)
            ->and($refused->getMessage())->toBe('Fixture shared secret was not saved: this installation has no APP_KEY, so nothing can be stored encrypted. Whoever runs the installation must set one. Nothing was written.');
    }

    credentialRekey($this->k1);

    expect(Fx::reader()->secret(Fx::SHARED)->reveal())->toBe($this->value);
});

it('tells set, removed and never set apart without decrypting', function (): void {
    expect(Fx::states()->of(Fx::SHARED)->status)->toBe(CredentialStatus::Set)
        ->and(Fx::states()->of(Fx::PAYMENT)->status)->toBe(CredentialStatus::NotSet);

    Fx::writer()->remove(Fx::SHARED, null);

    expect(Fx::states()->of(Fx::SHARED)->status)->toBe(CredentialStatus::Removed);
});
