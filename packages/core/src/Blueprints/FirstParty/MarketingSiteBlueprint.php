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
 * Marketing Site — the second first-party blueprint, Phase 5 (ADR-039; Adam, 2026-10-04), and the one ADR-030 moves
 * kitsunecms.org onto.
 *
 * @internal First-party payload: the handle and what it creates are the contract; this class is not.
 *
 * ⚠️ IN CORE, NOT A PACKAGE, for Blog's reason: a module-shipped blueprint would need an install, an enable and a
 * fresh boot before its apply, which is more than the one command ADR-030 asks for.
 *
 * ⚠️ PAGES, AND NOTHING THE ADMIN OR THE FORMAT CANNOT YET CARRY. ADR-030's four conditions ask for an apply and an
 * admin, never a rendered page (ADR-039), and declaring what nothing reads yet would lock a shape before the code that
 * reads it exists. So: no seed pages — content is a separate, opt-in key that is not built, and the operator writing
 * the pages is ADR-030's fourth condition (Adam, 2026-10-04: ship it empty); no availability — a no-op on a one-site
 * install, and no admin screen undoes a row; no image — a fresh install has no media type ~~and a blueprint cannot yet
 * declare one~~ — a blueprint can now (ADR-039, the DAM as built), and a later version may add one; no parent page — a tree the admin does not have; no menu, no singleton home, no SEO fields ~~(1.0.0)~~
 * but the meta description 1.1.0 adds, no repeating sections — menus, routing and theming are v1.1, and v1.0 has no
 * repeater; no `slug` field — every entry has the
 * platform's slug already; no date — nothing writes `entries.published_at` yet. ~~Until ADR-039's merge exists,~~
 * Anything left out of 1.0.0 reaches an org that applied it through the admin, where its owner can add a field or a
 * type — or by a later version, which may only add (ADR-039, the merge as built).
 *
 * ⚠️ 1.1.0 ADDS ONE FIELD, `page_meta`, AND CHANGES NOTHING 1.0.0 SHIPPED (Adam, 2026-10-04). A merge only adds
 * (ADR-039), so an org at 1.0.0 gains the field and keeps every edit; `ReleasedBlueprintsTest` merges this class over
 * every frozen release under `tests/Core/Fixtures/Released/`, and a version that changed anything recorded would fail
 * there before it reached an org. A textarea with no settings, optional and not indexed: no DDL, every existing page
 * stays valid, and it is the widest shape, so a lock never forces it narrower. Nothing renders it until v1.1's theming.
 *
 * ⚠️ PREFIXED STORAGE HANDLES, as Blog's are: storage is shared across an org, and a seeded org has `body` and
 * `summary`. Handles beginning `page_` are this blueprint's; an operator adding fields by hand should choose another
 * prefix, because a later version that declares the same handle adopts it only if it is identical and refuses otherwise.
 *
 * ⚠️ EVERY FIELD `none`, for Blog's reason: a page is editorial content written for publication, and `personal` would
 * put `page` on the subject-identifier report for good with no field worth nominating. An operator can reclassify.
 *
 * ⚠️ `page` IS DECLARED WITH Fail, AND Skip COULD NEVER SUCCEED: both roles grant on it, and a grant on a type the
 * apply adopted rather than created is refused (ADR-039).
 *
 * ⚠️ A WRITER DRAFTS AND EDITS, AND MOVES NOTHING INTO PUBLISHED. Grants have no "own pages" dimension (ADR-033): a
 * writer edits every page, a published one included, and the edit is live; may take any page back to draft or archive
 * it; and cannot move one into Published, which is the editor's `publish`. The `marketing_` prefix names the blueprint,
 * as Blog's `blog_` does — not the type, ~~which a later version may add to~~ because a later version's new type gets
 * a role of its own: a merge never adds a grant to a role an earlier version created — and not "site", which is a
 * scoping level (Adam, 2026-10-04).
 */
final class MarketingSiteBlueprint implements BlueprintDefinition
{
    private const PAGE = 'page';

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
                handle: self::PAGE,
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
                    /* 1.1.0. */
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
                /* Before Blog's Posts (10) and Tags (11), after an operator's own types (0); the admin cannot change it. */
                ordering: 5,
            ),
        ];
    }

    public function roles(): array
    {
        return [
            new RoleDeclaration(handle: 'marketing_editor', name: 'Marketing editor', grants: [
                self::PAGE => ['view', 'create', 'update', 'delete', 'publish'],
            ]),
            new RoleDeclaration(handle: 'marketing_writer', name: 'Marketing writer', grants: [
                self::PAGE => ['view', 'create', 'update'],
            ]),
        ];
    }
}
