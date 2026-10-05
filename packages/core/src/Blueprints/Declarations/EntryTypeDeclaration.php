<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Blueprints\Declarations;

use Kitsune\Core\Blueprints\OnCollision;

/**
 * One entry type a blueprint declares, with the fields it carries — ADR-039.
 *
 * ⚠️ NO `org_id`. A blueprint is applied INTO an org and takes its org from the apply, which is the axis
 * decision ADR-039 settles; a declaration that could name an org could name someone else's. And no global
 * (`org_id` NULL) option at all: a global type is visible to every org and its shape editable by none, which
 * is the opposite of what a blueprint is for.
 *
 * ⚠️ NO `settings`, THOUGH THE COLUMN EXISTS. `entry_types.settings` is published as "revisions on/off,
 * sluggable, publishable" in two documents ~~and is read by nothing — it is a cast on the model and nothing
 * else~~. Declaring it would be configuring behaviour that does not exist (AGENTS.md §14). ~~It arrives in the
 * format when something reads it.~~ One key is read now — `accepts`, the formats a media type takes (ADR-042
 * decision 33) — and it still does not arrive here: a declared media type accepts every format, as one made in the
 * admin does by default, and its owner narrows it on the type's page. It arrives as its own parameter when a
 * blueprint needs to narrow it — the Storefront's product images (ADR-040) are the expected first — never as
 * `settings`, whose other three published keys are still read by nothing.
 *
 * ⚠️ `isMedia` IS DECIDED HERE, ONCE (ADR-039, the DAM as built). The apply writes it when it creates the type and
 * never again: the model locks it (`EntryType::guardMediaFlag()`), a merge refuses a version that changes it either
 * way, and `Skip` refuses to adopt a type whose flag differs, because an adopted type keeps the flag it was created
 * with for good.
 *
 * ⚠️ NO `is_system`. Published as "undeletable" and enforced nowhere: `guardCascade()` never consults it.
 * ADR-038 declined to rely on it and used `org_id IS NULL` instead; a blueprint does not get to lean on it
 * either.
 */
final readonly class EntryTypeDeclaration
{
    /**
     * @param  string  $handle  lowercase; may not be one of `EntryType::RESERVED_HANDLES`, which the model
     *                          refuses at save because they collide with the admin's own route segments
     * @param  list<FieldDeclaration>  $fields
     * @param  OnCollision  $onCollision  defaults to Fail: a type belongs to one thing, so finding one already
     *                                    there means adopting somebody else's work under this blueprint's name
     * @param  bool  $isMedia  whether the type's entries are uploaded files (ADR-042 decision 1) — written when the
     *                         apply CREATES the type, never on one it adopts, and never changed after
     */
    public function __construct(
        public string $handle,
        public string $name,
        public string $pluralName,
        public array $fields = [],
        public ?string $icon = null,
        public ?string $description = null,
        public int $ordering = 0,
        public OnCollision $onCollision = OnCollision::Fail,
        public bool $isMedia = false,
    ) {}
}
