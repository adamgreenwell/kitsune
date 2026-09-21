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
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Scopes\OrgScope;
use Kitsune\Core\Tests\Fixtures\Modules\Good\GoodThing;
use Kitsune\Core\Tests\Fixtures\Modules\InertBoot\Inert;

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

    expect($result->refusal)->toContain('PSR-4 says that file defines')
        ->and($result->refusal)->toContain('NotThePromisedName');
});

/**
 * ⚠️ THE BYPASS AN ATTACK PASS FOUND, AND THE REASON THE THIRD QUESTION EXISTS. A class-body method beats a
 * trait method, so a model may `use EnforcesScope` and override `bootEnforcesScope()` with an empty body: the
 * attribute is present, `class_uses_recursive()` reports the trait, and no global scope is ever registered.
 * Measured at the SQL level before the fix — the honest control emitted `… 1 = 0` with no org context, this
 * one emitted a bare select across every org's rows.
 */
it('refuses a model that carries the trait and neuters its boot', function (): void {
    $result = verifyFixture('InertBoot', ['org']);

    expect($result->refusal)->toContain('does not register')
        ->and($result->refusal)->toContain('OrgScope')
        ->and($result->refusal)->toContain('bootEnforcesScope');

    /* The two older questions both still answer yes, which is exactly why they were not enough. */
    $model = Inert::class;

    expect(class_uses_recursive($model))->toContain(EnforcesScope::class)
        ->and((new ReflectionClass($model))->getAttributes(OrgScoped::class))->not->toBeEmpty()
        ->and(array_keys((new $model)->getGlobalScopes()))->not->toContain(OrgScope::class);
});

/**
 * ⚠️ A SECOND CLASS IN ONE FILE WAS NEVER NAMED, EXAMINED OR REFUSED — and the sweep's own `class_exists()`
 * on the promised class is what defined it. Reading the file rather than loading it is what closes this.
 */
it('refuses a file that smuggles a second class past PSR-4', function (): void {
    $result = verifyFixture('TwoInOne', ['unscoped:global']);

    expect($result->refusal)->toContain('Stowaway')
        ->and($result->refusal)->toContain('and nothing else');
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

/**
 * ⚠️ THE FLOOR, ASSERTED AS A PROPERTY RATHER THAN PER FIXTURE. `examined` was documented as existing so a
 * refusal could be told from a walk that never ran, and nothing consulted it — five different manifest shapes
 * passed having looked at zero classes. A pass must now mean something was looked at, whatever the shape.
 */
it('never passes having examined nothing', function (string $name, array $scoping): void {
    $result = verifyFixture($name, $scoping);

    /*
     * Asserted as an implication rather than behind an `if`, so the test always performs an assertion. A
     * conditional expectation passes silently for every fixture that takes the other branch, which is the
     * vacuity this file exists to avoid — Pest flags it as risky, and it is right to.
     */
    expect($result->passed() === false || $result->examined !== [])
        ->toBeTrue("{$name} passed with an empty sweep");
})->with([
    'good' => ['Good', ['unscoped:global']],
    'no models' => ['NoModels', []],
    'attribute only' => ['AttributeOnly', ['unscoped:global']],
    'two in one' => ['TwoInOne', ['unscoped:global']],
]);

it('refuses an empty but existing root rather than passing on nothing', function (): void {
    $empty = sys_get_temp_dir().'/kitsune-empty-module-'.bin2hex(random_bytes(6));
    mkdir($empty.'/src', 0o777, true);

    try {
        $manifest = ModuleManifest::from([
            'name' => 'fixture/empty',
            'autoload' => ['psr-4' => ['Fixture\\Empty\\' => 'src']],
            'extra' => ['kitsune' => ['provider' => 'Fixture\\Provider', 'scoping' => []]],
        ], 'fixture/empty');

        $result = ModuleVerifier::verify($manifest, $empty);

        expect($result->passed())->toBeFalse()
            ->and($result->refusal)->toContain('examined no classes at all');
    } finally {
        @rmdir($empty.'/src');
        @rmdir($empty);
    }
});
