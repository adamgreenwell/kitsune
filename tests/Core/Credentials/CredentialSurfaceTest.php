<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Console\CredentialsCommand;
use Kitsune\Core\Filament\Pages\Credentials;
use Kitsune\Core\Http\Controllers\CredentialSetController;
use Kitsune\Core\Models\Credential;
use Kitsune\Core\Models\OrgCredentialMode;

/*
 * The store's surface, and where a value can be opened — ADR-040, as built. Every symbol is `@internal`, so the v1.2
 * freeze inherits a list rather than a phrase, and CONTRIBUTING gains no exception.
 */

function credentialSourceFiles(string $under): array
{
    $root = dirname(__DIR__, 3).'/packages/core/src/'.$under;
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

it('marks every symbol it adds @internal', function (): void {
    $symbols = [Credential::class, OrgCredentialMode::class, CredentialsCommand::class, Credentials::class, CredentialSetController::class];

    foreach (credentialSourceFiles('Credentials') as $file) {
        $symbols[] = 'Kitsune\\Core\\Credentials\\'.basename($file, '.php');
    }

    // Not vacuous: the namespace holds the writer, the reader and the cipher.
    expect($symbols)->toContain('Kitsune\\Core\\Credentials\\CredentialWriter', 'Kitsune\\Core\\Credentials\\CredentialReader', 'Kitsune\\Core\\Credentials\\CredentialCipher');

    $public = array_values(array_filter($symbols, static fn (string $symbol): bool => ! str_contains((string) (new ReflectionClass($symbol))->getDocComment(), '@internal')));

    expect($public)->toBe([], 'these are not marked @internal: '.implode(', ', $public));
});

it('encrypts and decrypts in one file, opens through one caller, and lets nothing in the admin or HTTP near either', function (): void {
    $everything = credentialSourceFiles('');
    $read = static fn (string $file): string => (string) file_get_contents($file);
    $relative = static fn (string $file): string => substr($file, strlen(dirname(__DIR__, 3).'/packages/core/src/'));

    // ⚠️ EVERY SPELLING OF A CIPHER, not `decryptString` alone (review): `decrypt()`, the facade, an `Encrypter` built
    // anywhere else, and the extensions beneath them — a second door to a value, or a second key, would be any of them.
    $crypto = '/crypt\(|cryptString\(|Crypt::|Encrypter|openssl_|sodium_|hash_hkdf/';
    $decrypting = array_values(array_map($relative, array_filter($everything, static fn (string $f): bool => preg_match($crypto, $read($f)) === 1)));
    $opening = array_values(array_map($relative, array_filter($everything, static fn (string $f): bool => str_contains($read($f), 'cipher->open('))));

    expect($decrypting)->toBe(['Credentials/CredentialCipher.php'])
        ->and($opening)->toBe(['Credentials/CredentialReader.php']);

    foreach ([...credentialSourceFiles('Filament'), ...credentialSourceFiles('Http')] as $file) {
        foreach (['CredentialReader', 'CredentialCipher', 'reveal('] as $needle) {
            expect(str_contains($read($file), $needle))->toBeFalse($relative($file)." reaches {$needle}");
        }
    }
});

it('logs and reports nothing', function (): void {
    $core = dirname(__DIR__, 3).'/packages/core/src/';

    foreach ([...credentialSourceFiles('Credentials'), $core.'Console/CredentialsCommand.php', $core.'Filament/Pages/Credentials.php', $core.'Http/Controllers/CredentialSetController.php'] as $file) {
        $source = (string) file_get_contents($file);

        // Whole names only: `AuditLog::` is not `Log::`, and `->add(` is not `dd(` (PR B's page reads the audit log).
        foreach (['Log::', 'logger(', 'report(', 'dump(', 'dd('] as $needle) {
            expect(preg_match('/(?<![A-Za-z0-9_])'.preg_quote($needle, '/').'/', $source))->toBe(0, basename($file)." calls {$needle}");
        }
    }
});
