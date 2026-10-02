<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Kitsune\Core\Auth\Contracts\ProvisionsMembership;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Auth\RegistersOrgAwareProvider;
use Kitsune\Core\Blueprints\FirstOrg;
use Kitsune\Core\Filament\Panels\KitsunePanel;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\FirstOwnerPanelUser;
use Kitsune\Core\Tests\Fixtures\FirstOwnerUnscopedUser;
use Kitsune\Core\Tests\Fixtures\FirstOwnerUser;

/*
 * The first owner, seated by the bootstrap — ADR-026, as amended by ADR-039.
 *
 * ⚠️ ONE TRANSACTION, OR NOTHING. An org whose owner half-exists is one `--owner` is refused on for good, so every
 * failure here — a host that gets the account wrong, a step that throws, a post-condition — is asserted to leave every
 * table as it was, the audit log included.
 */

const FIRST_OWNER_PASSWORD = 'correct-horse-battery-staple';

beforeEach(function (): void {
    Site::query()->withoutGlobalScopes()->forceDelete();
    Org::query()->withoutGlobalScopes()->forceDelete();

    config(['auth.providers.users.model' => FirstOwnerUser::class]);
    FirstOwnerUser::reset();

    /* As the skeleton's `AppServiceProvider` does: without it, signing in finds no member before an org is in context. */
    RegistersOrgAwareProvider::on($this->app);

    $this->hash = Hash::make(FIRST_OWNER_PASSWORD);
});

afterEach(function (): void {
    FirstOwnerUser::reset();
    app(Context::class)->forget();
});

/** @return array<string, int> the row count of every table the bootstrap writes */
function firstOwnerCounts(): array
{
    return [
        'orgs' => DB::table('orgs')->count(),
        'sites' => DB::table('sites')->count(),
        'users' => DB::table('users')->count(),
        'org_user' => DB::table('org_user')->count(),
        'site_user' => DB::table('site_user')->count(),
        'roles' => DB::table('roles')->count(),
        'role_user' => DB::table('role_user')->count(),
        'audit_log' => DB::table('audit_log')->count(),
    ];
}

function firstOwnerNothingWritten(): void
{
    expect(firstOwnerCounts())->toBe(array_fill_keys(array_keys(firstOwnerCounts()), 0))
        ->and(app(Context::class)->orgId())->toBeNull()
        ->and(app(Context::class)->siteId())->toBeNull();
}

it('creates the org, its site and an owner who can sign in and owns it, in one go', function (): void {
    $org = FirstOrg::createWithOwner('myblog', null, null, 'en', 'owner@example.test', $this->hash);

    $user = FirstOwnerUser::query()->withoutGlobalScopes()->firstOrFail();
    $role = Role::query()->firstOrFail();

    expect(firstOwnerCounts())->toBe([
        'orgs' => 1, 'sites' => 1, 'users' => 1, 'org_user' => 1, 'site_user' => 1, 'roles' => 1, 'role_user' => 1, 'audit_log' => 1,
    ])
        ->and($user->email)->toBe('owner@example.test')
        ->and($user->name)->toBe('owner@example.test')
        ->and($user->password)->toBe($this->hash)
        ->and(Hash::check(FIRST_OWNER_PASSWORD, $user->password))->toBeTrue()
        ->and([$role->handle, $role->name, (bool) $role->is_owner])->toBe(['owner', 'Owner', true])
        ->and(Permissions::isOwner($user))->toBeTrue()
        ->and($user->canAccessTenant(Site::query()->firstOrFail()))->toBeTrue()
        /* Left in the new org, as `create()` leaves it; the command's `finally` clears it. */
        ->and(app(Context::class)->orgId())->toBe($org->getKey())
        ->and(app(Context::class)->siteId())->toBeNull();
});

/**
 * ⚠️ ONE AUDIT ROW, AND ITS ORDER IS THE PROOF. Membership first, then the role: assigned first, the membership that
 * followed would be an owner joining, written as `org.owner_added` too.
 */
it('records the ownership once, as the system, and nothing else', function (): void {
    $org = FirstOrg::createWithOwner('myblog', null, null, 'en', 'owner@example.test', $this->hash);
    $user = FirstOwnerUser::query()->withoutGlobalScopes()->firstOrFail();

    $rows = AuditLog::query()->withoutGlobalScopes()->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->action)->toBe('role.owner_assigned')
        ->and((int) $rows[0]->org_id)->toBe($org->getKey())
        ->and($rows[0]->site_id)->toBeNull()
        ->and($rows[0]->actor_id)->toBeNull()
        ->and($rows[0]->target_type)->toBe(FirstOwnerUser::class)
        ->and((string) $rows[0]->target_id)->toBe((string) $user->getKey());
});

/**
 * The check before the password was asked for was early; this one, inside the transaction, is the one that holds.
 *
 * ⚠️ AND IT IS ASSERTED TO RUN INSIDE, not merely to refuse. Review found the refusal alone passing with the check moved
 * back in front of the transaction — where, on SQLite's deferred transactions, a second first run's count is an
 * autocommit read and its write simply waits for the first to commit, so both commit. So both counts are recorded
 * with the transaction depth they ran at.
 */
it('refuses inside the transaction an installation that is no longer empty', function (string $what): void {
    if ($what === 'an org') {
        Org::create(['slug' => 'already', 'name' => 'Already']);
    } else {
        DB::table('users')->insert(['email' => 'somebody@example.test']);
    }

    $before = firstOwnerCounts();
    $outside = DB::transactionLevel();
    $depths = ['orgs' => [], 'users' => []];

    DB::listen(function ($query) use (&$depths): void {
        foreach (array_keys($depths) as $table) {
            if (preg_match('/^select count\(\*\) as ["`]?aggregate["`]? from ["`]?'.$table.'["`]?/i', $query->sql) === 1) {
                $depths[$table][] = $query->connection->transactionLevel();
            }
        }
    });

    expect(fn () => FirstOrg::createWithOwner('myblog', null, null, 'en', 'owner@example.test', $this->hash))
        ->toThrow(RuntimeException::class, $what === 'an org'
            ? 'this installation has 1 organisation(s), deleted ones included, and 0 account(s)'
            : 'this installation has 0 organisation(s), deleted ones included, and 1 account(s)');

    expect(firstOwnerCounts())->toBe($before)
        ->and(app(Context::class)->orgId())->toBeNull()
        ->and($depths['orgs'])->not->toBeEmpty()
        ->and($depths['users'])->not->toBeEmpty()
        ->and(max($depths['orgs']))->toBeGreaterThan($outside)
        ->and(max($depths['users']))->toBeGreaterThan($outside);
})->with(['an org', 'an account no scope can see']);

it('writes nothing when a step fails part way', function (string $how): void {
    if ($how === 'the host throws letting the owner in') {
        FirstOwnerUser::$break = 'throw-site';
    } else {
        Role::creating(fn () => throw new RuntimeException('the role write failed, for the sake of argument'));
    }

    expect(fn () => FirstOrg::createWithOwner('myblog', null, null, 'en', 'owner@example.test', $this->hash))
        ->toThrow(RuntimeException::class, 'for the sake of argument');

    firstOwnerNothingWritten();
})->with(['the host throws letting the owner in', 'the owner role cannot be written']);

/** ⚠️ Each of these is a host that got its half wrong: core proves the owner can sign in and owns the org, or writes nothing. */
it('writes nothing when the owner could not sign in, or would not own the org', function (string $break, string $refusal): void {
    FirstOwnerUser::$break = $break;

    expect(fn () => FirstOrg::createWithOwner('myblog', null, null, 'en', 'owner@example.test', $this->hash))
        ->toThrow(RuntimeException::class, $refusal);

    firstOwnerNothingWritten();
})->with([
    'the hash hashed again' => ['rehash', '::provisionAccount() did not store the password hash it was given'],
    'no membership of the org' => ['org', 'does not see the new account as the owner of [myblog]'],
    'no access to the site' => ['site', 'the new account cannot enter site [myblog] in the admin'],
]);

/**
 * ⚠️ THE DATABASE'S OWN WORDS ARE NOT REPEATED. A query exception's message carries its bindings — the hash among them,
 * and on PostgreSQL the whole failing row — so it is named by its step and its SQLSTATE, and nothing is chained to it.
 */
it('names a failing write by its step, never by the database\'s message', function (): void {
    FirstOwnerUser::$break = 'null-email';

    try {
        FirstOrg::createWithOwner('myblog', null, null, 'en', 'owner@example.test', $this->hash);
        $thrown = null;
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown->getMessage())->toContain('failed while creating the owner\'s account (SQLSTATE')
        ->and($thrown->getMessage())->not->toContain($this->hash)
        ->and($thrown->getMessage())->not->toContain('$2y$')
        ->and($thrown->getPrevious())->toBeNull();

    firstOwnerNothingWritten();
});

/** The path that creates no account carries no secret, and keeps the database's own diagnostics. */
it('keeps the database\'s own message where no account was being created', function (): void {
    Site::creating(fn () => DB::statement('select * from no_such_table_for_the_sake_of_argument'));

    expect(fn () => FirstOrg::create('myblog', null, null, 'en'))
        ->toThrow(PDOException::class, 'no_such_table_for_the_sake_of_argument');

    firstOwnerNothingWritten();
});

it('refuses a user model it cannot create the owner in, before anything is written', function (string $model, string $refusal): void {
    if ($model === 'elsewhere') {
        FirstOwnerUser::$connectionOverride = 'elsewhere';
        $model = FirstOwnerUser::class;
    }

    config(['auth.providers.users.model' => $model]);

    expect(fn () => FirstOrg::createWithOwner('myblog', null, null, 'en', 'owner@example.test', $this->hash))
        ->toThrow(RuntimeException::class, $refusal);

    FirstOwnerUser::$connectionOverride = null;
    firstOwnerNothingWritten();
})->with([
    'none resolves' => ['App\\Missing', 'no user model resolves'],
    'one without the contract' => [Authenticatable::class, 'does not implement '.ProvisionsMembership::class.' and '.HasTenants::class],
    'one with no membership scope' => [FirstOwnerUnscopedUser::class, 'registers no membership scope'],
    'one on another connection' => ['elsewhere', 'role assignments (`role_user.user_id`) do not refer to'],
]);

/*
 * ⚠️ WITH KITSUNE'S PANEL BOUND, THE PANEL IS ASKED TOO. The skeleton's user is a `FilamentUser`, and an account the
 * panel turns away at `canAccessPanel()` signs in to nothing, whatever its memberships say.
 */

/** Kitsune's panel, as far as the bootstrap asks it: bound, and nothing more. */
function firstOwnerPanel(): Panel
{
    $panel = Panel::make()->id('admin')->login();
    app()->instance(KitsunePanel::PANEL_BINDING, $panel);

    return $panel;
}

it('creates an owner the panel lets in, where there is a panel', function (): void {
    firstOwnerPanel();
    config(['auth.providers.users.model' => FirstOwnerPanelUser::class]);

    FirstOrg::createWithOwner('myblog', null, null, 'en', 'owner@example.test', $this->hash);

    $user = FirstOwnerPanelUser::query()->withoutGlobalScopes()->firstOrFail();

    expect(Permissions::isOwner($user))->toBeTrue()
        ->and(firstOwnerCounts()['role_user'])->toBe(1);
});

it('refuses a user model the panel could not ask, where there is a panel', function (): void {
    firstOwnerPanel();

    expect(fn () => FirstOrg::createWithOwner('myblog', null, null, 'en', 'owner@example.test', $this->hash))
        ->toThrow(RuntimeException::class, 'does not implement '.FilamentUser::class.', so Kitsune cannot');

    firstOwnerNothingWritten();
});

it('writes nothing when the panel would not let the owner in', function (): void {
    firstOwnerPanel();
    config(['auth.providers.users.model' => FirstOwnerPanelUser::class]);
    FirstOwnerPanelUser::$break = 'panel';

    expect(fn () => FirstOrg::createWithOwner('myblog', null, null, 'en', 'owner@example.test', $this->hash))
        ->toThrow(RuntimeException::class, 'the admin panel would not let the new account in — '.FirstOwnerPanelUser::class.'::canAccessPanel() refused it');

    firstOwnerNothingWritten();
});

/*
 * ⚠️ AND FOUND THE WAY SIGNING IN FINDS THEM. Every check above reads the account by key, past the scopes, with the org
 * in context; the sign-in form asks the panel's user provider for the address, with no org at all. Laravel's stock
 * provider keeps the membership scope, which matches nobody before an org is in context — review found every other
 * check passing, and "Sign in at …" printed, for an owner it could never find.
 */
it('writes nothing when signing in would not find the owner', function (bool $panel): void {
    if ($panel) {
        firstOwnerPanel();
        config(['auth.providers.users.model' => FirstOwnerPanelUser::class]);
    }

    config(['auth.providers.users.driver' => 'eloquent']);
    app('auth')->forgetGuards();

    expect(fn () => FirstOrg::createWithOwner('myblog', null, null, 'en', 'owner@example.test', $this->hash))
        ->toThrow(RuntimeException::class, 'signing in looks an account up by its address through ['
            .EloquentUserProvider::class.'], and that does not find the new');

    firstOwnerNothingWritten();
})->with(['with no panel' => false, 'through the panel\'s guard' => true]);

/**
 * The misconfiguration `RegistersOrgAwareProvider` records finding in a real install: the panel's guard authenticates
 * through a provider of its own name, and only `users` was made org-aware. Asked of the panel's provider, not `users`.
 */
it('asks the provider behind the panel\'s own guard', function (): void {
    config([
        'auth.providers.admins' => ['driver' => 'eloquent', 'model' => FirstOwnerPanelUser::class],
        'auth.guards.admin' => ['driver' => 'session', 'provider' => 'admins'],
        'auth.providers.users.model' => FirstOwnerPanelUser::class,
    ]);
    app()->instance(KitsunePanel::PANEL_BINDING, Panel::make()->id('admin')->authGuard('admin'));

    expect(fn () => FirstOrg::createWithOwner('myblog', null, null, 'en', 'owner@example.test', $this->hash))
        ->toThrow(RuntimeException::class, 'signing in looks an account up by its address through ['
            .EloquentUserProvider::class.']');

    firstOwnerNothingWritten();
});

/*
 * ⚠️ THIS ACCOUNT, HOLDING THIS HASH — not merely an account. A provider that is not Eloquent's (a directory, a host's
 * own) answers the address from wherever it keeps accounts, and may answer with somebody else, or with a password other
 * than the one typed. Each case below differs from the owner in one way only, so each comparison is the one refusing.
 */
it('refuses a sign-in that would find somebody else, or another password', function (string $differs): void {
    $hash = $this->hash;

    Auth::provider('first-owner-elsewhere', fn (): UserProvider => new class($differs, $hash) extends EloquentUserProvider
    {
        public function __construct(private string $differs, private string $ownerHash)
        {
            parent::__construct(app('hash'), FirstOwnerUser::class);
        }

        public function retrieveByCredentials(array $credentials): ?AuthenticatableContract
        {
            $owner = FirstOwnerUser::query()->withoutGlobalScopes()->where('email', $credentials['email'])->first();

            return new GenericUser($this->differs === 'somebody else'
                ? ['id' => 'somebody-else', 'password' => $this->ownerHash]
                : ['id' => $owner?->getKey(), 'password' => Hash::make('another-password-entirely')]);
        }
    });
    config(['auth.providers.users.driver' => 'first-owner-elsewhere']);

    expect(fn () => FirstOrg::createWithOwner('myblog', null, null, 'en', 'owner@example.test', $this->hash))
        ->toThrow(RuntimeException::class, 'and that does not find the new');

    firstOwnerNothingWritten();
})->with(['somebody else', 'another password']);
