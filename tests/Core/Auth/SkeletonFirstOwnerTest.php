<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Auth\RegistersOrgAwareProvider;
use Kitsune\Core\Blueprints\FirstOrg;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/*
 * The first owner, created in the skeleton's own `User` — the model a real installation copies (ADR-026, as amended).
 *
 * ⚠️ THE FIXTURES MIRROR THE SKELETON AND ARE NOT IT, as `RoleIsolationTest` says of `TestUser`: an implementation of
 * `ProvisionsMembership` that only a fixture carries is a contract no installation keeps. So the skeleton's model is
 * run here, against the fixture schema, through the same bootstrap the command calls.
 */

beforeEach(function (): void {
    Site::query()->withoutGlobalScopes()->forceDelete();
    Org::query()->withoutGlobalScopes()->forceDelete();

    config(['auth.providers.users.model' => User::class]);

    /* As the skeleton's own `AppServiceProvider` does, so signing in finds the owner as the skeleton would. */
    RegistersOrgAwareProvider::on($this->app);
});

afterEach(fn () => app(Context::class)->forget());

it('creates the first owner in the skeleton\'s user model, signing in and owning the org', function (): void {
    $hash = Hash::make('correct-horse-battery-staple');

    $org = FirstOrg::createWithOwner('myblog', null, null, 'en', 'owner@example.test', $hash);

    $user = User::query()->withoutGlobalScopes()->sole();
    $site = Site::query()->withoutGlobalScopes()->sole();

    expect($user->email)->toBe('owner@example.test')
        ->and($user->name)->toBe('owner@example.test')
        ->and($user->getAuthPassword())->toBe($hash)
        ->and(Hash::check('correct-horse-battery-staple', $user->getAuthPassword()))->toBeTrue()
        ->and(DB::table('org_user')->get(['org_id', 'user_id'])->map(fn ($row): array => (array) $row)->all())
        ->toEqual([['org_id' => $org->getKey(), 'user_id' => $user->getKey()]])
        ->and(DB::table('site_user')->get(['site_id', 'user_id'])->map(fn ($row): array => (array) $row)->all())
        ->toEqual([['site_id' => $site->getKey(), 'user_id' => $user->getKey()]])
        ->and($user->canAccessTenant($site))->toBeTrue()
        ->and(Permissions::isOwner($user))->toBeTrue()
        ->and(AuditLog::query()->withoutGlobalScopes()->pluck('action')->all())->toBe(['role.owner_assigned']);
});
