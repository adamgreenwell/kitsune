<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Blueprints\BlueprintRegistry;
use Kitsune\Core\Blueprints\Declarations\RoleDeclaration;
use Kitsune\Core\Blueprints\FirstOrg;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\RolePermission;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\FixtureBlueprint;

/*
 * The console seam — ADR-039. The work lives in `BlueprintApplier`, so what these assert is argument handling,
 * the refusals an operator meets, and the context being given back however the command ends.
 */

beforeEach(function (): void {
    FixtureBlueprint::reset();

    app(BlueprintRegistry::class)->register(new FixtureBlueprint);

    $this->org = Org::create(['slug' => 'acme', 'name' => 'Acme']);
});

afterEach(fn () => app(Context::class)->forget());

it('lists what is registered', function (): void {
    $this->artisan('kitsune:blueprint list')
        ->expectsOutputToContain('fixture')
        ->assertSuccessful();
});

/** Blog is core's own, so it is listed with nothing but core installed. */
/*
 * Blog is core's own, so it is listed with nothing but core installed — matched as one row, because the output mock
 * gives each written line to the first expectation it fits, and the fixture's row would answer a bare '1.0.0'.
 */
it('lists Blog', function (): void {
    expect(Artisan::call('kitsune:blueprint', ['action' => 'list']))->toBe(0)
        ->and(Artisan::output())->toMatch('/\|\s*blog\s*\|\s*1\.0\.0\s*\|\s*Kitsune\\\\Core\\\\Blueprints\\\\FirstParty\\\\BlogBlueprint\s*\|/');
});

it('applies into a named org', function (): void {
    $this->artisan('kitsune:blueprint apply fixture --org=acme')->assertSuccessful();

    expect(EntryType::query()->where('org_id', $this->org->getKey())->where('handle', 'dispatch')->exists())
        ->toBeTrue();
});

/**
 * ⚠️ THE CONTEXT IS GIVEN BACK, AND CLEARED RATHER THAN LEFT POINTING AT THE APPLIED ORG. ADR-027 records
 * three benchmark commands getting this wrong: a caller running more than one command was left scoped to an
 * org the first had rolled back or removed.
 */
it('leaves no org in context afterwards', function (): void {
    $this->artisan('kitsune:blueprint apply fixture --org=acme')->assertSuccessful();

    expect(app(Context::class)->orgId())->toBeNull();
});

it('gives the context back even when the apply fails', function (): void {
    EntryType::create([
        'org_id' => $this->org->getKey(), 'handle' => 'dispatch', 'name' => 'Theirs', 'plural_name' => 'Theirs',
    ]);

    $this->artisan('kitsune:blueprint apply fixture --org=acme')->assertFailed();

    expect(app(Context::class)->orgId())->toBeNull();
});

it('refuses an unknown blueprint by name', function (): void {
    $this->artisan('kitsune:blueprint apply nope --org=acme')
        ->expectsOutputToContain('No blueprint is registered under [nope]')
        ->assertFailed();
});

it('refuses an apply with no org named', function (): void {
    $this->artisan('kitsune:blueprint apply fixture')
        ->expectsOutputToContain('needs an organisation')
        ->assertFailed();
});

/**
 * ⚠️ AN UNKNOWN SLUG ON AN ESTABLISHED INSTALLATION IS STILL AN ERROR. The bootstrap below creates the first
 * org, and only the first: creating one here would be inventing a customer because somebody mistyped, and the
 * receipt would then record a blueprint applied into it.
 */
it('refuses an unknown org slug when the installation already has one', function (): void {
    $this->artisan('kitsune:blueprint apply fixture --org=ghost')
        ->expectsOutputToContain('already has 1')
        ->assertFailed();

    expect(Org::query()->where('slug', 'ghost')->exists())->toBeFalse();
});

/** The refusal's own reason, not a bare failure — it is the only thing that says which step stopped. */
it('prints the reason a blueprint refused to apply', function (): void {
    EntryType::create([
        'org_id' => $this->org->getKey(), 'handle' => 'dispatch', 'name' => 'Theirs', 'plural_name' => 'Theirs',
    ]);

    $this->artisan('kitsune:blueprint apply fixture --org=acme')
        ->expectsOutputToContain('will not adopt a type it did not create')
        ->assertFailed();
});

/**
 * ⚠️ STATUS READS PAST THE ORG SCOPE, because it is asked from a console with no org in context — where a
 * scoped read returns nothing whatever is in the table. The same trap `PersonServiceProvider::uninstall()`
 * measured for its own count.
 */
it('reports receipts across every org, from no context at all', function (): void {
    $this->artisan('kitsune:blueprint apply fixture --org=acme')->assertSuccessful();

    expect(app(Context::class)->orgId())->toBeNull();

    $this->artisan('kitsune:blueprint status')
        ->expectsOutputToContain('fixture')
        ->assertSuccessful();
});

it('reports an interrupted apply as interrupted', function (): void {
    app(Context::class)->setOrg($this->org);

    /* The state a crash between the intent record and the work leaves behind. */
    Blueprint::create(['handle' => 'fixture', 'version' => '1.0.0', 'manifest' => null, 'applied_at' => null]);

    app(Context::class)->forget();

    $this->artisan('kitsune:blueprint status')
        ->expectsOutputToContain('INTERRUPTED — no rows written; re-run to apply')
        ->assertSuccessful();
});

/** ⚠️ The other interrupted state, and the operator is told it is a different one: re-running finishes it. */
it('reports an apply whose rows committed and whose finish did not run as that', function (): void {
    app(Context::class)->setOrg($this->org);

    Blueprint::create(['handle' => 'fixture', 'version' => '1.0.0', 'manifest' => ['version' => '1.0.0', 'entry_types' => [], 'roles' => []], 'applied_at' => null]);

    app(Context::class)->forget();

    $this->artisan('kitsune:blueprint status')
        ->expectsOutputToContain('INTERRUPTED — rows written, not finished; re-run to finish')
        ->assertSuccessful();
});

/** Who can assign what was created is said as it is: an owner where the org has one, and that it has none where not. */
it('says how many roles it created, that nobody holds them, and who can assign them', function (bool $owner, string $tail): void {
    FixtureBlueprint::$roles = [new RoleDeclaration('dispatcher', 'Dispatcher', ['dispatch' => ['view']])];

    if ($owner) {
        app(Context::class)->setOrg($this->org);
        Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true]);
        app(Context::class)->forget();
    }

    $this->artisan('kitsune:blueprint apply fixture --org=acme')
        ->expectsOutputToContain('created  role dispatcher: entry.dispatch.view')
        ->expectsOutputToContain($tail)
        ->assertSuccessful();
})->with([
    'an org with an owner' => [true, '1 role was created and nobody holds it: an owner assigns it under Roles (ADR-033).'],
    'an org with none' => [false, '1 role was created and nobody holds it, and this organisation has no owner yet to assign it (ADR-033).'],
]);

/*
 * ⚠️ THE BOOTSTRAP, WHICH IS WHAT MAKES ADR-030's CONDITION SATISFIABLE.
 *
 * That ADR will not move kitsunecms.org onto Kitsune until a blueprint applies to a fresh install "with no
 * manual step outside the apply flow — no hand-edited config, no SQL, no *and then you also need to*". A fresh
 * install has no org, so requiring one to exist put exactly such a step in front of every apply.
 *
 * These run in their own describe with NO org created first, because the whole condition under test is that
 * the installation is empty.
 */
describe('on an installation with no organisation at all', function (): void {
    beforeEach(function (): void {
        Site::query()->withoutGlobalScopes()->forceDelete();
        Org::query()->withoutGlobalScopes()->forceDelete();
    });

    it('creates the first org and site, then applies into it', function (): void {
        $this->artisan('kitsune:blueprint apply fixture --org=acme')
            ->expectsOutputToContain('Created organisation acme')
            ->assertSuccessful();

        $org = Org::query()->where('slug', 'acme')->first();

        expect($org)->not->toBeNull()
            ->and($org->name)->toBe('Acme')
            ->and(Site::query()->withoutGlobalScopes()->where('org_id', $org->getKey())->count())->toBe(1)
            ->and(EntryType::query()->where('org_id', $org->getKey())->where('handle', 'dispatch')->exists())->toBeTrue();
    });

    /** ADR-026: onboarding creates the first user interactively, so a bootstrap that made one would pre-empt it. */
    it('creates no user', function (): void {
        $this->artisan('kitsune:blueprint apply fixture --org=acme')->assertSuccessful();

        expect(DB::table('users')->count())->toBe(0);
    });

    /** A fresh install does not know its own public URL, and guessing one takes a claim the operator has not made. */
    it('claims no host for the site it creates', function (): void {
        $this->artisan('kitsune:blueprint apply fixture --org=acme')->assertSuccessful();

        $site = Site::query()->withoutGlobalScopes()->firstOrFail();

        expect($site->base_url)->toBeNull()
            ->and($site->locale)->toBe('en');
    });

    it('takes the name, site slug and locale when they are given', function (): void {
        $this->artisan('kitsune:blueprint apply fixture --org=acme --org-name="Acme Incorporated" --site=main --locale=fr')
            ->assertSuccessful();

        $site = Site::query()->withoutGlobalScopes()->firstOrFail();

        expect(Org::query()->where('slug', 'acme')->value('name'))->toBe('Acme Incorporated')
            ->and($site->slug)->toBe('main')
            ->and($site->locale)->toBe('fr');
    });

    /**
     * ⚠️ ONE TRANSACTION, AND THE FAILURE IS INJECTED BECAUSE NO INPUT CAN REACH IT.
     *
     * The hazard is real and `BenchmarkStorageCommand::fixture()` records paying for it: a site slug is
     * globally unique and can fail AFTER the org is written, leaving the org behind. Here that would be worse
     * than untidy — the next run would find one org, refuse to bootstrap, and tell the operator to name an
     * organisation that exists but has no site.
     *
     * It is not reachable through this command, though, and saying so is better than dressing up a test that
     * pretends otherwise: a taken site slug implies a site, which implies an org, which is the one thing
     * `FirstOrg` refuses to bootstrap past. So the transaction is defence in depth against a write failing for
     * some other reason, and this makes a write fail for some other reason.
     */
    it('leaves no org behind when the site cannot be created', function (): void {
        Site::creating(function (): void {
            throw new RuntimeException('site write failed, for the sake of argument');
        });

        $this->artisan('kitsune:blueprint apply fixture --org=acme')->assertFailed();

        expect(Org::query()->withTrashed()->count())->toBe(0)
            ->and(DB::table('sites')->count())->toBe(0)
            ->and(DB::table('blueprints')->count())->toBe(0)
            ->and(app(Context::class)->orgId())->toBeNull();
    });

    /**
     * ⚠️ AND THE CONTEXT DOES NOT NAME THE ORG THE ROLLBACK TOOK AWAY. The transaction sets the new org in context;
     * a rollback that left it there handed the caller a context naming an org no row holds. Asked of `FirstOrg`
     * itself, because the command clears the context on its own way out and would hide it.
     */
    it('clears the context when the site cannot be created', function (): void {
        Site::creating(function (): void {
            throw new RuntimeException('site write failed, for the sake of argument');
        });

        expect(fn () => FirstOrg::create('acme', null, null, 'en'))
            ->toThrow(RuntimeException::class, 'site write failed');

        expect(app(Context::class)->orgId())->toBeNull()
            ->and(app(Context::class)->siteId())->toBeNull();
    });

    /**
     * ⚠️ A DEFINITION KNOWABLE AS WRONG LEAVES NO ORG BEHIND. Its refusal needs no database, so it comes before the
     * first org is written — or the installation would no longer be empty, and a corrected run under any other slug
     * would be refused.
     */
    it('creates no org for a definition it would refuse', function (): void {
        FixtureBlueprint::$roles = [new RoleDeclaration('dispatcher', 'Dispatcher', ['article' => ['view']])];

        $this->artisan('kitsune:blueprint apply fixture --org=newco')
            ->expectsOutputToContain('which is not a type this blueprint declares')
            ->doesntExpectOutputToContain('Created organisation')
            ->assertFailed();

        expect(Org::query()->withTrashed()->count())->toBe(0)
            ->and(DB::table('sites')->count())->toBe(0)
            ->and(DB::table('blueprints')->count())->toBe(0);
    });

    /**
     * ⚠️ PHASE 5's ONE COMMAND: an empty installation to a blog, with no step outside it. And no user — the first
     * owner is the next slice's, so until then the output says plainly that nobody can reach the org.
     */
    it('applies Blog to an empty installation in one command, and creates no user', function (): void {
        $this->artisan('kitsune:blueprint apply blog --org=myblog --no-interaction')
            ->expectsOutputToContain('Created organisation myblog and its first site. No user was created, and nobody is '
                .'a member of it (ADR-026) — until onboarding exists, nobody can reach it in the admin.')
            ->expectsOutputToContain('created  role blog_writer: entry.post.create, entry.post.update, entry.post.view, entry.tag.view')
            ->expectsOutputToContain('Applied blog 1.0.0 into myblog. 0 indexed.')
            ->expectsOutputToContain('2 roles were created and nobody holds them, and this organisation has no owner yet to assign them (ADR-033).')
            ->assertSuccessful();

        $org = Org::query()->where('slug', 'myblog')->firstOrFail();

        expect(Org::query()->count())->toBe(1)
            ->and(Site::query()->withoutGlobalScopes()->where('org_id', $org->getKey())->count())->toBe(1)
            ->and(EntryType::query()->where('org_id', $org->getKey())->orderBy('handle')->pluck('handle')->all())->toBe(['post', 'tag'])
            ->and(Role::query()->withoutGlobalScopes()->where('org_id', $org->getKey())->count())->toBe(2)
            ->and(RolePermission::query()->withoutGlobalScopes()->count())->toBe(14)
            ->and(DB::table('users')->count())->toBe(0)
            ->and(DB::table('role_user')->count())->toBe(0)
            ->and(app(Context::class)->orgId())->toBeNull();

        $this->artisan('kitsune:blueprint apply blog --org=myblog --no-interaction')
            ->expectsOutputToContain('already applied at this version')
            ->assertSuccessful();

        expect(RolePermission::query()->withoutGlobalScopes()->count())->toBe(14);
    });
});
