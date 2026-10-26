<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Blueprints\BlueprintDefinition;
use Kitsune\Core\Blueprints\Declarations\AvailabilityDeclaration;
use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;
use Kitsune\Core\Blueprints\Declarations\FieldDeclaration;
use Kitsune\Core\Blueprints\Declarations\RoleDeclaration;
use Kitsune\Core\Blueprints\DeclaresAvailability;
use Kitsune\Core\Blueprints\OnCollision;

/*
 * What a blueprint author may build on — ADR-039's public surface, and nothing past it.
 *
 * ⚠️ SEVEN SYMBOLS: ADR-039's availability amendment explicitly adds the optional capability and its declaration.
 * Everything else under `Blueprints/` is `@internal` on ADR-038's
 * precedent, Blog's own class included: its handle and what it creates are the contract, the class is not. A
 * symbol that loses the tag, or a new one that arrives without it, is a promise made without anybody deciding to.
 */

it('makes exactly the seven decided author symbols public', function (): void {
    $root = dirname(__DIR__, 3).'/packages/core/src/Blueprints';
    $public = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($root) + 1, -4);
        $symbol = 'Kitsune\\Core\\Blueprints\\'.str_replace('/', '\\', $relative);

        expect(class_exists($symbol) || interface_exists($symbol) || enum_exists($symbol))->toBeTrue($symbol);

        if (! str_contains((string) (new ReflectionClass($symbol))->getDocComment(), '@internal')) {
            $public[] = $symbol;
        }
    }

    sort($public);

    expect($public)->toBe([
        BlueprintDefinition::class,
        AvailabilityDeclaration::class,
        EntryTypeDeclaration::class,
        FieldDeclaration::class,
        RoleDeclaration::class,
        DeclaresAvailability::class,
        OnCollision::class,
    ]);
});

it('keeps the legacy definition required methods and types the optional availability capability', function (): void {
    expect(array_map(fn (ReflectionMethod $method): string => $method->getName(), (new ReflectionClass(BlueprintDefinition::class))->getMethods()))
        ->toBe(['handle', 'version', 'entryTypes', 'roles'])
        ->and(is_subclass_of(DeclaresAvailability::class, BlueprintDefinition::class))->toBeTrue();
    $own = array_values(array_filter((new ReflectionClass(DeclaresAvailability::class))->getMethods(), fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === DeclaresAvailability::class));
    expect(array_map(fn (ReflectionMethod $method): string => $method->getName(), $own))->toBe(['availability']);
});

it('declares availability by type handle, sparse scope, org-local target, value and collision policy alone', function (): void {
    $reflection = new ReflectionClass(AvailabilityDeclaration::class);
    expect(array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(), $reflection->getConstructor()->getParameters()))
        ->toBe(['entryType', 'scope', 'target', 'isEnabled', 'onCollision'])
        ->and($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();
});

/** ⚠️ A ROLE DECLARATION CARRIES NO OWNER FLAG, NO ORG AND NO HOLDERS — so none of them can arrive unnoticed. */
it('declares a role by handle, name, grants and collision policy alone', function (): void {
    $parameters = array_map(
        fn (ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionMethod(RoleDeclaration::class, '__construct'))->getParameters(),
    );

    expect($parameters)->toBe(['handle', 'name', 'grants', 'onCollision'])
        ->and((new ReflectionClass(RoleDeclaration::class))->isFinal())->toBeTrue()
        ->and((new ReflectionClass(RoleDeclaration::class))->isReadOnly())->toBeTrue();
});

/** ⚠️ NO ORG, GENERAL SETTINGS, SYSTEM FLAG OR SUBJECT — media and formats arrived separately by decision. */
it('declares an entry type by these parameters alone, media off unless declared', function (): void {
    $constructor = new ReflectionMethod(EntryTypeDeclaration::class, '__construct');

    expect(array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(), $constructor->getParameters()))
        ->toBe(['handle', 'name', 'pluralName', 'fields', 'icon', 'description', 'ordering', 'onCollision', 'isMedia', 'accepts'])
        ->and($constructor->getParameters()[8]->getDefaultValue())->toBeFalse()
        ->and($constructor->getParameters()[9]->getDefaultValue())->toBeNull()
        ->and((new ReflectionClass(EntryTypeDeclaration::class))->isFinal())->toBeTrue()
        ->and((new ReflectionClass(EntryTypeDeclaration::class))->isReadOnly())->toBeTrue();
});

/** ⚠️ A FIELD CARRIES NO DEFAULT CLASSIFICATION AND NOTHING NEW — its next parameter is a decision too. */
it('declares a field by these parameters alone', function (): void {
    expect(array_map(
        fn (ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionMethod(FieldDeclaration::class, '__construct'))->getParameters(),
    ))->toBe(['handle', 'type', 'label', 'piiClass', 'cardinality', 'settings', 'isIndexed', 'isRequired', 'helpText', 'ordering', 'group']);
});
