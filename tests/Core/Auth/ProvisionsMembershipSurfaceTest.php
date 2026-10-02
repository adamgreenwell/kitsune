<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Models\User;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Illuminate\Contracts\Auth\Authenticatable;
use Kitsune\Core\Auth\Contracts\ProvisionsMembership;
use Kitsune\Core\Auth\FirstOwnerCredentials;
use Kitsune\Core\Blueprints\FirstOrg;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tests\Fixtures\FirstOwnerUlidUser;
use Kitsune\Core\Tests\Fixtures\FirstOwnerUnscopedUser;
use Kitsune\Core\Tests\Fixtures\FirstOwnerUser;

/*
 * `ProvisionsMembership`, the one public symbol the first owner adds — ADR-026, as amended by ADR-039.
 *
 * ⚠️ PUBLIC IS FROZEN AT v1.2, so its shape is asserted rather than read: a parameter renamed or a method added is a
 * change every host built from the skeleton has to make, and it should fail here before it ships.
 */

it('declares exactly the three methods the bootstrap calls, beside what Authenticatable has', function (): void {
    $contract = new ReflectionClass(ProvisionsMembership::class);
    $inherited = array_map(fn (ReflectionMethod $method): string => $method->getName(), (new ReflectionClass(Authenticatable::class))->getMethods());

    $own = [];

    foreach ($contract->getMethods() as $method) {
        if (in_array($method->getName(), $inherited, true)) {
            continue;
        }

        $own[$method->getName()] = [
            'static' => $method->isStatic(),
            'parameters' => array_map(
                fn (ReflectionParameter $parameter): string => $parameter->getType().' $'.$parameter->getName(),
                $method->getParameters(),
            ),
            'returns' => (string) $method->getReturnType(),
        ];
    }

    expect($contract->isInterface())->toBeTrue()
        ->and($contract->getInterfaceNames())->toBe([Authenticatable::class])
        ->and($own)->toBe([
            'provisionAccount' => ['static' => true, 'parameters' => ['string $email', 'string $name', 'string $passwordHash'], 'returns' => 'static'],
            'admitToOrg' => ['static' => false, 'parameters' => [Org::class.' $org'], 'returns' => 'void'],
            'admitToSite' => ['static' => false, 'parameters' => [Site::class.' $site'], 'returns' => 'void'],
        ]);
});

/** The reference host's model, by reflection — as `RoleIsolationTest` checks its observer — and the fixtures beside it. */
it('is implemented by the skeleton\'s user model, with the panel\'s contracts beside it', function (): void {
    expect(is_subclass_of(User::class, ProvisionsMembership::class))->toBeTrue()
        ->and(is_subclass_of(User::class, HasTenants::class))->toBeTrue()
        ->and(is_subclass_of(User::class, FilamentUser::class))->toBeTrue()
        ->and(is_subclass_of(FirstOwnerUser::class, ProvisionsMembership::class))->toBeTrue()
        ->and(is_subclass_of(FirstOwnerUlidUser::class, ProvisionsMembership::class))->toBeTrue();
});

/**
 * ⚠️ `#[\SensitiveParameter]` IS NOT INHERITED, so every implementation carries it again: without it a stack trace —
 * a log line, an error page — prints the password or its hash as an argument.
 */
it('marks every parameter that carries the password or its hash as sensitive', function (string $class, string $method, string $parameter): void {
    $found = null;

    foreach ((new ReflectionMethod($class, $method))->getParameters() as $candidate) {
        if ($candidate->getName() === $parameter) {
            $found = $candidate;
        }
    }

    expect($found)->not->toBeNull("{$class}::{$method}() has no parameter \${$parameter}")
        ->and($found->getAttributes(SensitiveParameter::class))->toHaveCount(1);
})->with([
    'the contract' => [ProvisionsMembership::class, 'provisionAccount', 'passwordHash'],
    'the skeleton' => [User::class, 'provisionAccount', 'passwordHash'],
    'the fixture' => [FirstOwnerUser::class, 'provisionAccount', 'passwordHash'],
    'the ULID fixture' => [FirstOwnerUlidUser::class, 'provisionAccount', 'passwordHash'],
    'the unscoped fixture' => [FirstOwnerUnscopedUser::class, 'provisionAccount', 'passwordHash'],
    'the bootstrap' => [FirstOrg::class, 'createWithOwner', 'ownerPasswordHash'],
    'its transaction' => [FirstOrg::class, 'bootstrap', 'ownerPasswordHash'],
    'its owner step' => [FirstOrg::class, 'seatOwner', 'passwordHash'],
    'the rules' => [FirstOwnerCredentials::class, 'passwordRefusal', 'password'],
    'the line ending' => [FirstOwnerCredentials::class, 'withoutLineEnd', 'line'],
    'the answer' => [FirstOwnerCredentials::class, 'answered', 'answer'],
]);
