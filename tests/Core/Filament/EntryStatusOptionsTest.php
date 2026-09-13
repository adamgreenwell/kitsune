<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * The enforcement point for `entry.{type}.publish` — ADR-033.
 *
 * ⚠️ IT IS ONE METHOD READ TWICE, and that is the design rather than an implementation detail. The status
 * control's options and the validation rule both come from `EntryResource::statusOptions($this->user)`, so they cannot
 * disagree — a list computed once for the control and again for the rule is a list that drifts the day
 * somebody edits one of them, and the half that drifts silently is always the rule.
 *
 * ⚠️ AND THE RULE IS THE HALF THAT MATTERS AGAINST AN ATTACKER. `e2e/permissions.spec.js` asserts the
 * options a browser is offered; a hand-built request never opens a select. This file is the other half.
 */

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);

    $this->org = Org::create(['slug' => 'alpha', 'name' => 'Alpha']);
    app(Context::class)->setOrg($this->org);

    /** @var TestUser $user */
    $user = TestUser::create(['email' => 'writer@kitsune.test']);
    $this->user = $user;

    DB::table('org_user')->insert(['org_id' => $this->org->getKey(), 'user_id' => $user->getKey()]);

    $this->role = Role::create(['handle' => 'writer', 'name' => 'Writer']);
    DB::table('role_user')->insert(['role_id' => $this->role->getKey(), 'user_id' => $user->getKey()]);

    app()->instance(EntryType::class, new EntryType(['handle' => 'article']));
});

it('withholds published from somebody who may not publish', function (): void {
    $this->role->grant(Permissions::forEntryType('article', 'update'));

    expect(array_keys(EntryResource::statusOptions($this->user)))->toBe(['draft', 'archived']);
});

it('offers published to somebody who may', function (): void {
    $this->role->grant(Permissions::forEntryType('article', 'publish'));

    // ⚠️ And in reading order rather than assembly order, because the control is read by a person.
    expect(array_keys(EntryResource::statusOptions($this->user)))->toBe(['draft', 'published', 'archived']);
});

it('withholds published when no type has been established', function (): void {
    /*
     * Fail closed, the same way `EntryPolicy` does with no type in the container: a form rendered outside
     * `IdentifyEntryType` cannot say which type's publish permission to ask about, and "allow" is the wrong
     * default for a question nobody can answer.
     */
    $this->role->grant(Permissions::forEntryType('article', 'publish'));
    app()->forgetInstance(EntryType::class);

    expect(array_keys(EntryResource::statusOptions($this->user)))->toBe(['draft', 'archived']);
});

it('withholds published from nobody at all', function (): void {
    // A guest reaching a form is not a shape the panel produces, and it still must not be the shape that
    // grants the widest option on it.
    expect(array_keys(EntryResource::statusOptions(null)))->toBe(['draft', 'archived']);
});

it('lets an already published entry keep that status without the permission', function (): void {
    /*
     * ⚠️ REVIEW FOUND THE PERMISSION BECOMING A LICENCE TO UNPUBLISH. `publish` is permission to move an
     * entry INTO the published state — but withholding the option outright also withheld the entry's own
     * CURRENT value, so a copy-editor could not fix a typo on a published article without first demoting or
     * archiving it. Saving was impossible: the select offered no matching option and the `in` rule refused
     * the stored value.
     */
    $this->role->grant(Permissions::forEntryType('article', 'update'));

    expect(array_keys(EntryResource::statusOptions($this->user, 'published')))
        ->toBe(['draft', 'published', 'archived']);
});

it('does not let the concession become a way into the published state', function (): void {
    /*
     * ⚠️ THE DISTINCTION IS THE TRANSITION, NOT THE VALUE, and this is the half that keeps the permission
     * meaningful. The STORED status is what decides, so an entry that is draft or archived is offered no
     * `published` option — and one demoted to draft in a save has none in the next.
     */
    $this->role->grant(Permissions::forEntryType('article', 'update'));

    expect(array_keys(EntryResource::statusOptions($this->user, 'draft')))->toBe(['draft', 'archived'])
        ->and(array_keys(EntryResource::statusOptions($this->user, 'archived')))->toBe(['draft', 'archived'])
        ->and(array_keys(EntryResource::statusOptions($this->user, null)))->toBe(['draft', 'archived']);
});

it('still offers published to somebody who may, whatever the entry is now', function (): void {
    $this->role->grant(Permissions::forEntryType('article', 'publish'));

    expect(array_keys(EntryResource::statusOptions($this->user, 'draft')))
        ->toBe(['draft', 'published', 'archived']);
});
