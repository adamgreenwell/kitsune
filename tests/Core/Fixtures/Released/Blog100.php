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
 * Blog 1.0.0, AS RELEASED in #170 — written out literally, not derived from the shipped class.
 *
 * ⚠️ NEVER EDITED: A RELEASE ADDS A SIBLING. See `MarketingSite100` for why it is a copy.
 */
final class Blog100 implements BlueprintDefinition
{
    public function handle(): string
    {
        return 'blog';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function entryTypes(): array
    {
        return [
            new EntryTypeDeclaration(
                handle: 'post',
                name: 'Post',
                pluralName: 'Posts',
                fields: [
                    new FieldDeclaration(handle: 'post_body', type: 'rich_text', label: 'Body', piiClass: 'none', ordering: 10),
                    new FieldDeclaration(
                        handle: 'post_excerpt',
                        type: 'textarea',
                        label: 'Excerpt',
                        piiClass: 'none',
                        helpText: 'A sentence or two summing the post up, for wherever it is listed.',
                        ordering: 20,
                    ),
                    new FieldDeclaration(
                        handle: 'post_tags',
                        type: 'relation',
                        label: 'Tags',
                        piiClass: 'none',
                        cardinality: -1,
                        settings: ['targetTypes' => ['tag']],
                        helpText: 'Choose from the tags under Tags.',
                        ordering: 30,
                    ),
                ],
                icon: 'heroicon-o-pencil-square',
                description: 'Posts published on the blog.',
                ordering: 10,
            ),
            new EntryTypeDeclaration(
                handle: 'tag',
                name: 'Tag',
                pluralName: 'Tags',
                fields: [
                    new FieldDeclaration(
                        handle: 'tag_description',
                        type: 'textarea',
                        label: 'Description',
                        piiClass: 'none',
                        helpText: 'What the posts carrying this tag are about.',
                        ordering: 10,
                    ),
                ],
                icon: 'heroicon-o-tag',
                description: 'Topics a post can carry.',
                ordering: 11,
            ),
        ];
    }

    public function roles(): array
    {
        return [
            new RoleDeclaration(handle: 'blog_editor', name: 'Blog editor', grants: [
                'post' => ['view', 'create', 'update', 'delete', 'publish'],
                'tag' => ['view', 'create', 'update', 'delete', 'publish'],
            ]),
            new RoleDeclaration(handle: 'blog_writer', name: 'Blog writer', grants: [
                'post' => ['view', 'create', 'update'],
                'tag' => ['view'],
            ]),
        ];
    }
}
