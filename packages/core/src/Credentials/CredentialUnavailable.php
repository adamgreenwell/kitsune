<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Credentials;

use RuntimeException;

/**
 * A credential could not be read for the org in context, and the consumer fails closed — ADR-040.
 *
 * Consumers branch on `$reason`, never on the text. Never chained: a decryption failure becomes `Unreadable` in its own
 * words, and no message holds a value, a ciphertext or a key.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class CredentialUnavailable extends RuntimeException
{
    private function __construct(string $message, public readonly CredentialUnavailability $reason)
    {
        parent::__construct($message);
    }

    /**
     * @param  string  $slot  repeated only when it has a credential name's shape
     * @param  array{prefix?: string}  $detail
     */
    public static function because(
        CredentialUnavailability $reason,
        string $slot,
        ?string $org = null,
        ?CredentialMode $mode = null,
        array $detail = [],
    ): self {
        if (! CredentialSlot::isName($slot)) {
            return new self('Credential is unavailable: that is not a credential\'s name, and it is not repeated here.', $reason);
        }

        $in = $mode !== null ? " ({$mode->value} mode)" : '';

        return new self(match ($reason) {
            CredentialUnavailability::NoOrgContext => "Credential [{$slot}] is unavailable: there is no organisation context to read it for. Set the context from the site or organisation the request belongs to first.",
            CredentialUnavailability::UnknownSlot => "Credential [{$slot}] is unavailable: nothing enabled on this installation declares it.",
            CredentialUnavailability::NotSet => $mode !== null
                ? "Credential [{$slot}] is not set for organisation {$org} in {$mode->value} mode."
                : "Credential [{$slot}] is not set for organisation {$org}.",
            CredentialUnavailability::Unreadable => "Credential [{$slot}] for organisation {$org}{$in} cannot be decrypted with this installation's APP_KEY or APP_PREVIOUS_KEYS: it was stored under a key this installation no longer has, or what is stored there is damaged. An owner must set it again.",
            CredentialUnavailability::Misfiled => "Credential [{$slot}] for organisation {$org}{$in} is refused: what is stored there was sealed for another organisation, credential or mode. An owner must set it again.",
            CredentialUnavailability::WrongMode => "Credential [{$slot}] for organisation {$org} is refused: the organisation is in {$mode?->value} mode and the stored value is a {$mode?->other()->value}-mode key (it begins {$detail['prefix']}) (ADR-040). An owner must set it again.",
            CredentialUnavailability::WrongShape => "Credential [{$slot}] for organisation {$org}{$in} is refused: what is stored no longer fits what the module declares for it. An owner must set it again.",
        }, $reason);
    }
}
