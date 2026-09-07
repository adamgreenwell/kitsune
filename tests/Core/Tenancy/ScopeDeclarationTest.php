<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRevision;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Models\SiteGroup;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
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
    'Entry belongs to a site' => [Entry::class, SiteScoped::class],
    'EntryType is global or org-owned' => [EntryType::class, Unscoped::class],
    'FieldStorage is reached through its type' => [FieldStorage::class, Unscoped::class],
    'Field is per-type presentation' => [Field::class, Unscoped::class],
    'EntryRevision is reached through its entry' => [EntryRevision::class, Unscoped::class],
    'EntryTypeAvailability is resolved explicitly' => [EntryTypeAvailability::class, Unscoped::class],
]);

it('leaves no model in the package without a declaration', function (): void {
    // A sweep rather than a list, because the failure this guards against is
    // someone adding a model and forgetting — and a hand-maintained list has
    // exactly the same failure mode. Review caught one omission here already.
    $dir = __DIR__.'/../../../packages/core/src/Models';
    $models = [];

    foreach (glob($dir.'/*.php') ?: [] as $file) {
        $models[] = 'Kitsune\\Core\\Models\\'.basename($file, '.php');
    }

    expect($models)->not->toBeEmpty();

    foreach ($models as $model) {
        expect(fn () => ScopeResolver::for($model))
            ->not->toThrow(UndeclaredScopeException::class, "{$model} declares no scope");
    }
});

it('memoises resolution, because it runs on every model boot', function (): void {
    ScopeResolver::flush();

    $first = ScopeResolver::for(Site::class);
    $second = ScopeResolver::for(Site::class);

    expect($first)->toBe($second)->toBe(OrgScoped::class);
});
