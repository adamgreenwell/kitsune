<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers\Contracts;

/**
 * What core asks of the host's reader model — ADR-037, as built: the host owns a reader's identity (its guard, provider,
 * model and table), and core owns the front door (sign-in, and later sign-up and recovery). Core reads and writes the
 * host's row through these five methods only, as ADR-039's `ProvisionsMembership` lets core create the first owner
 * without naming the host's columns.
 *
 * Every method runs with the reader's org in Context. An address arrives normalised (`EmailAddress::normalise()`:
 * lower-case ASCII), and a password arrives already hashed: core never hands the host a plain password.
 *
 * ⚠️ THIS INTERFACE NEVER GROWS. A host's `App\Models\Reader` is frozen at `create-project`, so a method added here later
 * would be a fatal error on every installed site at `composer update`. A later capability (an address change, commerce's
 * passwordless claim) arrives as a NEW interface that core asks for with `instanceof`, and a site whose model lacks it
 * simply does not get that feature. `ReaderSurfaceTest` pins the list.
 *
 * Public surface under CONTRIBUTING's third exception, with `kitsune.readers.guard` and `ReaderRoutes::register()`.
 */
interface ReaderAccount
{
    /** The org-in-context's reader with this normalised address, or null — through the model's org-scoped query. */
    public static function findByEmail(#[\SensitiveParameter] string $email): ?static;

    /**
     * A new reader in the org in context. `$passwordHash` is already a hash (core hashes), or null for a reader with no
     * password yet.
     */
    public static function createReader(
        #[\SensitiveParameter] string $email,
        #[\SensitiveParameter] ?string $passwordHash,
        ?\DateTimeInterface $verifiedAt,
    ): static;

    /** The reader's address, as stored. */
    public function readerEmail(): string;

    /**
     * Everything the host holds about this reader, for a subject-access export — the model's own classification of
     * what is personal (ADR-037). Never a secret: no password hash, no remember token.
     *
     * @return array<string, scalar|null>
     */
    public function exportAccount(): array;

    /** Removes the row and anything the host keeps beside it. A hard delete. */
    public function eraseAccount(): void;
}
