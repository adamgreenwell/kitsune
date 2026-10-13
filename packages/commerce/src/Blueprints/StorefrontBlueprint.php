<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Blueprints;

use Kitsune\Core\Blueprints\BlueprintDefinition;
use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;
use Kitsune\Core\Blueprints\Declarations\FieldDeclaration;

/**
 * Storefront's adaptable content schema (ADR-039 and ADR-040); commerce records are never schema fields.
 *
 * Title and slug are the entry's own columns. Each site's entry owns its description and image choices;
 * the uploaded image bytes follow core's existing org-shared media custody. Drafts need neither field yet.
 * No roles are invented: owners already have authority, and an org grants ordinary entry actions explicitly.
 *
 * The v1.0.0 format list is frozen with this definition. An owner may adapt it; a future supported format does
 * not silently change the declaration under an already-applied version. SVG still requires its sanitizer.
 * A reverse removes only unused blueprint configuration, never sellables, listings, currency or site content.
 *
 * @internal First-party payload: its handle and declared schema are the contract; this class is not.
 */
final class StorefrontBlueprint implements BlueprintDefinition
{
    public function handle(): string
    {
        return 'storefront';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function entryTypes(): array
    {
        return [
            new EntryTypeDeclaration(
                handle: 'product',
                name: 'Product content',
                pluralName: 'Product content',
                fields: [
                    new FieldDeclaration(
                        handle: 'product_description',
                        type: 'rich_text',
                        label: 'Description',
                        piiClass: 'none',
                        ordering: 10,
                    ),
                    new FieldDeclaration(
                        handle: 'product_images',
                        type: 'relation',
                        label: 'Images',
                        piiClass: 'none',
                        cardinality: -1,
                        settings: ['targetTypes' => ['product_image']],
                        helpText: 'Choose images from Product images. This selection belongs to this site\'s product content.',
                        ordering: 20,
                    ),
                ],
                icon: 'heroicon-o-shopping-bag',
                description: 'Site-specific product content, connected to shared prices and stock through a listing.',
                ordering: 30,
            ),
            new EntryTypeDeclaration(
                handle: 'product_image',
                name: 'Product image',
                pluralName: 'Product images',
                icon: 'heroicon-o-photo',
                description: 'Images an organisation can choose separately for each site\'s product content.',
                ordering: 31,
                isMedia: true,
                accepts: ['jpeg', 'png', 'gif', 'webp', 'avif', 'svg'],
            ),
        ];
    }

    public function roles(): array
    {
        return [];
    }
}
