<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Credentials;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Kitsune\Core\Models\Credential;
use Kitsune\Core\Tenancy\Context;
use LogicException;

/**
 * Seals a credential and opens it again — ADR-040, and the only code in core that encrypts or decrypts anything.
 *
 * ⚠️ A KEY OF ITS OWN, DERIVED FROM `APP_KEY` (Adam, 2026-10-05): HKDF-SHA256 under a fixed label, with AES-256-GCM.
 * Measured: an encrypter so keyed cannot open an `APP_KEY` payload, and an `APP_KEY` encrypter cannot open this one —
 * so no path that decrypts a cookie, a session or a package's `Crypt::decrypt()` can open a credential, nor the
 * reverse. It adds no secret for an operator to keep, and rotation rides `APP_PREVIOUS_KEYS`, each derived the same
 * way. It does NOT narrow what a leak costs: `APP_KEY` and the database together still open every credential, which is
 * why `APP_KEY` is backup-critical now and the ADR says so.
 *
 * ⚠️ THE PLAINTEXT SEALED IS AN ENVELOPE naming the org, the credential and the mode beside the value, and `open()`
 * refuses a ciphertext whose envelope does not name the row it was read from and the org in context. A ciphertext
 * copied below Eloquent into another org's row, another credential's or the other mode's is refused rather than read.
 *
 * ⚠️ VERSIONED TWICE — the HKDF label and the envelope's header both end `/v1` — and changing either needs a path that
 * re-encrypts what is stored. A Laravel major that changed its payload format would read as `Unreadable`, never as a
 * crash.
 *
 * Bound `scoped`, so a test or a worker that changes `app.key` gets a fresh cipher at the next resolution.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class CredentialCipher
{
    public const INFO = 'kitsune/credentials/v1';

    public const ENVELOPE = 'kitsune-credential/v1';

    private const KEY_ID_LABEL = 'kitsune/credentials/key-id/v1';

    public function __construct(private readonly Context $context) {}

    public function seal(int $orgId, string $slot, string $mode, #[\SensitiveParameter] string $value): SealedCredential
    {
        $current = $this->currentKey() ?? throw new LogicException('There is no APP_KEY to seal a credential with.');

        $envelope = implode("\n", [self::ENVELOPE, (string) $orgId, $slot, $mode, $value]);

        try {
            return new SealedCredential($this->encrypter()->encryptString($envelope), self::keyIdOf($current));
        } finally {
            unset($envelope);
        }
    }

    /**
     * The value a row holds, as a `Secret`, for the org in context — or a refusal, never chained.
     *
     * @throws CredentialUnavailable `Unreadable` or `Misfiled`
     */
    public function open(Credential $row): Secret
    {
        $orgId = $this->context->orgId();
        $mode = CredentialMode::tryFrom($row->mode);
        $org = (string) ($this->context->org()->slug ?? $orgId);

        if ($this->currentKey() === null || $row->ciphertext === null) {
            throw CredentialUnavailable::because(CredentialUnavailability::Unreadable, $row->slot, $org, $mode);
        }

        try {
            $plain = $this->encrypter()->decryptString($row->ciphertext);
        } catch (DecryptException) {
            throw CredentialUnavailable::because(CredentialUnavailability::Unreadable, $row->slot, $org, $mode);
        }

        $parts = explode("\n", $plain, 5);
        unset($plain);

        if (count($parts) !== 5
            || $parts[0] !== self::ENVELOPE
            || $parts[1] !== (string) $row->org_id
            || $parts[2] !== $row->slot
            || $parts[3] !== $row->mode
            || $orgId === null
            || (string) $row->org_id !== (string) $orgId) {
            unset($parts);

            throw CredentialUnavailable::because(CredentialUnavailability::Misfiled, $row->slot, $org, $mode);
        }

        $secret = new Secret($parts[4]);
        unset($parts);

        return $secret;
    }

    /** The id of the key a new seal would use, without decrypting anything — null when `APP_KEY` is unset. */
    public function currentKeyId(): ?string
    {
        $key = $this->currentKey();

        return $key === null ? null : self::keyIdOf($key);
    }

    /** @return list<string> the ids of the keys derived from `APP_PREVIOUS_KEYS`, which still open what they sealed */
    public function previousKeyIds(): array
    {
        return array_map(self::keyIdOf(...), $this->previousKeys());
    }

    /** Built for each use, from the configuration as it is now: it costs microseconds, and a memo could go stale. */
    private function encrypter(): Encrypter
    {
        $current = $this->currentKey() ?? throw new LogicException('There is no APP_KEY to open a credential with.');

        return (new Encrypter($current, 'aes-256-gcm'))->previousKeys($this->previousKeys());
    }

    private function currentKey(): ?string
    {
        $configured = config('app.key');

        return is_string($configured) && $configured !== '' ? self::derive($configured) : null;
    }

    /** @return list<string> */
    private function previousKeys(): array
    {
        $configured = config('app.previous_keys', []);

        return array_values(array_map(
            self::derive(...),
            array_filter(is_array($configured) ? $configured : [], static fn (mixed $key): bool => is_string($key) && $key !== ''),
        ));
    }

    /**
     * A credentials key from an application key, read as `EncryptionServiceProvider::parseKey()` reads one.
     *
     * ⚠️ AN EMPTY KEY IS REFUSED BEFORE `hash_hkdf()` IS CALLED, so the internal function — whose arguments a trace
     * would print — never throws with a key in hand.
     */
    private static function derive(#[\SensitiveParameter] string $configured): string
    {
        $raw = str_starts_with($configured, 'base64:') ? base64_decode(substr($configured, 7), true) : $configured;

        if (! is_string($raw) || $raw === '') {
            throw new LogicException('An application key that decodes to nothing cannot derive a credentials key.');
        }

        return hash_hkdf('sha256', $raw, 32, self::INFO);
    }

    /**
     * A 64-bit tag of a 256-bit key under a fixed public label, so the admin and `kitsune:credentials status` tell the
     * current key, a previous one and a lost one apart without decrypting. It adds no oracle: whoever holds the database
     * can already test a guessed key against a ciphertext's tag.
     */
    private static function keyIdOf(#[\SensitiveParameter] string $key): string
    {
        return substr(hash_hmac('sha256', self::KEY_ID_LABEL, $key), 0, 16);
    }
}
