<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Payments\CheckoutRefused;
use Kitsune\Commerce\Payments\PaidConfirmationAudit;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\PaidOrderFixture as PaidFx;

beforeEach(function (): void {
    Fx::boot();
    $this->org = Org::create(['slug' => 'paid-audit', 'name' => 'Paid audit', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'paid-audit-main', 'name' => 'Main', 'base_url' => 'https://paid-audit.example.test']);
    $this->sibling = Site::create(['handle' => 'other', 'slug' => 'paid-audit-other', 'name' => 'Other', 'base_url' => 'https://other-audit.example.test']);
    $this->foreign = Org::create(['slug' => 'paid-audit-foreign', 'name' => 'Foreign', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->foreign);
    $this->foreignSite = Site::create(['handle' => 'foreign', 'slug' => 'paid-audit-foreign', 'name' => 'Foreign', 'base_url' => 'https://foreign-audit.example.test']);
    app(Context::class)->setSite($this->site);
    $this->attempt = PaidFx::attempt($this->site);
    $this->session = PaidFx::session($this->attempt);
    $this->confirm = function (): void {
        app(OrderLocks::class)->run(function (): void {
            $order = DB::table('commerce_orders')->where('id', $this->attempt->order_id)->sole();
            app(PaidOrders::class)->confirm(DB::connection(), $order, $this->attempt, $this->session);
        });
    };
});

afterEach(fn () => Fx::tearDown());

function paidConfirmationObserve(Closure $observer, Closure $work, bool $creating = false): mixed
{
    $dispatcher = AuditLog::getEventDispatcher();
    AuditLog::setEventDispatcher(clone $dispatcher);
    if ($creating) {
        AuditLog::creating($observer);
    } else {
        AuditLog::created($observer);
    }
    try {
        return $work();
    } finally {
        AuditLog::setEventDispatcher($dispatcher);
    }
}

/** Every row the confirmation may affect, compared after rollback without trusting model scopes. */
function paidConfirmationState(): array
{
    $tables = ['commerce_orders', 'commerce_order_lines', 'commerce_checkout_attempts', 'commerce_sellables', 'commerce_stock_movements',
        PaidOrders::RECEIPTS, PaidOrders::ALLOCATIONS, PaidOrders::OBLIGATIONS, PaidOrders::ATTEMPTS, 'audit_log'];

    return array_combine($tables, array_map(static fn (string $table): array => DB::table($table)->orderBy('id')->get()
        ->map(static fn (object $row): array => get_object_vars($row))->all(), $tables));
}

it('records exact system and ordinary staff identities without requiring an owner', function (bool $staff): void {
    $actor = $staff ? Fx::member() : null;
    expect($actor === null || ! Permissions::isOwner($actor))->toBeTrue();
    ($this->confirm)();
    $row = DB::table('audit_log')->where('action', 'commerce.order_paid')->sole();
    expect($row->actor_type)->toBe($actor?->getMorphClass())->and($row->actor_id)->toBe($actor === null ? null : (string) $actor->getKey())
        ->and((string) $row->target_id)->toBe((string) $this->attempt->order_id)->and((string) $row->org_id)->toBe((string) $this->org->id)
        ->and((string) $row->site_id)->toBe((string) $this->site->id)
        ->and(DB::table('commerce_orders')->value('state'))->toBe('paid');
})->with([false, true]);

it('rolls back the entire confirmation when its financial audit is cancelled', function (): void {
    $before = paidConfirmationState();
    paidConfirmationObserve(static fn ($audit): mixed => $audit->action === 'commerce.order_paid' ? false : null, function (): void {
        expect($this->confirm)->toThrow(CheckoutRefused::class);
    }, true);
    expect(paidConfirmationState())->toBe($before);
});

it('checks the persisted financial audit identity after host callbacks', function (string $field): void {
    Fx::owner();
    $before = paidConfirmationState();
    paidConfirmationObserve(function ($audit) use ($field): void {
        if ($audit->action !== 'commerce.order_paid') {
            return;
        }
        DB::table('audit_log')->where('id', $audit->getKey())->update([$field => match ($field) {
            'org_id' => $this->foreign->id, 'site_id' => $this->sibling->id,
            'action' => 'commerce.forged_confirmation', 'target_type' => 'another.model', 'target_id' => '999999',
            'actor_type' => 'another.actor', 'actor_id' => '999999',
        }]);
    }, function (): void {
        expect($this->confirm)->toThrow(CheckoutRefused::class);
    });
    expect(paidConfirmationState())->toBe($before);
})->with(['org_id', 'site_id', 'action', 'target_type', 'target_id', 'actor_type', 'actor_id']);

it('refuses stock line order receipt and refund request mutations before a later baseline can accept them', function (string $mutation): void {
    $before = paidConfirmationState();
    paidConfirmationObserve(function ($audit) use ($mutation): void {
        if ($audit->action !== 'commerce.order_paid') {
            return;
        }
        match ($mutation) {
            'stock' => DB::table('commerce_sellables')->update(['stock' => '1']),
            'movement' => DB::table('commerce_stock_movements')->update(['reason' => 'Forged allocation.']),
            'line' => DB::table('commerce_order_lines')->update(['title' => 'Forged item']),
            'order' => DB::table('commerce_orders')->update(['contact_email' => 'forged@example.test']),
            'receipt' => DB::table(PaidOrders::RECEIPTS)->update(['charge_id' => 'ch_Forged']),
            'request' => DB::table(PaidOrders::ATTEMPTS)->update(['request_payload' => '{}', 'request_hash' => CheckoutAttempts::digest([])]),
            'attempt' => DB::table(CheckoutAttempts::TABLE)->update(['credential_witness' => str_repeat('b', 64)]),
        };
    }, function (): void {
        expect($this->confirm)->toThrow(CheckoutRefused::class);
    });
    expect(paidConfirmationState())->toBe($before);
})->with(['stock', 'movement', 'line', 'order', 'receipt', 'request', 'attempt']);

it('refuses a coherent rewrite of awarded units refund money and stock that passes the financial projection', function (): void {
    $before = paidConfirmationState();
    $projectionAccepted = false;
    paidConfirmationObserve(function ($audit) use (&$projectionAccepted): void {
        if ($audit->action !== 'commerce.order_paid') {
            return;
        }
        DB::table('commerce_sellables')->update(['stock' => '1']);
        DB::table('commerce_stock_movements')->update(['quantity' => 1, 'after_stock' => '1']);
        DB::table(PaidOrders::ALLOCATIONS)->update(['awarded' => 1, 'unavailable' => 2, 'refund_item_net_minor' => '4000', 'refund_item_tax_minor' => '200']);
        DB::table(PaidOrders::OBLIGATIONS)->update(['amount_minor' => '4200']);
        $order = DB::table('commerce_orders')->sole();
        $receipt = DB::table(PaidOrders::RECEIPTS)->sole();
        $attempt = DB::table(PaidOrders::ATTEMPTS)->sole();
        $payload = ['charge' => $receipt->charge_id, 'amount' => '4200', 'metadata' => ['kitsune_order' => $order->reference,
            'kitsune_refund' => $attempt->reference, 'kitsune_request' => CheckoutAttempts::digest(['order' => $order->reference,
                'refund' => $attempt->reference, 'charge' => $receipt->charge_id, 'amount' => '4200'])]];
        DB::table(PaidOrders::ATTEMPTS)->update(['request_payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'request_hash' => CheckoutAttempts::digest($payload)]);
        $projection = app(PaidOrders::class)->projection(DB::connection(), $this->org->id, $order, true);
        $projectionAccepted = $projection['allocations'][0]['awarded'] === 1 && $projection['refund']['amount_minor'] === '4200';
    }, function (): void {
        expect($this->confirm)->toThrow(CheckoutRefused::class);
    });
    expect($projectionAccepted)->toBeTrue()->and(paidConfirmationState())->toBe($before);
});

it('rechecks the context and a present persisted actor after financial audit callbacks', function (string $mutation): void {
    $replacement = Fx::member('replacement-paid-audit@example.test');
    $owner = Fx::owner();
    $before = paidConfirmationState();
    try {
        paidConfirmationObserve(function ($audit) use ($mutation, $owner, $replacement): void {
            if ($audit->action !== 'commerce.order_paid') {
                return;
            }
            match ($mutation) {
                'context' => app(Context::class)->setSite($this->sibling),
                'context attributes' => app(Context::class)->org()->setAttribute('name', 'Changed cached org'),
                'actor' => Auth::guard('web')->setUser($replacement),
                'password' => DB::table('users')->where('id', $owner->getKey())->update(['password' => 'changed-authentication-witness']),
                'owner role' => DB::table('roles')->where('org_id', $this->org->id)->where('is_owner', true)->update(['is_owner' => false]),
            };
        }, function (): void {
            expect($this->confirm)->toThrow(CheckoutRefused::class);
        });
    } finally {
        app(Context::class)->forget();
        app(Context::class)->setSite($this->site->fresh());
        Auth::guard('web')->setUser($owner);
        Permissions::forget();
    }
    expect(paidConfirmationState())->toBe($before)->and(Permissions::isOwner($owner))->toBeTrue();
})->with(['context', 'context attributes', 'actor', 'password', 'owner role']);

it('refuses an incomplete or caller-forged paid graph without adding a financial audit', function (): void {
    $order = DB::table('commerce_orders')->sole();
    expect(fn () => app(PaidConfirmationAudit::class)->record(DB::connection(), $order))->toThrow(CheckoutRefused::class);
    ($this->confirm)();
    $order = DB::table('commerce_orders')->sole();
    unset($order->billing);
    expect(fn () => app(OrderLocks::class)->run(fn () => app(PaidConfirmationAudit::class)->record(DB::connection(), $order)))->toThrow(CheckoutRefused::class)
        ->and(DB::table('audit_log')->where('action', 'commerce.order_paid')->count())->toBe(1);
});
