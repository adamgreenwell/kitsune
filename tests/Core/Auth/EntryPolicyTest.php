<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Kitsune\Core\Auth\EntryPolicy;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * `EntryPolicy` — ADR-033, and Phase 4's last unchecked line.
 *
 * ⚠️ NOTHING HERE IS PERSISTED BEYOND THE ROLES, and that is deliberate rather than lazy. The policy's
 * whole job is deciding WHICH permission string a question maps to — the record's own `type_handle` with a
 * record in hand, the container's bound type without one. An unsaved `Entry` exercises that mapping exactly,
 * and building a site, a type and a row first would test the fixture.
 *
 * ⚠️ THE ENFORCEMENT IS ASSERTED ELSEWHERE, ON PURPOSE. Whether the panel actually consults this is a
 * question about the application rather than about the class, and ADR-024 says this layer structurally
 * cannot see it — so `e2e/permissions.spec.js` measures the refusal at the URL.
 */

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);

    $this->org = Org::create(['slug' => 'alpha', 'name' => 'Alpha']);
    app(Context::class)->setOrg($this->org);

    /** @var TestUser $user */
    $user = TestUser::create(['email' => 'editor@kitsune.test']);
    $this->user = $user;

    DB::table('org_user')->insert(['org_id' => $this->org->getKey(), 'user_id' => $user->getKey()]);

    $this->role = Role::create(['handle' => 'editor', 'name' => 'Editor']);
    DB::table('role_user')->insert(['role_id' => $this->role->getKey(), 'user_id' => $user->getKey()]);

    $this->policy = new EntryPolicy;
    $this->entry = new Entry(['type_handle' => 'article']);
});

it('resolves against the record\'s own type, not the request\'s', function (): void {
    /*
     * ⚠️ THE RECORD'S HANDLE IS THE ONE THAT COUNTS, and a record reached through a URL for another type is
     * exactly the confusion an attacker would arrange. So the container names `product` while the record
     * says `article`, and the `article` grant is what answers.
     */
    app()->instance(EntryType::class, new EntryType(['handle' => 'product']));
    $this->role->grant('entry.article.update');

    expect($this->policy->update($this->user, $this->entry))->toBeTrue()
        ->and($this->policy->update($this->user, new Entry(['type_handle' => 'product'])))->toBeFalse();
});

it('separates the actions rather than treating them as levels', function (): void {
    // Holding `update` is not holding `delete`, and holding `view` is not a floor under either.
    $this->role->grant('entry.article.update');

    expect($this->policy->update($this->user, $this->entry))->toBeTrue()
        ->and($this->policy->view($this->user, $this->entry))->toBeFalse()
        ->and($this->policy->delete($this->user, $this->entry))->toBeFalse();
});

it('reads the current type from the container when there is no record', function (): void {
    app()->instance(EntryType::class, new EntryType(['handle' => 'article']));
    $this->role->grant('entry.article.create');

    expect($this->policy->create($this->user))->toBeTrue()
        ->and($this->policy->viewAny($this->user))->toBeFalse();
});

it('refuses when no type has been established at all', function (): void {
    /*
     * ⚠️ FAIL CLOSED, and this is the case worth having a test for. A policy that fell back to "allowed"
     * because it could not tell which type it was being asked about would be an open door on every path
     * that forgot to establish one — and those paths are console commands, queue jobs and anything a module
     * adds, none of which go through `IdentifyEntryType`.
     */
    app()->forgetInstance(EntryType::class);
    $this->role->grant('entry.article.create');

    expect($this->policy->create($this->user))->toBeFalse()
        ->and($this->policy->viewAny($this->user))->toBeFalse()
        ->and($this->policy->publish($this->user))->toBeFalse();
});

it('maps restore and force-delete onto delete', function (): void {
    /*
     * ⚠️ NEITHER IS IN THE PUBLISHED VOCABULARY, so the question is which of the five they belong to. Both
     * operate on a deleted row, so the authority that removed it governs it — mapping them to `update`
     * would let an editor who may not delete an entry resurrect one, or erase it permanently.
     */
    $this->role->grant('entry.article.update');

    expect($this->policy->restore($this->user, $this->entry))->toBeFalse()
        ->and($this->policy->forceDelete($this->user, $this->entry))->toBeFalse();

    $this->role->grant('entry.article.delete');

    expect($this->policy->restore($this->user, $this->entry))->toBeTrue()
        ->and($this->policy->forceDelete($this->user, $this->entry))->toBeTrue();
});

it('refuses an entry whose type handle is empty', function (): void {
    // `type_handle` is denormalised and re-stamped on save, so this is a row written around the model —
    // and `entry..view` is a permission nobody can hold but which stops looking like a missing type.
    expect($this->policy->view($this->user, new Entry(['type_handle' => ''])))->toBeFalse();
});

it('is the policy the container resolves for an entry', function (): void {
    /*
     * ⚠️ THE REGISTRATION ITSELF, because every assertion above instantiates the policy directly and would
     * keep passing if nothing ever wired it up. That is the whole gap `e2e/permissions.spec.js` covers in a
     * browser; this is the cheap half of it, and it fails the moment the `Gate::policy()` line goes.
     */
    expect(Gate::getPolicyFor(Entry::class))->toBeInstanceOf(EntryPolicy::class);
});

it('lets an owner through every ability, without a grant', function (): void {
    $owner = Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true]);
    DB::table('role_user')->insert(['role_id' => $owner->getKey(), 'user_id' => $this->user->getKey()]);

    app()->instance(EntryType::class, new EntryType(['handle' => 'article']));

    expect($this->policy->viewAny($this->user))->toBeTrue()
        ->and($this->policy->create($this->user))->toBeTrue()
        ->and($this->policy->delete($this->user, $this->entry))->toBeTrue()
        ->and($this->policy->publish($this->user, $this->entry))->toBeTrue()
        ->and(Permissions::held($this->user))->toBe([]);
});

it('answers the bulk abilities Filament actually asks', function (): void {
    /*
     * ⚠️ A MISSING POLICY METHOD IS A DENIAL, INCLUDING FOR AN OWNER — review found all three absent.
     * Filament checks `deleteAny`, `forceDeleteAny` and `restoreAny` for bulk actions rather than
     * authorizing each record, so `DeleteBulkAction` asked for an ability this policy did not define and the
     * toolbar was refused to everybody. ADR-033 keeps the owner bypass inside these methods rather than in
     * `Gate::before`, so there was nothing above to rescue it: the narrower blast radius is bought with
     * every ability having to be spelled out.
     */
    app()->instance(EntryType::class, new EntryType(['handle' => 'article']));

    expect($this->policy->deleteAny($this->user))->toBeFalse()
        ->and($this->policy->forceDeleteAny($this->user))->toBeFalse()
        ->and($this->policy->restoreAny($this->user))->toBeFalse();

    $this->role->grant('entry.article.delete');

    expect($this->policy->deleteAny($this->user))->toBeTrue()
        ->and($this->policy->forceDeleteAny($this->user))->toBeTrue()
        ->and($this->policy->restoreAny($this->user))->toBeTrue();
});

it('resolves a bulk ability through the Gate, which is how it is actually reached', function (): void {
    /*
     * The assertion above instantiates the policy directly and would pass on a class Laravel never
     * consults. This is the path Filament takes — and `Gate::allows` on a CLASS rather than an instance is
     * the shape a bulk ability has, since there is no record to pass.
     */
    app()->instance(EntryType::class, new EntryType(['handle' => 'article']));
    $this->role->grant('entry.article.delete');

    expect(Gate::forUser($this->user)->allows('deleteAny', Entry::class))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('deleteAny', Entry::class))->toBeTrue();

    $this->role->revoke('entry.article.delete');

    expect(Gate::forUser($this->user)->allows('deleteAny', Entry::class))->toBeFalse();
});

/**
 * A site of this org, created in its own org's context.
 *
 * ⚠️ CONTEXT FIRST, THEN THE ROW. `EnforcesScope` refuses a write that NAMES a scope key belonging to
 * somewhere else — so a rival's site cannot be created while the context is on alpha, and the guard caught
 * every one of these fixtures on the first run. That is the guard working.
 */
function siteFor(Org $org, string $handle): Site
{
    app(Context::class)->setOrg($org);

    return Site::create(['org_id' => $org->getKey(), 'handle' => $handle, 'slug' => $handle, 'name' => $handle]);
}

/** The `article` type of this site's org, created once. */
function articleTypeFor(Site $site): EntryType
{
    app(Context::class)->setSite($site);

    return EntryType::query()->where('org_id', $site->org_id)->where('handle', 'article')->first()
        ?? EntryType::create([
            'org_id' => $site->org_id, 'handle' => 'article',
            'name' => 'Article', 'plural_name' => 'Articles',
        ]);
}

/**
 * A persisted entry belonging to one site, created in that site's context.
 *
 * ⚠️ THE REST OF THIS FILE DELIBERATELY PERSISTS NOTHING, and the scope tests below are the exception
 * rather than a change of mind: the hole they cover only exists for a row that EXISTS, because an instance
 * update or delete writes by primary key. An unsaved `Entry` has no row to reach, so the fixture has to be
 * real here and nowhere else.
 */
function entryOn(Site $site): Entry
{
    $type = articleTypeFor($site);

    return Entry::create(['entry_type_id' => $type->getKey(), 'title' => 'Row on '.$site->handle]);
}

/**
 * A persisted entry SHARED across its org, created in the context of one of that org's sites.
 *
 * `site_id` is named explicitly as null, which is what makes a row org-shared (ADR-021): the stamp fills
 * an ABSENT key and leaves a stated one alone. The context still needs a site, because clearing it would
 * clear the org with it and `EnforcesScope` has nothing to vouch for `org_id` then.
 */
function sharedEntryIn(Site $site): Entry
{
    $type = articleTypeFor($site);

    return Entry::create([
        'entry_type_id' => $type->getKey(),
        'title' => 'Row shared across '.$site->org_id,
        'site_id' => null,
    ]);
}

it('refuses a record from a sibling site, however the grant reads', function (): void {
    /*
     * ⚠️ A SCOPE ON THE QUERY IS NOT A CHECK ON THE INSTANCE, which review found this policy assuming.
     * `SiteScope` constrains the SELECT that loads an entry and says nothing about the object afterwards,
     * and an instance update or delete writes by primary key without reapplying it — so in a worker or a
     * multi-site command, a record loaded under site A survives a `Context` switch and a user in site B
     * holding the same `entry.article.update` authorised the write. B's grant, spent on A's row, with the
     * audit attributed to B.
     *
     * Same ORG, second site: the narrower boundary, and the one a merely org-scoped check would pass.
     */
    $home = siteFor($this->org, 'home');
    $sibling = siteFor($this->org, 'sib');

    $theirs = entryOn($sibling);

    // Now working in `home`, holding every action on this type.
    app(Context::class)->setSite($home);
    foreach (['view', 'update', 'delete', 'publish'] as $action) {
        $this->role->grant('entry.article.'.$action);
    }
    Permissions::forget();

    expect($this->policy->view($this->user, $theirs))->toBeFalse()
        ->and($this->policy->update($this->user, $theirs))->toBeFalse()
        ->and($this->policy->delete($this->user, $theirs))->toBeFalse()
        ->and($this->policy->restore($this->user, $theirs))->toBeFalse()
        ->and($this->policy->forceDelete($this->user, $theirs))->toBeFalse()
        ->and($this->policy->publish($this->user, $theirs))->toBeFalse();

    // And the same record, from the site it belongs to, is allowed — or the refusal above proves nothing.
    app(Context::class)->setSite($sibling);

    expect($this->policy->update($this->user, $theirs))->toBeTrue();
});

it('refuses an owner too, because the record is not theirs to reach', function (): void {
    /*
     * ⚠️ THE OWNER BYPASS IS ABOUT PERMISSIONS, NOT ABOUT TENANCY. `Permissions::isOwner()` answers for the
     * CURRENT org, so an owner of org B asked about org A's row would otherwise be told yes by the widest
     * grant in the system — the one case where the mistake costs the most.
     */
    $rival = Org::create(['slug' => 'rival', 'name' => 'Rival']);
    $theirs = entryOn(siteFor($rival, 'r'));

    app(Context::class)->setSite(siteFor($this->org, 'mine'));

    $this->role->update(['is_owner' => true]);
    Permissions::forget();

    expect(Permissions::isOwner($this->user))->toBeTrue()
        ->and($this->policy->update($this->user, $theirs))->toBeFalse()
        ->and($this->policy->view($this->user, $theirs))->toBeFalse();
});

it('allows an org-shared record from any of that org\'s sites', function (): void {
    /*
     * ⚠️ `site_id IS NULL` IS ORG-SHARED AND LEGITIMATE (ADR-021) — one media library serving eight brands.
     * A check that simply compared `site_id` to the current site would refuse every shared row, which is
     * the failure mode of tightening this by one line too many.
     */
    $home = siteFor($this->org, 'home');
    $other = siteFor($this->org, 'other');

    $shared = sharedEntryIn($home);

    $this->role->grant('entry.article.update');
    Permissions::forget();

    app(Context::class)->setSite($home);
    expect($this->policy->update($this->user, $shared))->toBeTrue();

    app(Context::class)->setSite($other);
    expect($this->policy->update($this->user, $shared))->toBeTrue();
});

it('agrees with the scope it is a copy of, shape by shape', function (): void {
    /*
     * ⚠️ THE ANTI-DRIFT PIN, and the reason `withinCurrentScope()` is allowed to be a second encoding of
     * `SiteScope`'s clause at all. The scope's version is SQL inside a WHERE and cannot be asked about an
     * object already in memory; this asserts the two answer identically for every shape a row can have, so
     * the copy cannot quietly become a wider or a narrower rule than the query it stands in for.
     *
     * `Entry::query()->whereKey(...)->exists()` IS the scope: whatever clause it applies is the oracle.
     */
    $home = siteFor($this->org, 'home');
    $sibling = siteFor($this->org, 'sib');

    $rival = Org::create(['slug' => 'rival2', 'name' => 'Rival 2']);
    $rivalSite = siteFor($rival, 'rr');

    $rows = [
        'same site' => entryOn($home),
        'sibling site, same org' => entryOn($sibling),
        'org-shared, same org' => sharedEntryIn($home),
        'another org\'s site' => entryOn($rivalSite),
        'another org\'s shared row' => sharedEntryIn($rivalSite),
    ];

    // The policy's question is asked from `home`, with every action granted so that only the scope decides.
    app(Context::class)->setOrg($this->org);
    app(Context::class)->setSite($home);
    $this->role->grant('entry.article.update');
    Permissions::forget();

    $disagreed = [];

    foreach ($rows as $shape => $row) {
        $scopeSaysYes = Entry::query()->whereKey($row->getKey())->exists();
        $policySaysYes = $this->policy->update($this->user, $row);

        if ($scopeSaysYes !== $policySaysYes) {
            $disagreed[] = $shape.': scope='.var_export($scopeSaysYes, true).' policy='.var_export($policySaysYes, true);
        }
    }

    expect($disagreed)->toBe([], 'the policy and the scope disagree: '.implode('; ', $disagreed));

    // ⚠️ Not vacuous: the oracle has to say yes to something and no to something, or an agreement is two
    // constants agreeing. Two of the five shapes are reachable from `home` and three are not.
    expect(collect($rows)->filter(fn (Entry $row): bool => Entry::query()->whereKey($row->getKey())->exists())->count())
        ->toBe(2);
});

it('says no with no site context at all, which is what the scope says', function (): void {
    /*
     * With no site established `SiteScope` adds `1 = 0` and the query returns nothing, so the policy must
     * not answer yes about a row that query could not produce. `Permissions::allows()` already refuses when
     * there is no ORG; this is the site half, and a console command holding a loaded entry is where it bites.
     *
     * ⚠️ THE ORG IS PUT BACK AFTER CLEARING THE SITE, and without that this test measured nothing. `setSite(null)`
     * clears the org with it — "setting a Site implies its Org" — so `Permissions::allows()` refused on the
     * org half and the assertion passed with the site check deleted. Measured: removing the guard left this
     * green. An org and no site is also the honest shape of the case, which is a worker that has resolved a
     * customer and not yet a site.
     */
    $row = entryOn(siteFor($this->org, 'home'));

    $this->role->grant('entry.article.update');
    Permissions::forget();

    expect($this->policy->update($this->user, $row))->toBeTrue();

    app(Context::class)->setSite(null);
    app(Context::class)->setOrg($this->org);

    expect(app(Context::class)->orgId())->toBe($this->org->getKey())
        ->and(app(Context::class)->hasSite())->toBeFalse()
        ->and(Entry::query()->whereKey($row->getKey())->exists())->toBeFalse()
        ->and($this->policy->update($this->user, $row))->toBeFalse();
});

it('still answers about an unsaved entry, which names no row', function (): void {
    /*
     * ⚠️ THE SCOPE CHECK IS FOR A ROW THAT EXISTS, and this is the line between the two. A new `Entry` has
     * no stored identity and nothing to mutate — its scope keys are the INSERT's business, stamped and
     * guarded by `EnforcesScope` — so refusing it here would break every question asked about a record
     * being built, and the type mapping the rest of this file tests is asked exactly that way.
     */
    $this->role->grant('entry.article.update');
    Permissions::forget();

    expect($this->entry->exists)->toBeFalse()
        ->and($this->policy->update($this->user, $this->entry))->toBeTrue();
});

it('asks the stored row, not the attributes it was handed', function (): void {
    /*
     * ⚠️ THE ATTRIBUTES WERE THE ORACLE AND THEY ARE A PENDING EDIT — review found it. Code preparing a
     * transfer sets a loaded org A entry's `site_id` and `org_id` to the current ones, and the comparison
     * accepted them: B's grant then authorised the write, and `EnforcesScope` accepts a destination that
     * matches the context too, so the transfer landed. The docblock already claimed the scoped query was the
     * oracle, which made it a description of an intention.
     *
     * ⚠️ AND `syncOriginal()` IS PUBLIC, so reading `getOriginal()` instead would have been the same defect
     * one method along. Only the database is out of the caller's reach.
     */
    $home = siteFor($this->org, 'home');
    $sibling = siteFor($this->org, 'sib');

    $theirs = entryOn($sibling);

    app(Context::class)->setSite($home);
    $this->role->grant('entry.article.update');
    Permissions::forget();

    // The forgery: the record now claims to live on the site we are working in.
    $theirs->site_id = $home->getKey();
    $theirs->org_id = $this->org->getKey();

    expect((int) $theirs->site_id)->toBe($home->getKey())
        ->and($this->policy->update($this->user, $theirs))->toBeFalse()
        ->and($this->policy->view($this->user, $theirs))->toBeFalse()
        ->and($this->policy->delete($this->user, $theirs))->toBeFalse();

    // And a `syncOriginal()` that makes the edit look clean changes nothing either.
    $theirs->syncOriginal();

    expect($theirs->isDirty())->toBeFalse()
        ->and($this->policy->update($this->user, $theirs))->toBeFalse();
});

it('refuses a record whose primary key has been edited', function (): void {
    /*
     * The same shape `Role` carries: an instance update or delete writes by the ORIGINAL key, so a changed
     * `id` attribute would have the policy answer about one row while the write touches another.
     */
    $home = siteFor($this->org, 'home');
    $mine = entryOn($home);
    $other = entryOn($home);

    app(Context::class)->setSite($home);
    $this->role->grant('entry.article.delete');
    Permissions::forget();

    expect($this->policy->delete($this->user, $mine))->toBeTrue();

    $mine->id = $other->getKey();

    expect($this->policy->delete($this->user, $mine))->toBeFalse();
});
