<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Modules\ModuleManifest;
use Kitsune\Core\Modules\ModuleVerification;
use Kitsune\Core\Modules\ModuleVerifier;
use Kitsune\Core\Tests\Fixtures\Modules\Good\GoodThing;

/** The fixture modules are real directories of real classes, loaded by the real autoloader. */
function modulePath(string $name): string
{
    return dirname(__DIR__).'/Fixtures/Modules/'.$name;
}

function verifyFixture(string $name, array $scoping): ModuleVerification
{
    $manifest = ModuleManifest::from([
        'name' => 'fixture/'.strtolower($name),
        'autoload' => ['psr-4' => ['Kitsune\\Core\\Tests\\Fixtures\\Modules\\'.$name.'\\' => '']],
        'extra' => ['kitsune' => ['provider' => 'Fixture\\Provider', 'scoping' => $scoping]],
    ], 'fixture/'.strtolower($name));

    return ModuleVerifier::verify($manifest, modulePath($name));
}

/**
 * ⚠️ THE POSITIVE CONTROL. Everything below asserts a refusal, and a verifier that refused unconditionally
 * would pass all of them.
 */
it('accepts a module whose models declare and enforce what the manifest says', function (): void {
    $result = verifyFixture('Good', ['unscoped:global']);

    expect($result->refusal)->toBeNull()
        ->and($result->passed())->toBeTrue()
        ->and($result->models)->toBe([GoodThing::class])
        ->and($result->examined)->toContain(GoodThing::class);
});

/**
 * ⚠️ THE CASE THE WHOLE CLASS EXISTS FOR. `User` carried `#[Unscoped]` with no trait for two phases —
 * "labelled correctly and completely unconstrained" (AGENTS.md §2). A check living inside
 * `EnforcesScope::bootEnforcesScope()` would never run for this model, because that is the trait it omits.
 */
it('refuses a model that declares a scope and does not enforce it', function (): void {
    $result = verifyFixture('AttributeOnly', ['unscoped:global']);

    expect($result->refusal)->toContain('does not `use EnforcesScope`')
        ->and($result->refusal)->toContain('comment with syntax');

    /* The sweep really did load it — this refusal is not an empty walk reporting success-by-absence. */
    expect($result->models)->toHaveCount(1)
        ->and($result->examined)->not->toBeEmpty();
});

it('refuses a model that declares no scope at all', function (): void {
    $result = verifyFixture('Undeclared', ['unscoped:global']);

    expect($result->refusal)->toContain('declares no scope')
        ->and($result->examined)->not->toBeEmpty();
});

it('refuses a model whose scope the manifest does not declare', function (): void {
    $result = verifyFixture('Unlisted', ['unscoped:global']);

    expect($result->refusal)->toContain('which its manifest does not declare')
        ->and($result->models)->toHaveCount(1);
});

/**
 * ⚠️ BOTH DIRECTIONS. Covering every model is not enough: a module declaring `org` and shipping nothing
 * org-scoped states an unproven claim that reads as a checked one.
 */
it('refuses a declared scope that no model uses', function (): void {
    $result = verifyFixture('Good', ['unscoped:global', 'org']);

    expect($result->refusal)->toContain('ships no model that uses it')
        ->and($result->refusal)->toContain('`org`');
});

/**
 * ⚠️ A SKIP HERE WAS A REAL DEFECT ONCE. `ScopeDeclarationTest` guarded with `class_exists()` and skipped what
 * it could not load, "which made the skeleton half a silent no-op".
 */
it('refuses a file that does not define the class its path promises', function (): void {
    $result = verifyFixture('Mismatched', []);

    expect($result->refusal)->toContain('which does not define it')
        ->and($result->examined)->not->toBeEmpty();
});

it('refuses a PSR-4 root that is not a directory, rather than examining nothing', function (): void {
    $manifest = ModuleManifest::from([
        'name' => 'fixture/absent',
        'autoload' => ['psr-4' => ['Fixture\\Absent\\' => 'src']],
        'extra' => ['kitsune' => ['provider' => 'Fixture\\Provider', 'scoping' => []]],
    ], 'fixture/absent');

    $result = ModuleVerifier::verify($manifest, modulePath('Good'));

    expect($result->refusal)->toContain('is not a directory')
        ->and($result->refusal)->toContain('not the same as finding nothing wrong')
        ->and($result->examined)->toBeEmpty();
});

it('accepts a module that ships no models and declares no scopes', function (): void {
    $result = verifyFixture('NoModels', []);

    expect($result->refusal)->toBeNull()
        ->and($result->models)->toBe([])
        /* It examined a class; it simply was not a model. An empty sweep would be a different result. */
        ->and($result->examined)->not->toBeEmpty();
});

it('refuses a through-scope naming something that is not a model', function (): void {
    $result = verifyFixture('Good', ['unscoped:through(Fixture\\Nope)']);

    expect($result->refusal)->toContain('is not a model that exists');
});

it('accepts a through-scope naming a real model', function (): void {
    $result = verifyFixture('Good', ['unscoped:through('.GoodThing::class.')']);

    expect($result->refusal)->toBeNull();
});
