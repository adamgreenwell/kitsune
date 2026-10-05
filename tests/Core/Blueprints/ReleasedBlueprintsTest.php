<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Blueprints\BlueprintDefinition;
use Kitsune\Core\Blueprints\BlueprintRegistry;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Schema\SchemaManager;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\SchemaManagerStandIn;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * Every first-party blueprint as it ships, merged over every version of it that was ever released — ADR-039's merge, as
 * the guard that keeps a release mergeable.
 *
 * ⚠️ THE RELEASES ARE FROZEN COPIES, under `tests/Core/Fixtures/Released/`, one per version, never edited. A merge only
 * adds, so a version that changes or drops anything an earlier release recorded is refused in every org that has that
 * release — and this is where that is caught, in CI, before any org meets it. Equal versions must be the same
 * definition: a change shipped under an existing version reaches no org that applied it.
 */

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);
    SchemaManagerStandIn::install()->recordOnly();
});

afterEach(function (): void {
    app()->forgetInstance(SchemaManager::class);
    app(Context::class)->forget();
});

/** @return list<BlueprintDefinition> every frozen release, found on disk rather than listed, so a new one cannot be left out */
function releasedVersions(): array
{
    $released = [];

    foreach (glob(__DIR__.'/../Fixtures/Released/*.php') ?: [] as $file) {
        $class = 'Kitsune\\Core\\Tests\\Fixtures\\Released\\'.basename($file, '.php');
        $released[] = new $class;
    }

    return $released;
}

/** A manifest less its ids and prose outcome, keys sorted — what a fresh apply and a merge must agree on. */
function releasedOracle(string $handle): array
{
    $manifest = (array) Blueprint::receiptFor($handle)?->manifest;
    unset($manifest['outcome']);

    foreach (['entry_types', 'roles'] as $key) {
        foreach ($manifest[$key] as $i => $row) {
            unset($manifest[$key][$i]['id']);
        }
    }

    $sort = static function (array $value) use (&$sort): array {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $sort($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    };

    return $sort($manifest);
}

it('merges every first-party blueprint as shipped over each of its released versions, ending where a fresh apply ends', function (): void {
    $shipped = app(BlueprintRegistry::class)->all();
    $checked = [];

    foreach (releasedVersions() as $i => $release) {
        $current = $shipped[$release->handle()] ?? null;

        expect($current)->not->toBeNull("{$release->handle()} {$release->version()} is a frozen release of nothing that ships");

        $upgraded = Org::create(['slug' => "upgraded-{$i}", 'name' => "Upgraded {$i}"]);
        app(Context::class)->setOrg($upgraded);
        BlueprintApplier::apply($release);
        $result = BlueprintApplier::apply($current);
        $merged = releasedOracle($current->handle());

        $fresh = Org::create(['slug' => "fresh-{$i}", 'name' => "Fresh {$i}"]);
        app(Context::class)->setOrg($fresh);
        BlueprintApplier::apply($current);

        expect($merged)->toBe(releasedOracle($current->handle()));

        if ($release->version() === $current->version()) {
            expect($result['skipped'])->toBe(['already applied at this version']);
        }

        $checked[] = "{$release->handle()} {$release->version()}";
    }

    /*
     * ⚠️ EVERY VERSION THAT SHIPS IS FROZEN HERE, the one shipping now included. A release with no frozen copy is merged
     * over by nothing, so the next one could change what it added — refused in every org that took it — and pass.
     */
    foreach ($shipped as $handle => $current) {
        expect($checked)->toContain("{$handle} {$current->version()}");
    }

    expect($checked)->toContain('blog 1.0.0', 'marketing-site 1.0.0', 'dam 1.0.0');
});
