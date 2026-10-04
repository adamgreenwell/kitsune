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
use Kitsune\Core\Blueprints\FirstParty\MarketingSiteBlueprint;

/**
 * The Marketing Site at a version that is not the one an org has: 1.0.0 with one field more.
 *
 * ⚠️ A TEST'S 1.1.0, NOT A RELEASE. Until ADR-039's merge exists, a different version over a finished apply is refused
 * with nothing written — which is what this proves — and Marketing Site 1.1.0 is not released (Adam, 2026-10-04).
 */
final class MarketingSiteAtAnotherVersion implements BlueprintDefinition
{
    public function handle(): string
    {
        return 'marketing-site';
    }

    public function version(): string
    {
        return '1.1.0';
    }

    public function entryTypes(): array
    {
        return array_map(
            static fn (EntryTypeDeclaration $type): EntryTypeDeclaration => new EntryTypeDeclaration(
                handle: $type->handle,
                name: $type->name,
                pluralName: $type->pluralName,
                fields: [
                    ...$type->fields,
                    new FieldDeclaration(handle: 'page_meta', type: 'textarea', label: 'Meta description', piiClass: 'none', ordering: 30),
                ],
                icon: $type->icon,
                description: $type->description,
                ordering: $type->ordering,
                onCollision: $type->onCollision,
            ),
            (new MarketingSiteBlueprint)->entryTypes(),
        );
    }

    public function roles(): array
    {
        return (new MarketingSiteBlueprint)->roles();
    }
}
