<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kitsune\Commerce\Orders\HistoricalAccess;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Orders\OrderRefused;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\FinancialAccessFixture as FinancialFx;

beforeEach(function (): void {
    Fx::boot();
    CarbonImmutable::setTestNow('2026-10-15 12:00:00 UTC');
    $this->org = Org::create(['slug' => 'historical-access', 'name' => 'Historical access', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'historical-main', 'name' => 'Main', 'base_url' => 'https://historical.example.test']);
    app(Context::class)->setSite($this->site);
    Fx::declareReaders();
    $this->reader = Fx::reader();
    $this->order = FinancialFx::purchase($this->site, (string) $this->reader->id, [['days' => 30, 'quantity' => 2, 'unknown' => true]]);
    $this->line = (int) DB::table('commerce_order_lines')->where('order_id', $this->order->id)->value('id');
    Fx::owner();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Fx::tearDown();
});

it('backfills retained historical orders and refuses custody-destroying rollback on a real engine', function (): void {
    DB::table(HistoricalAccess::WORK)->delete();
    Schema::drop(HistoricalAccess::TABLE);
    Schema::drop(HistoricalAccess::WORK);
    $migration = require __DIR__.'/../../packages/commerce/database/migrations/0001_01_01_000222_create_commerce_access_reviews.php';
    $migration->up();
    expect(DB::transactionLevel())->toBe(0)
        ->and(app(OrderReaderData::class)->record(DB::connection(), $this->order)['historical_access']['revision'])->toBe(0);
    app(HistoricalAccess::class)->decideAsOwner($this->order->reference, $this->line, 'award',
        ['version' => 1, 'kind' => 'fixed', 'days' => 30], 0, 'Reviewed retained engine purchase', 'archive:native', bin2hex(random_bytes(16)), true);
    expect(DB::transactionLevel())->toBe(0)->and(DB::table('entitlements')->value('expires_at'))->toBe('2026-11-14 12:00:00')
        ->and(fn () => $migration->down())->toThrow(RuntimeException::class)
        ->and(DB::table(HistoricalAccess::TABLE)->count())->toBe(1)->and(DB::table(HistoricalAccess::WORK)->count())->toBe(1);
});

it('rolls back an interrupted owner review without a source or success audit on a real engine', function (): void {
    $dispatcher = AuditLog::getEventDispatcher();
    AuditLog::setEventDispatcher(clone $dispatcher);
    AuditLog::created(function ($audit): void {
        if ($audit->action === 'commerce.historical_access_reviewed') {
            throw new RuntimeException('Simulated interrupted review commit');
        }
    });
    try {
        expect(fn () => app(HistoricalAccess::class)->decideAsOwner($this->order->reference, $this->line, 'award',
            ['version' => 1, 'kind' => 'permanent'], 0, 'Reviewed before interrupted commit', 'archive:interrupt', bin2hex(random_bytes(16)), true))->toThrow(OrderRefused::class);
    } finally {
        AuditLog::setEventDispatcher($dispatcher);
    }
    expect(DB::transactionLevel())->toBe(0)->and(DB::table(HistoricalAccess::TABLE)->count())->toBe(0)
        ->and(DB::table(HistoricalAccess::WORK)->value('revision'))->toBe(0)
        ->and(DB::table('entitlements')->count())->toBe(0)
        ->and(DB::table('audit_log')->where('action', 'commerce.historical_access_reviewed')->count())->toBe(0);
});
