<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Release;

use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use SensitiveParameter;

/** RSA PKCS#1 v1.5/SHA-256, usable through the already-required OpenSSL extension. */
final class Signature
{
    public static function sign(string $raw, #[SensitiveParameter] string $privatePem, #[SensitiveParameter] string $passphrase, string $publicPem, string $expectedFingerprint, string $version, string $channel): string
    {
        $manifest = Manifest::parse($raw);
        if ($manifest->version !== $version || $manifest->channel !== $channel
            || strlen($privatePem) > 65536 || strlen($passphrase) > 4096
            || ! str_starts_with($privatePem, '-----BEGIN ENCRYPTED PRIVATE KEY-----')) {
            throw new InvalidArgumentException('Signing identity or encrypted private-key input refused.');
        }
        $key = @openssl_pkey_get_private($privatePem, $passphrase);
        if ($key === false) {
            throw new InvalidArgumentException('Encrypted signing key could not be opened.');
        }
        $details = openssl_pkey_get_details($key);
        if ($details === false || ! hash_equals(self::fingerprint($publicPem), self::fingerprint($details['key']))) {
            throw new InvalidArgumentException('Signing key does not match the expected public key.');
        }
        if (! openssl_sign($raw, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new InvalidArgumentException('Release signing failed.');
        }
        self::verify($raw, $signature, $publicPem, $expectedFingerprint, $version, $channel);

        return $signature;
    }

    public static function fingerprint(string $publicPem): string
    {
        $details = openssl_pkey_get_details(self::publicKey($publicPem));
        if ($details === false) {
            throw new InvalidArgumentException('Cannot inspect release public key.');
        }
        $der = base64_decode(str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\r", "\n"], '', $details['key']), true);
        if ($der === false) {
            throw new InvalidArgumentException('Invalid public key encoding.');
        }

        return hash('sha256', $der);
    }

    public static function verify(string $raw, string $signature, string $publicPem, string $expectedFingerprint, string $version, string $channel): Manifest
    {
        if (strlen($raw) > Manifest::MAX_BYTES || ! preg_match('/\A[a-f0-9]{64}\z/D', $expectedFingerprint)
            || ! hash_equals($expectedFingerprint, self::fingerprint($publicPem))) {
            throw new InvalidArgumentException('Release trust fingerprint or manifest size refused.');
        }

        $key = self::publicKey($publicPem);
        $details = openssl_pkey_get_details($key);
        if ($details === false || strlen($signature) !== intdiv($details['bits'], 8)) {
            throw new InvalidArgumentException('Missing or invalid release signature.');
        }

        // The error result -1 is truthy. Only exactly 1 authenticates these raw bytes.
        if (openssl_verify($raw, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new InvalidArgumentException('Release signature verification failed.');
        }

        $manifest = Manifest::parse($raw);
        if ($manifest->version !== $version || $manifest->channel !== $channel) {
            throw new InvalidArgumentException('Signed release does not match the requested version and channel.');
        }

        return $manifest;
    }

    private static function publicKey(string $pem): OpenSSLAsymmetricKey
    {
        if (strlen($pem) > 16384 || ! str_starts_with($pem, '-----BEGIN PUBLIC KEY-----')) {
            throw new InvalidArgumentException('Unsupported release public key.');
        }
        $key = @openssl_pkey_get_public($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA
            || $details['bits'] < 3072 || $details['bits'] > 8192 || $details['bits'] % 256 !== 0) {
            throw new InvalidArgumentException('Release key must be RSA with 3072–8192 bits.');
        }

        return $key;
    }
}
