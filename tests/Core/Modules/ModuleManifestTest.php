<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Modules\ModuleManifest;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Attributes\OrgScopedThroughPivot;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Attributes\Unscoped;

/**
 * @return array<string, mixed>
 */
function manifest(array $kitsune = ['provider' => 'Acme\\Provider', 'scoping' => ['unscoped:global']], array $extra = []): array
{
    return [
        'name' => 'acme/thing',
        'autoload' => ['psr-4' => ['Acme\\' => 'src/']],
        'extra' => ['kitsune' => $kitsune] + $extra,
    ];
}

/**
 * ⚠️ THE POSITIVE CONTROL, AND IT IS NOT DECORATION. Every assertion below is that some manifest is REFUSED, and
 * a `refusalFor()` that returned a string unconditionally would pass all of them. This is the one test that
 * fails if the grammar refuses everything.
 */
it('accepts a manifest that declares a provider and a scope', function (): void {
    expect(ModuleManifest::refusalFor(manifest(), 'acme/thing'))->toBeNull();

    $parsed = ModuleManifest::from(manifest(), 'acme/thing');

    expect($parsed->package)->toBe('acme/thing')
        ->and($parsed->provider)->toBe('Acme\\Provider')
        ->and($parsed->scoping)->toBe(['unscoped:global'])
        /* Derived from the package's own autoload block rather than declared twice — and it keeps the PATHS,
         * because the sweep has to look where Composer actually loads from. */
        ->and($parsed->psr4)->toBe(['Acme\\' => ['src/']]);
});

it('accepts an empty scoping list, because a module may ship no models at all', function (): void {
    /*
     * The distinction the grammar turns on: `[]` says "nothing of mine is scoped", absence says nothing.
     * `array_key_exists` rather than `?? []` is what keeps those two apart — a `??` would silently read
     * absence as the empty declaration, which is the permissive reading of the one key the kernel exists to
     * demand.
     */
    expect(ModuleManifest::refusalFor(manifest(['provider' => 'Acme\\Provider', 'scoping' => []]), 'acme/thing'))
        ->toBeNull();
});

it('refuses a package with no kitsune block', function (): void {
    expect(ModuleManifest::refusalFor(['name' => 'acme/thing'], 'acme/thing'))
        ->toContain('declares no `extra.kitsune` block');
});

it('refuses a manifest that declares no scoping', function (): void {
    expect(ModuleManifest::refusalFor(manifest(['provider' => 'Acme\\Provider']), 'acme/thing'))
        ->toContain('declares no `scoping`');
});

it('refuses a manifest that names no provider', function (): void {
    expect(ModuleManifest::refusalFor(manifest(['scoping' => ['org']]), 'acme/thing'))
        ->toContain('names no `provider`');
});

it('refuses a scope outside the vocabulary', function (): void {
    expect(ModuleManifest::refusalFor(manifest(['provider' => 'P', 'scoping' => ['aware']]), 'acme/thing'))
        ->toContain('declares the scope `aware`');
});

/**
 * ⚠️ THE REASON `unscoped` IS NOT A WORD ON ITS OWN (ADR-038). Bare `unscoped` would be satisfied by core's own
 * commonest table shape — `entry_types` and `field_storage` carry a nullable `org_id` while declaring
 * `#[Unscoped]` — so a column-derived check would read "org" from the table, the module would list both, and
 * the check would then be satisfied by construction rather than by being true.
 */
it('refuses bare `unscoped` while accepting both split forms', function (): void {
    expect(ModuleManifest::refusalFor(manifest(['provider' => 'P', 'scoping' => ['unscoped']]), 'acme/thing'))
        ->toContain('declares the scope `unscoped`');

    expect(ModuleManifest::refusalFor(manifest(['provider' => 'P', 'scoping' => ['unscoped:global']]), 'acme/thing'))
        ->toBeNull();

    expect(ModuleManifest::refusalFor(manifest(['provider' => 'P', 'scoping' => ['unscoped:through(Acme\\Owner)']]), 'acme/thing'))
        ->toBeNull();
});

it('reads the model out of unscoped:through, and nothing out of the others', function (): void {
    expect(ModuleManifest::throughModel('unscoped:through(Acme\\Owner)'))->toBe('Acme\\Owner')
        ->and(ModuleManifest::throughModel('unscoped:global'))->toBeNull()
        ->and(ModuleManifest::throughModel('org'))->toBeNull();
});

it('refuses a repeated scope', function (): void {
    expect(ModuleManifest::refusalFor(manifest(['provider' => 'P', 'scoping' => ['org', 'org']]), 'acme/thing'))
        ->toContain('repeats a scope');
});

it('refuses a scoping value that is not a list', function (): void {
    expect(ModuleManifest::refusalFor(manifest(['provider' => 'P', 'scoping' => ['a' => 'org']]), 'acme/thing'))
        ->toContain('must be a list');
});

/**
 * ⚠️ The one refusal that is about the GATE rather than the declaration: Laravel registers
 * `extra.laravel.providers` from bootstrap/cache/packages.php before any Kitsune code runs, so a package
 * arranging that is live whatever the kernel decides.
 */
it('refuses a package that arranged to be booted by Laravel instead', function (): void {
    $composer = manifest(
        ['provider' => 'Acme\\Provider', 'scoping' => ['unscoped:global']],
        ['laravel' => ['providers' => ['Acme\\Provider']]],
    );

    expect(ModuleManifest::refusalFor($composer, 'acme/thing'))
        ->toContain('registers before the kernel');
});

it('maps every scope onto the attribute a model must carry', function (): void {
    expect(ModuleManifest::attributeFor('site'))->toBe(SiteScoped::class)
        ->and(ModuleManifest::attributeFor('org'))->toBe(OrgScoped::class)
        ->and(ModuleManifest::attributeFor('org-through-pivot'))->toBe(OrgScopedThroughPivot::class)
        ->and(ModuleManifest::attributeFor('unscoped:global'))->toBe(Unscoped::class)
        ->and(ModuleManifest::attributeFor('unscoped:through(Acme\\Owner)'))->toBe(Unscoped::class);
});

/**
 * ⚠️ Set equality, not "every scope maps to something". A map with an entry the vocabulary does not list, or a
 * scope the map does not cover, is the drift this asserts against — the same reason ScopeDeclarationTest
 * compares both directions.
 */
it('covers exactly the vocabulary it publishes', function (): void {
    foreach (ModuleManifest::SCOPES as $scope) {
        expect(ModuleManifest::isScope($scope))->toBeTrue("`{$scope}` is published but not recognised");
        expect(ModuleManifest::attributeFor($scope))->toBeString();
    }

    expect(ModuleManifest::isScope('unscoped'))->toBeFalse()
        ->and(ModuleManifest::isScope('aware'))->toBeFalse()
        ->and(ModuleManifest::isScope('agnostic'))->toBeFalse()
        /* ADR-009's published spelling is not a scope either; ADR-038 renamed the key and the vocabulary together. */
        ->and(ModuleManifest::isScope('tenancy'))->toBeFalse();
});

it('throws from `from()` with the same reason `refusalFor()` gives', function (): void {
    $composer = manifest(['provider' => 'Acme\\Provider']);
    $reason = ModuleManifest::refusalFor($composer, 'acme/thing');

    expect($reason)->not->toBeNull();

    expect(fn () => ModuleManifest::from($composer, 'acme/thing'))
        ->toThrow(RuntimeException::class, (string) $reason);
});
