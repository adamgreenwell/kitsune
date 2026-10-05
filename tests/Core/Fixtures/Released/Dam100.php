<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures\Released;

use Kitsune\Core\Blueprints\BlueprintDefinition;
use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;
use Kitsune\Core\Blueprints\Declarations\FieldDeclaration;
use Kitsune\Core\Blueprints\Declarations\RoleDeclaration;

/**
 * DAM 1.0.0, AS RELEASED — written out literally, not derived from the shipped class.
 *
 * ⚠️ NEVER EDITED: A RELEASE ADDS A SIBLING. See `MarketingSite100` for why it is a copy.
 */
final class Dam100 implements BlueprintDefinition
{
    public function handle(): string
    {
        return 'dam';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function entryTypes(): array
    {
        return [
            new EntryTypeDeclaration(
                handle: 'asset',
                name: 'Asset',
                pluralName: 'Assets',
                fields: [
                    new FieldDeclaration(
                        handle: 'asset_rights_holder',
                        type: 'text',
                        label: 'Rights holder',
                        piiClass: 'personal',
                        helpText: 'Who owns the rights to this file: a photographer, an agency, or this organisation.',
                        ordering: 10,
                    ),
                    new FieldDeclaration(
                        handle: 'asset_licence',
                        type: 'textarea',
                        label: 'Licence',
                        piiClass: 'none',
                        helpText: 'The licence this file is used under, and anything it rules out — for example "CC BY 4.0", '
                            .'or "editorial use only, not in print".',
                        ordering: 20,
                    ),
                    new FieldDeclaration(
                        handle: 'asset_licence_expires',
                        type: 'date',
                        label: 'Licence expires',
                        piiClass: 'none',
                        helpText: 'The last day this file may be used. Leave it empty if the licence does not end. Kitsune '
                            .'does not act on this date: nothing is hidden or withdrawn when it passes.',
                        ordering: 30,
                    ),
                ],
                icon: 'heroicon-o-archive-box',
                description: 'Files the organisation keeps, with who holds the rights to each and the licence it is used under.',
                ordering: 20,
                isMedia: true,
            ),
        ];
    }

    public function roles(): array
    {
        return [
            new RoleDeclaration(handle: 'dam_manager', name: 'Asset manager', grants: [
                'asset' => ['view', 'create', 'update', 'delete', 'publish'],
            ]),
            new RoleDeclaration(handle: 'dam_contributor', name: 'Asset contributor', grants: [
                'asset' => ['view', 'create', 'update', 'publish'],
            ]),
            new RoleDeclaration(handle: 'dam_viewer', name: 'Asset viewer', grants: [
                'asset' => ['view'],
            ]),
        ];
    }
}
