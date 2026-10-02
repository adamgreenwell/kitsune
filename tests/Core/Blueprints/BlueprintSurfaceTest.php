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
use Kitsune\Core\Blueprints\Declarations\RoleDeclaration;
use Kitsune\Core\Blueprints\OnCollision;

/*
 * What a blueprint author may build on — ADR-039's public surface, and nothing past it.
 *
 * ⚠️ FIVE SYMBOLS, AND A SIXTH IS A DECISION. Everything else under `Blueprints/` is `@internal` on ADR-038's
 * precedent, Blog's own class included: its handle and what it creates are the contract, the class is not. A
 * symbol that loses the tag, or a new one that arrives without it, is a promise made without anybody deciding to.
 */

it('makes exactly five symbols public', function (): void {
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
        EntryTypeDeclaration::class,
        FieldDeclaration::class,
        RoleDeclaration::class,
        OnCollision::class,
    ]);
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
