<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Models\SiteGroup;
use Kitsune\Core\Settings\SettingsResolver;
use Kitsune\Core\Tenancy\Context;

/*
 * ADR-022: sparse overrides resolved org → site group → site, shallow merge
 * on top-level keys, and every resolved value carries its origin.
 */

beforeEach(function (): void {
    $this->org = Org::create([
        'name' => 'Golfdom Media',
        'slug' => 'golfdom-media',
        'settings' => ['timezone' => 'UTC', 'logo' => 'org-logo.svg', 'analytics_id' => null],
    ]);

    app(Context::class)->setOrg($this->org);

    $this->group = SiteGroup::create([
        'org_id' => $this->org->id,
        'handle' => 'golfdom',
        'name' => 'Golfdom',
        'settings' => ['logo' => 'golfdom-logo.svg'],
    ]);

    $this->site = Site::create([
        'org_id' => $this->org->id,
        'site_group_id' => $this->group->id,
        'handle' => 'golfdom-fr',
        'name' => 'Golfdom FR',
        'locale' => 'fr',
        'settings' => ['analytics_id' => 'UA-FR-1'],
    ]);

    $this->resolver = new SettingsResolver(['timezone' => 'Europe/London', 'theme' => 'default']);
});

afterEach(fn () => app(Context::class)->forget());

it('falls back to the platform default when nothing overrides', function (): void {
    $resolved = $this->resolver->resolve($this->site, 'theme');

    expect($resolved->value)->toBe('default');
    expect($resolved->origin)->toBe('default');
    expect($resolved->describe())->toBe('platform default');
});

it('lets the org override a platform default', function (): void {
    $resolved = $this->resolver->resolve($this->site, 'timezone');

    expect($resolved->value)->toBe('UTC');
    expect($resolved->origin)->toBe('org');
});

it('lets the site group override the org', function (): void {
    $resolved = $this->resolver->resolve($this->site, 'logo');

    expect($resolved->value)->toBe('golfdom-logo.svg');
    expect($resolved->origin)->toBe('site_group');
    expect($resolved->describe())->toBe('inherited from site group Golfdom');
});

it('lets the site override everything above it', function (): void {
    $resolved = $this->resolver->resolve($this->site, 'analytics_id');

    expect($resolved->value)->toBe('UA-FR-1');
    expect($resolved->origin)->toBe('site');
    expect($resolved->isInherited())->toBeFalse();
});

it('reports inheritance so the admin can show provenance', function (): void {
    // ADR-022 treats this as a first-class requirement, not a nicety:
    // opacity is what makes operators afraid to touch configuration.
    expect($this->resolver->resolve($this->site, 'logo')->isInherited())->toBeTrue();
    expect($this->resolver->resolve($this->site, 'analytics_id')->isInherited())->toBeFalse();
});

it('stores only overrides, so absent means inherit', function (): void {
    // The site row holds one key. It does not carry a copy of everything,
    // which is what keeps an intentional override distinguishable from a
    // stale duplicate.
    expect(array_keys((array) $this->site->settings))->toBe(['analytics_id']);
    expect($this->resolver->all($this->site))->toHaveKeys(['timezone', 'theme', 'logo', 'analytics_id']);
});

it('overrides a whole key rather than merging into it', function (): void {
    $this->org->update(['settings' => ['nav' => ['a' => 1, 'b' => 2]]]);
    $this->site->update(['settings' => ['nav' => ['a' => 9]]]);
    $this->resolver->forget($this->site);

    // Shallow merge: a setting is atomic. Half-inherited nested objects are
    // the most confusing possible outcome.
    expect($this->resolver->resolve($this->site, 'nav')->value)->toBe(['a' => 9]);
});

it('invalidates the resolved cache when a setting changes', function (): void {
    expect($this->resolver->get($this->site, 'analytics_id'))->toBe('UA-FR-1');

    $this->site->update(['settings' => ['analytics_id' => 'UA-FR-2']]);
    $this->resolver->forget($this->site);

    // "I changed the setting and nothing happened" is the support burden
    // this exists to prevent.
    expect($this->resolver->get($this->site, 'analytics_id'))->toBe('UA-FR-2');
});

it('returns a fallback for an unknown key', function (): void {
    expect($this->resolver->get($this->site, 'nope', 'fallback'))->toBe('fallback');
    expect($this->resolver->resolve($this->site, 'nope'))->toBeNull();
});
