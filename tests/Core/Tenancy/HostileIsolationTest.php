<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Models\SiteGroup;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\SharedThing;
use Kitsune\Core\Tests\Fixtures\SiteThing;

/*
 * ADR-009 and ADR-021 call these the most valuable tests in the codebase, and
 * they are written from the attacker's side on purpose: each one sets up a
 * context that SHOULD NOT see a row, then tries to see it.
 *
 * There are two boundaries and they are not equally protected. Cross-site has
 * Filament's tenancy underneath it. Cross-org has nothing — Filament does not
 * model Org — so the second group is the one with no safety net.
 */

beforeEach(function (): void {
    // Two orgs. Org A has two sites in one group; Org B has one.
    $this->orgA = Org::create(['name' => 'Golfdom Media', 'slug' => 'golfdom-media']);
    $this->orgB = Org::create(['name' => 'Rival Publishing', 'slug' => 'rival']);

    $context = app(Context::class);

    $context->setOrg($this->orgA);
    $groupA = SiteGroup::create(['org_id' => $this->orgA->id, 'handle' => 'golfdom', 'name' => 'Golfdom']);
    $this->siteA1 = Site::create(['org_id' => $this->orgA->id, 'site_group_id' => $groupA->id, 'handle' => 'golfdom-en', 'slug' => 'golfdom-en', 'name' => 'Golfdom', 'locale' => 'en']);
    $this->siteA2 = Site::create(['org_id' => $this->orgA->id, 'site_group_id' => $groupA->id, 'handle' => 'golfdom-fr', 'slug' => 'golfdom-fr', 'name' => 'Golfdom FR', 'locale' => 'fr']);

    $context->setOrg($this->orgB);
    $this->siteB1 = Site::create(['org_id' => $this->orgB->id, 'handle' => 'rival', 'slug' => 'rival', 'name' => 'Rival', 'locale' => 'en']);

    $context->forget();
});

afterEach(fn () => app(Context::class)->forget());

/* ─────────────── boundary 1: cross-site within one org ─────────────── */

describe('cross-site isolation within one org', function (): void {
    it('does not leak another site\'s rows', function (): void {
        app(Context::class)->setSite($this->siteA1);
        SiteThing::create(['label' => 'english-only']);

        // Same org, different site. The attacker is a legitimate user here.
        app(Context::class)->setSite($this->siteA2);

        expect(SiteThing::count())->toBe(0);
        expect(SiteThing::where('label', 'english-only')->exists())->toBeFalse();
    });

    it('does not leak by primary key either', function (): void {
        app(Context::class)->setSite($this->siteA1);
        $id = SiteThing::create(['label' => 'english-only'])->id;

        app(Context::class)->setSite($this->siteA2);

        expect(SiteThing::find($id))->toBeNull();
    });

    it('DOES share org-shared rows across sites in the same org', function (): void {
        // site_id NULL means shared (ADR-021) - the media library case.
        app(Context::class)->setSite($this->siteA1);
        SiteThing::create(['label' => 'shared-asset', 'site_id' => null]);

        app(Context::class)->setSite($this->siteA2);

        expect(SiteThing::where('label', 'shared-asset')->exists())->toBeTrue();
    });
});

/* ─────────── boundary 2: cross-org — no framework safety net ─────────── */

describe('cross-org isolation', function (): void {
    it('does not leak another org\'s site-scoped rows', function (): void {
        app(Context::class)->setSite($this->siteA1);
        SiteThing::create(['label' => 'confidential']);

        app(Context::class)->setSite($this->siteB1);

        expect(SiteThing::count())->toBe(0);
        expect(SiteThing::where('label', 'confidential')->exists())->toBeFalse();
    });

    it('does not leak another org\'s org-scoped rows', function (): void {
        app(Context::class)->setOrg($this->orgA);
        SharedThing::create(['label' => 'org-a-billing']);

        app(Context::class)->setOrg($this->orgB);

        expect(SharedThing::count())->toBe(0);
    });

    it('does not leak org-SHARED rows across orgs', function (): void {
        // The dangerous case: site_id IS NULL must still be fenced by org, or
        // a bare NULL check would expose every org's shared media to everyone.
        app(Context::class)->setSite($this->siteA1);
        SiteThing::create(['label' => 'org-a-shared-media', 'site_id' => null]);

        app(Context::class)->setSite($this->siteB1);

        expect(SiteThing::where('label', 'org-a-shared-media')->exists())->toBeFalse();
    });

    it('cannot be escaped by writing a row into another org', function (): void {
        // Reading and writing are separate holes. A scope that only filters
        // SELECTs still lets a caller insert into someone else's org.
        app(Context::class)->setSite($this->siteA1);

        expect(fn () => SiteThing::create(['label' => 'planted', 'org_id' => $this->orgB->id]))
            ->toThrow(RuntimeException::class, 'Refusing to write');

        expect(SiteThing::withoutGlobalScopes()->where('label', 'planted')->exists())->toBeFalse();
    });

    /*
     * ⚠️ This is the case the test above USED to stand in for, and its old
     * comment is why: "even if the attacker forced org_id, site_id still pins
     * it to site A1". That was true of forcing ONE key. Forcing both was never
     * tested, and the trait only DEFAULTED them — `site_id` when the key was
     * absent, `org_id` through `??` — so both supplied values were inserted
     * verbatim. With `status = published` on an Entry that plants live content
     * on a rival's public site, which SiteScope then shows to them and hides
     * from its author.
     */
    it('cannot be escaped by forcing BOTH keys, which the old test did not cover', function (): void {
        app(Context::class)->setSite($this->siteA1);

        expect(fn () => SiteThing::create([
            'label' => 'planted', 'org_id' => $this->orgB->id, 'site_id' => $this->siteB1->id,
        ]))->toThrow(RuntimeException::class, 'Refusing to write');

        app(Context::class)->setSite($this->siteB1);

        expect(SiteThing::where('label', 'planted')->exists())->toBeFalse();
    });

    it('cannot be escaped by MOVING a saved row, which stamping never saw', function (): void {
        app(Context::class)->setSite($this->siteA1);
        $mine = SiteThing::create(['label' => 'mine']);

        expect(fn () => $mine->update(['org_id' => $this->orgB->id, 'site_id' => $this->siteB1->id]))
            ->toThrow(RuntimeException::class, 'Refusing to write');

        expect($mine->fresh()->org_id)->toBe($this->orgA->id);
    });

    /*
     * ⚠️ The guards live in model events, and a MASS UPDATE instantiates no
     * models. The global scope limits which rows are SELECTED and says nothing
     * about the values assigned, so this transferred the current scope's rows
     * into another org — where the scope then showed them to their new owner
     * and hid them from their author — without going near
     * withoutScopeBecause().
     *
     * Fourth model in this project to need a builder for this shape. It is not
     * a property of any of them: a guard in a model event is a guard on one
     * path.
     */
    it('cannot be escaped by a MASS UPDATE, which dispatches nothing', function (): void {
        app(Context::class)->setSite($this->siteA1);
        SiteThing::create(['label' => 'mine']);

        expect(fn () => SiteThing::query()->update([
            'org_id' => $this->orgB->id, 'site_id' => $this->siteB1->id,
        ]))->toThrow(RuntimeException::class, 'Refusing to write');

        expect(SiteThing::where('label', 'mine')->exists())->toBeTrue();
    });

    it('catches a QUALIFIED column name in a mass update', function (): void {
        app(Context::class)->setSite($this->siteA1);
        SiteThing::create(['label' => 'mine']);

        expect(fn () => SiteThing::query()->update(['site_things.org_id' => $this->orgB->id]))
            ->toThrow(RuntimeException::class, 'Refusing to write');
    });

    it('refuses updateFrom, whose assignments are invisible here', function (): void {
        app(Context::class)->setSite($this->siteA1);

        expect(fn () => SiteThing::query()->updateFrom(['org_id' => $this->orgB->id]))
            ->toThrow(RuntimeException::class, 'assigns through a join');
    });

    it('refuses to force-delete past the cascade refusal', function (): void {
        // ⚠️ Eloquent sends forceDelete() straight to the query builder rather
        // than through delete(), so the cascade refusal never saw it — and on a
        // soft-deleting model it is the call that actually removes rows.
        app(Context::class)->setSite($this->siteA1);

        expect(fn () => SiteThing::query()->forceDelete())->not->toThrow(TypeError::class);
    });

    it('refuses an UPSERT, whose conflict target the scope does not constrain', function (): void {
        /*
         * ⚠️ An upsert resolves its conflict on the unique key, which the global
         * scope does not touch. From org A, upserting a row carrying org A's
         * `org_id` but org B's primary key passes every value check and then
         * UPDATES org B's row — the row being overwritten is never named in the
         * values, so there is nothing here that could make it safe.
         */
        app(Context::class)->setSite($this->siteA1);

        expect(fn () => SiteThing::query()->upsert(
            [['id' => 999, 'label' => 'planted', 'org_id' => $this->orgA->id, 'site_id' => $this->siteA1->id]],
            ['id'],
            ['label'],
        ))->toThrow(RuntimeException::class, 'conflict target is not constrained');
    });

    it('still allows a mass update that leaves the scope keys alone', function (): void {
        app(Context::class)->setSite($this->siteA1);
        SiteThing::create(['label' => 'mine']);

        expect(fn () => SiteThing::query()->update(['label' => 'renamed']))
            ->not->toThrow(RuntimeException::class);

        expect(SiteThing::where('label', 'renamed')->exists())->toBeTrue();
    });

    it('still lets an org-shared row be written with a null site', function (): void {
        // NULL site_id is legitimate and must stay so: it is how a row is
        // shared across an org's sites.
        app(Context::class)->setSite($this->siteA1);

        expect(fn () => SiteThing::create(['label' => 'shared', 'site_id' => null]))
            ->not->toThrow(RuntimeException::class);
    });

    it('still lets the reviewable escape hatch write across scopes', function (): void {
        // Provisioning and cross-org admin tooling legitimately do, which is
        // what withoutScopeBecause() is for.
        app(Context::class)->setSite($this->siteA1);

        $planted = SiteThing::withoutScopeBecause('test: provisioning another org', fn () => SiteThing::create([
            'label' => 'provisioned', 'org_id' => $this->orgB->id, 'site_id' => $this->siteB1->id,
        ]));

        expect($planted->org_id)->toBe($this->orgB->id);
    });
});

/* ─────────────────────── fail closed, always ─────────────────────── */

describe('failing closed', function (): void {
    it('returns nothing at all when no context is set', function (): void {
        app(Context::class)->setSite($this->siteA1);
        SiteThing::create(['label' => 'anything']);

        app(Context::class)->forget();

        // A forgotten middleware must be a visible outage, never a silent leak.
        expect(SiteThing::count())->toBe(0);
        expect(SharedThing::count())->toBe(0);
    });

    it('clears the site when the org is switched underneath it', function (): void {
        $context = app(Context::class);
        $context->setSite($this->siteA1);
        expect($context->siteId())->toBe($this->siteA1->id);

        $context->setOrg($this->orgB);

        // A site belonging to org A cannot survive a switch to org B.
        expect($context->site())->toBeNull();
    });
});

/* ───────── route key must be globally unique (ADR-021 amendment) ───────── */

describe('site route keys', function (): void {
    it('allows two orgs to use the same handle', function (): void {
        // UNIQUE (org_id, handle): handles are an operator's own naming,
        // scoped to their organisation.
        app(Context::class)->setOrg($this->orgB);

        $duplicate = Site::create([
            'org_id' => $this->orgB->id,
            'handle' => 'golfdom-en',
            'slug' => 'rival-golfdom-en',
            'name' => 'Rival',
        ]);

        expect($duplicate->handle)->toBe($this->siteA1->handle);
    });

    it('constrains the slug to be globally unique, because it is the route key', function (): void {
        // Asserted through schema introspection rather than by triggering the
        // violation. Provoking it is not portable: PostgreSQL aborts the whole
        // transaction, poisoning the RefreshDatabase wrapper, and MySQL drops
        // the savepoint meant to contain that. The guarantee under test is
        // that the constraint EXISTS, and this checks exactly that.
        //
        // /admin/{site} carries no org segment, so the segment identifying a
        // site must be unique across the installation. For a user belonging
        // to both orgs the URL would otherwise be genuinely ambiguous.
        $unique = collect(Schema::getIndexes('sites'))
            ->filter(fn (array $index): bool => (bool) ($index['unique'] ?? false))
            ->pluck('columns');

        expect($unique)->toContain(['slug']);
    });

    it('routes on the slug, not the handle', function (): void {
        expect((new Site)->getRouteKeyName())->toBe('slug');
    });
});
