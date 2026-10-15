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
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Commerce\Payments\RefundRecovery;
use Kitsune\Core\Credentials\Secret;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Credentials;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;

require_once __DIR__.'/../Core/Fixtures/RefundRecoveryFixture.php';

afterEach(function (): void {
    Fx::tearDown();
    CarbonImmutable::setTestNow();
    ModuleKernel::flush();
});

it('submits one exact durable refund and preserves each provider lifecycle state', function (string $status): void {
    $seed = refundRecoverySeed();
    $provider = refundRecoveryProvider($seed);
    $provider->status = $status;
    $result = app(RefundRecovery::class)->reconcile($seed['order']->reference, $seed['row']->reference);
    $row = DB::table(PaidOrders::ATTEMPTS)->sole();
    expect($result)->toBe(['reference' => $seed['row']->reference, 'state' => $status])
        ->and($row->refund_id)->toBe('re_Recovery')->and($row->lease_token)->toBeNull()->and($row->replay_count)->toBe(1)
        ->and((string) DB::table('commerce_sellables')->value('stock'))->toBe('0')
        ->and(DB::table('commerce_orders')->value('state'))->toBe('paid');
    expect(array_column($provider->calls, 'method'))->toBe(['GET', 'GET', 'GET', 'POST']);
    refundRecoveryAssertOutside($provider);
})->with(['pending', 'requires_action', 'succeeded', 'failed', 'canceled']);

it('refreshes known successful refunds and reopens owed money after later failure without a new POST', function (): void {
    $seed = refundRecoverySeed();
    $provider = refundRecoveryProvider($seed);
    $recovery = app(RefundRecovery::class);
    $recovery->reconcile($seed['order']->reference, $seed['row']->reference);
    $provider->calls = [];
    $provider->status = 'failed';
    expect($recovery->reconcile($seed['order']->reference, $seed['row']->reference)['state'])->toBe('failed');
    $projection = app(PaidOrders::class)->projection(DB::connection(), (int) $seed['org']->id, DB::table('commerce_orders')->sole());
    expect($projection['refund']['owed_minor'])->toBe('2100')->and(array_column($provider->calls, 'method'))->toBe(['GET', 'GET', 'GET']);
    refundRecoveryAssertOutside($provider);
});

it('adopts the exact remote refund after an ambiguous POST without replacing its identity', function (bool $old): void {
    $seed = refundRecoverySeed();
    $provider = refundRecoveryProvider($seed);
    $provider->timeout = true;
    $recovery = app(RefundRecovery::class);
    expect(fn () => $recovery->reconcile($seed['order']->reference, $seed['row']->reference))->toThrow(CheckoutRefused::class);
    expect(DB::table(PaidOrders::ATTEMPTS)->value('state'))->toBe('unknown');
    $provider->timeout = false;
    $provider->calls = [];
    if ($old) {
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHours(24));
    }
    expect($recovery->reconcile($seed['order']->reference, $seed['row']->reference)['state'])->toBe('succeeded')
        ->and(DB::table(PaidOrders::ATTEMPTS)->count())->toBe(1)->and(array_column($provider->calls, 'method'))->toBe(['GET', 'GET', 'GET']);
    refundRecoveryAssertOutside($provider);
})->with([false, true]);

it('holds an old unknown submission with no exact provider object instead of reusing an expired idempotency key', function (): void {
    $seed = refundRecoverySeed();
    $provider = refundRecoveryProvider($seed);
    $provider->timeout = true;
    $recovery = app(RefundRecovery::class);
    expect(fn () => $recovery->reconcile($seed['order']->reference, $seed['row']->reference))->toThrow(CheckoutRefused::class);
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHours(23));
    $provider->refunds = [];
    $provider->calls = [];
    expect($recovery->reconcile($seed['order']->reference, $seed['row']->reference)['state'])->toBe('held')
        ->and(array_column($provider->calls, 'method'))->toBe(['GET', 'GET', 'GET'])
        ->and(DB::table(PaidOrders::ATTEMPTS)->count())->toBe(1);
});

it('retries an unknown operation inside its deadline with precisely the original key', function (): void {
    $seed = refundRecoverySeed();
    $provider = refundRecoveryProvider($seed);
    $provider->timeout = true;
    $recovery = app(RefundRecovery::class);
    expect(fn () => $recovery->reconcile($seed['order']->reference, $seed['row']->reference))->toThrow(CheckoutRefused::class);
    $provider->timeout = false;
    $provider->refunds = [];
    expect($recovery->reconcile($seed['order']->reference, $seed['row']->reference)['state'])->toBe('succeeded');
    $posts = array_values(array_filter($provider->calls, static fn (array $call): bool => $call['method'] === 'POST'));
    expect(array_column($posts, 'key'))->toBe([$seed['row']->idempotency_key, $seed['row']->idempotency_key])
        ->and(DB::table(PaidOrders::ATTEMPTS)->count())->toBe(1);
});

it('holds multiple matches incomplete bounded scans and external refund conflicts', function (string $scenario): void {
    $seed = refundRecoverySeed();
    $provider = refundRecoveryProvider($seed);
    $first = refundRecoveryObject($seed, $seed['row']);
    if ($scenario === 'multiple') {
        $provider->refunds = [$first, array_replace($first, ['id' => 're_Another'])];
    } elseif ($scenario === 'incomplete') {
        $provider->refunds = [array_replace($first, ['metadata' => [], 'status' => 'failed'])];
        $provider->more = true;
        $provider->hook = static function (string $path) use ($provider, $first): void {
            if ($path === '/v1/refunds') {
                $pages = count(array_filter($provider->calls, static fn (array $call): bool => $call['path'] === '/v1/refunds'));
                $provider->refunds = [array_replace($first, ['id' => 're_Page'.$pages, 'metadata' => [], 'status' => 'failed'])];
            }
        };
    } elseif ($scenario === 'external-object') {
        $provider->refunds = [array_replace($first, ['metadata' => []])];
    } else {
        $provider->chargeRefunded = 200;
    }
    expect(app(RefundRecovery::class)->reconcile($seed['order']->reference, $seed['row']->reference)['state'])->toBe('held')
        ->and(array_unique(array_column($provider->calls, 'method')))->toBe(['GET'])
        ->and(count($provider->calls))->toBeLessThanOrEqual(4);
    refundRecoveryAssertOutside($provider);
})->with(['multiple', 'incomplete', 'external-object', 'external-aggregate']);

it('rechecks the graph credential mode owner password and lease after every provider callback', function (string $scenario): void {
    $seed = refundRecoverySeed();
    $provider = refundRecoveryProvider($seed);
    if (in_array($scenario, ['revoke', 'password'], true)) {
        auth('web')->setUser($seed['owner']);
        Fx::forget();
    }
    $provider->hook = static function (string $path) use ($seed, $scenario): void {
        if ($path !== '/v1/account') {
            return;
        }
        match ($scenario) {
            'mode' => DB::table('org_credential_modes')->updateOrInsert(['org_id' => $seed['org']->id], ['mode' => 'live', 'changed_at' => '2026-10-15 12:00:00']),
            'order' => DB::table('commerce_orders')->where('id', $seed['order']->id)->update(['contact_email' => 'changed@example.test']),
            'revoke' => DB::table('role_user')->where('user_id', $seed['owner']->id)->delete(),
            'password' => DB::table('users')->where('id', $seed['owner']->id)->update(['password' => Hash::make('changed password')]),
            'lease' => CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds(91)),
        };
    };
    $recovery = app(RefundRecovery::class);
    expect(fn () => in_array($scenario, ['revoke', 'password'], true)
        ? $recovery->recoverAsOwner($seed['order']->reference, $seed['row']->reference, new Secret('sk_test_'.str_repeat('b', 32)), Credentials::PASSWORD)
        : $recovery->reconcile($seed['order']->reference, $seed['row']->reference))->toThrow(CheckoutRefused::class);
    expect($provider->calls)->toHaveCount(1)->and(DB::table(PaidOrders::ATTEMPTS)->value('refund_id'))->toBeNull();
    refundRecoveryAssertOutside($provider);
})->with(['mode', 'order', 'revoke', 'password', 'lease']);

it('allows explicit original-account owner recovery after mode changes and rejects wrong account or password', function (string $scenario): void {
    $seed = refundRecoverySeed();
    auth('web')->setUser($seed['owner']);
    Fx::forget();
    DB::table('org_credential_modes')->updateOrInsert(['org_id' => $seed['org']->id], ['mode' => 'live', 'changed_at' => '2026-10-15 12:00:00']);
    $provider = refundRecoveryProvider($seed);
    if ($scenario === 'account') {
        $provider->account = 'acct_Wrong';
    }
    $recover = fn () => app(RefundRecovery::class)->recoverAsOwner($seed['order']->reference, $seed['row']->reference,
        new Secret('sk_test_'.str_repeat('b', 32)), $scenario === 'password' ? 'wrong' : Credentials::PASSWORD);
    if ($scenario === 'success') {
        expect($recover()['state'])->toBe('succeeded');
    } else {
        expect($recover)->toThrow(CheckoutRefused::class);
        expect($provider->calls)->toHaveCount($scenario === 'account' ? 1 : 0);
    }
    expect(DB::table('org_credential_modes')->value('mode'))->toBe('live');
    refundRecoveryAssertOutside($provider);
})->with(['success', 'account', 'password']);

it('creates a new identity only after explicit owner authorization and freshly verified failure', function (): void {
    $seed = refundRecoverySeed();
    $provider = refundRecoveryProvider($seed);
    $provider->status = 'failed';
    $recovery = app(RefundRecovery::class);
    $recovery->reconcile($seed['order']->reference, $seed['row']->reference);
    auth('web')->setUser($seed['owner']);
    Fx::forget();
    $provider->calls = [];
    $retry = $recovery->retryAsOwner($seed['order']->reference, new Secret('sk_test_'.str_repeat('b', 32)), Credentials::PASSWORD);
    expect($retry['state'])->toBe('owed')->and($retry['reference'])->not->toBe($seed['row']->reference)
        ->and(DB::table(PaidOrders::ATTEMPTS)->count())->toBe(2)
        ->and(array_column($provider->calls, 'method'))->toBe(['GET', 'GET', 'GET']);
    $provider->calls = [];
    expect(fn () => $recovery->retryAsOwner($seed['order']->reference, new Secret('sk_test_'.str_repeat('b', 32)), Credentials::PASSWORD))
        ->toThrow(CheckoutRefused::class);
    expect($provider->calls)->toBe([])->and(DB::table(PaidOrders::ATTEMPTS)->count())->toBe(2);
});

it('holds newer attempts when an older failed refund subsequently reports success', function (): void {
    $seed = refundRecoverySeed();
    $provider = refundRecoveryProvider($seed);
    $provider->status = 'failed';
    $recovery = app(RefundRecovery::class);
    $recovery->reconcile($seed['order']->reference, $seed['row']->reference);
    auth('web')->setUser($seed['owner']);
    Fx::forget();
    $recovery->retryAsOwner($seed['order']->reference, new Secret('sk_test_'.str_repeat('b', 32)), Credentials::PASSWORD);
    $provider->status = 'succeeded';
    $provider->calls = [];
    expect($recovery->recoverAsOwner($seed['order']->reference, $seed['row']->reference, new Secret('sk_test_'.str_repeat('b', 32)), Credentials::PASSWORD)['state'])->toBe('held')
        ->and(DB::table(PaidOrders::ATTEMPTS)->orderBy('id')->pluck('state')->all())->toBe(['held', 'held'])
        ->and(array_column($provider->calls, 'method'))->toBe(['GET', 'GET', 'GET']);
});

it('rolls back a misfiled refund audit and retains an ambiguous operation for exact recovery', function (): void {
    $seed = refundRecoverySeed();
    $provider = refundRecoveryProvider($seed);
    AuditLog::creating(static function (AuditLog $audit): void {
        if ($audit->action === 'commerce.refund_reconciled') {
            $audit->target_id = 'misfiled';
        }
    });
    expect(fn () => app(RefundRecovery::class)->reconcile($seed['order']->reference, $seed['row']->reference))->toThrow(CheckoutRefused::class);
    expect(DB::table(PaidOrders::ATTEMPTS)->value('state'))->toBe('unknown')
        ->and(DB::table(PaidOrders::ATTEMPTS)->value('refund_id'))->toBeNull()
        ->and(DB::table('audit_log')->where('action', 'commerce.refund_reconciled')->count())->toBe(0);
    refundRecoveryAssertOutside($provider);
});

it('refuses cross-Site cross-Org busy and ambient-transaction calls before touching Stripe', function (string $scenario): void {
    $seed = refundRecoverySeed();
    $provider = refundRecoveryProvider($seed);
    if ($scenario === 'org') {
        app(Context::class)->setOrg(Org::create(['name' => 'Foreign', 'slug' => 'foreign-recovery', 'currency' => 'USD']));
    }
    if (in_array($scenario, ['site', 'org'], true)) {
        app(Context::class)->setSite(Site::create(['handle' => 'other', 'slug' => 'refund-other', 'name' => 'Other', 'locale' => 'en']));
    } elseif ($scenario === 'busy') {
        DB::table(PaidOrders::ATTEMPTS)->update(['lease_token' => str_repeat('a', 64), 'lease_until' => '2026-10-15 12:01:00']);
    }
    $work = fn () => app(RefundRecovery::class)->reconcile($seed['order']->reference, $seed['row']->reference);
    expect(fn () => $scenario === 'transaction' ? DB::transaction($work) : $work())->toThrow(CheckoutRefused::class);
    expect($provider->calls)->toBe([])->and(DB::table(PaidOrders::ATTEMPTS)->value('state'))->toBe('owed');
})->with(['site', 'org', 'busy', 'transaction']);

it('fences nested reconciliation using the committed lease and completes only the original claim', function (): void {
    $seed = refundRecoverySeed();
    $provider = refundRecoveryProvider($seed);
    $refusal = null;
    $provider->hook = static function (string $path) use ($seed, &$refusal): void {
        if ($path === '/v1/account') {
            try {
                app(RefundRecovery::class)->reconcile($seed['order']->reference, $seed['row']->reference);
            } catch (CheckoutRefused $failure) {
                $refusal = $failure->reason;
            }
        }
    };
    expect(app(RefundRecovery::class)->reconcile($seed['order']->reference, $seed['row']->reference)['state'])->toBe('succeeded')
        ->and($refusal)->toBe('busy')->and(DB::table(PaidOrders::ATTEMPTS)->value('replay_count'))->toBe(1);
    refundRecoveryAssertOutside($provider);
});

it('caps owner-authorized retry history at ten durable identities', function (): void {
    $seed = refundRecoverySeed();
    $provider = refundRecoveryProvider($seed);
    $provider->status = 'failed';
    $recovery = app(RefundRecovery::class);
    $recovery->reconcile($seed['order']->reference, $seed['row']->reference);
    for ($number = 1; $number < PaidOrders::MAX_REFUND_ATTEMPTS; $number++) {
        app(OrderLocks::class)->run(function () use ($seed, $number): void {
            $obligation = DB::table(PaidOrders::OBLIGATIONS)->sole();
            app(PaidOrders::class)->newRefundAttempt(DB::connection(), $seed['order'], (int) $obligation->id, $seed['receipt']->charge_id, (string) $obligation->amount_minor);
            $latest = DB::table(PaidOrders::ATTEMPTS)->orderByDesc('id')->first();
            DB::table(PaidOrders::ATTEMPTS)->where('id', $latest->id)->update(['state' => 'failed', 'refund_id' => 're_Failed'.$number,
                'first_submitted_at' => '2026-10-15 12:00:00']);
        });
    }
    auth('web')->setUser($seed['owner']);
    Fx::forget();
    $keys = DB::table(PaidOrders::ATTEMPTS)->orderBy('id')->pluck('idempotency_key')->all();
    expect(fn () => $recovery->retryAsOwner($seed['order']->reference, new Secret('sk_test_'.str_repeat('b', 32)), Credentials::PASSWORD))
        ->toThrow(CheckoutRefused::class);
    expect(DB::table(PaidOrders::ATTEMPTS)->orderBy('id')->pluck('idempotency_key')->all())->toBe($keys)
        ->and(DB::table(PaidOrders::ATTEMPTS)->count())->toBe(10);
});

it('keeps ISO ledger money separate from special-currency Stripe wire amounts through creation and subsequent GET', function (string $currency, string $wire): void {
    $seed = refundRecoverySeed($currency);
    $provider = refundRecoveryProvider($seed);
    $recovery = app(RefundRecovery::class);
    expect($recovery->reconcile($seed['order']->reference, $seed['row']->reference)['state'])->toBe('succeeded');
    $payload = app(PaidOrders::class)->refundRequest(DB::table(PaidOrders::ATTEMPTS)->sole());
    expect($payload['amount'])->toBe($wire)
        ->and((string) DB::table(PaidOrders::OBLIGATIONS)->value('amount_minor'))->toBe('2100')
        ->and((string) DB::table(PaidOrders::RECEIPTS)->value('amount_minor'))->toBe('6300')
        ->and((string) DB::table('commerce_orders')->value('confirmed_total_minor'))->toBe('6300');
    $provider->chargeRefunded = $wire;
    $provider->calls = [];
    expect($recovery->reconcile($seed['order']->reference, $seed['row']->reference)['state'])->toBe('succeeded')
        ->and(array_column($provider->calls, 'method'))->toBe(['GET', 'GET', 'GET']);
    refundRecoveryAssertOutside($provider);
})->with(['ISK' => ['ISK', '210000'], 'UGX' => ['UGX', '210000'], 'MGA' => ['MGA', '21']]);

it('compares external refund aggregates in MGA wire units before accepting a known refund receipt', function (): void {
    $seed = refundRecoverySeed('MGA');
    $provider = refundRecoveryProvider($seed);
    $recovery = app(RefundRecovery::class);
    $recovery->reconcile($seed['order']->reference, $seed['row']->reference);
    $provider->chargeRefunded = '22';
    $provider->calls = [];
    expect($recovery->reconcile($seed['order']->reference, $seed['row']->reference)['state'])->toBe('held')
        ->and(DB::table(PaidOrders::ATTEMPTS)->value('category'))->toBe('external_refund')
        ->and(array_column($provider->calls, 'method'))->toBe(['GET', 'GET', 'GET']);
});
