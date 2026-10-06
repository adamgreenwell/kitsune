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
 * The credentials enabled modules keep — ADR-040's `@internal` seam, the shape of `AdminSurface` and
 * `BlueprintRegistry`: a singleton bound in `register()`, filled by modules in `registerModule()`.
 *
 * ⚠️ A DISABLED MODULE'S PROVIDER NEVER RUNS, so its credentials are not declared: their rows stay encrypted and unread,
 * because the reader refuses a credential nothing declares, and they return if the module is enabled again.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class CredentialSlots
{
    /** @var array<string, CredentialSlot> */
    private array $slots = [];

    public function register(CredentialSlot $slot): self
    {
        if (array_key_exists($slot->name, $this->slots)) {
            throw new LogicException(sprintf(
                'Refusing the credential slot [%s]: it is already registered, and two declarations of one slot would '
                .'disagree about what it holds.',
                $slot->name,
            ));
        }

        $this->slots[$slot->name] = $slot;

        return $this;
    }

    public function find(string $name): ?CredentialSlot
    {
        return $this->slots[$name] ?? null;
    }

    /** @return list<CredentialSlot> in the order they were registered */
    public function all(): array
    {
        return array_values($this->slots);
    }

    /** Whether any declared credential keeps a test and a live value — so whether an org's mode means anything. */
    public function hasModed(): bool
    {
        foreach ($this->slots as $slot) {
            if ($slot->moded) {
                return true;
            }
        }

        return false;
    }

    /** For tests, as `AdminSurface::flush()`. */
    public function flush(): void
    {
        $this->slots = [];
    }
}
