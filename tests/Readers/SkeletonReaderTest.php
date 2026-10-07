<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Models\Reader;
use App\Providers\AppServiceProvider;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Readers\Contracts\ReaderAccount;
use Kitsune\Core\Readers\ReaderAccounts;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tenancy\Scopes\OrgScope;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;

/*
 * The skeleton's reader — ADR-037: its model, its table and its declaration, as a fresh install ships them.
 */

beforeEach(function (): void {
    $this->world = ReaderFixture::world();
});

it('declares the reader guard from the skeleton\'s own provider, and core can use it', function (): void {
    config(['kitsune.readers.guard' => null, 'auth.guards.readers' => null, 'auth.providers.readers' => null]);

    (new AppServiceProvider($this->app))->register();
    Auth::forgetGuards();
    $this->app->forgetInstance(ReaderGuard::class);

    expect(config('kitsune.readers.guard'))->toBe('readers')
        ->and(config('auth.guards.readers'))->toBe(['driver' => 'session', 'provider' => 'readers'])
        ->and(config('auth.providers.readers'))->toBe(['driver' => 'eloquent', 'model' => Reader::class])
        // Never the membership-stripping provider staff use: a reader's org scope stays on.
        ->and(config('auth.providers.users.driver'))->toBe('kitsune-eloquent')
        ->and(app(ReaderGuard::class)->fault())->toBeNull()
        ->and(app(ReaderAccounts::class)->fault())->toBeNull();
});

it('is an org-scoped account, and never a panel user', function (): void {
    expect(is_subclass_of(Reader::class, ReaderAccount::class))->toBeTrue()
        ->and(in_array(EnforcesScope::class, class_uses_recursive(Reader::class), true))->toBeTrue()
        ->and(array_key_exists(OrgScope::class, (new Reader)->getGlobalScopes()))->toBeTrue();

    foreach ([FilamentUser::class, HasTenants::class, MustVerifyEmail::class, CanResetPassword::class, Authorizable::class] as $contract) {
        expect(is_subclass_of(Reader::class, $contract))->toBeFalse($contract);
    }
});

it('creates a reader in the org in context, storing the hash it is given unchanged', function (): void {
    $hash = Hash::make(ReaderFixture::PASSWORD);
    app(Context::class)->setOrg($this->world['rival']);

    $reader = Reader::createReader('someone@kitsune.test', $hash, null);

    expect($reader->org_id)->toBe($this->world['rival']->getKey())
        ->and(DB::table('readers')->where('id', $reader->getKey())->value('password'))->toBe($hash)
        ->and($reader->email_verified_at)->toBeNull();

    $passwordless = Reader::createReader('nobody-yet@kitsune.test', null, null);

    expect(DB::table('readers')->where('id', $passwordless->getKey())->value('password'))->toBeNull();
});

it('stores an address in lower case, however it is written', function (): void {
    app(Context::class)->setOrg($this->world['golfdom']);

    $reader = Reader::createReader('MiXeD@Kitsune.TEST', null, null);

    expect(DB::table('readers')->where('id', $reader->getKey())->value('email'))->toBe('mixed@kitsune.test')
        ->and($reader->readerEmail())->toBe('mixed@kitsune.test')
        ->and(Reader::findByEmail('mixed@kitsune.test')?->getKey())->toBe($reader->getKey());
});

it('holds one reader per address in each org, and the same address in two', function (): void {
    ReaderFixture::reader($this->world['golfdom'], 'same@kitsune.test');
    ReaderFixture::reader($this->world['rival'], 'same@kitsune.test');

    expect(DB::table('readers')->where('email', 'same@kitsune.test')->count())->toBe(2);

    app(Context::class)->setOrg($this->world['golfdom']);

    expect(static fn () => Reader::createReader('same@kitsune.test', null, null))->toThrow(QueryException::class);
});

it('exports every column but its secrets — a column added without a decision fails here', function (): void {
    $reader = ReaderFixture::reader($this->world['golfdom'], 'subscriber@kitsune.test');
    app(Context::class)->setOrg($this->world['golfdom']);
    $export = Reader::findByEmail('subscriber@kitsune.test')?->exportAccount() ?? [];

    $covered = ['id', 'org_id', 'password', 'remember_token', 'email', 'email_verified_at', 'created_at', 'updated_at'];
    $columns = Schema::getColumnListing('readers');
    sort($columns);
    sort($covered);

    expect($columns)->toBe($covered)
        ->and(array_keys($export))->toBe(['reader', 'email', 'email_verified_at', 'has_password', 'created_at', 'updated_at'])
        ->and($export['reader'])->toBe((string) $reader->getKey())
        ->and($export['has_password'])->toBeTrue()
        ->and((string) json_encode($export))->not->toContain('$2y$');
});

it('erases its row and nothing else', function (): void {
    $reader = ReaderFixture::reader($this->world['golfdom'], 'subscriber@kitsune.test');
    ReaderFixture::reader($this->world['golfdom'], 'other@kitsune.test');
    app(Context::class)->setOrg($this->world['golfdom']);

    Reader::findByEmail('subscriber@kitsune.test')?->eraseAccount();

    expect(DB::table('readers')->pluck('email')->all())->toBe(['other@kitsune.test'])
        ->and(DB::table('readers')->where('id', $reader->getKey())->exists())->toBeFalse();
});
