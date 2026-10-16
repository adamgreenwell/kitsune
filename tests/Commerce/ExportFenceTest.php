<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Exports\ExportRefused;
use Kitsune\Commerce\Exports\OrderHistoryExport;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as ExportFenceFx;
use Kitsune\Core\Tests\Fixtures\OrderFixture;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Kitsune\Core\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    ExportFenceFx::boot();
    $this->world = ReaderFixture::world();
    DB::table('orgs')->update(['currency' => 'USD']);
    $this->site = $this->world['sites']['golfdom'];
    app(Context::class)->setSite($this->site);
    $this->owner = ExportFenceFx::member();
    $this->module = Module::create(['handle' => 'kitsune/commerce', 'version' => 'historical-export-version',
        'is_enabled' => false, 'installed_at' => now()]);
    $this->order = OrderFixture::plant($this->site);
});

afterEach(fn () => ExportFenceFx::tearDown());

it('refuses and rolls back custody changed by the final fresh owner callback before returning any artifact', function (string $attack, string $format): void {
    $line = DB::table('commerce_order_lines')->where('order_id', $this->order->id)->sole();
    $beforeLine = (array) $line;
    $beforeModule = (array) DB::table('modules')->where('id', $this->module->id)->sole();
    $connection = DB::connection();
    $pdo = $connection->getPdo();
    $level = $connection->transactionLevel();
    $owner = $this->owner;
    $site = $this->site;
    $module = $this->module;
    $retrievals = 0;
    $attacked = false;
    $artifact = null;
    $dispatcher = TestUser::getEventDispatcher();
    TestUser::setEventDispatcher(clone $dispatcher);
    TestUser::retrieved(function (TestUser $fresh) use ($owner, $line, $module, $attack, &$retrievals, &$attacked): void {
        if ((string) $fresh->getKey() !== (string) $owner->getKey() || ++$retrievals !== 2) {
            return;
        }
        $attacked = true;
        if ($attack === 'line') {
            DB::table('commerce_order_lines')->where('id', $line->id)->update(['gross_minor' => '1']);
        } elseif ($attack === 'title') {
            // This remains a valid historical graph; its changed canonical fingerprint must still be refused.
            DB::table('commerce_order_lines')->where('id', $line->id)->update(['title' => 'Changed after serialization']);
        } else {
            DB::table('modules')->where('id', $module->id)->delete();
        }
    });
    try {
        try {
            $artifact = app(OrderHistoryExport::class)->forOwner($format);
            $this->fail('A final host callback must not release an artifact checked before its corruption.');
        } catch (ExportRefused $failure) {
            expect($failure->getPrevious())->toBeNull()
                ->and($failure->getMessage())->not->toContain('Historical Buyer', 'historical-buyer@example.test', 'Saved course title', $this->order->reference);
        }
    } finally {
        TestUser::setEventDispatcher($dispatcher);
    }
    expect($attacked)->toBeTrue()->and($retrievals)->toBeGreaterThanOrEqual(2)->and($artifact)->toBeNull()
        ->and((array) DB::table('commerce_order_lines')->where('id', $line->id)->sole())->toBe($beforeLine)
        ->and((array) DB::table('modules')->where('id', $module->id)->sole())->toBe($beforeModule)
        ->and(Auth::guard('web')->user())->toBe($owner)
        ->and(app(Context::class)->orgId())->toBe($site->org_id)->and(app(Context::class)->siteId())->toBe($site->id)
        ->and($connection->getPdo())->toBe($pdo)->and($connection->transactionLevel())->toBe($level)
        ->and($pdo->inTransaction())->toBeTrue();
})->with([['line', 'jsonl'], ['line', 'csv'], ['title', 'jsonl'], ['title', 'csv'], ['module', 'jsonl'], ['module', 'csv']]);

it('refuses a native unsigned high-water ID instead of silently omitting the highest order', function (): void {
    $connection = DB::connection();
    if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Only MySQL/MariaDB BIGINT UNSIGNED can store an order ID above PHP_INT_MAX; SQLite/PostgreSQL IDs have signed capacity.');
    }
    $status = $connection->selectOne("SHOW TABLE STATUS LIKE 'commerce_orders'");
    $next = filter_var($status->Auto_increment, FILTER_VALIDATE_INT);
    expect($next)->toBeInt()->toBeGreaterThan(0);
    // Explicit unsigned IDs raise AUTO_INCREMENT even when the test transaction rolls back. Restore its prior
    // value only after RefreshDatabase has released that transaction and proved no retained rows remain.
    $this->afterRollback(function () use ($connection, $next): void {
        if ($connection->transactionLevel() !== 0 || $connection->table('commerce_orders')->exists()) {
            throw new RuntimeException('The unsigned export fixture could not safely restore its sequence.');
        }
        $connection->statement('ALTER TABLE commerce_orders AUTO_INCREMENT = '.$next);
    });
    $high = (array) $this->order;
    $high['id'] = '9223372036854775808';
    $high['reference'] = str_repeat('b', 32);
    $high['request_key'] = str_repeat('c', 64);
    $high['active_cart_key'] = null;
    $high['state'] = 'cancelled';
    $high['cancelled_at'] = OrderFixture::now();
    // No children are necessary: an unrepresentable high-water mark must be refused before order projection.
    $connection->table('commerce_orders')->insert($high);
    expect((string) $connection->table('commerce_orders')->where('reference', $high['reference'])->value('id'))->toBe($high['id']);
    $projected = false;
    DB::listen(static function (QueryExecuted $query) use (&$projected): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'select')
            && preg_match('/(?:from|join)\s+["`]?commerce_order_lines["`]?\b/i', $query->sql) === 1) {
            $projected = true;
        }
    });
    try {
        app(OrderHistoryExport::class)->forOwner('jsonl');
        $this->fail('A high-water ID must never be truncated into a complete-looking export.');
    } catch (ExportRefused $failure) {
        expect($failure->getPrevious())->toBeNull()->and($projected)->toBeFalse();
    }
});
