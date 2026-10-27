<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Release\Manifest;
use Kitsune\Release\Signature;

beforeEach(function (): void {
    require_once dirname(__DIR__, 3).'/bin/release/Manifest.php';
    require_once dirname(__DIR__, 3).'/bin/release/Signature.php';
});

function releaseManifestFixture(): string
{
    return "kitsune-release-manifest-v1\n"
        ."version\tv1.0.0-test.213\nchannel\ttest\n"
        ."source\t".str_repeat('a', 40)."\ntree\t".str_repeat('b', 40)."\n"
        ."signature\trsa-pkcs1-sha256\n"
        ."artifact\tfixture.tar\tprovenance\tany\t7\t".hash('sha256', 'fixture')."\n"
        ."package\tkitsune/core\tdev-".str_repeat('a', 40)."\t".str_repeat('a', 40)."\t".str_repeat('c', 64)."\tsplit\n";
}

it('round trips the bounded canonical manifest without PHP or JSON serialization ambiguity', function (): void {
    $bytes = releaseManifestFixture();
    $manifest = Manifest::parse($bytes);

    expect($manifest->bytes())->toBe($bytes)
        ->and($manifest->version)->toBe('v1.0.0-test.213')
        ->and($manifest->artifacts[0]['sha256'])->toBe(hash('sha256', 'fixture'))
        ->and($manifest->packages[0]['publication'])->toBe('split');
});

it('rejects malformed, ambiguous and unsupported signed metadata', function (Closure $mutate): void {
    expect(fn () => Manifest::parse($mutate(releaseManifestFixture())))->toThrow(InvalidArgumentException::class);
})->with([
    'unknown format' => [fn ($s) => str_replace('manifest-v1', 'manifest-v2', $s)],
    'missing final newline' => [fn ($s) => rtrim($s, "\n")],
    'CRLF' => [fn ($s) => str_replace("\n", "\r\n", $s)],
    'NUL' => [fn ($s) => $s."\0"],
    'extra blank line' => [fn ($s) => $s."\n"],
    'oversized manifest' => [fn ($s) => $s.str_repeat('x', 65536)],
    'duplicate header' => [fn ($s) => str_replace("channel\ttest\n", "channel\ttest\nchannel\ttest\n", $s)],
    'unknown algorithm' => [fn ($s) => str_replace('rsa-pkcs1-sha256', 'rsa-sha1', $s)],
    'unknown role' => [fn ($s) => str_replace("\tprovenance\t", "\texecute\t", $s)],
    'path traversal' => [fn ($s) => str_replace('fixture.tar', '../fixture.tar', $s)],
    'absolute path' => [fn ($s) => str_replace('fixture.tar', '/fixture.tar', $s)],
    'option filename' => [fn ($s) => str_replace('fixture.tar', '-fixture.tar', $s)],
    'encoded separator' => [fn ($s) => str_replace('fixture.tar', 'dir%2ffixture.tar', $s)],
    'zero size' => [fn ($s) => str_replace("\t7\t", "\t0\t", $s)],
    'noncanonical size' => [fn ($s) => str_replace("\t7\t", "\t07\t", $s)],
    'integer overflow' => [fn ($s) => str_replace("\t7\t", "\t99999999999999999999\t", $s)],
    'wrong digest length' => [fn ($s) => str_replace(hash('sha256', 'fixture'), 'abc', $s)],
    'duplicate artifact' => [fn ($s) => preg_replace('/(artifact[^\n]+\n)/', '$1$1', $s)],
    'duplicate package' => [fn ($s) => preg_replace('/(package[^\n]+\n)/', '$1$1', $s)],
    'unknown publication intent' => [fn ($s) => str_replace("\tsplit\n", "\tpush-everywhere\n", $s)],
    'development version/reference mismatch' => [fn ($s) => str_replace('dev-'.str_repeat('a', 40), 'dev-'.str_repeat('d', 40), $s)],
    'stable channel with prerelease' => [fn ($s) => str_replace("channel\ttest", "channel\tstable", $s)],
    'stable development package' => [fn ($s) => str_replace(['v1.0.0-test.213', "channel\ttest"], ['v1.0.0', "channel\tstable"], $s)],
]);

it('requires an independently pinned RSA key and verifies the exact requested release', function (): void {
    // Test key only, generated in memory. It is never written or passed in an argument/environment variable.
    $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072]);
    $public = openssl_pkey_get_details($private)['key'];
    $bytes = releaseManifestFixture();
    openssl_sign($bytes, $signature, $private, OPENSSL_ALGO_SHA256);
    $pin = Signature::fingerprint($public);

    expect(Signature::verify($bytes, $signature, $public, $pin, 'v1.0.0-test.213', 'test')->bytes())->toBe($bytes);

    foreach ([$bytes.' ', str_replace('test.213', 'test.214', $bytes)] as $changed) {
        expect(fn () => Signature::verify($changed, $signature, $public, $pin, 'v1.0.0-test.213', 'test'))->toThrow(InvalidArgumentException::class);
    }

    expect(fn () => Signature::verify($bytes, '', $public, $pin, 'v1.0.0-test.213', 'test'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Signature::verify($bytes, $signature, $public, str_repeat('0', 64), 'v1.0.0-test.213', 'test'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Signature::verify($bytes, $signature, $public, $pin, 'v1.0.1', 'test'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Signature::verify($bytes, $signature, $public, $pin, 'v1.0.0-test.213', 'stable'))->toThrow(InvalidArgumentException::class);
});

it('does not treat a signed unsupported schema or a weak key as admission', function (): void {
    $weak = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    expect(fn () => Signature::fingerprint(openssl_pkey_get_details($weak)['key']))->toThrow(InvalidArgumentException::class);

    $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072]);
    $public = openssl_pkey_get_details($private)['key'];
    $unsupported = str_replace('manifest-v1', 'manifest-v99', releaseManifestFixture());
    openssl_sign($unsupported, $signature, $private, OPENSSL_ALGO_SHA256);

    expect(fn () => Signature::verify($unsupported, $signature, $public, Signature::fingerprint($public), 'v1.0.0-test.213', 'test'))->toThrow(InvalidArgumentException::class);
});

it('distinguishes semantic version prereleases from build metadata', function (): void {
    expect(Manifest::validVersion('v1.0.0+build.01'))->toBeTrue()
        ->and(Manifest::validVersion('v1.0.0-rc.1+build.01'))->toBeTrue()
        ->and(Manifest::validVersion('v01.0.0'))->toBeFalse()
        ->and(Manifest::validVersion('v1.0.0-01'))->toBeFalse()
        ->and(Manifest::validVersion('v1.0.0+'))->toBeFalse();

    $bytes = str_replace(
        ['v1.0.0-test.213', "channel\ttest", 'dev-'.str_repeat('a', 40)],
        ['v1.0.0+build-with-hyphen.01', "channel\tstable", '1.0.0+build.01'],
        releaseManifestFixture(),
    );
    expect(Manifest::parse($bytes)->channel)->toBe('stable');
});

it('signs only with the matching pinned private key and protects passphrase failures', function (): void {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072]);
    $public = openssl_pkey_get_details($key)['key'];
    $passphrase = bin2hex(random_bytes(24));
    openssl_pkey_export($key, $encrypted, $passphrase);
    $bytes = releaseManifestFixture();
    $pin = Signature::fingerprint($public);
    $signed = Signature::sign($bytes, $encrypted, $passphrase, $public, $pin, 'v1.0.0-test.213', 'test');

    expect(Signature::verify($bytes, $signed, $public, $pin, 'v1.0.0-test.213', 'test')->bytes())->toBe($bytes)
        ->and(fn () => Signature::sign($bytes, $encrypted, 'wrong', $public, $pin, 'v1.0.0-test.213', 'test'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Signature::sign($bytes, $encrypted, $passphrase, $public, str_repeat('0', 64), 'v1.0.0-test.213', 'test'))->toThrow(InvalidArgumentException::class);
});
