<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Credentials\CredentialAuthentication;
use Kitsune\Core\Credentials\CredentialAuthenticationRefused;
use Kitsune\Core\Credentials\CredentialRefusal;
use Kitsune\Core\Credentials\CredentialRefused;
use Kitsune\Core\Credentials\FreshCredentialAuthentication;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\AttributeOnlyUser;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Fx;
use Kitsune\Core\Tests\Fixtures\ElsewhereUser;
use Kitsune\Core\Tests\Fixtures\TestUser;
use Kitsune\Core\Tests\Fixtures\UnscopedUser;

beforeEach(function (): void {
    Fx::boot();
    config(['hashing.bcrypt.rounds' => 4]);
    $this->org = Fx::org('fresh-auth');
    $this->owner = Fx::member();
    $this->password = 'a local fixture password '.bin2hex(random_bytes(8));
    DB::table('users')->where('id', $this->owner->getKey())->update(['password' => Hash::make($this->password)]);
    $this->authentication = app(FreshCredentialAuthentication::class);
});

afterEach(fn () => Fx::tearDown());

it('checks the current primary password rather than the stale guard model and rechecks the same proof', function (): void {
    expect(Hash::check($this->password, $this->owner->getAuthPassword()))->toBeFalse();
    $proof = $this->authentication->confirm($this->password);
    $proof->assertCurrent();
    $proof->assertCurrent();
    expect($proof)->toBeInstanceOf(CredentialAuthentication::class)
        ->and(DB::table('credentials')->count())->toBe(0);
});

it('refuses missing wrong or oversized passwords with one payload-free unchained message', function (?string $password): void {
    try {
        $this->authentication->confirm($password);
        $this->fail('fresh authentication was accepted');
    } catch (CredentialAuthenticationRefused $failure) {
        expect($failure->getMessage())->toBe('Fresh owner authentication is required for this credential change.')
            ->and($failure->getPrevious())->toBeNull();
    }
})->with([null, '', 'wrong-password', str_repeat('p', 4097)]);

it('refuses unsupported or missing local password hashes', function (?string $hash): void {
    DB::table('users')->where('id', $this->owner->getKey())->update(['password' => $hash]);
    expect(fn () => $this->authentication->confirm($this->password))->toThrow(CredentialAuthenticationRefused::class);
})->with([null, '', 'not-a-password-hash']);

it('does not accept a stale formerly valid password after the account password changes', function (): void {
    $this->owner->refresh();
    DB::table('users')->where('id', $this->owner->getKey())->update(['password' => Hash::make('a different local password')]);
    expect(fn () => $this->authentication->confirm($this->password))->toThrow(CredentialAuthenticationRefused::class);
    $this->authentication->confirm('a different local password')->assertCurrent();
});

it('refuses cached owner authority once current membership assignment or role ownership is gone', function (string $changed): void {
    expect(Permissions::isOwner($this->owner))->toBeTrue();
    match ($changed) {
        'membership' => DB::table('org_user')->where('user_id', $this->owner->getKey())->delete(),
        'assignment' => DB::table('role_user')->where('user_id', $this->owner->getKey())->delete(),
        'role' => DB::table('roles')->where('org_id', $this->org->getKey())->update(['is_owner' => false]),
    };
    expect(Permissions::isOwner($this->owner))->toBeTrue()
        ->and(fn () => $this->authentication->confirm($this->password))->toThrow(CredentialAuthenticationRefused::class);
})->with(['membership', 'assignment', 'role']);

it('refuses another org even when an invalid foreign owner assignment remains', function (): void {
    $other = Org::create(['slug' => 'fresh-other', 'name' => 'Other']);
    app(Context::class)->setOrg($other);
    expect(fn () => $this->authentication->confirm($this->password))->toThrow(CredentialAuthenticationRefused::class);
});

it('refuses system nonowner and non-Eloquent identities', function (string $actor): void {
    match ($actor) {
        'system' => Auth::guard('web')->logout(),
        'member' => Fx::member(owner: false, email: 'fresh-member@example.test'),
        'external' => Auth::guard('web')->setUser(new GenericUser(['id' => $this->owner->getKey(), 'password' => Hash::make($this->password), 'remember_token' => null])),
    };
    expect(fn () => $this->authentication->confirm($this->password))->toThrow(CredentialAuthenticationRefused::class);
})->with(['system', 'member', 'external']);

it('refuses unenforced membership models and same-key users on another connection', function (string $class): void {
    config(['database.connections.identity' => config('database.connections.'.DB::getDefaultConnection())]);
    Auth::guard('web')->setUser((new $class)->newFromBuilder($this->owner->getAttributes()));
    try {
        expect(fn () => $this->authentication->confirm($this->password))->toThrow(CredentialAuthenticationRefused::class)
            ->and(fn () => $this->authentication->confirm(null, requirePassword: false))->toThrow(CredentialAuthenticationRefused::class);
    } finally {
        DB::purge('identity');
    }
})->with([AttributeOnlyUser::class, UnscopedUser::class, ElsewhereUser::class]);

it('refuses a deleted account or org even when the signed-in instance remains', function (string $deleted): void {
    if ($deleted === 'account') {
        DB::table('role_user')->where('user_id', $this->owner->getKey())->delete();
        DB::table('org_user')->where('user_id', $this->owner->getKey())->delete();
        DB::table('users')->where('id', $this->owner->getKey())->delete();
    } else {
        DB::table('orgs')->where('id', $this->org->getKey())->update(['deleted_at' => now()]);
    }
    expect(fn () => $this->authentication->confirm($this->password))->toThrow(CredentialAuthenticationRefused::class);
})->with(['account', 'org']);

it('invalidates proof after password authority actor org or site context changes', function (string $changed): void {
    $proof = $this->authentication->confirm($this->password);
    match ($changed) {
        'password' => DB::table('users')->where('id', $this->owner->getKey())->update(['password' => Hash::make($this->password)]),
        'membership' => DB::table('org_user')->where('user_id', $this->owner->getKey())->delete(),
        'assignment' => DB::table('role_user')->where('user_id', $this->owner->getKey())->delete(),
        'role' => DB::table('roles')->where('org_id', $this->org->getKey())->update(['is_owner' => false]),
        'actor' => Fx::member(email: 'fresh-other-owner@example.test'),
        'org' => app(Context::class)->forget(),
        'site' => app(Context::class)->setSite(Site::create(['handle' => 'fresh-site', 'slug' => 'fresh-site', 'name' => 'Fresh site'])),
    };
    expect(fn () => $proof->assertCurrent())->toThrow(CredentialAuthenticationRefused::class);
})->with(['password', 'membership', 'assignment', 'role', 'actor', 'org', 'site']);

it('binds proof to its exact transaction rather than a reusable transaction level', function (): void {
    $connection = DB::connection();
    $connection->beginTransaction();
    try {
        $proof = $this->authentication->confirm($this->password);
        $proof->assertCurrent();
        $connection->commit();
        $connection->beginTransaction();
        expect(fn () => $proof->assertCurrent())->toThrow(CredentialAuthenticationRefused::class);
    } finally {
        $connection->rollBack();
    }
});

it('does not expose or serialize authentication evidence and refuses an unearned constructed proof', function (): void {
    $proof = $this->authentication->confirm($this->password);
    ob_start();
    var_dump($proof);
    $dump = ob_get_clean();
    expect($dump)->not->toContain($this->password)->not->toContain($this->owner->email)
        ->and((array) $proof)->toBe([])->and(json_encode($proof))->toBe('"[withheld]"')
        ->and(fn () => serialize($proof))->toThrow(CredentialAuthenticationRefused::class)
        ->and(fn () => clone $proof)->toThrow(CredentialAuthenticationRefused::class)
        ->and(fn () => (new CredentialAuthentication)->assertCurrent())->toThrow(CredentialAuthenticationRefused::class);
});

it('proves a passwordless owners current authority when this write does not require a password', function (): void {
    DB::table('users')->where('id', $this->owner->getKey())->update(['password' => null]);
    $proof = $this->authentication->confirm(null, requirePassword: false);
    $proof->assertCurrent();
    expect(fn () => $this->authentication->confirm(null))->toThrow(CredentialAuthenticationRefused::class);
    DB::table('role_user')->where('user_id', $this->owner->getKey())->delete();
    expect(fn () => $proof->assertCurrent())->toThrow(CredentialAuthenticationRefused::class)
        ->and(fn () => $this->authentication->confirm(null, requirePassword: false))->toThrow(CredentialAuthenticationRefused::class);
});

it('still binds the current password when an ordinary write does not require reentry', function (): void {
    $proof = $this->authentication->confirm(null, requirePassword: false);
    DB::table('users')->where('id', $this->owner->getKey())->update(['password' => null]);
    expect(fn () => $proof->assertCurrent())->toThrow(CredentialAuthenticationRefused::class);
});

it('uses current account membership and owner reads after a rival changes an older repeatable-read snapshot', function (string $changed): void {
    $connection = DB::connection();
    if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('This two-connection proof measures MySQL/MariaDB repeatable-read semantics.');
    }
    $default = DB::getDefaultConnection();
    config(['database.connections.credential-auth-rival' => config('database.connections.'.$default)]);
    $rival = DB::connection('credential-auth-rival');
    $orgId = $userId = null;
    $this->afterRollback(function () use ($rival, &$orgId, &$userId): void {
        if ($userId !== null) {
            $rival->table('role_user')->where('user_id', $userId)->delete();
            $rival->table('org_user')->where('user_id', $userId)->delete();
        }
        if ($orgId !== null) {
            $rival->table('roles')->where('org_id', $orgId)->delete();
            $rival->table('orgs')->where('id', $orgId)->delete();
        }
        if ($userId !== null) {
            $rival->table('users')->where('id', $userId)->delete();
        }
        DB::purge('credential-auth-rival');
    });
    // The ordinary fixture belongs to RefreshDatabase and is discarded before committed rival data is made.
    $connection->rollBack();
    $orgId = (int) $rival->table('orgs')->insertGetId(['slug' => 'fresh-rival-org', 'name' => 'Fresh rival']);
    $userId = (int) $rival->table('users')->insertGetId(['email' => 'fresh-rival@example.test', 'password' => Hash::make($this->password)]);
    $roleId = (int) $rival->table('roles')->insertGetId(['org_id' => $orgId, 'handle' => 'owner', 'name' => 'Owner', 'is_owner' => true]);
    if ($changed !== 'grant') {
        $rival->table('org_user')->insert(['org_id' => $orgId, 'user_id' => $userId]);
        $rival->table('role_user')->insert(['role_id' => $roleId, 'user_id' => $userId]);
    }
    $org = (new Org)->newFromBuilder((array) $rival->table('orgs')->where('id', $orgId)->first(), $default);
    $owner = (new TestUser)->newFromBuilder((array) $rival->table('users')->where('id', $userId)->first(), $default);
    app(Context::class)->setOrg($org);
    Auth::guard('web')->setUser($owner);
    Permissions::forget();
    $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connection->beginTransaction();
    $oldHash = $connection->table('users')->where('id', $userId)->value('password');
    $oldMembership = $connection->table('org_user')->where('user_id', $userId)->count();
    $oldAssignments = $connection->table('role_user')->where('user_id', $userId)->count();
    $oldOwner = $connection->table('roles')->where('id', $roleId)->value('is_owner');
    expect($connection->table('org_credential_modes')->where('org_id', $orgId)->count())->toBe(0);
    if ($changed === 'grant') {
        $rival->table('org_user')->insert(['org_id' => $orgId, 'user_id' => $userId]);
        $rival->table('role_user')->insert(['role_id' => $roleId, 'user_id' => $userId]);
        expect(TestUser::query()->whereKey($userId)->first())->toBeNull();
        $this->authentication->confirm($this->password)->assertCurrent();
    } elseif ($changed === 'mode') {
        $rival->table('org_credential_modes')->insert(['org_id' => $orgId, 'mode' => 'live', 'changed_at' => now()]);
        try {
            Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));
            $this->fail('an older test-mode snapshot bypassed live authentication');
        } catch (CredentialRefused $failure) {
            expect($failure->reason)->toBe(CredentialRefusal::FreshAuthentication);
        }
        Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40), $this->password);
        expect($connection->table('credentials')->where('org_id', $orgId)->count())->toBe(1);
    } else {
        match ($changed) {
            'password' => $rival->table('users')->where('id', $userId)->update(['password' => Hash::make('the newer password')]),
            'membership' => $rival->table('org_user')->where('user_id', $userId)->delete(),
            'assignment' => $rival->table('role_user')->where('user_id', $userId)->delete(),
            'role' => $rival->table('roles')->where('id', $roleId)->update(['is_owner' => false]),
        };
        expect(fn () => $this->authentication->confirm($this->password))->toThrow(CredentialAuthenticationRefused::class);
        if ($changed === 'password') {
            $this->authentication->confirm('the newer password')->assertCurrent();
        } else {
            expect(fn () => $this->authentication->confirm(null, requirePassword: false))->toThrow(CredentialAuthenticationRefused::class);
        }
    }
    expect($connection->table('users')->where('id', $userId)->value('password'))->toBe($oldHash)
        ->and($connection->table('org_user')->where('user_id', $userId)->count())->toBe($oldMembership)
        ->and($connection->table('role_user')->where('user_id', $userId)->count())->toBe($oldAssignments)
        ->and($connection->table('roles')->where('id', $roleId)->value('is_owner'))->toBe($oldOwner)
        ->and($connection->table('org_credential_modes')->where('org_id', $orgId)->count())->toBe(0);
})->with(['password', 'membership', 'assignment', 'role', 'grant', 'mode']);
