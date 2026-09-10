<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Context;

/**
 * The migration ADR-030 calls mandatory, which did not exist until review looked for it.
 *
 * ⚠️ THE DOCUMENT REQUIRED THIS AND NOTHING IMPLEMENTED IT. `field-types.md` §3 and ADR-030 both
 * said *"Migration is not optional. Patterns already authored were accepted by the screen, not by
 * the grammar, so any outside it must be found before this lands"* — and there was no command, no
 * migration, and nothing that ran `unpublishable()` over stored rows. A published requirement with
 * no implementation is the same failure as a published rule with no enforcement, which this branch
 * had already made once.
 *
 * ⚠️ WHAT AN UPGRADE ACTUALLY SUFFERS is worse than "some patterns are now invalid". A stored
 * `^(a|aa)+$` keeps being published in the API schema and keeps being enforced server-side, because
 * nothing revalidates a row that is not saved — and then the first UNRELATED edit to that field
 * fails, telling the author their pattern is invalid on a screen where they changed a label.
 */
beforeEach(function (): void {
    $this->org = Org::create(['name' => 'Golfdom', 'slug' => 'golfdom']);
    app(Context::class)->setOrg($this->org);
});

afterEach(fn () => app(Context::class)->forget());

/** A field storage row carrying a pattern, written past the settings guard. */
function storedPattern(int $orgId, string $handle, string $pattern): FieldStorage
{
    $storage = FieldStorage::create([
        'org_id' => $orgId, 'handle' => $handle, 'type' => 'text',
        'pii_class' => 'none', 'cardinality' => 1,
    ]);

    /*
     * ⚠️ WRITTEN THROUGH THE QUERY BUILDER, PAST ELOQUENT ENTIRELY, and both halves of that are
     * deliberate. The point of this fixture is a row that PREDATES the grammar, which by definition
     * cannot be created through the model — `guardSettingsAreUsable()` would refuse it, which is the
     * whole reason the audit is needed.
     *
     * `FieldStorage::query()->update()` does not work either, and the failure is the codebase
     * defending itself correctly: `settings` is a per-row guarded column, so `GuardedStorageBuilder`
     * refuses to write it in bulk because its guards depend on the row (ADR-006). A raw table write
     * is the only honest way to stage a legacy row, and it is confined to this helper.
     */
    DB::table('field_storage')
        ->where('id', $storage->getKey())
        ->update(['settings' => json_encode(['pattern' => $pattern])]);

    return $storage->refresh();
}

it('reports nothing when every stored pattern is publishable', function (): void {
    storedPattern($this->org->id, 'slug', '^[a-z0-9-]{3,32}$');
    storedPattern($this->org->id, 'hex', '^#[0-9A-Fa-f]{6}$');

    $this->artisan('kitsune:audit-patterns')
        ->expectsOutputToContain('examined 2 stored patterns')
        ->expectsOutputToContain('Every stored pattern satisfies the published grammar.')
        ->assertSuccessful();
});

it('names each unpublishable pattern, its field and the reason', function (): void {
    storedPattern($this->org->id, 'good', '^[a-z]+$');
    storedPattern($this->org->id, 'ambiguous', '^(a|aa)+$');

    $this->artisan('kitsune:audit-patterns')
        ->expectsOutputToContain('ambiguous')
        ->expectsOutputToContain('^(a|aa)+$')
        ->expectsOutputToContain('1 stored pattern is unpublishable.')
        ->assertSuccessful();
});

it('exits non-zero under --strict, so a deployment can gate on it', function (): void {
    /*
     * ⚠️ SUCCESS WITHOUT `--strict` IS DELIBERATE. An operator running the audit to find out where
     * they stand should not have the command look like it failed; a deployment that must not proceed
     * asks for the gate explicitly. There is no `--force`, because there is no mechanical repair: a
     * pattern says what a field accepts, and only its owner knows what that should be.
     */
    storedPattern($this->org->id, 'ambiguous', '^(a|aa)+$');

    $this->artisan('kitsune:audit-patterns')->assertSuccessful();
    $this->artisan('kitsune:audit-patterns', ['--strict' => true])->assertFailed();
});

it('examines every org, not only one', function (): void {
    /*
     * ⚠️ THE FAILURE THIS COMMAND EXISTS TO PREVENT, REPRODUCED INSIDE IT. An audit that reported
     * "0 problems" for an installation full of them would be worse than none. `FieldStorage` is
     * `#[Unscoped]`, so a plain query already crosses orgs — asserted rather than assumed, because
     * that attribute could change and this is the test that would notice.
     */
    $other = Org::create(['name' => 'Other', 'slug' => 'other']);

    storedPattern($this->org->id, 'mine', '^[a-z]+$');
    storedPattern($other->id, 'theirs', '^(a|aa)+$');

    $this->artisan('kitsune:audit-patterns')
        ->expectsOutputToContain('theirs')
        ->expectsOutputToContain('examined 2 stored patterns')
        ->assertSuccessful();
});

it('ignores rows with no pattern rather than counting them', function (): void {
    FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'plain', 'type' => 'text',
        'pii_class' => 'none', 'cardinality' => 1,
    ]);

    $this->artisan('kitsune:audit-patterns')
        ->expectsOutputToContain('examined 0 stored patterns')
        ->assertSuccessful();
});
