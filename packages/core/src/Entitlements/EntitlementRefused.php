<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Entitlements;

use RuntimeException;

/**
 * A reader's access was not changed, exported or erased, and nothing was written — ADR-040.
 *
 * ⚠️ NO MESSAGE EVER HOLDS THE READER'S IDENTIFIER OR THE SOURCE, and neither is a property. A reader id beside an
 * entitlement's name is purchase history, and so is an order reference beside one. A refusal repeats the name only
 * when it has a name's shape, which alone identifies nobody.
 *
 * ⚠️ NEVER CHAINED. A database exception interpolates its bindings, which hold the reader's identifier and the source:
 * each refusal is one message in its own words.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class EntitlementRefused extends RuntimeException
{
    public const GRANT = 'grant';

    public const COMP = 'comp';

    public const REVOKE = 'revoke';

    public const FORGET = 'forget';

    public const EXPORT = 'export';

    /**
     * @param  bool  $mayHaveApplied  a COMMIT the database refused, whose change cannot be told from here to have
     *                                applied or not — so a caller's own words never say "not given" over it
     */
    private function __construct(
        string $message,
        public readonly EntitlementRefusal $reason,
        public readonly ?string $entitlement,
        public readonly bool $mayHaveApplied = false,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  self::GRANT|self::COMP|self::REVOKE|self::FORGET|self::EXPORT  $act
     * @param  string|null  $entitlement  the name asked for, repeated only when it has a name's shape
     * @param  array{state?: string, fault?: string, commit?: bool}  $detail
     */
    public static function because(EntitlementRefusal $reason, string $act, ?string $entitlement = null, array $detail = []): self
    {
        $named = $entitlement !== null && EntitlementName::isName($entitlement) ? $entitlement : null;

        return new self(self::message($reason, $act, $named, $detail), $reason, $named, $reason === EntitlementRefusal::Database && ($detail['commit'] ?? false));
    }

    /** @param  array{state?: string, fault?: string, commit?: bool}  $detail */
    private static function message(EntitlementRefusal $reason, string $act, ?string $named, array $detail): string
    {
        $doing = match ($act) {
            self::COMP => 'give an entitlement by hand',
            self::REVOKE => 'revoke an entitlement',
            self::FORGET => 'erase a reader\'s entitlements',
            self::EXPORT => 'export a reader\'s entitlements',
            default => 'grant an entitlement',
        };
        $done = match ($act) {
            self::COMP => 'given',
            self::REVOKE => 'revoked',
            self::FORGET => 'erased',
            self::EXPORT => 'exported',
            default => 'granted',
        };
        $subject = match (true) {
            $act === self::FORGET || $act === self::EXPORT => 'A reader\'s entitlements',
            $named !== null => "[{$named}]",
            default => 'The entitlement',
        };
        $tag = $named !== null ? " [{$named}]" : '';

        return match ($reason) {
            EntitlementRefusal::NoSiteContext => "Refusing to {$doing}: there is no site in context. An entitlement belongs to one site (ADR-040, ADR-037), and the writer takes the site its caller set, never an argument. Nothing was written.",
            EntitlementRefusal::SiteGone => "Refusing to {$doing}: the site in context no longer exists. Nothing was written.",
            EntitlementRefusal::NoOrgContext => "Refusing to {$doing}: there is no organisation in context, so the change could not be recorded, and an unrecorded change is refused (ADR-020). Nothing was written.",
            EntitlementRefusal::NotAnOwner => $act === self::COMP
                ? 'Refusing to give an entitlement by hand: only an owner of this organisation, signed in, may do that. A comp is the one act that gives back access once revoked, so it is a person\'s decision and never a caller\'s (ADR-040, ADR-033). Nothing was written.'
                : "Refusing to {$doing}: only an owner of this organisation may give, take away, export or erase a reader's access (ADR-033). Nothing was written.",
            EntitlementRefusal::ReaderActing => "Refusing to {$doing}: this request is a reader's own, and a reader never changes their own access. Access is given by an owner, a verified payment, an import or a migration (ADR-040). Nothing was written.",
            EntitlementRefusal::NoReaderGuard => "Refusing to {$doing}: ".($detail['fault'] ?? 'this installation has no usable reader guard').'. Without a usable reader guard core cannot tell one reader from another, and it never guesses (ADR-037). Nothing was written.',
            EntitlementRefusal::NotAName => "Refusing to {$doing}: that is not an entitlement's name. A name is two lower-case words joined by a dot — course.advanced-php, download.whitepaper-2026 — of letters, digits and single hyphens, the first word starting with a letter, at most 100 characters (ADR-040). It is not repeated here. Nothing was written.",
            EntitlementRefusal::NotASource => "Refusing to {$doing}{$tag}: that is not a source. A source names its producer as two lower-case words joined by a dot, optionally followed by a colon and a reference of up to 64 letters, digits, hyphens or underscores — commerce.order:4821, import.legacy:batch-7 — at most 100 characters (ADR-040). It is not repeated here. Nothing was written.",
            EntitlementRefusal::ReservedSource => "Refusing to grant{$tag}: sources beginning core. are Kitsune's own, and an owner's comp is given through comp(), never grant(). It is not repeated here. Nothing was written.",
            EntitlementRefusal::NotAReader => "Refusing to {$doing}{$tag}: that is not an identifier a reader can have here — printable characters with no spaces, at most 255 bytes, of the reader model's key type. It is not repeated here. Nothing was written.",
            EntitlementRefusal::UnknownReader => "Refusing to {$doing}{$tag}: no reader with that identifier belongs to this organisation. It is not repeated here. Nothing was written.",
            EntitlementRefusal::AlreadyEnded => "Refusing to {$doing}{$tag}: the end given is at or before this moment, so nobody would ever hold it. For access with no end, give no end. Nothing was written.",
            EntitlementRefusal::TooFar => "Refusing to {$doing}{$tag}: an end after 9999-12-31 23:59:59 UTC cannot be stored on every database Kitsune supports. For access with no end, give no end. Nothing was written.",
            EntitlementRefusal::Race => "{$subject} was not {$done}: it was changed somewhere else at the same moment. Nothing was written; try again.",
            EntitlementRefusal::Database => ($detail['commit'] ?? false)
                ? "{$subject} may not have been {$done}: the database refused to commit (SQLSTATE ".($detail['state'] ?? '?').'), and whether the change applied cannot be told from here. Asking again is safe: a repeat changes nothing that already landed.'
                : "{$subject} was not {$done}: the database refused (SQLSTATE ".($detail['state'] ?? '?').'). Its message is not repeated, because it carries the reader\'s identifier. Nothing was written.',
            EntitlementRefusal::Cancelled => "{$subject} was not {$done}: a listener cancelled the save. Nothing was written, and nothing is recorded.",
        };
    }
}
