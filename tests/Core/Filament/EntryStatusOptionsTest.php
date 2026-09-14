<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRevision;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
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

/** A real entry of a real type, which the options tests above do not need and these two do. */
function publishedArticle(Org $org): Entry
{
    $site = Site::create(['org_id' => $org->getKey(), 'handle' => 's', 'slug' => 's', 'name' => 'S']);
    app(Context::class)->setSite($site);

    $type = EntryType::create([
        'org_id' => $org->getKey(), 'handle' => 'article',
        'name' => 'Article', 'plural_name' => 'Articles',
    ]);

    app()->instance(EntryType::class, $type);

    return Entry::create([
        'entry_type_id' => $type->getKey(),
        'title' => 'Published piece',
        'status' => 'published',
    ]);
}

it('asks the database for the status, not the instance it was handed', function (): void {
    /*
     * ⚠️ THE CONCESSION IS ABOUT THE STORED ROW AND IT WAS READING A LOADED ATTRIBUTE — review found the gap
     * between the sentence this file publishes and the value the resource passed. An editor without
     * `publish` keeps the `published` option only because the entry IS published; with the form held open
     * across somebody else's demotion, `$record->status` still said published, so the option stayed offered
     * and the `in` rule kept accepting it.
     */
    $this->role->grant(Permissions::forEntryType('article', 'update'));

    $entry = publishedArticle($this->org);

    // Somebody who may publish demotes it while this instance is in hand.
    DB::table('entries')->where('id', $entry->getKey())->update(['status' => 'draft']);

    // The instance is stale, which is the whole shape of the defect.
    expect($entry->status)->toBe('published')
        ->and(array_keys(EntryResource::statusOptionsFor($this->user, $entry)))->toBe(['draft', 'archived']);
});

it('does not put a demoted entry back when a stale form saves', function (): void {
    /*
     * ⚠️ THE REPORTED CONSEQUENCE OF THAT WINDOW DID NOT REPRODUCE, AND SAYING SO IS THE POINT OF THIS TEST.
     * The review that found the stale read described the stale form OVERWRITING the newer draft state. It
     * does not: Eloquent writes dirty attributes, and an instance loaded as `published` submitting
     * `published` puts no status in the update at all — the demotion survives and the edit lands.
     *
     * It is kept because the guard above is one read away from that being untrue, and because it is a fact
     * about the framework rather than about Kitsune: nothing here would notice if it changed.
     */
    $entry = publishedArticle($this->org);

    DB::table('entries')->where('id', $entry->getKey())->update(['status' => 'draft']);

    // The form submits the value it rendered, alongside the edit somebody actually made.
    $entry->status = 'published';
    $entry->title = 'Retitled by the copy editor';
    $entry->save();

    $stored = DB::table('entries')->where('id', $entry->getKey())->first(['status', 'title']);

    expect($stored->status)->toBe('draft')
        ->and($stored->title)->toBe('Retitled by the copy editor');
});

it('refuses a restore that would publish, from somebody who may not', function (): void {
    /*
     * ⚠️ THE FORM IS NOT THE ONLY WAY INTO THE PUBLISHED STATE, which is where review's question about the
     * stale read led rather than where it pointed. `EntryRevision::SNAPSHOT_ATTRIBUTES` carries `status`, so
     * restoring a version that was published publishes the entry — through a button with no rule behind it,
     * for an editor holding only `entry.article.update`. ADR-033 registers `publish` as the permission to
     * move INTO that state; this is the other route into it.
     */
    $this->role->grant(Permissions::forEntryType('article', 'update'));

    $entry = publishedArticle($this->org);

    // Demoted the way somebody who may publish would demote it, which files the second version.
    $entry->status = 'draft';
    $entry->save();

    $published = EntryRevision::query()
        ->where('entry_id', $entry->getKey())
        ->where('status', 'published')
        ->firstOrFail();

    Auth::login($this->user);
    Permissions::forget();

    expect(fn () => $entry->restoreRevision($published))
        ->toThrow(RuntimeException::class, 'entry.article.publish');

    expect(DB::table('entries')->where('id', $entry->getKey())->value('status'))->toBe('draft');

    /*
     * ⚠️ And it is a permission check rather than a lock on the operation: the same restore, by somebody who
     * may publish, goes through. Without this half the test would pass against a guard that refused every
     * restore.
     */
    $this->role->grant(Permissions::forEntryType('article', 'publish'));
    Permissions::forget();

    $entry->restoreRevision($published);

    expect(DB::table('entries')->where('id', $entry->getKey())->value('status'))->toBe('published');
});

it('refuses an instance write that publishes, whatever the form allowed', function (): void {
    /*
     * ⚠️ THIS IS REVIEW'S THIRD FRAMING OF THE SAME WINDOW, AND IT DEFEATS THE ARGUMENT I PUBLISHED AGAINST
     * CLOSING IT. I said the stale form could not put an entry back because Eloquent writes dirty attributes:
     * an instance loaded as `published` submitting `published` writes no status at all. That is true and it is
     * not enough — an instance loaded as DRAFT, with the stored row published while validation ran and demoted
     * again before the save, submits a `published` that IS dirty, and the write lands.
     *
     * So the transition is now decided inside the write, from the locked row, and the form's rule is the
     * courtesy that gives a validation error instead of an exception.
     */
    $this->role->grant(Permissions::forEntryType('article', 'update'));

    $entry = publishedArticle($this->org);

    $entry->status = 'draft';
    $entry->save();

    Auth::login($this->user);
    Permissions::forget();

    // The form rendered `published` as an option; this instance was loaded as a draft.
    $entry->status = 'published';

    expect(fn () => $entry->save())->toThrow(RuntimeException::class, 'entry.article.publish')
        ->and(DB::table('entries')->where('id', $entry->getKey())->value('status'))->toBe('draft');

    /*
     * ⚠️ AND IT IS A PERMISSION RATHER THAN A LOCK: the same write, by somebody who may publish, lands. Without
     * this half the guard could be refusing every publication and the test would not notice.
     */
    $this->role->grant(Permissions::forEntryType('article', 'publish'));
    Permissions::forget();

    $entry->save();

    expect(DB::table('entries')->where('id', $entry->getKey())->value('status'))->toBe('published');
});

it('leaves the supported bulk publish alone, which is the limitation stated in ADR-033', function (): void {
    /*
     * ⚠️ THE OTHER HALF OF THE DECISION, asserted so it cannot be tightened by accident.
     * `Entry::query()->update(['status' => 'published'])` is a write this project deliberately allows, audits
     * and versions — it arrives on a prototype with no key, carries no acting identity, and the scope is what
     * narrows it. A guard that refused it would break the supported path; one that skipped it silently would
     * be claiming a boundary it does not have. This test is the claim, in the form that fails if either
     * changes.
     */
    $this->role->grant(Permissions::forEntryType('article', 'update'));

    $entry = publishedArticle($this->org);

    $entry->status = 'draft';
    $entry->save();

    Auth::login($this->user);
    Permissions::forget();

    Entry::query()->whereKey($entry->getKey())->update(['status' => 'published']);

    expect(DB::table('entries')->where('id', $entry->getKey())->value('status'))->toBe('published');
});

it('refuses a status the vocabulary does not contain', function (): void {
    /*
     * ⚠️ THE SECOND HALF OF THE COLLATION FINDING, AND THE HALF THAT CANNOT BE FIXED BY COMPARING BETTER.
     * MySQL's and MariaDB's default collations are accent-insensitive as well as case-insensitive, so
     * `scopePublished()`'s `status = 'published'` matches a stored `publíshed` — while `mb_strtolower()`
     * leaves that a different string and the publication guard stands aside. No PHP predicate can enumerate
     * what a given server considers equal, because it depends on the column's collation.
     *
     * So the column holds a closed set and the question stops being askable. `archived` still works, which
     * is what keeps this a vocabulary rather than a lock.
     *
     * ⚠️ THIS REPLACED A TEST THAT ASSERTED THE CASE-VARIANTS WERE REFUSED AS UNPERMITTED PUBLICATIONS. They
     * are refused earlier now, for not being statuses at all, and one test asserting the stronger rule beats
     * two asserting the same values through different guards. The canonical spelling's permission check is
     * covered by `it refuses an instance write that publishes`.
     */
    $this->role->grant(Permissions::forEntryType('article', 'update'));

    $entry = publishedArticle($this->org);

    $entry->status = 'draft';
    $entry->save();

    Auth::login($this->user);
    Permissions::forget();

    foreach (['publíshed', 'PUBLISHED', 'pending', ''] as $spelling) {
        $entry->status = $spelling;

        expect(fn () => $entry->save())
            ->toThrow(RuntimeException::class, 'entry status', "[{$spelling}] was stored");
    }

    expect(DB::table('entries')->where('id', $entry->getKey())->value('status'))->toBe('draft');

    // And a value the vocabulary does contain still writes, by somebody who may not publish.
    $entry->status = 'archived';
    $entry->save();

    expect(DB::table('entries')->where('id', $entry->getKey())->value('status'))->toBe('archived');
});
