<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Auth\RegistersOrgAwareProvider;
use Kitsune\Core\Http\Api\SiteAccessToken;
use Kitsune\Core\Http\Api\SiteTokens;
use Kitsune\Core\Http\Api\SiteTokenSchema;
use Kitsune\Core\Http\Middleware\ResolveSiteFromRequest;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Attributes\OrgScopedThroughPivot;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\TestUser;

#[OrgScopedThroughPivot(table: 'org_user', foreignKey: 'user_id')]
class SiteTokenGuardStaff extends TestUser implements HasTenants
{
    public bool $implicitAdmission = false;

    public function getTenants(Panel $panel): array|Collection
    {
        return [];
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->implicitAdmission
            || DB::table('site_user')->where('site_id', $tenant->getKey())->where('user_id', $this->getKey())->exists();
    }
}

beforeEach(function (): void {
    // The runtime suite creates the opt-in migration within the fixture transaction. Non-transactional
    // DDL engines are qualified separately, so their default application schema stays unchanged here.
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Runs an opt-in migration inside the SQLite fixture transaction.');
    }

    config([
        'kitsune.api.staff_provider' => 'api-fixture-staff',
        'auth.providers.api-fixture-staff' => ['driver' => 'eloquent', 'model' => SiteTokenGuardStaff::class],
    ]);
    RegistersOrgAwareProvider::on(app(), 'api-fixture-staff');
    SiteTokenSchema::create(SiteTokenGuardStaff::class);
    SiteTokens::register();

    $this->org = Org::create(['slug' => 'api', 'name' => 'API']);
    $this->otherOrg = Org::create(['slug' => 'other-api', 'name' => 'Other API']);
    $makeSite = static function (Org $org, string $slug): Site {
        app(Context::class)->setOrg($org);

        return Site::create([
            'handle' => $slug, 'slug' => $slug, 'name' => $slug,
            'url_strategy' => 'domain', 'base_url' => "https://{$slug}.example.test",
        ]);
    };
    $this->site = $makeSite($this->org, 'api');
    $sibling = $this->sibling = $makeSite($this->org, 'sibling');
    $foreign = $makeSite($this->otherOrg, 'foreign');
    app(Context::class)->setSite($this->site);

    $this->actor = SiteTokenGuardStaff::create(['email' => 'actor@example.test']);
    foreach ([$this->org, $this->otherOrg] as $org) {
        DB::table('org_user')->insert(['org_id' => $org->getKey(), 'user_id' => $this->actor->getKey()]);
    }
    foreach ([$this->site, $sibling, $foreign] as $site) {
        DB::table('site_user')->insert(['site_id' => $site->getKey(), 'user_id' => $this->actor->getKey()]);
    }

    $this->entryType = EntryType::create(['handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
    $this->role = Role::create(['handle' => 'api-editor', 'name' => 'API editor']);
    DB::table('role_user')->insert(['role_id' => $this->role->getKey(), 'user_id' => $this->actor->getKey()]);
    $this->role->grant('entry.article.view');
    $this->role->grant('entry.article.update');

    $handler = static function (Request $request, string $action) {
        abort_unless(SiteTokens::allows($request, 'article', $action), 403);

        return response()->json(['site' => app(Context::class)->site()?->slug]);
    };
    Route::get('/api-test/{action}', $handler)->middleware(['auth:'.SiteTokens::GUARD, ResolveSiteFromRequest::class]);
    Route::get('/lazy-api/{action}', $handler)->middleware(ResolveSiteFromRequest::class);
    Route::get('/missing-context/{action}', $handler)->middleware('auth:'.SiteTokens::GUARD);

    $this->read = function (string $credential, string $host = 'api', string $path = '/api-test/view') {
        app()->forgetScopedInstances();
        Permissions::forget();
        Auth::forgetGuards();
        Auth::shouldUse('web');

        return $this->getJson("https://{$host}.example.test{$path}", ['Authorization' => 'Bearer '.$credential]);
    };
});

it('keeps a token bound to its Site and current actor permissions', function (): void {
    $credential = SiteTokens::issue($this->actor, 'test client', ['entry.article.view'], now()->addHour());
    $token = SiteAccessToken::query()->sole();
    expect($token->token)->toBe(hash('sha256', explode('|', $credential)[1]))
        ->and($token->toArray())->not->toHaveKey('token');

    ($this->read)($credential)->assertOk()->assertExactJson(['site' => 'api']);
    ($this->read)($credential, path: '/api-test/update')->assertForbidden();
    ($this->read)($credential, 'sibling')->assertUnauthorized();
    ($this->read)($credential, 'foreign')->assertUnauthorized();
    ($this->read)($credential, 'unmatched')->assertUnauthorized();
    ($this->read)($credential, path: '/missing-context/view')->assertUnauthorized();

    app(Context::class)->setSite($this->site);
    $this->role->revoke('entry.article.view');
    ($this->read)($credential)->assertForbidden();
});

it('refuses session authentication and rechecks expiry and revocation', function (): void {
    $credential = SiteTokens::issue($this->actor, 'test client', ['entry.article.view'], now()->addHour());
    $token = SiteAccessToken::query()->sole();
    $this->actingAs($this->actor, 'web');
    app(Context::class)->forget();
    $this->getJson('https://api.example.test/api-test/view')->assertUnauthorized();

    app(Context::class)->setSite($this->site);
    $token->forceFill(['expires_at' => now()->subHour()])->save();
    ($this->read)($credential)->assertUnauthorized();
    $token->forceFill(['expires_at' => now()->addHour()])->save();

    ($this->read)($credential)->assertOk();
    expect(SiteTokens::revoke($token->id))->toBeTrue();
    ($this->read)($credential)->assertUnauthorized();
});

it('refuses to issue missing grants and wildcard permissions', function (): void {
    expect(fn () => SiteTokens::issue($this->actor, 'test client', ['entry.article.delete'], now()->addHour()))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => SiteTokens::issue($this->actor, 'test client', ['entry.*.view'], now()->addHour()))
        ->toThrow(InvalidArgumentException::class)
        ->and(SiteAccessToken::query()->count())->toBe(0);
});

it('refuses a previously issued ability while its entry type is disabled on the Site', function (): void {
    $credential = SiteTokens::issue($this->actor, 'test client', ['entry.article.view'], now()->addHour());
    ($this->read)($credential)->assertOk();

    EntryTypeAvailability::create([
        'entry_type_id' => $this->entryType->getKey(),
        'scope_type' => 'site',
        'scope_id' => $this->site->getKey(),
        'is_enabled' => false,
    ]);
    ($this->read)($credential)->assertForbidden();
});

it('issues a credential once through Artisan and revokes only inside the selected Site', function (): void {
    $status = Artisan::call('kitsune:api-token', [
        'action' => 'issue',
        '--org' => 'api',
        '--site' => 'api',
        '--actor' => (string) $this->actor->getKey(),
        '--name' => 'test client',
        '--ability' => ['entry.article.view'],
    ]);
    expect($status)->toBe(0);
    $credential = trim(Artisan::output());
    if (preg_match('/\A[1-9][0-9]*\|[a-f0-9]{64}\z/', $credential) !== 1) {
        $this->fail('The command did not emit exactly one token.');
    }
    $token = SiteAccessToken::query()->sole();
    ($this->read)($credential)->assertOk();

    $command = ['action' => 'revoke', '--org' => 'api', '--site' => 'sibling', '--id' => (string) $token->id];
    expect(Artisan::call('kitsune:api-token', $command))->toBe(1);
    ($this->read)($credential)->assertOk();

    $command['--site'] = 'api';
    expect(Artisan::call('kitsune:api-token', $command))->toBe(0);
    ($this->read)($credential)->assertUnauthorized();
});

it('resolves the token before checking authorization through the request guard', function (): void {
    $credential = SiteTokens::issue($this->actor, 'test client', ['entry.article.view'], now()->addHour());
    ($this->read)($credential, path: '/lazy-api/view')->assertOk();
});

it('refuses issuance without a persisted Site membership even when host admission says yes', function (): void {
    $this->actor->implicitAdmission = true;
    DB::table('site_user')->where('site_id', $this->site->getKey())->where('user_id', $this->actor->getKey())->delete();

    expect(fn () => SiteTokens::issue($this->actor, 'test client', ['entry.article.view'], now()->addHour()))
        ->toThrow(InvalidArgumentException::class, 'selected Site and Org')
        ->and(SiteAccessToken::query()->count())->toBe(0);
});

it('restores the prior context after a command succeeds or refuses', function (): void {
    $context = app(Context::class)->setSite($this->sibling);
    $issue = [
        'action' => 'issue',
        '--org' => 'api',
        '--site' => 'api',
        '--actor' => (string) $this->actor->getKey(),
        '--name' => 'test client',
        '--ability' => ['entry.article.view'],
    ];

    expect(Artisan::call('kitsune:api-token', $issue))->toBe(0)
        ->and($context->siteId())->toBe($this->sibling->getKey());
    $issue['--site'] = 'missing';
    expect(Artisan::call('kitsune:api-token', $issue))->toBe(1)
        ->and($context->siteId())->toBe($this->sibling->getKey());
});
