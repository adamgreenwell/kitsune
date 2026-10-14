<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Blueprints\BlueprintDefinition;
use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;
use Kitsune\Core\Blueprints\Declarations\FieldDeclaration;

/** Frozen Storefront 1.0.0 baseline; do not edit when the shipped definition grows. */
return new class implements BlueprintDefinition
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
};
