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
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Attributes\Unscoped;
use Kitsune\Core\Tenancy\ScopeResolver;
use Kitsune\Core\Tenancy\UndeclaredScopeException;
use Kitsune\Core\Tests\Fixtures\UndeclaredThing;

/*
 * CONTRIBUTING makes this a rule that fails the build: every model declares
 * exactly one of #[SiteScoped], #[OrgScoped] or #[Unscoped]. There is no
 * default, because a model that forgot would otherwise be readable across
 * every org on the installation.
 */

it('refuses to boot a model with no scope declaration', function (): void {
    ScopeResolver::flush();

    expect(fn () => new UndeclaredThing)->toThrow(UndeclaredScopeException::class);
});

it('explains what to do rather than just failing', function (): void {
    ScopeResolver::flush();

    try {
        ScopeResolver::for(UndeclaredThing::class);
        $this->fail('expected UndeclaredScopeException');
    } catch (UndeclaredScopeException $e) {
        expect($e->getMessage())
            ->toContain('#[SiteScoped]')
            ->toContain('#[OrgScoped]')
            ->toContain('#[Unscoped]')
            ->toContain('readable across every org');
    }
});

it('resolves the declared scope for each core model', function (string $model, string $expected): void {
    expect(ScopeResolver::for($model))->toBe($expected);
})->with([
    'Org is unscoped by necessity' => [Org::class, Unscoped::class],
    'Site belongs to an org' => [Site::class, OrgScoped::class],
    'SiteGroup belongs to an org' => [SiteGroup::class, OrgScoped::class],
]);

it('memoises resolution, because it runs on every model boot', function (): void {
    ScopeResolver::flush();

    $first = ScopeResolver::for(Site::class);
    $second = ScopeResolver::for(Site::class);

    expect($first)->toBe($second)->toBe(OrgScoped::class);
});
