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
 * Marketing Site 1.1.0, AS RELEASED — 1.0.0 with `page_meta` — written out literally, not derived from the shipped class.
 *
 * ⚠️ NEVER EDITED: A RELEASE ADDS A SIBLING. See `MarketingSite100` for why it is a copy.
 */
final class MarketingSite110 implements BlueprintDefinition
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
        return [
            new EntryTypeDeclaration(
                handle: 'page',
                name: 'Page',
                pluralName: 'Pages',
                fields: [
                    new FieldDeclaration(handle: 'page_body', type: 'rich_text', label: 'Body', piiClass: 'none', ordering: 10),
                    new FieldDeclaration(
                        handle: 'page_summary',
                        type: 'textarea',
                        label: 'Summary',
                        piiClass: 'none',
                        helpText: 'A sentence or two summing the page up, for wherever it is listed or linked.',
                        ordering: 20,
                    ),
                    new FieldDeclaration(
                        handle: 'page_meta',
                        type: 'textarea',
                        label: 'Meta description',
                        piiClass: 'none',
                        helpText: 'A sentence or two for search results and link previews.',
                        ordering: 30,
                    ),
                ],
                icon: 'heroicon-o-document',
                description: 'The site\'s own pages, such as its home page and its about page.',
                ordering: 5,
            ),
        ];
    }

    public function roles(): array
    {
        return [
            new RoleDeclaration(handle: 'marketing_editor', name: 'Marketing editor', grants: [
                'page' => ['view', 'create', 'update', 'delete', 'publish'],
            ]),
            new RoleDeclaration(handle: 'marketing_writer', name: 'Marketing writer', grants: [
                'page' => ['view', 'create', 'update'],
            ]),
        ];
    }
}
