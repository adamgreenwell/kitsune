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
        $thing = SiteThing::create(['label' => 'planted', 'org_id' => $this->orgB->id]);

        app(Context::class)->setSite($this->siteB1);

        // Even if the attacker forced org_id, site_id still pins it to site A1.
        expect(SiteThing::where('label', 'planted')->exists())->toBeFalse();
        expect($thing->site_id)->toBe($this->siteA1->id);
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
