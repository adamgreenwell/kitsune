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
 * sluggable, publishable" in two documents and is read by nothing — it is a cast on the model and nothing
 * else. Declaring it would be configuring behaviour that does not exist (AGENTS.md §14). It arrives in the
 * format when something reads it.
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
    ) {}
}
