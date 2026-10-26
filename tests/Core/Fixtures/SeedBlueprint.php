<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;
use Kitsune\Core\Blueprints\Declarations\FieldDeclaration;
use Kitsune\Core\Blueprints\Declarations\SeedEntryDeclaration;
use Kitsune\Core\Blueprints\DeclaresSeedContent;

final class SeedBlueprint implements DeclaresSeedContent
{
    /**
     * @param  list<SeedEntryDeclaration>  $seeds
     * @param  list<EntryTypeDeclaration>|null  $types
     */
    public function __construct(private array $seeds, private string $revision = '1.0.0', private ?array $types = null) {}

    public function handle(): string
    {
        return 'seed_test';
    }

    public function version(): string
    {
        return $this->revision;
    }

    public function entryTypes(): array
    {
        return $this->types ?? [new EntryTypeDeclaration('seed_article', 'Article', 'Articles', fields: [
            new FieldDeclaration('seed_body', 'text', 'Body', 'none'),
            new FieldDeclaration('seed_related', 'relation', 'Related', 'none', cardinality: -1),
        ])];
    }

    public function roles(): array
    {
        return [];
    }

    public function seedContent(): array
    {
        return $this->seeds;
    }
}
