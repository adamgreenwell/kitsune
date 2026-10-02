<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Blueprints\FirstParty;

use Kitsune\Core\Blueprints\BlueprintDefinition;
use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;
use Kitsune\Core\Blueprints\Declarations\FieldDeclaration;
use Kitsune\Core\Blueprints\Declarations\RoleDeclaration;

/**
 * Blog — the first first-party blueprint, Phase 5 (ADR-039; Adam, 2026-10-02).
 *
 * @internal First-party payload: the handle and what it creates are the contract; this class is not.
 *
 * ⚠️ IN CORE, NOT A PACKAGE. ADR-039 rejected a Composer package per blueprint and lets the payload ship inside
 * `kitsune/core`; a module-shipped blueprint would need an install, an enable and a fresh boot before its apply,
 * which is more than the one command Phase 5 is measured by.
 *
 * ⚠️ POSTS AND TAGS, AND NOTHING THE ADMIN CANNOT YET CARRY (Adam, 2026-10-02: the lean set). No date field —
 * `entries.published_at` is the platform's column for one (field-types.md §2), though neither the admin nor
 * publishing writes it yet, so a post has no publication date until core does: a gap in core, not one to paper over
 * here with a second date; no featured image — a fresh install has no media type and a blueprint cannot yet declare
 * one; no byline type — it would hold personal data with no subject to nominate, and users are not entries; no
 * `page`, which is the Marketing Site's; no category, which wants a tree the admin does not have; and no SEO fields,
 * which nothing renders until v1.1.
 *
 * ⚠️ PREFIXED STORAGE HANDLES. Storage is shared across an org, and a seeded org already has `body` and
 * `summary`; `person` and the fixture prefix theirs for the same reason.
 *
 * ⚠️ EVERY FIELD `none`. A post is editorial content written for publication, as the seeded `article` body is.
 * `personal` would put `post` on the subject-identifier report for good, with no field worth nominating — the
 * warning fatigue that column was made three-state to avoid. An operator can reclassify: `pii_class` is not a
 * shape attribute.
 *
 * ⚠️ A WRITER DRAFTS AND EDITS, AND MOVES NOTHING INTO PUBLISHED. Kitsune's grants have no "own posts" dimension
 * (ADR-033), so a writer who publishes only their own work cannot be expressed. What `update` does give is wider than
 * a Contributor's: a writer edits every post, a published one included — the edit is live, and the post stays
 * published — and may take any post back to draft or archive it; what a writer cannot do is move a post into
 * Published, which is `publish`, the editor's. The `blog_` prefix keeps both roles clear of an org's own `editor`
 * and of other blueprints'.
 */
final class BlogBlueprint implements BlueprintDefinition
{
    private const POST = 'post';

    private const TAG = 'tag';

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
                handle: self::POST,
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
                        settings: ['targetTypes' => [self::TAG]],
                        helpText: 'Choose from the tags under Tags.',
                        ordering: 30,
                    ),
                ],
                icon: 'heroicon-o-pencil-square',
                description: 'Posts published on the blog.',
                ordering: 10,
            ),
            new EntryTypeDeclaration(
                handle: self::TAG,
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
                self::POST => ['view', 'create', 'update', 'delete', 'publish'],
                self::TAG => ['view', 'create', 'update', 'delete', 'publish'],
            ]),
            new RoleDeclaration(handle: 'blog_writer', name: 'Blog writer', grants: [
                self::POST => ['view', 'create', 'update'],
                self::TAG => ['view'],
            ]),
        ];
    }
}
