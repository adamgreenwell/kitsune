<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Tenancy\Contracts\RefusesCascadingDeletes;

/*
 * ⚠️ A MODEL THAT DECLARES THE CONTRACT MUST HAVE A BUILDER THAT RUNS IT.
 *
 * `FieldStorage` is why this file exists. It carried the cascade hazard `RefusesCascadingDeletes` describes,
 * and adding the interface alone would have changed nothing: its `GuardedStorageBuilder` extends Eloquent's
 * builder directly rather than `ScopedBuilder`, and had no `delete()` override at all — so `guardCascade()`
 * would have been unreachable from every bulk, quiet and query-builder delete. Dead code that reads as a
 * guard, which is worse than no guard, because the next person greps for the interface and stops.
 *
 * The trait's docblock now claims "the rule lives in one place and every guarded builder pulls it in". That
 * is a published consequence, so something has to enforce it (AGENTS.md invariant 14) — a plugin model, or a
 * later first-party one on `GuardedRelationBuilder` or `AppendOnlyBuilder`, would otherwise reintroduce the
 * same defect with the full suite green and no test naming the gap.
 *
 * This asserts the PAIRING by reflection rather than by behaviour, deliberately: a behavioural test would
 * need a delete path per model, and the thing that went wrong was structural.
 */

/** Every first-party model, found on disk rather than listed — a list is a thing to forget to update. */
function coreModelClasses(): array
{
    $classes = [];

    foreach (glob(dirname(__DIR__, 3).'/packages/*/src/Models/*.php') ?: [] as $file) {
        $package = basename(dirname($file, 3));
        $namespace = $package === 'core' ? 'Kitsune\\Core\\Models\\' : 'Kitsune\\'.ucfirst($package).'\\Models\\';
        $class = $namespace.basename($file, '.php');

        if (class_exists($class) && is_subclass_of($class, Model::class)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

it('finds the models to check, rather than passing on an empty list', function (): void {
    /* The floor every sweep in this repo needs: a sweep that examined nothing must not report success. */
    expect(count(coreModelClasses()))->toBeGreaterThan(5);
});

it('gives every model declaring the cascade contract a builder that runs the guard', function (): void {
    $declaring = array_values(array_filter(
        coreModelClasses(),
        fn (string $class): bool => is_subclass_of($class, RefusesCascadingDeletes::class),
    ));

    expect($declaring)->not->toBeEmpty();

    foreach ($declaring as $class) {
        /** @var Model $model */
        $model = new $class;
        $builder = $model->newQuery();

        $runsTheGuard = false;

        foreach (['delete', 'forceDelete'] as $method) {
            $declaredIn = (new ReflectionMethod($builder, $method))->getDeclaringClass()->getName();

            /*
             * Eloquent's own `Builder` is the failure: it means this model's builder never overrode the
             * method, so the delete goes straight to the query builder and the guard is never consulted.
             */
            if (! str_starts_with($declaredIn, 'Illuminate\\')) {
                $runsTheGuard = true;
            }
        }

        expect($runsTheGuard)->toBeTrue(
            $class.' implements RefusesCascadingDeletes but '.$builder::class
            .' overrides neither delete() nor forceDelete(), so guardCascade() is unreachable from a bulk, '
            .'quiet or query-builder delete — the FieldStorage defect, recurring.'
        );
    }
});
