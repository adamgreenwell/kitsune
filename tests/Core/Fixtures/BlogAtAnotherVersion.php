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
use Kitsune\Core\Blueprints\Declarations\RoleDeclaration;
use Kitsune\Core\Tests\Fixtures\Released\Blog100;

/**
 * Blog at a version that is not the one an org has: 1.0.0 with a series type, a post's series, and a role for series.
 *
 * ⚠️ A TEST'S 1.1.0, NOT A RELEASE. It holds every kind of addition a merge writes, on a real blueprint: a new type, a
 * new relation field on a type 1.0.0 created that targets the type this same merge creates, and a new role granting on
 * the new type and on one 1.0.0 created — which, under ADR-039's answer to (a), is how new authority ships.
 */
final class BlogAtAnotherVersion implements BlueprintDefinition
{
    public function handle(): string
    {
        return 'blog';
    }

    public function version(): string
    {
        return '1.1.0';
    }

    public function entryTypes(): array
    {
        [$post, $tag] = (new Blog100)->entryTypes();

        return [
            new EntryTypeDeclaration(
                handle: $post->handle,
                name: $post->name,
                pluralName: $post->pluralName,
                fields: [
                    ...$post->fields,
                    new FieldDeclaration(
                        handle: 'post_series',
                        type: 'relation',
                        label: 'Series',
                        piiClass: 'none',
                        settings: ['targetTypes' => ['series']],
                        ordering: 40,
                    ),
                ],
                icon: $post->icon,
                description: $post->description,
                ordering: $post->ordering,
            ),
            $tag,
            new EntryTypeDeclaration(
                handle: 'series',
                name: 'Series',
                pluralName: 'Series',
                fields: [
                    new FieldDeclaration(handle: 'series_description', type: 'textarea', label: 'Description', piiClass: 'none', ordering: 10),
                ],
                ordering: 12,
            ),
        ];
    }

    public function roles(): array
    {
        return [
            ...(new Blog100)->roles(),
            new RoleDeclaration(handle: 'blog_series_editor', name: 'Blog series editor', grants: [
                'series' => ['view', 'create', 'update', 'delete', 'publish'],
                'post' => ['view'],
            ]),
        ];
    }
}
