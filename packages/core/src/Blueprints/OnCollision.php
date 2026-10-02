<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Blueprints;

/**
 * What a declaration does when the thing it declares is already there — ADR-039.
 *
 * ⚠️ PER DECLARATION, NOT PER APPLY, because the tables genuinely disagree about what a second write does and
 * one policy for all of them would be wrong somewhere. `Role::grant()` is a `firstOrCreate` and a no-op;
 * `EntryType::create()`, `FieldStorage::create()` and `Field::create()` each collide on a unique index;
 * `ModuleLifecycle::install()` throws outright. Drupal's Recipes reached the same shape from the same problem
 * — `create` errors if the thing exists, `createIfNotExists` skips — and it is the one part of that design
 * worth taking whole.
 *
 * ⚠️ THIS IS NOT WHAT MAKES RE-APPLY IDEMPOTENT. The receipt is: ~~a second apply reads what the first one
 * wrote and skips it~~ a second apply at the same version finds the receipt finished and does nothing; one whose
 * rows committed and whose finish did not run is finished, at the version it records; one that wrote no rows is
 * applied afresh; and a different version over a finished receipt is refused until ADR-039's merge exists — so an author
 * never has to choose `Skip` to get idempotence. This enum answers a different question — what to do about a row
 * **this blueprint did not create**, which is a collision with somebody else's work rather than with its own.
 */
enum OnCollision: string
{
    /**
     * Refuse, naming what is in the way. The default for anything a blueprint OWNS.
     *
     * An entry type belongs to one thing. Finding an `article` already in the org means either the operator
     * built one by hand or another blueprint did, and quietly adopting it would put this blueprint's fields on
     * a type it does not understand and record in the receipt that it created something it did not.
     */
    case Fail = 'fail';

    /**
     * Leave what is there and carry on, counting it as satisfied.
     *
     * For declarations an author is content to find already present — a role the org may reasonably have
     * defined itself, say. It is deliberately not the default: "it was already there" and "I made it" are
     * different facts, and the receipt records which.
     *
     * ⚠️ WHAT "LEAVE" MEANS DIFFERS BY WHAT IS DECLARED, and the difference is deliberate. An entry type is
     * adopted, and the declared fields are added to it. A role is left exactly as it is, grants included:
     * authority added to a role somebody else defined is a change no person made.
     */
    case Skip = 'skip';
}
