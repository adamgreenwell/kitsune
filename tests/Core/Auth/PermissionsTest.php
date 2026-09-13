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
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * The permission vocabulary and its resolution — ADR-033.
 *
 * ⚠️ A PERMISSION IS A STRING, so the registry is the only thing standing between a grant and a typo that
 * is silently never held. These tests are the registry's, and they are as much about what is REFUSED as
 * what is accepted: a grant that stores and never matches fails closed and invisibly, which is the failure
 * mode ADR-033 accepts a table of strings in order to avoid a worse one.
 */

function member(Org $org, bool $owner = false, array $grants = []): TestUser
{
    /** @var TestUser $user */
    $user = TestUser::create(['email' => 'u'.mt_rand(1, 1_000_000_000).'@kitsune.test']);

    $role = Role::create([
        'org_id' => $org->getKey(),
        'handle' => 'r'.mt_rand(1, 1_000_000_000),
        'name' => 'Role',
        'is_owner' => $owner,
    ]);

    foreach ($grants as $grant) {
        $role->grant($grant);
    }

    DB::table('role_user')->insert(['role_id' => $role->getKey(), 'user_id' => $user->getKey()]);
    DB::table('org_user')->insert(['org_id' => $org->getKey(), 'user_id' => $user->getKey()]);

    return $user;
}

beforeEach(function (): void {
    // `Permissions` asks membership through the user model's own scoped query, so the provider has to name
    // a model that carries the skeleton's declaration — see `TestUser`.
    config(['auth.providers.users.model' => TestUser::class]);

    $this->org = Org::create(['slug' => 'alpha', 'name' => 'Alpha']);
    app(Context::class)->setOrg($this->org);
});

it('names a permission the way architecture.md §4 says', function (): void {
    expect(Permissions::forEntryType('article', 'publish'))->toBe('entry.article.publish');
});

it('accepts a grant on a type that does not exist yet', function (): void {
    /*
     * ⚠️ SHAPE, NOT EXISTENCE, and it is deliberate rather than lax. A blueprint seeds permissions
     * alongside the entry type it creates, and the two arrive in one operation — so requiring the type
     * first would make the normal case impossible.
     */
    expect(Permissions::validated('entry.product.view'))->toBe('entry.product.view');
});

it('refuses an action that is not registered', function (): void {
    // The typo that a string-valued permission makes possible, caught at the write rather than at the
    // check — where it would look like a role that simply does not have the grant.
    expect(fn () => Permissions::validated('entry.article.viwe'))
        ->toThrow(InvalidArgumentException::class, 'not a registered action');
});

it('refuses a shape that is not a permission at all', function (): void {
    foreach (['article.view', 'entry.article', 'entry.article.view.extra', 'site.article.view', ''] as $bad) {
        expect(fn () => Permissions::validated($bad))->toThrow(InvalidArgumentException::class);
    }
});

it('refuses a type segment that could never match', function (): void {
    // `entry..view` and `entry.art*cle.view` store happily and match nothing, which is the shape of grant
    // that becomes a belief about what a role can do.
    foreach (['entry..view', 'entry.art*cle.view', 'entry.Article.view', 'entry.1article.view'] as $bad) {
        expect(fn () => Permissions::validated($bad))->toThrow(InvalidArgumentException::class);
    }
});

it('grants and revokes, and grants once', function (): void {
    $role = Role::create(['org_id' => $this->org->getKey(), 'handle' => 'editor', 'name' => 'Editor']);

    $role->grant('entry.article.update');
    $role->grant('entry.article.update');

    expect($role->permissions()->count())->toBe(1);

    $role->revoke('entry.article.update');

    expect($role->permissions()->count())->toBe(0);
});

it('refuses to store a grant the registry rejects', function (): void {
    $role = Role::create(['org_id' => $this->org->getKey(), 'handle' => 'editor', 'name' => 'Editor']);

    expect(fn () => $role->grant('entry.article.approve'))->toThrow(InvalidArgumentException::class)
        ->and($role->permissions()->count())->toBe(0);
});

it('resolves a grant the user holds, and nothing else', function (): void {
    $user = member($this->org, grants: ['entry.article.view']);

    expect(Permissions::allows($user, 'entry.article.view'))->toBeTrue()
        ->and(Permissions::allows($user, 'entry.article.update'))->toBeFalse()
        ->and(Permissions::allows($user, 'entry.product.view'))->toBeFalse();
});

it('resolves the explicit wildcard for the action it names, and not for others', function (): void {
    /*
     * ⚠️ AT CHECK TIME, WHICH IS THE POINT OF IT. Expanding `entry.*.view` at grant time would cover
     * exactly the types that existed when it was written — the one thing the wildcard is for not doing. So
     * a type nobody had thought of resolves, and a different ACTION still does not.
     */
    $user = member($this->org, grants: ['entry.*.view']);

    expect(Permissions::allows($user, 'entry.article.view'))->toBeTrue()
        ->and(Permissions::allows($user, 'entry.invented_later.view'))->toBeTrue()
        ->and(Permissions::allows($user, 'entry.article.delete'))->toBeFalse();
});

it('refuses a string this vocabulary does not define, wildcard or not', function (): void {
    /*
     * ⚠️ THE WILDCARD WAS CONSTRUCTED FROM THE CALLER'S STRING AND NOTHING CHECKED IT, which review found:
     * any three segments ending in a registered action became `entry.*.{action}`, so a holder of
     * `entry.*.view` was granted `site.settings.view` — a SUBJECT this vocabulary does not have. The direct
     * match cannot fail that way, because a stored grant went through `validated()`; the wildcard is built
     * at check time, so what is built has to be checked.
     *
     * ⚠️ AND IT MATTERS MOST FOR A FUTURE ABILITY. `entry` is the only subject in v1.0, so today's wrong
     * answer is about a permission nobody asks for — but a module adding `media.image.view` in v1.2 would
     * have found every `entry.*` holder already granted it, retroactively, by a wildcard written about
     * entries. Failing closed now is what keeps that from being a migration problem.
     */
    $wildcard = member($this->org, grants: ['entry.*.view']);

    expect(Permissions::allows($wildcard, 'entry.article.view'))->toBeTrue()
        ->and(Permissions::allows($wildcard, 'site.settings.view'))->toBeFalse()
        ->and(Permissions::allows($wildcard, 'media.image.view'))->toBeFalse()
        // An unregistered action, which `validated()` refuses and the wildcard used to answer anyway.
        ->and(Permissions::allows($wildcard, 'entry.article.frobnicate'))->toBeFalse()
        // Arity: two segments and four, neither of which is a permission.
        ->and(Permissions::allows($wildcard, 'entry.view'))->toBeFalse()
        ->and(Permissions::allows($wildcard, 'entry.article.sub.view'))->toBeFalse();
});

it('gives an owner the same answer about a permission that does not exist', function (): void {
    /*
     * ⚠️ CHECKED BEFORE THE OWNER BYPASS, deliberately. An owner told yes about `site.settings.view` is an
     * owner whose CALLER now believes such a permission is real — and a caller that believes it will write
     * the other half of the feature against a check that answers for everybody. A question nobody can ask
     * gets one answer, and it is no.
     */
    $owner = member($this->org, owner: true);

    expect(Permissions::allows($owner, 'entry.article.delete'))->toBeTrue()
        ->and(Permissions::allows($owner, 'site.settings.view'))->toBeFalse()
        ->and(Permissions::allows($owner, 'entry.article.frobnicate'))->toBeFalse();
});

it('still answers about a type handle no FORM would have accepted', function (): void {
    /*
     * ⚠️ THE TYPE SEGMENT'S SHAPE IS A RULE ABOUT WRITING A GRANT, NOT ABOUT ANSWERING ONE, and conflating
     * the two would have been a regression dressed as a fix. `validated()` requires `^[a-z][a-z0-9_]*$` so
     * that a stored grant cannot be a string nobody can hold; the entry type table does not, and the panel
     * enforces it on the create screen only — a seeder or a blueprint reaches the model directly.
     *
     * So `entry.*.update` has to cover an installation's real type whatever its handle looks like, and an
     * owner has to be let through for it. Refusing here would deny authority over data that exists.
     */
    $wildcard = member($this->org, grants: ['entry.*.update']);
    $owner = member($this->org, owner: true);

    expect(Permissions::allows($wildcard, 'entry.blog-post.update'))->toBeTrue()
        ->and(Permissions::allows($owner, 'entry.blog-post.update'))->toBeTrue()
        // And it is still not a grant anybody may WRITE, which is the other half of the distinction.
        ->and(fn () => Permissions::validated('entry.blog-post.update'))
        ->toThrow(InvalidArgumentException::class, 'is not an entry type handle');
});

it('lets an owner through without holding a grant', function (): void {
    // The bootstrap hole: somebody has to create the first entry type, which is before any permission
    // naming that type can exist.
    $owner = member($this->org, owner: true);

    expect(Permissions::allows($owner, 'entry.article.delete'))->toBeTrue()
        ->and(Permissions::isOwner($owner))->toBeTrue()
        ->and(Permissions::held($owner))->toBe([]);
});

it('refuses everything with no user and with no org context', function (): void {
    /*
     * ⚠️ NO CONTEXT MEANS NO, not "unconstrained" — the same answer `OrgScope` gives a query in that state,
     * and the opposite of the convenient one. A console command or a queue job that has not established an
     * org is exactly where an authorization check would otherwise pass by accident.
     */
    $user = member($this->org, grants: ['entry.article.view']);

    expect(Permissions::allows(null, 'entry.article.view'))->toBeFalse();

    app(Context::class)->forget();

    expect(Permissions::allows($user, 'entry.article.view'))->toBeFalse()
        ->and(Permissions::isOwner($user))->toBeFalse();
});

it('records the four operations that change authority, and nothing else', function (): void {
    /*
     * ⚠️ THIS EXISTS BECAUSE ADR-033 PUBLISHED THE CLAIM BEFORE ANYTHING ENFORCED IT. "What is audited is
     * the assignment" was true of the intent and of no code for one commit — AGENTS.md #14's exact shape.
     *
     * ⚠️ AND THE AUDIT IS IN THE METHODS RATHER THAN AT A BUILDER, which is a departure from ADR-020's
     * mechanism and has a reason: `AuditedBuilder` is bound to `Entry`, and `role_user` is a skeleton pivot
     * with no core model in front of it, so there is no builder here to audit at. `$user->roles()->attach()`
     * is the visible back door, in the sense that ADR already states about `toBase()`.
     */
    $role = Role::create(['handle' => 'editor', 'name' => 'Editor']);

    /** @var TestUser $user */
    $user = TestUser::create(['email' => 'audited@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => $this->org->getKey(), 'user_id' => $user->getKey()]);

    // ⚠️ Creating the role recorded nothing, and that is the line rather than a gap: a role holding no
    // grants and held by nobody is a name, not authority.
    expect(AuditLog::query()->count())->toBe(0);

    $role->grant('entry.article.update');
    $role->assignTo($user->getKey());
    $role->revoke('entry.article.update');
    $role->removeFrom($user->getKey());

    expect(AuditLog::query()->orderBy('id')->pluck('action')->all())
        ->toBe(['role.granted', 'role.assigned', 'role.revoked', 'role.unassigned']);

    /*
     * ⚠️ AND AN ASSIGNMENT ROW NAMES THE USER, NOT THE ROLE — review found the first version recording the
     * role, which made this ADR's own question (*who was made an owner*) unanswerable the moment two people
     * held one. An assignment has three parties and `audit_log` holds two, so the target is the
     * irreplaceable half and the role is still there to be read.
     */
    $assignment = AuditLog::query()->where('action', 'role.assigned')->sole();

    expect($assignment->target_id)->toBe($user->getKey())
        ->and($assignment->target_type)->toBe($user->getMorphClass());

    // A grant is about the role, and that is the right target for it.
    expect(AuditLog::query()->where('action', 'role.granted')->value('target_id'))->toBe($role->getKey());
});

it('names an owner elevation in the action, because the target cannot hold it', function (): void {
    /*
     * ⚠️ OWNER-NESS IS THE FACT ADR-033 SINGLES OUT, and with the user in the target column there is nowhere
     * else for it to go — `audit_log` has no payload by design. An action is a vocabulary rather than a
     * payload, and `role.owner_assigned` is greppable in a way a target alone is not.
     */
    $owner = Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true]);

    /** @var TestUser $user */
    $user = TestUser::create(['email' => 'elevated@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => $this->org->getKey(), 'user_id' => $user->getKey()]);

    $owner->assignTo($user->getKey());
    $owner->removeFrom($user->getKey());

    expect(AuditLog::query()->orderBy('id')->pluck('action')->all())
        ->toBe(['role.owner_assigned', 'role.owner_unassigned'])
        ->and(AuditLog::query()->where('action', 'role.owner_assigned')->value('target_id'))
        ->toBe($user->getKey());
});

it('records nothing for an operation that changed nothing', function (): void {
    /*
     * ⚠️ An idempotent call is not an event. Granting twice, revoking what was never held, assigning an
     * existing holder — each leaves the same end state it found, and a log that recorded them would fill
     * with rows that answer no question. It is the same reasoning ADR-020 gives for keeping the log to
     * actions.
     */
    $role = Role::create(['handle' => 'editor', 'name' => 'Editor']);

    /** @var TestUser $user */
    $user = TestUser::create(['email' => 'idempotent@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => $this->org->getKey(), 'user_id' => $user->getKey()]);

    $role->grant('entry.article.view');
    $role->assignTo($user->getKey());

    $before = AuditLog::query()->count();

    $role->grant('entry.article.view');
    $role->assignTo($user->getKey());
    $role->revoke('entry.article.delete');
    $role->removeFrom($user->getKey() + 1000);

    expect(AuditLog::query()->count())->toBe($before);
});
