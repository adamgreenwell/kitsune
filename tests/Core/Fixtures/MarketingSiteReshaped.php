<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Kitsune\Core\Blueprints\BlueprintDefinition;
use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;
use Kitsune\Core\Blueprints\Declarations\FieldDeclaration;

/**
 * A test's 1.2.0 that RESHAPES what 1.0.0 shipped: the test's 1.1.0, with `page_body` a textarea instead of rich text.
 *
 * ⚠️ NOT A RELEASE, AND NOTHING MAY EVER SHIP LIKE IT. A merge refuses it by name, and over a page an editor has saved
 * names the lock too — which is what this exists to prove, from the locked side.
 */
final class MarketingSiteReshaped implements BlueprintDefinition
{
    public function handle(): string
    {
        return 'marketing-site';
    }

    public function version(): string
    {
        return '1.2.0';
    }

    public function entryTypes(): array
    {
        return array_map(
            static fn (EntryTypeDeclaration $type): EntryTypeDeclaration => new EntryTypeDeclaration(
                handle: $type->handle,
                name: $type->name,
                pluralName: $type->pluralName,
                fields: array_map(
                    static fn (FieldDeclaration $field): FieldDeclaration => $field->handle !== 'page_body' ? $field : new FieldDeclaration(
                        handle: $field->handle,
                        type: 'textarea',
                        label: $field->label,
                        piiClass: $field->piiClass,
                        ordering: $field->ordering,
                    ),
                    $type->fields,
                ),
                icon: $type->icon,
                description: $type->description,
                ordering: $type->ordering,
                onCollision: $type->onCollision,
            ),
            (new MarketingSiteAtAnotherVersion)->entryTypes(),
        );
    }

    public function roles(): array
    {
        return (new MarketingSiteAtAnotherVersion)->roles();
    }
}
