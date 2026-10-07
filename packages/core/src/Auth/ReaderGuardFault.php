<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Auth;

/**
 * Why core cannot resolve a reader on this installation — ADR-037. `ReaderGuard::fault()` asks in this order.
 *
 * ⚠️ ONE EXCEPTION TO THAT ORDER: a guard whose driver cannot be built is `UnknownGuard`, asked once its provider is
 * known to build, because building the guard builds the provider too.
 *
 * ⚠️ `PanelShaped` BEFORE `NotOrgScoped`: a panel user's model registers the membership scope and no org scope, so the
 * other order would describe it as merely unscoped, and `PanelShaped` could never be reached.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
enum ReaderGuardFault
{
    /** `kitsune.readers.guard` absent, null, empty or not a string. */
    case NotDeclared;

    /** Not a guard's name, `auth.guards.{name}` is not an array naming a provider, or its driver cannot be built. */
    case UnknownGuard;

    /** Some panel authenticates with it. */
    case PanelGuard;

    /** Its provider has no `getModel()`, or the model is not an Eloquent model a guard can return. */
    case NotEloquent;

    /** Its model registers the membership scope: shaped like a panel user. */
    case PanelShaped;

    /** Its model does not register the org scope — the REGISTERED scope, not the attribute. */
    case NotOrgScoped;

    /**
     * The fault in words, for a refusal and the console's status line. The guard's name and the model's class are
     * configuration, never personal data.
     */
    public function sentence(string $guard, ?string $model): string
    {
        return match ($this) {
            self::NotDeclared => 'this installation declares no reader guard (kitsune.readers.guard)',
            self::UnknownGuard => "the declared reader guard [{$guard}] is not a guard with a provider and a driver Laravel can build in config/auth.php",
            self::PanelGuard => "the declared reader guard [{$guard}] is a panel's guard, and a reader is not a panel user",
            self::NotEloquent => "the declared reader guard [{$guard}] does not load an Eloquent model",
            self::PanelShaped => "the declared reader guard's model [{$model}] is scoped through membership, as a panel user is",
            self::NotOrgScoped => "the declared reader guard's model [{$model}] is not scoped to an organisation (#[OrgScoped] with EnforcesScope)",
        };
    }
}
