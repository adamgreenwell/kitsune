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
use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;
use Kitsune\Core\Blueprints\Declarations\FieldDeclaration;
use Kitsune\Core\Blueprints\Declarations\RoleDeclaration;
use Kitsune\Core\Blueprints\FirstOrg;
use Kitsune\Core\Blueprints\FirstParty\MarketingSiteBlueprint;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\RolePermission;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Schema\SchemaManager;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\FixtureBlueprint;
use Kitsune\Core\Tests\Fixtures\Released\MarketingSite100;
use Kitsune\Core\Tests\Fixtures\SchemaManagerStandIn;

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

/** The Marketing Site is core's too, matched as one row for the reason Blog's is. */
it('lists the Marketing Site', function (): void {
    expect(Artisan::call('kitsune:blueprint', ['action' => 'list']))->toBe(0)
        ->and(Artisan::output())->toMatch('/\|\s*marketing-site\s*\|\s*1\.1\.0\s*\|\s*Kitsune\\\\Core\\\\Blueprints\\\\FirstParty\\\\MarketingSiteBlueprint\s*\|/');
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

/**
 * A later version is merged by the same command — ADR-039's merge, as an operator meets it. Matched as the whole
 * output, because the lines are the report: what the version added, that nothing the earlier one wrote was changed,
 * and the new role nobody holds yet.
 */
it('merges a later version in one command', function (): void {
    expect(Artisan::call('kitsune:blueprint', ['action' => 'apply', 'handle' => 'fixture', '--org' => 'acme']))->toBe(0);

    FixtureBlueprint::$version = '1.1.0';
    FixtureBlueprint::$roles = [new RoleDeclaration('dispatcher', 'Dispatcher', ['dispatch' => ['view']])];

    expect(Artisan::call('kitsune:blueprint', ['action' => 'apply', 'handle' => 'fixture', '--org' => 'acme']))->toBe(0)
        ->and(Artisan::output())->toBe(implode("\n", [
            '  created  role dispatcher: entry.dispatch.view',
            '  skipped  version: merged over 1.0.0, which the receipt recorded — what 1.1.0 adds was written, and nothing 1.0.0 wrote was changed',
            'Applied fixture 1.1.0 into acme. 0 indexed.',
            '1 role was created and nobody holds it, and this organisation has no owner yet to assign it (ADR-033).',
        ])."\n");
});

/** And one that changes what an earlier version shipped is refused, saying what, with the receipt where it was. */
it('refuses a version that changes what it shipped, printing why, and exits 1', function (): void {
    $this->artisan('kitsune:blueprint apply fixture --org=acme')->assertSuccessful();

    FixtureBlueprint::$version = '1.1.0';
    FixtureBlueprint::$piiClass = 'personal';

    $this->artisan('kitsune:blueprint apply fixture --org=acme')
        /* One expectation: the refusal is one line, and the output mock gives a line to the first that fits it. */
        ->expectsOutputToContain('never changes or removes what 1.0.0 recorded — field dispatch_body on dispatch changes its pii_class. Nothing was written')
        ->assertExitCode(1);

    expect(Blueprint::query()->withoutGlobalScopes()->where('handle', 'fixture')->value('version'))->toBe('1.0.0');
});

/**
 * ⚠️ ADR-030's SECOND CONDITION, IN THE COMMAND ITS SITE WILL RUN: an org at the released Marketing Site 1.0.0 takes 1.1.0
 * with the same command, and every line it prints is the report — the field 1.1.0 adds, and that nothing 1.0.0 wrote was
 * changed. The registry is swapped between the two runs, as an upgrade of core swaps the class behind the handle.
 */
it('upgrades the Marketing Site from 1.0.0 to 1.1.0 in one command', function (): void {
    app()->instance(BlueprintRegistry::class, (new BlueprintRegistry)->register(new MarketingSite100));

    expect(Artisan::call('kitsune:blueprint', ['action' => 'apply', 'handle' => 'marketing-site', '--org' => 'acme']))->toBe(0)
        ->and(Artisan::output())->toContain('Applied marketing-site 1.0.0 into acme. 0 indexed.');

    app()->instance(BlueprintRegistry::class, (new BlueprintRegistry)->register(new MarketingSiteBlueprint));

    expect(Artisan::call('kitsune:blueprint', ['action' => 'apply', 'handle' => 'marketing-site', '--org' => 'acme']))->toBe(0)
        ->and(Artisan::output())->toBe(implode("\n", [
            '  created  field storage page_meta',
            '  skipped  version: merged over 1.0.0, which the receipt recorded — what 1.1.0 adds was written, and nothing 1.0.0 wrote was changed',
            'Applied marketing-site 1.1.0 into acme. 0 indexed.',
        ])."\n")
        ->and(Blueprint::query()->withoutGlobalScopes()->where('handle', 'marketing-site')->value('version'))->toBe('1.1.0');
});

/*
 * ⚠️ THE REVERSE — ADR-039, as built. One action on this command, acting at once with no `--force` and no prompt, as
 * `kitsune:module uninstall` does; it looks the organisation up and never creates one, and needs no registered blueprint.
 */

/** Every line it prints is the report, so every line is asserted — and then the org is as if Blog had never been applied. */
it('reverses Blog in one command, printing what it removed, and a later apply starts afresh', function (): void {
    $this->artisan('kitsune:blueprint apply blog --org=acme')->assertSuccessful();

    expect(Artisan::call('kitsune:blueprint', ['action' => 'reverse', 'handle' => 'blog', '--org' => 'acme']))->toBe(0)
        ->and(Artisan::output())->toBe(implode("\n", [
            '  removed  entry type post, with fields post_body, post_excerpt, post_tags',
            '  removed  entry type tag, with field tag_description',
            '  removed  field storage post_body',
            '  removed  field storage post_excerpt',
            '  removed  field storage post_tags',
            '  removed  field storage tag_description',
            '  removed  role blog_editor, revoking its 10 grants',
            '  removed  role blog_writer, revoking its 4 grants',
            'Reversed blog 1.0.0 in acme; a later apply of it starts afresh, as the blueprint then declares it, not as it was edited here. 0 un-indexed.',
        ])."\n")
        ->and(app(Context::class)->orgId())->toBeNull();

    $this->artisan('kitsune:blueprint status')
        ->expectsOutputToContain('No blueprint has been applied in any organisation.')
        ->assertSuccessful();

    $this->artisan('kitsune:blueprint apply blog --org=acme')
        ->expectsOutputToContain('created  entry type post')
        ->assertSuccessful();
});

it('prints a reverse\'s refusal, exits 1, writes nothing and gives the context back', function (): void {
    $this->artisan('kitsune:blueprint apply blog --org=acme')->assertSuccessful();
    app(Context::class)->setOrg($this->org);
    $editor = Role::query()->where('handle', 'blog_editor')->firstOrFail();
    app(Context::class)->forget();
    $user = DB::table('users')->insertGetId(['name' => 'Sam', 'email' => 'sam@kitsune.test', 'password' => 'x']);
    DB::table('role_user')->insert(['role_id' => $editor->getKey(), 'user_id' => $user]);

    $this->artisan('kitsune:blueprint reverse blog --org=acme')
        ->expectsOutputToContain('Blueprint [blog] cannot be reversed in this organisation: role blog_editor is held by 1 '
            .'account — an owner unassigns it under Roles first, which is audited (ADR-033). A reverse removes only what '
            .'this blueprint created')
        ->assertExitCode(1);

    expect(Blueprint::query()->withoutGlobalScopes()->where('handle', 'blog')->exists())->toBeTrue()
        ->and(EntryType::query()->where('org_id', $this->org->getKey())->count())->toBe(2)
        ->and(app(Context::class)->orgId())->toBeNull();
});

it('refuses a reverse with its arguments wrong, writing nothing', function (string $command, string $refusal): void {
    $this->artisan('kitsune:blueprint apply fixture --org=acme')->assertSuccessful();

    $this->artisan($command)
        ->expectsOutputToContain($refusal)
        ->assertExitCode(1);

    expect(Blueprint::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(Org::query()->count())->toBe(1)
        ->and(app(Context::class)->orgId())->toBeNull();
})->with([
    'no blueprint' => ['kitsune:blueprint reverse --org=acme', '`kitsune:blueprint reverse` needs a blueprint, e.g. `kitsune:blueprint reverse blog --org=acme`.'],
    'no organisation' => ['kitsune:blueprint reverse fixture', '`kitsune:blueprint reverse` needs an organisation, e.g. `--org=acme`. A blueprint is reversed out of one organisation at a time (ADR-039).'],
    'an unknown organisation' => ['kitsune:blueprint reverse fixture --org=ghost', 'No organisation has the slug [ghost], and a reverse never creates one. Nothing was written.'],
    '--owner' => ['kitsune:blueprint reverse fixture --org=acme --owner=me@kitsune.test', '`--owner` belongs to `kitsune:blueprint apply`, which can create an organisation; a reverse creates nothing. Nothing was written.'],
    '--owner-password-stdin' => ['kitsune:blueprint reverse fixture --org=acme --owner-password-stdin', '`--owner-password-stdin` belongs to `kitsune:blueprint apply`'],
    '--org-name' => ['kitsune:blueprint reverse fixture --org=acme --org-name=Acme', '`--org-name` belongs to `kitsune:blueprint apply`'],
    '--site' => ['kitsune:blueprint reverse fixture --org=acme --site=main', '`--site` belongs to `kitsune:blueprint apply`'],
    '--locale, even its default' => ['kitsune:blueprint reverse fixture --org=acme --locale=en', '`--locale` belongs to `kitsune:blueprint apply`'],
]);

it('lists reverse among the actions', function (): void {
    $this->artisan('kitsune:blueprint undo fixture --org=acme')
        ->expectsOutputToContain('`undo` is not a blueprint action. Use: list, status, apply, reverse.')
        ->assertExitCode(1);
});

/** ⚠️ NEVER AN APPLY: a reverse of a blueprint this org does not have writes no receipt and no row. */
it('refuses to reverse what was never applied, and applies nothing', function (): void {
    $this->artisan('kitsune:blueprint reverse fixture --org=acme')
        ->expectsOutputToContain('Blueprint [fixture] has not been applied in this organisation, so there is no receipt to reverse.')
        ->assertExitCode(1);

    expect(Blueprint::query()->withoutGlobalScopes()->exists())->toBeFalse()
        ->and(EntryType::query()->where('org_id', $this->org->getKey())->exists())->toBeFalse();
});

it('clears the receipt of an apply that wrote no rows, and exits 0', function (): void {
    EntryType::create(['org_id' => $this->org->getKey(), 'handle' => 'dispatch', 'name' => 'Theirs', 'plural_name' => 'Theirs']);
    $this->artisan('kitsune:blueprint apply fixture --org=acme')->assertFailed();

    expect(Artisan::call('kitsune:blueprint', ['action' => 'reverse', 'handle' => 'fixture', '--org' => 'acme']))->toBe(0)
        ->and(Artisan::output())->toBe("Reversed fixture 1.0.0 in acme: its apply had written no rows, so only its receipt was removed.\n")
        ->and(Blueprint::query()->withoutGlobalScopes()->exists())->toBeFalse()
        ->and(EntryType::query()->where('org_id', $this->org->getKey())->where('handle', 'dispatch')->value('name'))->toBe('Theirs');
});

it('clears a receipt whose manifest it cannot read, saying why, and exits 0', function (): void {
    $this->artisan('kitsune:blueprint apply fixture --org=acme')->assertSuccessful();
    DB::table('blueprints')->update(['manifest' => null]);

    expect(Artisan::call('kitsune:blueprint', ['action' => 'reverse', 'handle' => 'fixture', '--org' => 'acme']))->toBe(0)
        ->and(Artisan::output())->toBe(implode("\n", [
            '  note     manifest: not this organisation\'s record of what this blueprint wrote — it records nothing',
            'Removed the receipt for fixture 1.0.0 in acme, and nothing else: no row it names could be identified as this '
            .'blueprint\'s. A later apply starts afresh, and refuses by name any type of its own it finds still here.',
        ])."\n")
        ->and(EntryType::query()->where('org_id', $this->org->getKey())->where('handle', 'dispatch')->exists())->toBeTrue();
});

/** A module removed takes its blueprint out of the registry; only the reverse can clear the receipt it left. */
it('reverses a blueprint no longer registered', function (): void {
    $this->artisan('kitsune:blueprint apply fixture --org=acme')->assertSuccessful();
    app()->instance(BlueprintRegistry::class, new BlueprintRegistry);

    $this->artisan('kitsune:blueprint reverse fixture --org=acme')
        ->expectsOutputToContain('Reversed fixture 1.0.0 in acme')
        ->assertSuccessful();

    expect(Blueprint::query()->withoutGlobalScopes()->exists())->toBeFalse();
});

/** The rows and the receipt are gone; a column left behind holds no data, and the message names the repair. */
it('prints every line, then the column it could not drop, and exits 1', function (): void {
    FixtureBlueprint::$override = [new EntryTypeDeclaration(handle: 'dispatch', name: 'Dispatch', pluralName: 'Dispatches', fields: [
        new FieldDeclaration(handle: 'dispatch_code', type: 'text', label: 'Code', piiClass: 'none', isIndexed: true),
    ])];
    $schema = SchemaManagerStandIn::install()->recordOnly();
    $this->artisan('kitsune:blueprint apply fixture --org=acme')->assertSuccessful();
    $schema->throwOnce(new RuntimeException('the disk said no'));

    expect(Artisan::call('kitsune:blueprint', ['action' => 'reverse', 'handle' => 'fixture', '--org' => 'acme']))->toBe(1)
        ->and(Artisan::output())->toBe(implode("\n", [
            '  removed  entry type dispatch, with field dispatch_code',
            '  removed  field storage dispatch_code',
            'Reversed fixture 1.0.0 in acme — its rows and its receipt are gone — but 1 generated column could not be '
            .'dropped: dispatch_code: the disk said no. Nothing holds data in it; run `kitsune:schema-sync --force` to drop it.',
        ])."\n")
        ->and(Blueprint::query()->withoutGlobalScopes()->exists())->toBeFalse()
        ->and(EntryType::query()->where('org_id', $this->org->getKey())->exists())->toBeFalse();

    app()->forgetInstance(SchemaManager::class);
});

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
    /** ⚠️ THE ONE PLACE `apply` WOULD CREATE AN ORGANISATION, AND `reverse` STILL DOES NOT. */
    it('creates no organisation for a reverse', function (): void {
        $this->artisan('kitsune:blueprint reverse fixture --org=acme')
            ->expectsOutputToContain('No organisation has the slug [acme], and a reverse never creates one. Nothing was written.')
            ->assertExitCode(1);

        expect(Org::query()->withTrashed()->count())->toBe(0)
            ->and(DB::table('sites')->count())->toBe(0);
    });

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
     * ⚠️ PHASE 5's ONE COMMAND: an empty installation to a blog, with no step outside it. Without `--owner` no user is
     * created, so the output says plainly that nobody can reach the org — and that `--owner` cannot be added later.
     */
    it('applies Blog to an empty installation in one command, and creates no user', function (): void {
        $this->artisan('kitsune:blueprint apply blog --org=myblog --no-interaction')
            ->expectsOutputToContain('Created organisation myblog and its first site, with no owner because `--owner` was '
                .'not given: nobody can sign in to it, and `--owner` is now refused on this installation, because it '
                .'creates a first owner only where there is no organisation and no account (ADR-026).')
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

    /**
     * ⚠️ ADR-030's FIRST CONDITION, IN THE COMMAND ITS SITE WILL RUN: an empty installation to a Marketing Site, with no
     * step outside the one command — and every line it prints, so a line that changes is a change somebody reviews.
     */
    it('applies the Marketing Site to an empty installation in one command', function (): void {
        $this->artisan('kitsune:blueprint apply marketing-site --org=acme --no-interaction')
            ->expectsOutputToContain('Created organisation acme and its first site, with no owner because `--owner` was '
                .'not given: nobody can sign in to it, and `--owner` is now refused on this installation, because it '
                .'creates a first owner only where there is no organisation and no account (ADR-026).')
            ->expectsOutputToContain('  created  entry type page')
            ->expectsOutputToContain('  created  field storage page_body')
            ->expectsOutputToContain('  created  field storage page_summary')
            ->expectsOutputToContain('  created  field storage page_meta')
            ->expectsOutputToContain('  created  role marketing_editor: entry.page.create, entry.page.delete, entry.page.publish, entry.page.update, entry.page.view')
            ->expectsOutputToContain('  created  role marketing_writer: entry.page.create, entry.page.update, entry.page.view')
            ->expectsOutputToContain('Applied marketing-site 1.1.0 into acme. 0 indexed.')
            ->expectsOutputToContain('2 roles were created and nobody holds them, and this organisation has no owner yet to assign them (ADR-033).')
            ->assertSuccessful();

        $org = Org::query()->where('slug', 'acme')->firstOrFail();

        expect(Org::query()->count())->toBe(1)
            ->and(Site::query()->withoutGlobalScopes()->where('org_id', $org->getKey())->count())->toBe(1)
            ->and(EntryType::query()->where('org_id', $org->getKey())->pluck('handle')->all())->toBe(['page'])
            ->and(Role::query()->withoutGlobalScopes()->where('org_id', $org->getKey())->count())->toBe(2)
            ->and(RolePermission::query()->withoutGlobalScopes()->count())->toBe(8)
            ->and(DB::table('users')->count())->toBe(0)
            ->and(app(Context::class)->orgId())->toBeNull();

        $this->artisan('kitsune:blueprint apply marketing-site --org=acme --no-interaction')
            ->expectsOutputToContain('already applied at this version')
            ->assertSuccessful();
    });

    /** Blog joins the Marketing Site's org afterwards, with no `--owner` — the org exists, so none is wanted. */
    it('then applies Blog into the same org', function (): void {
        $this->artisan('kitsune:blueprint apply marketing-site --org=acme --no-interaction')->assertSuccessful();

        $this->artisan('kitsune:blueprint apply blog --org=acme --no-interaction')
            ->expectsOutputToContain('Applied blog 1.0.0 into acme. 0 indexed.')
            ->assertSuccessful();

        $org = Org::query()->where('slug', 'acme')->firstOrFail();

        expect(EntryType::query()->where('org_id', $org->getKey())->orderBy('handle')->pluck('handle')->all())->toBe(['page', 'post', 'tag'])
            ->and(RolePermission::query()->withoutGlobalScopes()->count())->toBe(22);
    });
});
