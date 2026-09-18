<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Models\SiteGroup;
use Kitsune\Core\Settings\SettingsResolver;
use Kitsune\Core\Tenancy\Context;

/*
 * ADR-022: sparse overrides resolved org → site group → site, shallow merge
 * on top-level keys, and every resolved value carries its origin.
 *
 * ⚠️ THE CONTAINER'S RESOLVER, NOT ONE BUILT HERE. Invalidation is automatic — the models' own events drop what was
 * resolved from them — and those events reach the instance the application is bound to. A resolver constructed in
 * the test would be one nothing invalidates, so a test of it could only ever prove the manual `forget()`.
 */

beforeEach(function (): void {
    // Read when the resolver is first built, which is the `app()` call at the end of this hook.
    config(['kitsune.settings' => ['timezone' => 'Europe/London', 'theme' => 'default']]);

    $this->org = Org::create([
        'name' => 'Golfdom Media',
        'slug' => 'golfdom-media',
        'settings' => ['timezone' => 'UTC', 'logo' => 'org-logo.svg', 'analytics_id' => null],
    ]);

    app(Context::class)->setOrg($this->org);

    $this->group = SiteGroup::create([
        'org_id' => $this->org->id,
        'handle' => 'golfdom',
        'name' => 'Golfdom',
        'settings' => ['logo' => 'golfdom-logo.svg'],
    ]);

    $this->site = Site::create([
        'org_id' => $this->org->id,
        'site_group_id' => $this->group->id,
        'handle' => 'golfdom-fr', 'slug' => 'golfdom-fr',
        'name' => 'Golfdom FR',
        'locale' => 'fr',
        'settings' => ['analytics_id' => 'UA-FR-1'],
    ]);

    $this->resolver = app(SettingsResolver::class);
});

afterEach(fn () => app(Context::class)->forget());

it('falls back to the platform default when nothing overrides', function (): void {
    $resolved = $this->resolver->resolve($this->site, 'theme');

    expect($resolved->value)->toBe('default');
    expect($resolved->origin)->toBe('default');
    expect($resolved->describe())->toBe('platform default');
});

it('lets the org override a platform default', function (): void {
    $resolved = $this->resolver->resolve($this->site, 'timezone');

    expect($resolved->value)->toBe('UTC');
    expect($resolved->origin)->toBe('org');
});

it('lets the site group override the org', function (): void {
    $resolved = $this->resolver->resolve($this->site, 'logo');

    expect($resolved->value)->toBe('golfdom-logo.svg');
    expect($resolved->origin)->toBe('site_group');
    expect($resolved->describe())->toBe('inherited from site group Golfdom');
});

it('lets the site override everything above it', function (): void {
    $resolved = $this->resolver->resolve($this->site, 'analytics_id');

    expect($resolved->value)->toBe('UA-FR-1');
    expect($resolved->origin)->toBe('site');
    expect($resolved->isInherited())->toBeFalse();
});

it('reports inheritance so the admin can show provenance', function (): void {
    // ADR-022 treats this as a first-class requirement, not a nicety:
    // opacity is what makes operators afraid to touch configuration.
    expect($this->resolver->resolve($this->site, 'logo')->isInherited())->toBeTrue();
    expect($this->resolver->resolve($this->site, 'analytics_id')->isInherited())->toBeFalse();
});

it('stores only overrides, so absent means inherit', function (): void {
    // The site row holds one key. It does not carry a copy of everything,
    // which is what keeps an intentional override distinguishable from a
    // stale duplicate.
    expect(array_keys((array) $this->site->settings))->toBe(['analytics_id']);
    expect($this->resolver->all($this->site))->toHaveKeys(['timezone', 'theme', 'logo', 'analytics_id']);
});

it('overrides a whole key rather than merging into it', function (): void {
    $this->org->update(['settings' => ['nav' => ['a' => 1, 'b' => 2]]]);
    $this->site->update(['settings' => ['nav' => ['a' => 9]]]);

    // Shallow merge: a setting is atomic. Half-inherited nested objects are
    // the most confusing possible outcome.
    expect($this->resolver->resolve($this->site, 'nav')->value)->toBe(['a' => 9]);
});

it('invalidates the resolved cache when a setting changes, with nobody calling forget()', function (): void {
    expect($this->resolver->get($this->site, 'analytics_id'))->toBe('UA-FR-1');

    // A plain model write — not the settings writer — and no forget() after it. ADR-022: invalidation must be
    // correct AND automatic. "I changed the setting and nothing happened" is the support burden this prevents.
    $this->site->update(['settings' => ['analytics_id' => 'UA-FR-2']]);

    expect($this->resolver->get($this->site, 'analytics_id'))->toBe('UA-FR-2');
});

it('returns a fallback for an unknown key', function (): void {
    expect($this->resolver->get($this->site, 'nope', 'fallback'))->toBe('fallback');
    expect($this->resolver->resolve($this->site, 'nope'))->toBeNull();
});

describe('what it reads', function (): void {
    /*
     * ⚠️ THE TRAP THE FIRST VERSION WAS IN. It read `$site->org->settings` and `$site->siteGroup->settings`, and a
     * relation loads once per model instance — so after a level changed through a DIFFERENT instance, dropping the
     * memo was not enough: resolving again through the same `$site` read the relation as that instance first loaded
     * it. Measured on the old code: an org moved from UTC to America/New_York through another instance still
     * resolved UTC. Each of these writes through a fresh instance and resolves through the original one.
     */
    it('sees an org-level write made through another instance, resolving through the same site', function (): void {
        expect($this->resolver->get($this->site, 'timezone'))->toBe('UTC');

        Org::query()->findOrFail($this->org->id)->update(['settings' => ['timezone' => 'America/New_York']]);

        expect($this->resolver->get($this->site, 'timezone'))->toBe('America/New_York');
    });

    it('sees a site-group write made through another instance', function (): void {
        expect($this->resolver->get($this->site, 'logo'))->toBe('golfdom-logo.svg');

        SiteGroup::query()->findOrFail($this->group->id)->update(['settings' => ['logo' => 'rebrand.svg']]);

        expect($this->resolver->get($this->site, 'logo'))->toBe('rebrand.svg');
    });

    it('sees a site write made through another instance', function (): void {
        expect($this->resolver->get($this->site, 'analytics_id'))->toBe('UA-FR-1');

        Site::query()->findOrFail($this->site->id)->update(['settings' => ['analytics_id' => 'UA-FR-3']]);

        expect($this->resolver->get($this->site, 'analytics_id'))->toBe('UA-FR-3');
    });

    it('resolves the site group with no org context, which the relation could not', function (): void {
        /*
         * ⚠️ MEASURED ON THE OLD CODE: a console command or queued job holding a site, with no org context,
         * resolved `logo` to null — not even the org's value. `SiteGroup` is `#[OrgScoped]`, and `OrgScope` with
         * no context matches nothing, so the `siteGroup` relation found no group and the brand level vanished.
         */
        app(Context::class)->forget();

        $resolved = $this->resolver->resolve($this->site, 'logo');

        expect($resolved?->value)->toBe('golfdom-logo.svg')
            ->and($resolved?->origin)->toBe('site_group');
    });

    it('reads a group only within the site\'s own org', function (): void {
        // A `site_group_id` naming another org's group is not a foreign key the schema can refuse, so the read
        // pins the group to the site's org rather than trusting the id.
        $rival = Org::create(['name' => 'Rival', 'slug' => 'rival-resolver']);
        $theirs = SiteGroup::withoutScopeBecause('a rival org\'s group, for the test', fn () => SiteGroup::create([
            'org_id' => $rival->id, 'handle' => 'theirs', 'name' => 'Theirs', 'settings' => ['logo' => 'theirs.svg'],
        ]));

        DB::table('sites')->where('id', $this->site->id)->update(['site_group_id' => $theirs->id]);
        $this->resolver->forget($this->site);

        expect($this->resolver->resolve($this->site, 'logo')->origin)->toBe('org');
    });
});

it('resolves the defaults, marked as defaults, when there is no site', function (): void {
    // An org-level page, a console command and a queued job have no site — and still format dates.
    expect($this->resolver->get(null, 'timezone'))->toBe('Europe/London')
        ->and($this->resolver->resolve(null, 'timezone')->origin)->toBe('default')
        ->and($this->resolver->all(null))->toHaveKeys(['timezone', 'theme']);
});

it('is one resolver per request, built from configuration', function (): void {
    // Scoped, like `Context`: a long-lived worker must not carry one request's memo into the next job.
    expect(app(SettingsResolver::class))->toBe($this->resolver);

    app()->forgetScopedInstances();
    config(['kitsune.settings' => ['timezone' => 'Asia/Tokyo']]);

    expect(app(SettingsResolver::class))->not->toBe($this->resolver)
        ->and(app(SettingsResolver::class)->get(null, 'timezone'))->toBe('Asia/Tokyo');
});

describe('forget() reaches exactly a scope and what is beneath it', function (): void {
    /*
     * The memo is observed through staleness rather than by reflection. A raw `DB::table()` write fires no model
     * event, so after one only `forget()` decides whether a site re-reads: a site whose entry was dropped sees the
     * new value, and a site whose entry was kept still sees the old one.
     */
    beforeEach(function (): void {
        $this->sister = Site::create([
            'org_id' => $this->org->id, 'site_group_id' => $this->group->id,
            'handle' => 'golfdom-en', 'slug' => 'golfdom-en', 'name' => 'Golfdom EN',
        ]);
        $this->otherGroup = SiteGroup::create(['org_id' => $this->org->id, 'handle' => 'other', 'name' => 'Other']);
        $this->cousin = Site::create([
            'org_id' => $this->org->id, 'site_group_id' => $this->otherGroup->id,
            'handle' => 'other-site', 'slug' => 'other-site', 'name' => 'Other site',
        ]);
        $this->loner = Site::create(['org_id' => $this->org->id, 'handle' => 'loner', 'slug' => 'loner', 'name' => 'Loner']);

        $this->rival = Org::create(['name' => 'Rival', 'slug' => 'rival', 'settings' => ['timezone' => 'Asia/Tokyo']]);
        $this->stranger = Site::withoutScopeBecause('a rival org\'s site, for the test', fn () => Site::create([
            'org_id' => $this->rival->id, 'handle' => 'stranger', 'slug' => 'stranger', 'name' => 'Stranger',
        ]));

        $this->all = ['site' => $this->site, 'sister' => $this->sister, 'cousin' => $this->cousin, 'loner' => $this->loner, 'stranger' => $this->stranger];

        foreach ($this->all as $site) {
            $this->resolver->all($site);
        }

        // Every level of every site now reads `probe` = changed, and none has been told.
        foreach (['orgs', 'site_groups', 'sites'] as $table) {
            DB::table($table)->update(['settings' => json_encode(['probe' => 'changed'])]);
        }

        $this->reread = fn (): array => array_keys(array_filter(
            $this->all,
            fn (Site $site): bool => $this->resolver->get($site, 'probe') === 'changed',
        ));
    });

    it('forgets an org and every site of it, and no site of another org', function (): void {
        $this->resolver->forget($this->org);

        expect(($this->reread)())->toBe(['site', 'sister', 'cousin', 'loner']);
    });

    it('forgets the other org\'s sites when that org is forgotten, and none of this one\'s', function (): void {
        $this->resolver->forget($this->rival);

        expect(($this->reread)())->toBe(['stranger']);
    });

    it('forgets a site group and its sites, and no site of a sibling group or of none', function (): void {
        $this->resolver->forget($this->group);

        expect(($this->reread)())->toBe(['site', 'sister']);
    });

    it('forgets a site and nothing else, not even its sister in the same group', function (): void {
        $this->resolver->forget($this->site);

        expect(($this->reread)())->toBe(['site']);
    });

    it('forgets everything when given no scope', function (): void {
        $this->resolver->forget();

        expect(($this->reread)())->toBe(['site', 'sister', 'cousin', 'loner', 'stranger']);
    });
});
