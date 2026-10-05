<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures\Manifests;

/**
 * Manifests exactly as core wrote them before `EntryTypeDeclaration` could declare a media type — ADR-039, the DAM as
 * built.
 *
 * ⚠️ CAPTURED, NOT WRITTEN. Each is what core at 09bbbd8 recorded for a frozen release applied into a fresh org, pasted
 * literally with only the ids parameterised, and NEVER EDITED: stage and every install tracking `main` hold receipts in
 * exactly this shape, so these are what a later core must still merge, finish and reverse. Outside `Released/`, so
 * `ReleasedBlueprintsTest`'s glob never meets them.
 */
final class BeforeMedia
{
    /**
     * Blog 1.0.0's manifest, as core wrote it before the format could declare a media type.
     *
     * @param  array<string, int>  $ids  each recorded type's and created role's id, by handle
     * @return array<string, mixed>
     */
    public static function blog100(array $ids): array
    {
        return [
            'version' => '1.0.0',
            'entry_types' => [
                [
                    'handle' => 'post',
                    'id' => $ids['post'],
                    'outcome' => 'created',
                    'name' => 'Post',
                    'plural_name' => 'Posts',
                    'icon' => 'heroicon-o-pencil-square',
                    'description' => 'Posts published on the blog.',
                    'ordering' => 10,
                    'on_collision' => 'fail',
                    'fields' => [
                        [
                            'handle' => 'post_body',
                            'outcome' => 'created',
                            'type' => 'rich_text',
                            'label' => 'Body',
                            'pii_class' => 'none',
                            'cardinality' => 1,
                            'is_indexed' => false,
                            'settings' => [],
                            'is_required' => false,
                            'help_text' => null,
                            'ordering' => 10,
                            'group' => null,
                        ],
                        [
                            'handle' => 'post_excerpt',
                            'outcome' => 'created',
                            'type' => 'textarea',
                            'label' => 'Excerpt',
                            'pii_class' => 'none',
                            'cardinality' => 1,
                            'is_indexed' => false,
                            'settings' => [],
                            'is_required' => false,
                            'help_text' => 'A sentence or two summing the post up, for wherever it is listed.',
                            'ordering' => 20,
                            'group' => null,
                        ],
                        [
                            'handle' => 'post_tags',
                            'outcome' => 'created',
                            'type' => 'relation',
                            'label' => 'Tags',
                            'pii_class' => 'none',
                            'cardinality' => -1,
                            'is_indexed' => false,
                            'settings' => [
                                'targetTypes' => [
                                    'tag',
                                ],
                            ],
                            'is_required' => false,
                            'help_text' => 'Choose from the tags under Tags.',
                            'ordering' => 30,
                            'group' => null,
                        ],
                    ],
                ],
                [
                    'handle' => 'tag',
                    'id' => $ids['tag'],
                    'outcome' => 'created',
                    'name' => 'Tag',
                    'plural_name' => 'Tags',
                    'icon' => 'heroicon-o-tag',
                    'description' => 'Topics a post can carry.',
                    'ordering' => 11,
                    'on_collision' => 'fail',
                    'fields' => [
                        [
                            'handle' => 'tag_description',
                            'outcome' => 'created',
                            'type' => 'textarea',
                            'label' => 'Description',
                            'pii_class' => 'none',
                            'cardinality' => 1,
                            'is_indexed' => false,
                            'settings' => [],
                            'is_required' => false,
                            'help_text' => 'What the posts carrying this tag are about.',
                            'ordering' => 10,
                            'group' => null,
                        ],
                    ],
                ],
            ],
            'roles' => [
                [
                    'handle' => 'blog_editor',
                    'id' => $ids['blog_editor'],
                    'outcome' => 'created',
                    'name' => 'Blog editor',
                    'on_collision' => 'fail',
                    'grants' => [
                        'entry.post.create',
                        'entry.post.delete',
                        'entry.post.publish',
                        'entry.post.update',
                        'entry.post.view',
                        'entry.tag.create',
                        'entry.tag.delete',
                        'entry.tag.publish',
                        'entry.tag.update',
                        'entry.tag.view',
                    ],
                ],
                [
                    'handle' => 'blog_writer',
                    'id' => $ids['blog_writer'],
                    'outcome' => 'created',
                    'name' => 'Blog writer',
                    'on_collision' => 'fail',
                    'grants' => [
                        'entry.post.create',
                        'entry.post.update',
                        'entry.post.view',
                        'entry.tag.view',
                    ],
                ],
            ],
            'outcome' => [
                'created' => [
                    'entry type post',
                    'field storage post_body',
                    'field storage post_excerpt',
                    'field storage post_tags',
                    'entry type tag',
                    'field storage tag_description',
                    'role blog_editor: entry.post.create, entry.post.delete, entry.post.publish, entry.post.update, entry.post.view, entry.tag.create, entry.tag.delete, entry.tag.publish, entry.tag.update, entry.tag.view',
                    'role blog_writer: entry.post.create, entry.post.update, entry.post.view, entry.tag.view',
                ],
                'adopted' => [],
                'skipped' => [],
            ],
        ];
    }

    /**
     * the Marketing Site 1.0.0's manifest, as core wrote it before the format could declare a media type.
     *
     * @param  array<string, int>  $ids  each recorded type's and created role's id, by handle
     * @return array<string, mixed>
     */
    public static function marketingSite100(array $ids): array
    {
        return [
            'version' => '1.0.0',
            'entry_types' => [
                [
                    'handle' => 'page',
                    'id' => $ids['page'],
                    'outcome' => 'created',
                    'name' => 'Page',
                    'plural_name' => 'Pages',
                    'icon' => 'heroicon-o-document',
                    'description' => 'The site\'s own pages, such as its home page and its about page.',
                    'ordering' => 5,
                    'on_collision' => 'fail',
                    'fields' => [
                        [
                            'handle' => 'page_body',
                            'outcome' => 'created',
                            'type' => 'rich_text',
                            'label' => 'Body',
                            'pii_class' => 'none',
                            'cardinality' => 1,
                            'is_indexed' => false,
                            'settings' => [],
                            'is_required' => false,
                            'help_text' => null,
                            'ordering' => 10,
                            'group' => null,
                        ],
                        [
                            'handle' => 'page_summary',
                            'outcome' => 'created',
                            'type' => 'textarea',
                            'label' => 'Summary',
                            'pii_class' => 'none',
                            'cardinality' => 1,
                            'is_indexed' => false,
                            'settings' => [],
                            'is_required' => false,
                            'help_text' => 'A sentence or two summing the page up, for wherever it is listed or linked.',
                            'ordering' => 20,
                            'group' => null,
                        ],
                    ],
                ],
            ],
            'roles' => [
                [
                    'handle' => 'marketing_editor',
                    'id' => $ids['marketing_editor'],
                    'outcome' => 'created',
                    'name' => 'Marketing editor',
                    'on_collision' => 'fail',
                    'grants' => [
                        'entry.page.create',
                        'entry.page.delete',
                        'entry.page.publish',
                        'entry.page.update',
                        'entry.page.view',
                    ],
                ],
                [
                    'handle' => 'marketing_writer',
                    'id' => $ids['marketing_writer'],
                    'outcome' => 'created',
                    'name' => 'Marketing writer',
                    'on_collision' => 'fail',
                    'grants' => [
                        'entry.page.create',
                        'entry.page.update',
                        'entry.page.view',
                    ],
                ],
            ],
            'outcome' => [
                'created' => [
                    'entry type page',
                    'field storage page_body',
                    'field storage page_summary',
                    'role marketing_editor: entry.page.create, entry.page.delete, entry.page.publish, entry.page.update, entry.page.view',
                    'role marketing_writer: entry.page.create, entry.page.update, entry.page.view',
                ],
                'adopted' => [],
                'skipped' => [],
            ],
        ];
    }

    /**
     * the Marketing Site 1.1.0's manifest, as core wrote it before the format could declare a media type.
     *
     * @param  array<string, int>  $ids  each recorded type's and created role's id, by handle
     * @return array<string, mixed>
     */
    public static function marketingSite110(array $ids): array
    {
        return [
            'version' => '1.1.0',
            'entry_types' => [
                [
                    'handle' => 'page',
                    'id' => $ids['page'],
                    'outcome' => 'created',
                    'name' => 'Page',
                    'plural_name' => 'Pages',
                    'icon' => 'heroicon-o-document',
                    'description' => 'The site\'s own pages, such as its home page and its about page.',
                    'ordering' => 5,
                    'on_collision' => 'fail',
                    'fields' => [
                        [
                            'handle' => 'page_body',
                            'outcome' => 'created',
                            'type' => 'rich_text',
                            'label' => 'Body',
                            'pii_class' => 'none',
                            'cardinality' => 1,
                            'is_indexed' => false,
                            'settings' => [],
                            'is_required' => false,
                            'help_text' => null,
                            'ordering' => 10,
                            'group' => null,
                        ],
                        [
                            'handle' => 'page_summary',
                            'outcome' => 'created',
                            'type' => 'textarea',
                            'label' => 'Summary',
                            'pii_class' => 'none',
                            'cardinality' => 1,
                            'is_indexed' => false,
                            'settings' => [],
                            'is_required' => false,
                            'help_text' => 'A sentence or two summing the page up, for wherever it is listed or linked.',
                            'ordering' => 20,
                            'group' => null,
                        ],
                        [
                            'handle' => 'page_meta',
                            'outcome' => 'created',
                            'type' => 'textarea',
                            'label' => 'Meta description',
                            'pii_class' => 'none',
                            'cardinality' => 1,
                            'is_indexed' => false,
                            'settings' => [],
                            'is_required' => false,
                            'help_text' => 'A sentence or two for search results and link previews.',
                            'ordering' => 30,
                            'group' => null,
                        ],
                    ],
                ],
            ],
            'roles' => [
                [
                    'handle' => 'marketing_editor',
                    'id' => $ids['marketing_editor'],
                    'outcome' => 'created',
                    'name' => 'Marketing editor',
                    'on_collision' => 'fail',
                    'grants' => [
                        'entry.page.create',
                        'entry.page.delete',
                        'entry.page.publish',
                        'entry.page.update',
                        'entry.page.view',
                    ],
                ],
                [
                    'handle' => 'marketing_writer',
                    'id' => $ids['marketing_writer'],
                    'outcome' => 'created',
                    'name' => 'Marketing writer',
                    'on_collision' => 'fail',
                    'grants' => [
                        'entry.page.create',
                        'entry.page.update',
                        'entry.page.view',
                    ],
                ],
            ],
            'outcome' => [
                'created' => [
                    'entry type page',
                    'field storage page_body',
                    'field storage page_summary',
                    'field storage page_meta',
                    'role marketing_editor: entry.page.create, entry.page.delete, entry.page.publish, entry.page.update, entry.page.view',
                    'role marketing_writer: entry.page.create, entry.page.update, entry.page.view',
                ],
                'adopted' => [],
                'skipped' => [],
            ],
        ];
    }
}
