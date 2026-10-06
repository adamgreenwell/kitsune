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
 * A credential was not written, and nothing was — ADR-040.
 *
 * ⚠️ NEVER CHAINED. A database exception interpolates its bindings, which hold ciphertext at worst, and a decryption
 * failure is its own refusal: each is one message in its own words, as `FirstOrg`'s are. And no message holds the
 * value or any part of it — the only text in one that came near the input is a prefix the module DECLARED.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class CredentialRefused extends RuntimeException
{
    public const SET = 'set';

    public const REMOVE = 'remove';

    public const SWITCH = 'switch';

    private function __construct(
        string $message,
        public readonly CredentialRefusal $reason,
        public readonly ?string $slot,
        public readonly ?CredentialMode $mode,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  self::SET|self::REMOVE|self::SWITCH  $act
     * @param  string|null  $slot  the name asked for, repeated only when it has a name's shape
     * @param  array{prefix?: string, state?: string}  $detail
     */
    public static function because(
        CredentialRefusal $reason,
        string $act,
        ?string $slot = null,
        ?CredentialSlot $declared = null,
        ?CredentialMode $mode = null,
        array $detail = [],
    ): self {
        $named = $slot !== null && CredentialSlot::isName($slot) ? $slot : null;

        return new self(self::message($reason, $act, $named, $declared, $mode, $detail), $reason, $named, $mode);
    }

    /** @param  array{prefix?: string, state?: string}  $detail */
    private static function message(
        CredentialRefusal $reason,
        string $act,
        ?string $slot,
        ?CredentialSlot $declared,
        ?CredentialMode $mode,
        array $detail,
    ): string {
        $doing = match ($act) {
            self::REMOVE => 'remove a credential',
            self::SWITCH => 'switch this organisation between test and live mode',
            default => 'change a credential',
        };
        $verb = match ($act) {
            self::REMOVE => 'remove',
            self::SWITCH => 'switch',
            default => 'change',
        };
        $done = match ($act) {
            self::REMOVE => 'removed',
            self::SWITCH => 'switched',
            default => 'saved',
        };
        $subject = $act === self::SWITCH
            ? 'The organisation\'s mode'
            : ($declared !== null ? (string) __($declared->label) : 'The credential');
        $tail = $declared !== null && $declared->moded && $mode !== null ? " for {$mode->value} mode" : '';

        return match ($reason) {
            CredentialRefusal::NoOrgContext => "Refusing to {$doing}: there is no organisation context, so the change could not be recorded, and an unrecorded change is refused (ADR-020). Nothing was written.",
            CredentialRefusal::NotAnOwner => "Refusing to {$doing}: only an owner of this organisation may change its credentials (ADR-033). Nothing was written.",
            CredentialRefusal::NotAName => "Refusing to {$verb} a credential: that is not a credential's name — names look like commerce.stripe-secret-key — and it is not repeated here in case it was the value. Nothing was written.",
            CredentialRefusal::UnknownSlot => "Refusing to {$verb} [{$slot}]: nothing enabled on this installation declares a credential by that name. Nothing was written.",
            CredentialRefusal::ModeRequired => "Refusing to {$verb} [{$slot}]: it keeps a test-mode and a live-mode value, so the mode must be named. Nothing was written.",
            CredentialRefusal::ModeNotTaken => "Refusing to {$verb} [{$slot}]: it keeps one value whatever the organisation's mode, so no mode is named. Nothing was written.",
            CredentialRefusal::Empty => "{$subject} was not saved{$tail}: no value was given. To take it away, remove it instead. Nothing was written.",
            CredentialRefusal::Characters => "{$subject} was not saved{$tail}: the value contains a space, a line break or a character outside printable ASCII, which no key it takes contains — a copy that picked up something else? Nothing was written.",
            CredentialRefusal::TooShort => "{$subject} was not saved{$tail}: the value is shorter than {$declared?->minLength} characters. Nothing was written.",
            CredentialRefusal::TooLong => "{$subject} was not saved{$tail}: the value is longer than {$declared?->maxLength} characters. Nothing was written.",
            CredentialRefusal::OtherMode => $mode === CredentialMode::Live
                ? "{$subject} was not saved for live mode: it is a test-mode key (it begins {$detail['prefix']}). A test key in live mode takes no money while appearing to (ADR-040). Nothing was written."
                : "{$subject} was not saved for test mode: it is a live-mode key (it begins {$detail['prefix']}). A live key in test mode moves real money. Nothing was written.",
            CredentialRefusal::Shape => "{$subject} was not saved{$tail}: a value here begins ".implode(' or ', $declared?->prefixesFor($mode) ?? []).', and this one does not. Nothing was written.',
            CredentialRefusal::NoAppKey => "{$subject} was not saved: this installation has no APP_KEY, so nothing can be stored encrypted. Whoever runs the installation must set one. Nothing was written.",
            CredentialRefusal::Race => "{$subject} was not {$done}: it was changed somewhere else at the same moment. Nothing was written; try again.",
            CredentialRefusal::Database => "{$subject} was not {$done}: the database refused the write (SQLSTATE {$detail['state']}). Its message is not repeated, because it can carry what was being stored. Nothing was written.",
            CredentialRefusal::Cancelled => "{$subject} was not {$done}: a listener cancelled the save. Nothing was written, and nothing is recorded.",
        };
    }
}
