<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\UlidUser;

/*
 * Role-based access control on a host whose users carry ULIDs — #91, ADR-033.
 *
 * ⚠️ RBAC WAS INTEGER-ONLY, AND THE CAST WAS NOT HARMLESS. `Role::assignTo(int)` threw a `TypeError` for a ULID,
 * `Permissions::key()` turned one into null, and the holder picker filtered them out. Where a cast did run it named
 * somebody: PHP reads a string's leading digits, so `(int) '01J…'` is `1`. The key type is the host's to choose, and
 * core now reads an identifier as the user model's own type.
 */

beforeEach(function (): void {
    config(['auth.providers.users.model' => UlidUser::class]);

    $this->org = Org::create(['slug' => 'ulid-host', 'name' => 'ULID host']);
    app(Context::class)->setOrg($this->org);
});

afterEach(fn () => app(Context::class)->forget());

it('assigns a role to a ULID-keyed user, resolves it, and audits the ULID', function (): void {
    /** @var UlidUser $user */
    $user = UlidUser::create(['email' => 'ulid@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => $this->org->getKey(), 'user_id' => $user->getKey()]);

    $role = Role::create(['handle' => 'editor', 'name' => 'Editor']);
    $role->grant('entry.article.update');

    $mark = (int) AuditLog::query()->max('id');
    $role->assignTo($user->getKey());

    expect($user->getKey())->toBeString()->toHaveLength(26)
        ->and(Permissions::allows($user, 'entry.article.update'))->toBeTrue()
        ->and(Permissions::allows($user, 'entry.article.delete'))->toBeFalse()
        ->and(AuditLog::query()->where('id', '>', $mark)->value('target_id'))->toBe($user->getKey());
});
