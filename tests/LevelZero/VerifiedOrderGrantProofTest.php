<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Models\Reader;
use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Core\Entitlements\EntitlementWriter;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\FinancialAccessFixture;
use Kitsune\Core\Tests\Fixtures\OrderFixture;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Kitsune\Core\Tests\TestCase;

beforeEach(function (): void {
    config(AppServiceProvider::readerConfig());
    config(['hashing.bcrypt.rounds' => 4, 'app.key' => 'base64:'.base64_encode(str_repeat('g', 32)), 'cache.default' => 'array']);
    CarbonImmutable::setTestNow('2026-10-15 12:00:00 UTC');
    ModuleKernel::flush();
    ModuleLifecycle::install(app(), 'kitsune/commerce');
    ModuleLifecycle::enable(app(), 'kitsune/commerce');
    (require TestCase::READERS_MIGRATION)->up();
    $this->org = Org::create(['slug' => 'verified-grant-proof', 'name' => 'Verified grant proof', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'verified-grant-proof-main', 'name' => 'Main', 'base_url' => 'https://grant-proof.example.test']);
    ReaderFixture::mode($this->org, 'sign-in');
    $this->reader = ReaderFixture::reader($this->org, 'proof-reader@example.test');
    app(Context::class)->setSite($this->site);
    $this->order = FinancialAccessFixture::purchase($this->site, (string) $this->reader->id);
    $this->request = OrderFixture::request(app('session.store'));
    Auth::guard(ReaderFixture::GUARD)->login($this->reader);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    ReaderFixture::forget();
    ModuleKernel::flush();
});

it('rolls back current source or audit corruption from native buyer admission callbacks in final producer proof', function (bool $audit): void {
    $dispatcher = Reader::getEventDispatcher();
    Reader::setEventDispatcher(clone $dispatcher);
    $observed = 0;
    Reader::retrieved(static function () use (&$observed, $audit): void {
        $row = DB::table('entitlements')->where('entitlement', 'course.purchased')->first();
        if ($row === null) {
            return;
        }
        $observed++;
        if ($audit) {
            DB::table('audit_log')->where('action', EntitlementWriter::GRANTED)->where('target_id', $row->id)->update(['actor_id' => 'forged']);
        } else {
            DB::table('entitlements')->where('id', $row->id)->update(['expires_at' => null]);
        }
    });
    try {
        expect(app(AccessFulfillment::class)->reconcileReader($this->request, $this->order->reference))
            ->toMatchArray(['state' => 'held', 'category' => 'grant_refused']);
    } finally {
        Reader::setEventDispatcher($dispatcher);
    }
    $pdo = new PDO('sqlite:'.DB::connection()->getDatabaseName());
    expect($observed)->toBeGreaterThan(0)
        ->and((int) $pdo->query('SELECT COUNT(*) FROM entitlements')->fetchColumn())->toBe(0)
        ->and((int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'entitlement.granted'")->fetchColumn())->toBe(0)
        ->and($pdo->query('SELECT state FROM commerce_access_effects')->fetchColumn())->toBe('pending')
        ->and((int) $pdo->query('SELECT COUNT(*) FROM commerce_payment_receipts')->fetchColumn())->toBe(1)
        ->and((string) Auth::guard(ReaderFixture::GUARD)->id())->toBe((string) $this->reader->id)
        ->and(DB::transactionLevel())->toBe(0)->and(DB::getPdo()->inTransaction())->toBeFalse();
})->with(['current source' => false, 'producer audit' => true]);
