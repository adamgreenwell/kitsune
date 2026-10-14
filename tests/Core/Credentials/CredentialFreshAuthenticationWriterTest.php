<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialRefusal;
use Kitsune\Core\Credentials\CredentialRefused;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Fx;

beforeEach(function (): void {
    Fx::boot();
    $this->org = Fx::org('fresh-writer');
    $this->owner = Fx::member();
});

afterEach(fn () => Fx::tearDown());

/** A digest so an assertion failure never prints ciphertext or a stored hash. */
function credentialFreshStored(): string
{
    return hash('sha256', json_encode([
        DB::table('credentials')->orderBy('id')->get()->all(),
        DB::table('org_credential_modes')->orderBy('id')->get()->all(),
        DB::table('audit_log')->where('action', 'like', 'credential.%')->orderBy('id')->get()->all(),
    ], JSON_THROW_ON_ERROR));
}

it('requires a fresh password at every live entry and live or active neutral credential boundary', function (string $action, ?string $password): void {
    Fx::writer()->set(Fx::PAYMENT, CredentialMode::Live, Fx::value('fx_live_'), Fx::PASSWORD);
    Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));
    if (str_starts_with($action, 'neutral')) {
        Fx::writer()->switchTo(CredentialMode::Live, Fx::PASSWORD);
    }
    $write = match ($action) {
        'live set' => fn () => Fx::writer()->set(Fx::PAYMENT, CredentialMode::Live, Fx::value('fx_live_'), $password),
        'live remove' => fn () => Fx::writer()->remove(Fx::PAYMENT, CredentialMode::Live, $password),
        'enter live' => fn () => Fx::writer()->switchTo(CredentialMode::Live, $password),
        'neutral set' => fn () => Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40), $password),
        'neutral remove' => fn () => Fx::writer()->remove(Fx::SHARED, null, $password),
    };
    $before = credentialFreshStored();
    try {
        $write();
        $this->fail('live credential authentication was bypassed');
    } catch (CredentialRefused $failure) {
        expect($failure->reason)->toBe(CredentialRefusal::FreshAuthentication)
            ->and($failure->getPrevious())->toBeNull();
    }
    expect(credentialFreshStored())->toBe($before);
})->with(['live set', 'live remove', 'enter live', 'neutral set', 'neutral remove'])->with([null, 'wrong local password']);

it('does not reuse a successful live authentication on the following write', function (): void {
    Fx::writer()->set(Fx::PAYMENT, CredentialMode::Live, Fx::value('fx_live_'), Fx::PASSWORD);
    $before = credentialFreshStored();
    expect(fn () => Fx::writer()->remove(Fx::PAYMENT, CredentialMode::Live))->toThrow(CredentialRefused::class)
        ->and(credentialFreshStored())->toBe($before);
});

it('does not accept cached owner authority for an ordinary test credential mutation', function (): void {
    expect(Permissions::isOwner($this->owner))->toBeTrue();
    DB::table('role_user')->where('user_id', $this->owner->getKey())->delete();
    $before = credentialFreshStored();
    expect(fn () => Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40)))->toThrow(CredentialRefused::class)
        ->and(credentialFreshStored())->toBe($before);
});

it('rolls back the credential and audit when an audit callback changes the authentication proof', function (string $changed): void {
    $other = Fx::member(email: 'fresh-second-owner@example.test');
    Auth::guard('web')->setUser($this->owner);
    $otherOrg = Org::create(['slug' => 'fresh-audit-other', 'name' => 'Other']);
    $before = credentialFreshStored();
    $event = 'eloquent.creating: '.AuditLog::class;
    $events = AuditLog::getEventDispatcher();
    $original = $events->getRawListeners()[$event] ?? [];
    AuditLog::creating(function (AuditLog $row) use ($changed, $other, $otherOrg): void {
        if (! str_starts_with($row->action, 'credential.')) {
            return;
        }
        match ($changed) {
            'password' => DB::table('users')->where('id', $this->owner->getKey())->update(['password' => Hash::make(Fx::PASSWORD)]),
            'membership' => DB::table('org_user')->where('user_id', $this->owner->getKey())->delete(),
            'assignment' => DB::table('role_user')->where('user_id', $this->owner->getKey())->delete(),
            'role' => DB::table('roles')->where('org_id', $this->org->getKey())->update(['is_owner' => false]),
            'actor' => Auth::guard('web')->setUser($other),
            'context' => app(Context::class)->setOrg($otherOrg),
        };
    });
    try {
        expect(fn () => Fx::writer()->set(Fx::PAYMENT, CredentialMode::Live, Fx::value('fx_live_'), Fx::PASSWORD))
            ->toThrow(CredentialRefused::class);
    } finally {
        $events->forget($event);
        foreach ($original as $listener) {
            $events->listen($event, $listener);
        }
        app(Context::class)->setOrg($this->org);
        Auth::guard('web')->setUser($this->owner);
    }
    expect(credentialFreshStored())->toBe($before);
})->with(['password', 'membership', 'assignment', 'role', 'actor', 'context']);
