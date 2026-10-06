<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Credentials;

use Kitsune\Core\Models\Credential;
use Kitsune\Core\Tenancy\Context;

/**
 * The one door out: a credential's value for the org in context, at the mode it is in — ADR-040.
 *
 * ⚠️ THE ONLY CALLER OF `CredentialCipher::open()`, and nothing under `Filament/` or `Http/` may call it (asserted): a
 * value leaves the server only where a module hands it to the provider it belongs to.
 *
 * ⚠️ THE MODE IN FORCE, AND NO OTHER. There is deliberately no mode parameter, so a consumer cannot ask for test keys
 * while the org is live — the money that appears to move without moving. A missing value is `NotSet`, never the other
 * mode's; and the value read is checked against the declaration again, so a tampered row, or a module whose prefixes
 * tightened since, refuses rather than serves.
 *
 * ⚠️ NO USER CHECK, AND NO MEMO. A webhook has nobody to ask, so a consumer sets the context from the site its request
 * belongs to first — and no context is its own refusal, never mistaken for "not configured". A memo or a cache would
 * keep plaintext; a read is two indexed selects and one GCM open, at the moment of use.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class CredentialReader
{
    public function __construct(
        private readonly Context $context,
        private readonly CredentialSlots $slots,
        private readonly CredentialStates $states,
        private readonly CredentialCipher $cipher,
    ) {}

    /** @throws CredentialUnavailable never null: a consumer fails closed on every reason */
    public function secret(string $slot): Secret
    {
        if ($this->context->orgId() === null) {
            throw CredentialUnavailable::because(CredentialUnavailability::NoOrgContext, $slot);
        }

        $declared = $this->slots->find($slot) ?? throw CredentialUnavailable::because(CredentialUnavailability::UnknownSlot, $slot);
        $mode = $declared->moded ? $this->states->mode() : null;
        $org = (string) ($this->context->org()->slug ?? $this->context->orgId());

        $row = Credential::query()->where('slot', $slot)->where('mode', $mode->value ?? 'none')->first();

        if ($row === null || $row->ciphertext === null) {
            throw CredentialUnavailable::because(CredentialUnavailability::NotSet, $slot, $org, $mode);
        }

        $secret = $this->cipher->open($row);
        $refusal = $declared->refusalFor($mode, $secret->reveal());

        if ($refusal === CredentialRefusal::OtherMode && $mode !== null) {
            throw CredentialUnavailable::because(CredentialUnavailability::WrongMode, $slot, $org, $mode, [
                'prefix' => (string) $declared->otherModePrefixOf($mode, $secret->reveal()),
            ]);
        }

        if ($refusal !== null) {
            throw CredentialUnavailable::because(CredentialUnavailability::WrongShape, $slot, $org, $mode);
        }

        return $secret;
    }
}
