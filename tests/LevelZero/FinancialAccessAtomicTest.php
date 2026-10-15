<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Payments\AccessAdjustments;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\CheckoutRefused;
use Kitsune\Commerce\Payments\FinancialState;
use Kitsune\Core\Entitlements\EntitlementWriter;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\FinancialAccessFixture as FinancialFx;

beforeEach(function (): void {
    Fx::boot();
    CarbonImmutable::setTestNow('2026-10-15 12:00:00 UTC');
    ModuleKernel::flush();
    ModuleLifecycle::install(app(), 'kitsune/commerce');
    ModuleLifecycle::enable(app(), 'kitsune/commerce');
    ModuleKernel::boot(app());
    $org = Org::create(['slug' => 'atomic-financial-access', 'name' => 'Atomic financial access', 'currency' => 'USD']);
    app(Context::class)->setOrg($org);
    $site = Site::create(['handle' => 'main', 'slug' => 'atomic-financial-access-main', 'name' => 'Main', 'base_url' => 'https://atomic.example.test']);
    app(Context::class)->setSite($site);
    Fx::declareReaders();
    $this->reader = Fx::reader();
    $this->order = FinancialFx::purchase($site, (string) $this->reader->id);
    app(AccessFulfillment::class)->reconcile($this->order->reference);
    Fx::owner();
    $this->line = (int) DB::table('commerce_order_lines')->where('order_id', $this->order->id)->value('id');
    $this->observer = new PDO('sqlite:'.config('database.connections.custody.database'));
    $this->observer->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
});

afterEach(function (): void {
    $this->observer = null;
    CarbonImmutable::setTestNow();
    Fx::tearDown();
    ModuleKernel::flush();
});

it('commits a local refund intent and its suspension together as seen by an independent connection', function (): void {
    $before = [];
    $pdo = $this->observer;
    $dispatcher = AuditLog::getEventDispatcher();
    AuditLog::setEventDispatcher(clone $dispatcher);
    AuditLog::created(function (AuditLog $audit) use (&$before, $pdo): void {
        if ($audit->action === EntitlementWriter::REVOKED) {
            $before[] = [(int) $pdo->query('SELECT COUNT(*) FROM commerce_financial_refunds')->fetchColumn(),
                $pdo->query('SELECT revoked_at FROM entitlements')->fetchColumn()];
        }
    });
    try {
        app(FinancialState::class)->requestAsOwner($this->order->reference, [$this->line => 1], 'Atomically suspend this returned pass', bin2hex(random_bytes(16)));
    } finally {
        AuditLog::setEventDispatcher($dispatcher);
    }
    expect($before)->toBe([[0, null]])
        ->and((int) $pdo->query('SELECT COUNT(*) FROM commerce_financial_refunds')->fetchColumn())->toBe(1)
        ->and($pdo->query('SELECT revoked_at FROM entitlements')->fetchColumn())->not->toBeNull()
        ->and($pdo->query('SELECT revocation_witness FROM commerce_access_adjustment_effects')->fetchColumn())->not->toBeNull()
        ->and(DB::transactionLevel())->toBe(0)->and(DB::getPdo()->inTransaction())->toBeFalse();
});

it('rolls the refund intent back if the required source suspension cannot be audited', function (): void {
    $dispatcher = AuditLog::getEventDispatcher();
    AuditLog::setEventDispatcher(clone $dispatcher);
    AuditLog::creating(static fn (AuditLog $audit): ?bool => $audit->action === EntitlementWriter::REVOKED ? false : null);
    try {
        expect(fn () => app(FinancialState::class)->requestAsOwner($this->order->reference, [$this->line => 1],
            'Refused suspension must not create a money request', bin2hex(random_bytes(16))))->toThrow(CheckoutRefused::class);
    } finally {
        AuditLog::setEventDispatcher($dispatcher);
    }
    expect((int) $this->observer->query('SELECT COUNT(*) FROM commerce_financial_refunds')->fetchColumn())->toBe(0)
        ->and($this->observer->query('SELECT revoked_at FROM entitlements')->fetchColumn())->toBeNull()
        ->and((int) $this->observer->query("SELECT COUNT(*) FROM audit_log WHERE action = 'entitlement.revoked'")->fetchColumn())->toBe(0)
        ->and(DB::table(AccessAdjustments::EFFECTS)->count())->toBe(0)
        ->and(DB::transactionLevel())->toBe(0)->and(DB::getPdo()->inTransaction())->toBeFalse();
});
