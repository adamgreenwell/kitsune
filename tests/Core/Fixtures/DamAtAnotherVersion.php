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
use Kitsune\Core\Blueprints\FirstParty\DamBlueprint;

/**
 * The DAM at a version that is not the one an org has: 1.0.0 with a credit line — or, told to, with `asset` declared
 * ordinary, which no version may do.
 *
 * ⚠️ A TEST'S 1.1.0, NOT A RELEASE. It is what a later version of a media type's blueprint may add — a field on the media
 * type it created — and the one change no version may make, its media flag.
 */
final class DamAtAnotherVersion implements BlueprintDefinition
{
    public function __construct(private bool $media = true) {}

    public function handle(): string
    {
        return 'dam';
    }

    public function version(): string
    {
        return '1.1.0';
    }

    public function entryTypes(): array
    {
        [$asset] = (new DamBlueprint)->entryTypes();

        return [
            new EntryTypeDeclaration(
                handle: $asset->handle,
                name: $asset->name,
                pluralName: $asset->pluralName,
                fields: [
                    ...$asset->fields,
                    new FieldDeclaration(handle: 'asset_credit', type: 'text', label: 'Credit', piiClass: 'personal', ordering: 40),
                ],
                icon: $asset->icon,
                description: $asset->description,
                ordering: $asset->ordering,
                onCollision: $asset->onCollision,
                isMedia: $this->media,
            ),
        ];
    }

    public function roles(): array
    {
        return (new DamBlueprint)->roles();
    }
}
