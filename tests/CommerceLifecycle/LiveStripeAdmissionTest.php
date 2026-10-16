<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Payments\CheckoutRefused;
use Kitsune\Commerce\Payments\LiveStripeAdmission;
use Kitsune\Commerce\Payments\StripeCheckout;
use Kitsune\Commerce\Payments\StripeMode;
use Kitsune\Commerce\Payments\StripeWebhook;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Fx;

require_once __DIR__.'/../Core/Fixtures/LiveStripeFixture.php';

afterEach(function (): void {
    Fx::tearDown();
    CarbonImmutable::setTestNow();
    ModuleKernel::flush();
});

it('requires a separate audited owner admission and shares it across the Org sites', function (): void {
    $seed = liveAdmissionSeed();
    $service = app(LiveStripeAdmission::class);
    expect($service->statusForOwner()['state'])->toBe('not_admitted');
    expect(fn () => liveAdmissionGate())->toThrow(CheckoutRefused::class);
    $service->admit(null, Fx::PASSWORD);
    $status = $service->statusForOwner();
    expect($status['state'])->toBe('admitted')->and($status['account'])->toBe('acct_LiveFixture')
        ->and(liveAdmissionGate('acct_LiveFixture'))->toBe('acct_LiveFixture')
        ->and($seed['provider']->calls)->toBe(['account']);
    expect(fn () => liveAdmissionGate('acct_Foreign'))->toThrow(CheckoutRefused::class);
    $sibling = Site::create(['handle' => 'sibling', 'slug' => 'live-sibling', 'name' => 'Sibling', 'locale' => 'en', 'base_url' => 'https://sibling.example.test']);
    app(Context::class)->setSite($sibling);
    expect(liveAdmissionGate())->toBe('acct_LiveFixture');
    $row = DB::table(LiveStripeAdmission::TABLE)->sole();
    $json = json_encode($row, JSON_THROW_ON_ERROR);
    expect($json)->not->toContain($seed['key'], $seed['hook'], Fx::PASSWORD, 'private@example.test')
        ->and(DB::table(AuditLog::TABLE)->where('action', 'commerce.live_stripe_admitted')->count())->toBe(1);
    $foreign = Fx::org('foreign-live');
    $foreignSite = Site::create(['handle' => 'foreign', 'slug' => 'foreign-live', 'name' => 'Foreign', 'locale' => 'en', 'base_url' => 'https://foreign.example.test']);
    app(Context::class)->setSite($foreignSite);
    expect(fn () => liveAdmissionGate())->toThrow(CheckoutRefused::class);
});

it('refuses stale owners passwords modes and missing signing credentials before any provider call', function (string $scenario): void {
    $seed = liveAdmissionSeed();
    $password = Fx::PASSWORD;
    if ($scenario === 'password') {
        $password = 'incorrect';
    } elseif ($scenario === 'membership') {
        DB::table('org_user')->where('user_id', $seed['owner']->id)->delete();
    } elseif ($scenario === 'role') {
        DB::table('role_user')->where('user_id', $seed['owner']->id)->delete();
    } elseif ($scenario === 'mode') {
        app(CredentialWriter::class)->switchTo(CredentialMode::Test);
    } elseif ($scenario === 'webhook') {
        app(CredentialWriter::class)->remove(StripeWebhook::SLOT, CredentialMode::Live, Fx::PASSWORD);
    } elseif ($scenario === 'module') {
        DB::table('modules')->where('handle', 'kitsune/commerce')->update(['is_enabled' => false]);
    }
    expect(fn () => app(LiveStripeAdmission::class)->admit(null, $password))->toThrow(CheckoutRefused::class)
        ->and($seed['provider']->calls)->toBe([])->and(DB::table(LiveStripeAdmission::TABLE)->count())->toBe(0);
})->with(['password', 'membership', 'role', 'mode', 'webhook', 'module']);

it('requires readiness from the authenticated live account without retaining its personal fields', function (string $field, mixed $value): void {
    $seed = liveAdmissionSeed();
    $seed['provider']->body[$field] = $value;
    expect(fn () => app(LiveStripeAdmission::class)->admit(null, Fx::PASSWORD))->toThrow(CheckoutRefused::class)
        ->and($seed['provider']->calls)->toBe(['account'])->and(DB::table(LiveStripeAdmission::TABLE)->count())->toBe(0);
})->with([['charges_enabled', false], ['payouts_enabled', false], ['details_submitted', false], ['charges_enabled', 'true'], ['id', '../foreign'], ['object', 'customer']]);

it('fences changes between the two authentication phases without committing an admission', function (string $scenario): void {
    $seed = liveAdmissionSeed();
    $seed['provider']->hook = static function () use ($seed, $scenario): void {
        if ($scenario === 'revoke') {
            app(LiveStripeAdmission::class)->revoke(null, Fx::PASSWORD);
        } elseif ($scenario === 'key') {
            app(CredentialWriter::class)->set(StripeCheckout::SLOT, CredentialMode::Live, Fx::value('sk_live_'), Fx::PASSWORD);
        } elseif ($scenario === 'webhook') {
            app(CredentialWriter::class)->set(StripeWebhook::SLOT, CredentialMode::Live, Fx::value('whsec_'), Fx::PASSWORD);
        } elseif ($scenario === 'password') {
            DB::table($seed['owner']->getTable())->where('id', $seed['owner']->id)->update(['password' => Hash::make('changed')]);
        } elseif ($scenario === 'role') {
            DB::table('role_user')->where('user_id', $seed['owner']->id)->delete();
        } elseif ($scenario === 'module') {
            DB::table('modules')->where('handle', 'kitsune/commerce')->update(['version' => 'obsolete']);
        } else {
            app(Context::class)->forget();
        }
    };
    expect(fn () => app(LiveStripeAdmission::class)->admit(null, Fx::PASSWORD))->toThrow(CheckoutRefused::class)
        ->and(DB::table(LiveStripeAdmission::TABLE)->where('state', 'admitted')->count())->toBe(0)
        ->and(DB::table(AuditLog::TABLE)->where('action', 'commerce.live_stripe_admitted')->count())->toBe(0);
})->with(['revoke', 'key', 'webhook', 'password', 'role', 'module', 'context']);

it('invalidates admission after either secret revision and requires a current version for readmission', function (string $slot): void {
    liveAdmissionSeed();
    $service = app(LiveStripeAdmission::class);
    $service->admit(null, Fx::PASSWORD);
    $version = $service->statusForOwner()['version'];
    app(CredentialWriter::class)->set($slot, CredentialMode::Live, Fx::value($slot === StripeCheckout::SLOT ? 'sk_live_' : 'whsec_'), Fx::PASSWORD);
    expect($service->statusForOwner()['state'])->toBe('stale');
    expect(fn () => liveAdmissionGate())->toThrow(CheckoutRefused::class);
    expect(fn () => $service->admit(null, Fx::PASSWORD))->toThrow(CheckoutRefused::class);
    // Readmission must use the newly authenticated key, so this fixture asserts refusal before its old-key provider accepts anything.
    expect(fn () => $service->revoke(null, Fx::PASSWORD))->toThrow(CheckoutRefused::class);
    $service->revoke($version, Fx::PASSWORD);
    expect($service->statusForOwner()['state'])->toBe('revoked');
})->with([StripeCheckout::SLOT, StripeWebhook::SLOT, StripeWebhook::PREVIOUS_SLOT]);

it('revokes only new sales and leaves original API custody unchanged', function (): void {
    $seed = liveAdmissionSeed();
    $service = app(LiveStripeAdmission::class);
    $service->admit(null, Fx::PASSWORD);
    $before = app(OrderLocks::class)->run(fn () => StripeMode::witness(DB::connection(), (int) $seed['org']->id, 'live'));
    $version = $service->statusForOwner()['version'];
    $service->revoke($version, Fx::PASSWORD);
    expect(fn () => liveAdmissionGate())->toThrow(CheckoutRefused::class);
    $after = app(OrderLocks::class)->run(fn () => StripeMode::witness(DB::connection(), (int) $seed['org']->id, 'live'));
    expect($after)->toBe($before)->and($service->statusForOwner()['state'])->toBe('revoked');
    expect(fn () => $service->admit($version, Fx::PASSWORD))->toThrow(CheckoutRefused::class);
    $service->admit($service->statusForOwner()['version'], Fx::PASSWORD);
    expect(liveAdmissionGate())->toBe('acct_LiveFixture');
});

it('rolls back missing or altered persisted admission audits', function (string $scenario): void {
    liveAdmissionSeed();
    AuditLog::created(static function (AuditLog $audit) use ($scenario): void {
        if ($audit->action !== 'commerce.live_stripe_admitted') {
            return;
        }
        if ($scenario === 'audit') {
            DB::table(AuditLog::TABLE)->where('id', $audit->id)->update(['actor_id' => null]);
        } else {
            DB::table(LiveStripeAdmission::TABLE)->update(['account_id' => 'acct_Corrupted']);
        }
    });
    expect(fn () => app(LiveStripeAdmission::class)->admit(null, Fx::PASSWORD))->toThrow(CheckoutRefused::class)
        ->and(DB::table(LiveStripeAdmission::TABLE)->count())->toBe(0)
        ->and(DB::table(AuditLog::TABLE)->where('action', 'commerce.live_stripe_admitted')->count())->toBe(0);
})->with(['audit', 'row']);
