<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Privacy;

use Illuminate\Encryption\Encrypter;
use LogicException;

/** @internal Erasure journals use a separate versioned key from cookies, sessions and credentials. */
final class ErasureJournalCipher
{
    public function seal(#[\SensitiveParameter] string $payload): string
    {
        return $this->encrypter()->encryptString($payload);
    }

    public function unseal(#[\SensitiveParameter] string $envelope): string
    {
        return $this->encrypter()->decryptString($envelope);
    }

    private function encrypter(): Encrypter
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new LogicException('An application key is required for erasure journals.');
        }

        $previous = [];
        $configured = config('app.previous_keys', []);
        foreach (is_array($configured) ? $configured : [] as $prior) {
            if (is_string($prior) && $prior !== '') {
                $previous[] = self::derive($prior);
            }
        }

        return (new Encrypter(self::derive($key), 'aes-256-gcm'))->previousKeys($previous);
    }

    private static function derive(#[\SensitiveParameter] string $configured): string
    {
        $raw = str_starts_with($configured, 'base64:') ? base64_decode(substr($configured, 7), true) : $configured;
        if (! is_string($raw) || $raw === '') {
            throw new LogicException('The application key cannot derive an erasure journal key.');
        }

        return hash_hkdf('sha256', $raw, 32, 'kitsune/erasure-journal/v1');
    }
}
