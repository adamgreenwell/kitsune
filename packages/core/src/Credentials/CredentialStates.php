<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Credentials;

use Kitsune\Core\Models\Credential;
use Kitsune\Core\Models\OrgCredentialMode;

/**
 * What the org in context has stored, read from the rows and their key ids alone — ADR-040.
 *
 * ⚠️ IT DECRYPTS NOTHING. Whether a value is set, removed, sealed under a previous key or under none this installation
 * has, is all in the row; so the admin and `kitsune:credentials status` can say so without opening a ciphertext.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class CredentialStates
{
    public function __construct(
        private readonly CredentialSlots $slots,
        private readonly CredentialCipher $cipher,
    ) {}

    /** The org's mode: test until it chose otherwise, and test with no org in context, where nothing reads anyway. */
    public function mode(): CredentialMode
    {
        $stored = OrgCredentialMode::query()->value('mode');

        return is_string($stored) ? (CredentialMode::tryFrom($stored) ?? CredentialMode::Test) : CredentialMode::Test;
    }

    /** One line: this credential at this mode, or at the mode in force when none is named. */
    public function of(string $slot, ?CredentialMode $mode = null): CredentialState
    {
        $moded = $this->slots->find($slot)->moded ?? $mode !== null;
        $modeValue = $moded ? ($mode ?? $this->mode())->value : 'none';

        $row = Credential::query()->where('slot', $slot)->where('mode', $modeValue)->first(['id', 'ciphertext', 'key_id', 'changed_at']);

        if ($row === null) {
            return new CredentialState(CredentialStatus::NotSet, null, null);
        }

        return new CredentialState(self::statusOf($row, $this->cipher), $row->changed_at, $row->id);
    }

    /** A row's status, from whether it holds a value and which key sealed it. */
    public static function statusOf(Credential $row, CredentialCipher $cipher): CredentialStatus
    {
        return match (true) {
            $row->ciphertext === null => CredentialStatus::Removed,
            $row->key_id !== null && $row->key_id === $cipher->currentKeyId() => CredentialStatus::Set,
            in_array($row->key_id, $cipher->previousKeyIds(), true) => CredentialStatus::SetUnderPreviousKey,
            default => CredentialStatus::Unreadable,
        };
    }
}
