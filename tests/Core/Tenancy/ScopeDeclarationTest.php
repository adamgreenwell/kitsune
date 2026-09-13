<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
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
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\ScopeResolver;
use Kitsune\Core\Tenancy\UndeclaredScopeException;
use Kitsune\Core\Tests\Fixtures\UndeclaredThing;

/*
 * CONTRIBUTING makes this a rule that fails the build: every model declares
 * exactly one of #[SiteScoped], #[OrgScoped], #[OrgScopedThroughPivot] or
 * #[Unscoped]. There is no default, because a model that forgot would
 * otherwise be readable across every org on the installation.
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
        // ⚠️ The pivot option is asserted because omitting it MISDIRECTS: a
        // developer told to pick from three would reach for #[OrgScoped],
        // whose scope compares an `org_id` column their model does not have.
        // A fail-closed error that points at the wrong fix is worse than a
        // terse one.
        expect($e->getMessage())
            ->toContain('#[SiteScoped]')
            ->toContain('#[OrgScoped]')
            ->toContain('#[OrgScopedThroughPivot]')
            ->toContain('#[Unscoped]')
            ->toContain('compare a column that is not there')
            ->toContain('readable across every org');
    }
});

it('adds the trait beside the attribute on every core model', function (): void {
    /*
     * ⚠️ AGENTS.md INVARIANT 2 SAYS "IF YOU ADD THE ATTRIBUTE, ADD THE TRAIT", and it was published and
     * unenforced — review found `RolePermission` annotated and not enforcing, and the sweep then showed SEVEN
     * more core models in the same state. The rule cites its own cause: `User` carried `#[Unscoped]` and no
     * trait for two phases, "labelled correctly and completely unconstrained".
     *
     * ⚠️ IT COVERS `#[Unscoped]` TOO, WHICH IS THE PART THAT HAD DRIFTED. For an unscoped model the resolver
     * applies no scope — that is what the declaration means — so what the trait buys is the model being
     * CHECKED on boot rather than merely annotated. The attribute alone is a comment with syntax.
     *
     * This is the fourth rule in this project to need a test rather than attention, after the licence header,
     * `field-types.md`'s refusal accounting, and documents promising files that already exist.
     */
    $models = [];

    foreach (glob(dirname(__DIR__, 3).'/packages/core/src/Models/*.php') ?: [] as $file) {
        $class = 'Kitsune\\Core\\Models\\'.basename($file, '.php');

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            continue;
        }

        $models[] = $class;
    }

    // Not vacuous: an empty sweep would pass over nothing at all.
    expect($models)->toContain(Entry::class)->toContain(Org::class);

    $unenforced = array_values(array_filter(
        $models,
        static fn (string $class): bool => ! in_array(EnforcesScope::class, class_uses_recursive($class), true),
    ));

    expect($unenforced)->toBe([], 'these models declare a scope and do not enforce it: '.implode(', ', $unenforced));
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
    // Both trees. The first version of this sweep covered only the package
    // and therefore had exactly the gap it was written to prevent — the
    // skeleton's own User model went unchecked. Review caught that too.
    $trees = [
        __DIR__.'/../../../packages/core/src/Models' => 'Kitsune\\Core\\Models\\',
        __DIR__.'/../../../skeleton/app/Models' => 'App\\Models\\',
    ];

    $models = [];

    foreach ($trees as $dir => $namespace) {
        foreach (glob($dir.'/*.php') ?: [] as $file) {
            $models[] = $namespace.basename($file, '.php');
        }
    }

    // Every discovered class must actually load. The previous version
    // guarded with class_exists() and skipped anything unloadable, which
    // made the skeleton half a silent no-op — the root composer had no App\\
    // mapping, so the check it claimed to perform never ran. The root
    // autoload-dev now maps it, and a missing class is a failure rather
    // than a skip.
    expect($models)->not->toBeEmpty();

    foreach ($models as $model) {
        expect(class_exists($model))->toBeTrue("{$model} is not autoloadable, so the sweep cannot check it");
    }

    // Explicit try/catch rather than not->toThrow(Class, $message).
    //
    // That form reads as "does not throw, and here is why it matters", but
    // Pest treats the second argument as the EXPECTED EXCEPTION MESSAGE. It
    // therefore passed whenever the real message differed — which is always,
    // since the message names the model. The assertion looked correct and
    // checked nothing, and removing a model's attribute left it green.
    $undeclared = [];

    foreach ($models as $model) {
        try {
            ScopeResolver::for($model);
        } catch (UndeclaredScopeException) {
            $undeclared[] = $model;
        }
    }

    expect($undeclared)->toBe([], 'models missing a scope declaration: '.implode(', ', $undeclared));
});

it('memoises resolution, because it runs on every model boot', function (): void {
    ScopeResolver::flush();

    $first = ScopeResolver::for(Site::class);
    $second = ScopeResolver::for(Site::class);

    expect($first)->toBe($second)->toBe(OrgScoped::class);
});
