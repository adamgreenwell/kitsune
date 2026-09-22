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
use Kitsune\Core\Blueprints\OnCollision;

/**
 * A blueprint definition the tests can vary — the mechanism's stand-in for Blog.
 *
 * Mutable statics rather than constructor arguments because a test wants to change ONE thing about a
 * definition and apply it again, which is what an upgrade and a collision both look like.
 */
final class FixtureBlueprint implements BlueprintDefinition
{
    public static string $version = '1.0.0';

    public static OnCollision $onCollision = OnCollision::Fail;

    public static string $piiClass = 'none';

    public static bool $indexed = false;

    /** @var list<EntryTypeDeclaration>|null */
    public static ?array $override = null;

    public static function reset(): void
    {
        self::$version = '1.0.0';
        self::$onCollision = OnCollision::Fail;
        self::$piiClass = 'none';
        self::$indexed = false;
        self::$override = null;
    }

    public function handle(): string
    {
        return 'fixture';
    }

    public function version(): string
    {
        return self::$version;
    }

    public function entryTypes(): array
    {
        if (self::$override !== null) {
            return self::$override;
        }

        return [
            new EntryTypeDeclaration(
                handle: 'dispatch',
                name: 'Dispatch',
                pluralName: 'Dispatches',
                fields: [
                    new FieldDeclaration(
                        handle: 'dispatch_body',
                        type: 'textarea',
                        label: 'Body',
                        piiClass: self::$piiClass,
                        isIndexed: self::$indexed,
                    ),
                ],
                onCollision: self::$onCollision,
            ),
        ];
    }
}
