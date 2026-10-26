<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Blueprints\BlueprintDefinition;
use Kitsune\Core\Blueprints\Declarations\SeedEntryDeclaration;
use Kitsune\Core\Blueprints\DeclaresSeedContent;
use Kitsune\Core\Blueprints\OnCollision;

it('adds seed content through an optional capability while leaving the original four methods intact', function (): void {
    expect(interface_exists(DeclaresSeedContent::class))->toBeTrue();
    expect(is_subclass_of(DeclaresSeedContent::class, BlueprintDefinition::class))->toBeTrue();
    $base = array_map(static fn (ReflectionMethod $method): string => $method->getName(), (new ReflectionClass(BlueprintDefinition::class))->getMethods());
    sort($base);
    expect($base)->toBe(['entryTypes', 'handle', 'roles', 'version'])
        ->and((string) (new ReflectionMethod(DeclaresSeedContent::class, 'seedContent'))->getReturnType())->toBe('array');
});

it('declares only author seed keys and content rather than scope credentials or arbitrary row columns', function (): void {
    $shape = new ReflectionClass(SeedEntryDeclaration::class);
    expect($shape->isFinal())->toBeTrue()->and($shape->isReadOnly())->toBeTrue();
    expect(array_map(static fn (ReflectionParameter $p): string => $p->getName(), $shape->getConstructor()->getParameters()))
        ->toBe(['key', 'entryType', 'title', 'slug', 'values', 'relations', 'status', 'onCollision']);
    $seed = new SeedEntryDeclaration('welcome', 'page', 'Welcome');
    expect($seed->slug)->toBeNull()->and($seed->values)->toBe([])->and($seed->relations)->toBe([])
        ->and($seed->status)->toBe('draft')->and($seed->onCollision)->toBe(OnCollision::Fail);
});

it('keeps every frozen core release loadable without asking it for seed content', function (): void {
    $checked = 0;
    foreach (glob(__DIR__.'/../Fixtures/Released/*.php') ?: [] as $file) {
        $class = 'Kitsune\\Core\\Tests\\Fixtures\\Released\\'.basename($file, '.php');
        $definition = new $class;
        expect($definition)->toBeInstanceOf(BlueprintDefinition::class)
            ->and($definition instanceof DeclaresSeedContent)->toBeFalse()
            ->and(method_exists($definition, 'seedContent'))->toBeFalse();
        $checked++;
    }
    expect($checked)->toBe(4);
});

it('also leaves the separately released commerce blueprint loadable without seed content', function (): void {
    $definition = require __DIR__.'/../../Commerce/Fixtures/Released/Storefront100.php';
    expect($definition)->toBeInstanceOf(BlueprintDefinition::class)
        ->and($definition instanceof DeclaresSeedContent)->toBeFalse()
        ->and(method_exists($definition, 'seedContent'))->toBeFalse();
});
