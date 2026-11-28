<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Kitsune\Core\Blueprints\BlueprintDefinition;
use Kitsune\Core\Blueprints\BlueprintRegistrar;
use Kitsune\Core\Blueprints\BlueprintRegistry;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Tests\Fixtures\FixtureBlueprint;

it('accepts one definition per handle and keeps the original object', function (): void {
    $registry = new BlueprintRegistry;
    $first = new FixtureBlueprint;
    $registry->register($first)->register($first);

    expect(fn () => $registry->register(new FixtureBlueprint))
        ->toThrow(RuntimeException::class, 'Two blueprint instances claim the handle [fixture]')
        ->and($registry->get('fixture'))->toBe($first)
        ->and($registry->all())->toBe(['fixture' => $first]);
});

it('lets a trusted provider register during boot and closes registration when boot completes', function (): void {
    $previous = Container::getInstance();
    $host = new Application;
    try {
        $host->singleton(BlueprintRegistry::class);
        $host->singleton(BlueprintRegistrar::class);
        $definition = new FixtureBlueprint;
        $host->register(new class($host, $definition) extends ServiceProvider
        {
            public function __construct(Application $app, private readonly BlueprintDefinition $definition)
            {
                parent::__construct($app);
            }

            public function boot(BlueprintRegistrar $registrar): void
            {
                $registrar->register($this->definition);
            }
        });
        $registry = $host->make(BlueprintRegistry::class);
        expect($registry->get('fixture'))->toBeNull();

        $host->boot();

        expect($registry->get('fixture'))->toBe($definition)
            ->and(fn () => $host->make(BlueprintRegistrar::class)->register($definition))
            ->toThrow(LogicException::class, 'during application bootstrap');
    } finally {
        Container::setInstance($previous);
    }
});

it('binds the registrar in Core and makes no changes for a late registration', function (): void {
    $registrar = app(BlueprintRegistrar::class);
    $registry = app(BlueprintRegistry::class);
    $prior = $registry->all();
    expect(fn () => $registrar->register(new FixtureBlueprint))->toThrow(LogicException::class)
        ->and($registry->all())->toBe($prior)
        ->and(Blueprint::count())->toBe(0);
});
