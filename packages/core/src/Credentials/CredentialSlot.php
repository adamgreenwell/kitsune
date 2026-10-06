<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Credentials;

use LogicException;

/**
 * One credential a module keeps for each org — ADR-040, declared in the module's `registerModule()`.
 *
 * ⚠️ CORE KNOWS NOTHING ABOUT ANY PROVIDER. What a Stripe key looks like is commerce's to declare, here, as the prefixes
 * each mode's values begin with; core checks a value against the declaration and nothing else. A prefix that belongs
 * to one mode alone decides the mode a value is for, so a test key pasted for live mode is refused. Where a prefix is
 * the same for both modes, or there is none, the mode is the line the owner pasted into.
 *
 * ⚠️ A VALUE THAT MUST BE SHOWN OR SENT TO A BROWSER — a publishable key — IS NOT A CREDENTIAL. Nothing stored here is
 * ever shown again.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final readonly class CredentialSlot
{
    private const NAME = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D';

    private const PREFIX = '/^[A-Za-z0-9_-]{1,32}$/D';

    /** A value is printable ASCII and nothing else: no key a provider issues holds a space, a line break or more. */
    private const VALUE = '/^[\x21-\x7E]+$/D';

    private const LONGEST = 1024;

    /**
     * @param  string  $name  two lower-case words joined by a dot, the first naming the module: `commerce.stripe-secret-key`
     * @param  string  $label  a translation key, shown wherever the credential is named
     * @param  string  $help  a translation key: where the operator finds the value, and what it is for
     * @param  bool  $moded  true: a test value and a live value; false: one value whatever the mode
     * @param  array{test?: list<string>, live?: list<string>, none?: list<string>}  $prefixes
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $help,
        public bool $moded,
        public array $prefixes = [],
        public int $minLength = 16,
        public int $maxLength = 255,
    ) {
        self::refuseUnless(self::isName($name), $name, 'a slot\'s name is two lower-case words joined by a dot, the first naming the module that declares it — for example commerce.stripe-secret-key.');
        self::refuseUnless(! str_starts_with($name, 'core.'), $name, 'the core. prefix is reserved for Kitsune itself.');
        self::refuseUnless(trim($label) !== '' && trim($help) !== '', $name, 'it needs a label and help text, because an owner pastes a key into it by name.');

        $keys = array_keys($prefixes);
        sort($keys);

        if ($moded) {
            self::refuseUnless($keys === [] || $keys === ['live', 'test'], $name, 'a slot kept for test and live mode names prefixes for both modes or for neither.');
        } else {
            self::refuseUnless($keys === [] || $keys === ['none'], $name, 'a slot with one value names its prefixes under \'none\'.');
        }

        $longest = 0;

        foreach ($prefixes as $list) {
            self::refuseUnless($list !== [], $name, 'a prefix is 1 to 32 letters, digits, hyphens or underscores.');

            foreach ($list as $prefix) {
                self::refuseUnless(preg_match(self::PREFIX, $prefix) === 1, $name, 'a prefix is 1 to 32 letters, digits, hyphens or underscores.');
                $longest = max($longest, strlen($prefix));
            }
        }

        foreach ($prefixes['test'] ?? [] as $test) {
            foreach ($prefixes['live'] ?? [] as $live) {
                self::refuseUnless(
                    $test === $live || (! str_starts_with($test, $live) && ! str_starts_with($live, $test)),
                    $name,
                    'its test and live prefixes overlap, so one key could match both modes.',
                );
            }
        }

        self::refuseUnless(
            $minLength >= 16 && $minLength <= $maxLength && $maxLength <= self::LONGEST && $minLength > $longest,
            $name,
            'lengths must satisfy 16 ≤ minimum ≤ maximum ≤ 1024, and the minimum must exceed every prefix.',
        );
    }

    /** Whether a string has the shape of a credential's name — asked before one is ever repeated in a message. */
    public static function isName(string $name): bool
    {
        return strlen($name) <= 100 && preg_match(self::NAME, $name) === 1;
    }

    /**
     * Why this value cannot go in this slot at this mode, or null.
     *
     * ⚠️ ON THE EXACT BYTES IT IS GIVEN, after the caller's own trimming, and it never quotes them: a refusal names a
     * declared bound or prefix, never the value or any part of it.
     */
    public function refusalFor(?CredentialMode $mode, #[\SensitiveParameter] string $value): ?CredentialRefusal
    {
        if ($value === '') {
            return CredentialRefusal::Empty;
        }

        if (preg_match(self::VALUE, $value) !== 1) {
            return CredentialRefusal::Characters;
        }

        if (strlen($value) < $this->minLength) {
            return CredentialRefusal::TooShort;
        }

        if (strlen($value) > $this->maxLength) {
            return CredentialRefusal::TooLong;
        }

        $own = $this->prefixesFor($mode);

        if ($own === [] || self::beginsWithAny($value, $own) !== null) {
            return null;
        }

        return $this->otherModePrefixOf($mode, $value) !== null ? CredentialRefusal::OtherMode : CredentialRefusal::Shape;
    }

    /**
     * The other mode's prefix this value begins with, for the message that says so — a DECLARED string, never one cut
     * from the input. Null for a slot with one value, or a value that begins with none of them.
     */
    public function otherModePrefixOf(?CredentialMode $mode, #[\SensitiveParameter] string $value): ?string
    {
        if (! $this->moded || $mode === null) {
            return null;
        }

        $theirs = array_values(array_diff($this->prefixes[$mode->other()->value] ?? [], $this->prefixesFor($mode)));

        return self::beginsWithAny($value, $theirs);
    }

    /** @return list<string> the prefixes a value for this mode begins with, or none for a slot that declares none */
    public function prefixesFor(?CredentialMode $mode): array
    {
        if (! $this->moded) {
            return $this->prefixes['none'] ?? [];
        }

        return $mode === null ? [] : ($this->prefixes[$mode->value] ?? []);
    }

    /** @param  list<string>  $prefixes */
    private static function beginsWithAny(#[\SensitiveParameter] string $value, array $prefixes): ?string
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return $prefix;
            }
        }

        return null;
    }

    private static function refuseUnless(bool $holds, string $name, string $why): void
    {
        if (! $holds) {
            throw new LogicException(sprintf('Refusing the credential slot [%s]: %s', $name, $why));
        }
    }
}
