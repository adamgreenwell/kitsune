<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Filament\Panels\KitsunePanel;
use Kitsune\Core\Http\Middleware\GuardUploadStaging;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\PanelTenancy;
use Kitsune\Core\Tests\Fixtures\PanelUser;
use Symfony\Component\HttpFoundation\Response;

/*
 * Only a user who may upload media may stage a file — ADR-042 decision 4, from the attacker's side.
 *
 * ⚠️ THE GATE ITSELF, CALLED AS THE ENDPOINT CALLS IT: with Kitsune's context empty, because the upload route has no
 * site segment and its signature names nothing but an expiry. Every refusal asserts the gate's own message and that
 * nothing behind it ran, so a refusal made anywhere else cannot pass for this one; each has an admitted control.
 *
 * ⚠️ "THE SAME TRUST AS UPLOADING" IS THE RULE, and the ADR's own words. So a user holding the permissions but unable
 * to reach any Upload action — no site in the org, the media type off everywhere they can go, or turned away by the
 * panel — is refused like anybody else.
 */

/** Create a role in this org holding these permissions, and give it to the user. */
function stagingRole(Org $org, PanelUser $user, array $permissions, bool $owner = false): Role
{
    $context = app(Context::class);
    $before = $context->site() ?? $context->org();
    $context->setOrg($org);

    $role = Role::create(['handle' => 'r'.bin2hex(random_bytes(3)), 'name' => 'Role', 'is_owner' => $owner]);

    foreach ($permissions as $permission) {
        $role->grant($permission);
    }

    DB::table('role_user')->insert(['role_id' => $role->getKey(), 'user_id' => $user->getKey()]);

    $before instanceof Site ? $context->setSite($before) : $context->setOrg($before);
    Permissions::forget();

    return $role;
}

/** What uploading needs on one type: the list page that holds the Upload action, and both halves of the action. */
function uploadGrant(string $handle): array
{
    return [
        Permissions::forEntryType($handle, 'view'),
        Permissions::forEntryType($handle, 'create'),
        Permissions::forEntryType($handle, 'publish'),
    ];
}

/** A request shaped like Livewire's: `files[]`, each an upload. */
function stagingRequest(mixed $files = null): Request
{
    return Request::create('/livewire-x/upload-file', 'POST', [], [], [
        'files' => $files ?? [UploadedFile::fake()->create('photo.png', 1)],
    ]);
}

/**
 * Run the gate, and report what it answered and whether the endpoint behind it ran.
 *
 * @return array{0: Response, 1: bool}
 */
function throughGate(Request $request, ?Response $endpoint = null): array
{
    $reached = false;

    $response = (new GuardUploadStaging)->handle($request, function () use (&$reached, $endpoint): Response {
        $reached = true;

        return $endpoint ?? response('staged');
    });

    return [$response, $reached];
}

function expectRefusedAtGate(array $outcome): void
{
    [$response, $reached] = $outcome;

    expect($response->getStatusCode())->toBe(403)
        ->and(json_decode((string) $response->getContent(), true)['message'] ?? null)->toBe(GuardUploadStaging::REFUSAL)
        ->and($reached)->toBeFalse();
}

function expectAdmittedAtGate(array $outcome): void
{
    [$response, $reached] = $outcome;

    expect($reached)->toBeTrue()
        ->and($response->getStatusCode())->toBe(200);
}

beforeEach(function (): void {
    config(['auth.providers.users.model' => PanelUser::class]);

    $this->org = Org::create(['slug' => 'staging', 'name' => 'Staging']);
    $context = app(Context::class)->setOrg($this->org);
    $this->here = Site::create(['handle' => 'here', 'slug' => 'staging-here', 'name' => 'Here', 'locale' => 'en']);
    $this->elsewhere = Site::create(['handle' => 'elsewhere', 'slug' => 'staging-elsewhere', 'name' => 'Elsewhere', 'locale' => 'en']);

    EntryType::create(['org_id' => null, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);
    EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);

    $this->user = PanelUser::create(['email' => 'uploader@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => $this->org->id, 'user_id' => $this->user->id]);
    $this->user->reachableSiteIds = [(int) $this->here->id];

    $context->forget();
});

afterEach(fn () => app(Context::class)->forget());

describe('through Kitsune\'s panel', function (): void {
    beforeEach(function (): void {
        PanelTenancy::enter($this->here);

        // The upload route has no site: nothing set Kitsune's context before the gate runs.
        app(Context::class)->forget();
    });

    it('refuses anyone not signed in', function (): void {
        expectRefusedAtGate(throughGate(stagingRequest()));
    });

    it('admits a member holding create and publish on a media type, at a site they reach', function (): void {
        stagingRole($this->org, $this->user, uploadGrant('image'));
        $this->actingAs($this->user);

        expectAdmittedAtGate(throughGate(stagingRequest()));
    });

    /**
     * Each piece missing on its own. ⚠️ `view` INCLUDED, which review found missing: `/c/{type}` answers 403 without it,
     * so `create` and `publish` alone reach no Upload action.
     */
    it('refuses a user who holds less than uploading needs', function (array $permissions): void {
        stagingRole($this->org, $this->user, $permissions);
        $this->actingAs($this->user);

        expectRefusedAtGate(throughGate(stagingRequest()));
    })->with([
        'view and update' => [[Permissions::forEntryType('image', 'view'), Permissions::forEntryType('image', 'update')]],
        'create and publish, without view' => [[Permissions::forEntryType('image', 'create'), Permissions::forEntryType('image', 'publish')]],
        'view and create, without publish' => [[Permissions::forEntryType('image', 'view'), Permissions::forEntryType('image', 'create')]],
        'view and publish, without create' => [[Permissions::forEntryType('image', 'view'), Permissions::forEntryType('image', 'publish')]],
        'all three, on a type that holds no media' => [uploadGrant('article')],
    ]);

    it('admits the wildcard, and an owner, as `allows()` does everywhere', function (): void {
        $role = stagingRole($this->org, $this->user, uploadGrant(Permissions::ANY_TYPE));
        $this->actingAs($this->user);

        expectAdmittedAtGate(throughGate(stagingRequest()));

        DB::table('role_user')->where('role_id', $role->getKey())->delete();
        stagingRole($this->org, $this->user, [], owner: true);

        expectAdmittedAtGate(throughGate(stagingRequest()));
    });

    /** ⚠️ The grants, and no site to use them at: no Upload action is reachable, so no staging either. */
    it('refuses a user the panel gives no site, whatever they hold', function (): void {
        stagingRole($this->org, $this->user, [], owner: true);
        $this->user->reachableSiteIds = [];
        $this->actingAs($this->user);

        expectRefusedAtGate(throughGate(stagingRequest()));
    });

    /** A site the host lists but refuses at its door, as Filament asks `canAccessTenant()` there, is no site. */
    it('refuses at a site the panel lists and then refuses to enter', function (): void {
        stagingRole($this->org, $this->user, [], owner: true);
        $this->user->refusedSiteIds = [(int) $this->here->id];
        $this->actingAs($this->user);

        expectRefusedAtGate(throughGate(stagingRequest()));
    });

    /** ADR-022: `/c/{type}` answers 404 for a type switched off at the site, so there is no Upload action there. */
    it('refuses where every media type is off at every site they reach, and admits once one site has it', function (): void {
        app(Context::class)->setOrg($this->org);
        EntryTypeAvailability::create(['entry_type_id' => EntryType::query()->where('handle', 'image')->value('id'), 'scope_type' => 'site', 'scope_id' => $this->here->id, 'is_enabled' => false]);
        app(Context::class)->forget();

        stagingRole($this->org, $this->user, [], owner: true);
        $this->actingAs($this->user);

        expectRefusedAtGate(throughGate(stagingRequest()));

        $this->user->reachableSiteIds = [(int) $this->here->id, (int) $this->elsewhere->id];
        Permissions::forget();

        expectAdmittedAtGate(throughGate(stagingRequest()));
    });

    it('refuses a user the panel turns away at its door', function (): void {
        stagingRole($this->org, $this->user, [], owner: true);
        $this->user->admittedToPanel = false;
        $this->actingAs($this->user);

        expectRefusedAtGate(throughGate(stagingRequest()));
    });

    /** An org's own `image` that holds no media shadows the global media type, as `IdentifyEntryType` resolves it. */
    it('refuses a grant on a handle the org has shadowed with a type that holds no media', function (): void {
        app(Context::class)->setOrg($this->org);
        EntryType::create(['org_id' => $this->org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images']);
        app(Context::class)->forget();

        stagingRole($this->org, $this->user, uploadGrant('image'));
        $this->actingAs($this->user);

        expectRefusedAtGate(throughGate(stagingRequest()));
    });

    it('refuses at a site whose org is gone', function (): void {
        stagingRole($this->org, $this->user, [], owner: true);
        $this->org->delete();
        $this->actingAs($this->user);

        expectRefusedAtGate(throughGate(stagingRequest()));
    });

    /**
     * ⚠️ CROSS-ORG. A `role_user` row naming another org's uploader role, and a site there, confer nothing without
     * membership of that org — the control adds exactly the membership.
     */
    it('refuses another org\'s grant without membership there, and admits it with membership', function (): void {
        $rival = Org::create(['slug' => 'staging-rival', 'name' => 'Rival']);
        app(Context::class)->setOrg($rival);
        $theirs = Site::create(['handle' => 'theirs', 'slug' => 'staging-theirs', 'name' => 'Theirs', 'locale' => 'en']);
        app(Context::class)->forget();

        stagingRole($rival, $this->user, uploadGrant('image'));
        $this->user->reachableSiteIds = [(int) $this->here->id, (int) $theirs->id];
        $this->actingAs($this->user);

        expectRefusedAtGate(throughGate(stagingRequest()));

        DB::table('org_user')->insert(['org_id' => $rival->id, 'user_id' => $this->user->id]);
        Permissions::forget();

        expectAdmittedAtGate(throughGate(stagingRequest()));
    });

    it('puts Kitsune\'s context back whatever it answered', function (): void {
        stagingRole($this->org, $this->user, uploadGrant('image'));
        $this->actingAs($this->user);

        throughGate(stagingRequest());
        expect(app(Context::class)->org())->toBeNull();

        $this->user->reachableSiteIds = [(int) $this->elsewhere->id, (int) $this->here->id];
        app(Context::class)->setSite($this->elsewhere);
        $this->user->admittedToPanel = true;

        throughGate(stagingRequest());
        expect(app(Context::class)->site()?->is($this->elsewhere))->toBeTrue();

        $this->user->reachableSiteIds = [];
        app(Context::class)->forget();

        expectRefusedAtGate(throughGate(stagingRequest()));
        expect(app(Context::class)->org())->toBeNull();

        /*
         * ⚠️ AN ORG AND NO SITE, WHICH REVIEW FOUND LEFT WITH ONE: `setOrg()` keeps a site of the same org, so restoring
         * the org alone handed the caller the last candidate site.
         */
        $this->user->reachableSiteIds = [(int) $this->here->id];
        app(Context::class)->setOrg($this->org);

        expectAdmittedAtGate(throughGate(stagingRequest()));
        expect(app(Context::class)->org()?->is($this->org))->toBeTrue()
            ->and(app(Context::class)->site())->toBeNull();
    });

    /**
     * ⚠️ A SESSION THE PANEL WOULD END IS NOBODY — review found it staging. The panel's `AuthenticateSession` logs a
     * session out at its next page once the password changed elsewhere; one kept off the panel's pages went on
     * staging. Asked only of a panel that runs that middleware, as the panel itself only asks then.
     */
    it('refuses a session the panel would end, and only when the panel would end it', function (): void {
        stagingRole($this->org, $this->user, uploadGrant('image'));
        $this->actingAs($this->user);

        $request = stagingRequest();
        $request->setLaravelSession($session = app('session')->driver());
        $session->put('password_hash_web', auth()->guard('web')->hashPasswordForCookie('hash-before'));

        // The panel runs no session check: it ends nothing, and neither does this.
        $this->user->passwordHash = 'hash-after';
        expectAdmittedAtGate(throughGate($request));

        app(KitsunePanel::PANEL_BINDING)->middleware([AuthenticateSession::class]);

        expectRefusedAtGate(throughGate($request));

        // The control: the hash the session stored still matches the password.
        $this->user->passwordHash = 'hash-before';
        expectAdmittedAtGate(throughGate($request));
    });

    /**
     * ⚠️ BY THE PANEL'S OWN GUARD. The endpoint runs no `auth` middleware, so a host whose panel signs in through a
     * guard of its own would otherwise be asked about the default guard's user — nobody.
     */
    it('asks the panel\'s guard, not the default one', function (): void {
        config(['auth.guards.kitsune' => ['driver' => 'session', 'provider' => 'users']]);
        app(KitsunePanel::PANEL_BINDING)->authGuard('kitsune');
        stagingRole($this->org, $this->user, uploadGrant('image'));

        $this->actingAs($this->user, 'web');
        expectRefusedAtGate(throughGate(stagingRequest()));

        auth()->guard('web')->logout();
        $this->actingAs($this->user, 'kitsune');
        auth()->shouldUse('web');

        expectAdmittedAtGate(throughGate(stagingRequest()));
    });

    /** Livewire's rules are keyed `files.*`: a single part, or none, would meet no rule at all. */
    it('refuses anything but a list of files, after asking who is asking', function (mixed $files): void {
        $request = Request::create('/livewire-x/upload-file', 'POST', [], [], $files === null ? [] : ['files' => $files]);

        expectRefusedAtGate(throughGate($request));

        stagingRole($this->org, $this->user, uploadGrant('image'));
        $this->actingAs($this->user);

        [$response, $reached] = throughGate($request);

        expect($response->getStatusCode())->toBe(422)
            ->and($reached)->toBeFalse();
    })->with([
        'a single file' => fn (): UploadedFile => UploadedFile::fake()->create('photo.png', 1),
        'a list holding a list' => fn (): array => [[UploadedFile::fake()->create('photo.png', 1)]],
        'no files' => fn (): mixed => null,
    ]);
});

describe('the sweep after an accepted upload', function (): void {
    beforeEach(function (): void {
        PanelTenancy::enter($this->here);
        app(Context::class)->forget();

        Storage::fake(MediaDisks::INTAKE);
        Storage::disk(MediaDisks::INTAKE)->put('livewire-tmp/stale.png', 'x');
        Storage::disk(MediaDisks::INTAKE)->put('livewire-tmp/stale.png.json', '{}');
        touch(Storage::disk(MediaDisks::INTAKE)->path('livewire-tmp/stale.png'), Carbon::now()->getTimestamp() - 25 * 3600);
        touch(Storage::disk(MediaDisks::INTAKE)->path('livewire-tmp/stale.png.json'), Carbon::now()->getTimestamp() - 25 * 3600);
    });

    it('removes stale staged files once the endpoint has accepted one, with no scheduler running', function (): void {
        stagingRole($this->org, $this->user, uploadGrant('image'));
        $this->actingAs($this->user);

        expectAdmittedAtGate(throughGate(stagingRequest()));

        expect(Storage::disk(MediaDisks::INTAKE)->allFiles())->toBe([]);
    });

    it('walks no disk for a request it refused, or one the endpoint refused', function (): void {
        expectRefusedAtGate(throughGate(stagingRequest()));

        stagingRole($this->org, $this->user, uploadGrant('image'));
        $this->actingAs($this->user);

        throughGate(stagingRequest(), response()->json(['message' => 'refused'], 422));

        expect(Storage::disk(MediaDisks::INTAKE)->allFiles())->toHaveCount(2);
    });

    it('never turns an accepted upload into an error when the sweep fails', function (): void {
        stagingRole($this->org, $this->user, uploadGrant('image'));
        $this->actingAs($this->user);
        Exceptions::fake();
        Storage::shouldReceive('disk')->with(MediaDisks::INTAKE)->andThrow(new RuntimeException('the intake disk is gone'));

        expectAdmittedAtGate(throughGate(stagingRequest()));

        Exceptions::assertReported(fn (RuntimeException $failed): bool => $failed->getMessage() === 'the intake disk is gone');
    });
});

/*
 * With no Kitsune panel there is no Upload action to reach, so the ADR's letter is what is asked: create and publish on
 * a media type, in an org the user belongs to.
 */
describe('with no panel', function (): void {
    /** No list page to open, so no `view` to hold: `create` and `publish`, as the ADR's letter says. */
    it('admits a member holding create and publish on a media type', function (): void {
        stagingRole($this->org, $this->user, [Permissions::forEntryType('image', 'create'), Permissions::forEntryType('image', 'publish')]);
        $this->actingAs($this->user);

        expectAdmittedAtGate(throughGate(stagingRequest()));
    });

    it('refuses a grant on a type that holds no media, and one in an org they do not belong to', function (): void {
        stagingRole($this->org, $this->user, uploadGrant('article'));

        $rival = Org::create(['slug' => 'staging-rival', 'name' => 'Rival']);
        stagingRole($rival, $this->user, uploadGrant('image'));

        $this->actingAs($this->user);

        expectRefusedAtGate(throughGate(stagingRequest()));
    });

    it('refuses once the org is gone', function (): void {
        stagingRole($this->org, $this->user, uploadGrant('image'));
        $this->org->delete();
        $this->actingAs($this->user);

        expectRefusedAtGate(throughGate(stagingRequest()));
    });
});
