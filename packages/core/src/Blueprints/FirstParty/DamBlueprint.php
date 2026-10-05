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
 * DAM — the third first-party blueprint, Phase 5 (ADR-039, the DAM as built; Adam, 2026-10-05): a library of files with
 * who holds their rights and the licence each is used under.
 *
 * @internal First-party payload: the handle and what it creates are the contract; this class is not.
 *
 * ⚠️ IN CORE, NOT A PACKAGE, for Blog's reason: a module-shipped blueprint would need an install, an enable and a fresh
 * boot before its apply. And a fresh production install has no media type at all, so this is the first one-command
 * route to an upload.
 *
 * ⚠️ ONE LIBRARY, EVERY FORMAT (Adam, 2026-10-05). `asset` is one media type, declared with `isMedia` and naming no
 * formats, so it takes every format Kitsune stores — one added later included — as a type made in the admin does by
 * default. Decision 33 made "which files" a property of one type rather than a reason for several: one list, one set of
 * grants, one set of storage. Its owner narrows the formats on the type's page; SVG is still refused until a sanitiser
 * is installed (ADR-041), whatever the type says.
 *
 * ⚠️ AND NOTHING THE ADMIN CANNOT YET SHOW. No alt text or caption — a shared file holds one value for every site, so
 * one language across an org's French and English sites, and nothing renders either before v1.1's theming. No separate
 * credit line — it is usually the rights holder. No licence vocabulary — a shipped select's option keys are permanent,
 * and nothing in v1.0 filters a list by a field. Nothing required — an upload writes no field values, so a required
 * field would make every upload invalid on its own page. Nothing indexed — no DDL. No group — nothing renders one. No
 * second type, no seed content, no availability. A later version may add a field or a type, and may only add
 * (ADR-039, the merge as built).
 *
 * ⚠️ RIGHTS HOLDER IS `personal`, AND WHAT THAT PUTS ON THE REPORT (Adam, 2026-10-05). A rights holder is often a named
 * photographer, and a person's name is ADR-020's own example. Until the owner chooses Rights holder as the type's
 * subject identifier — one choice on the type's page — `asset` is on the subject-identifier report and the type list
 * says the subject is missing; after it, a photographer's access request finds every file crediting them. The licence
 * and its expiry are contract terms, `none`. An owner can reclassify any of them.
 *
 * ⚠️ `asset` IS DECLARED WITH Fail, AND Skip COULD NEVER SUCCEED: every role grants on it, and a grant on a type the
 * apply adopted rather than created is refused (ADR-039). It cannot be `image`: every seeded installation has a global
 * `image`, and a global handle is refused under any policy.
 *
 * ⚠️ A CONTRIBUTOR CAN MAKE FILES PUBLIC. Uploading needs `create` and `publish` (ADR-042 decision 3), and grants have no
 * "own files" dimension (ADR-033), so a contributor can make any of the org's shared files public, at every site —
 * asked to acknowledge it, and audited (decision 32). Only a manager deletes, restores and deletes forever. A viewer
 * browses and downloads, private files included: private means behind sign-in and `view`. A writer of Blog or the
 * Marketing Site needs Asset viewer beside their own role to pick an asset in a relation field an owner adds.
 *
 * ⚠️ NOTHING ACTS ON A DATE. Licence expiry is recorded, and nothing hides, withdraws or warns when it passes: that would
 * need background work the floor allows only as cron an installation may not run (ADR-027), or a check at every read,
 * and v1.0 builds neither. Its help text says so.
 *
 * ⚠️ PREFIXED HANDLES, AND NO MODULE CLAIMS `asset` GLOBALLY. Roles begin `dam_`, for the blueprint, as `blog_` and
 * `marketing_` do; storage begins `asset_`, for the type, as `post_` and `page_` do — storage is shared across an org,
 * and an operator adding fields by hand should choose another prefix. A first-party module that created a global `asset`
 * would make this blueprint unapplicable wherever it is installed, and let an org's `dam_*` grants reach its entries.
 */
final class DamBlueprint implements BlueprintDefinition
{
    private const ASSET = 'asset';

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
                handle: self::ASSET,
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
                /* After the content that uses it — Pages (5), Posts (10), Tags (11); the admin cannot change it. */
                ordering: 20,
                isMedia: true,
            ),
        ];
    }

    public function roles(): array
    {
        return [
            new RoleDeclaration(handle: 'dam_manager', name: 'Asset manager', grants: [
                self::ASSET => ['view', 'create', 'update', 'delete', 'publish'],
            ]),
            new RoleDeclaration(handle: 'dam_contributor', name: 'Asset contributor', grants: [
                self::ASSET => ['view', 'create', 'update', 'publish'],
            ]),
            new RoleDeclaration(handle: 'dam_viewer', name: 'Asset viewer', grants: [
                self::ASSET => ['view'],
            ]),
        ];
    }
}
