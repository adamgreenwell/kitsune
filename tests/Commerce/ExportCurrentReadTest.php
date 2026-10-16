<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Exports\OrderHistoryExport;
use Kitsune\Commerce\Payments\AccessAdjustments;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\FinancialState;
use Kitsune\Commerce\Privacy\PrivacyState;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture;
use Kitsune\Core\Tests\Fixtures\OrderFixture;

/*
 * The rival commits both a Site and historical order after the host has pinned an InnoDB consistent snapshot.
 * The console producer must use current primary locking reads while ordinary host reads retain their old snapshot.
 * No provider, catalogue, owner fixture or database service is required by the default bare-clone test leg.
 */
it('exports newly committed historical custody from an older native repeatable-read host snapshot', function (string $scope): void {
    $connection = DB::connection();
    if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('This two-connection export regression measures MySQL/MariaDB repeatable-read semantics.');
    }
    EntitlementFixture::boot();
    Auth::guard('web')->logout();
    config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
    $default = (string) config('database.default');
    $rivalName = 'export-current-rival';
    config(['database.connections.'.$rivalName => config('database.connections.'.$default)]);
    $rival = DB::connection($rivalName);
    $orgId = null;
    $moduleId = null;
    // The rival fixtures are committed, so register their exact cleanup before making the first write.
    $this->afterRollback(function () use ($rival, $rivalName, $default, &$orgId, &$moduleId): void {
        DB::setDefaultConnection($default);
        if ($orgId !== null) {
            foreach ([PrivacyState::TABLE, AccessAdjustments::WORK, FinancialState::WORK, AccessFulfillment::WORK,
                'commerce_order_access_tokens', 'commerce_order_lines', 'commerce_orders', 'sites'] as $table) {
                $rival->table($table)->where('org_id', $orgId)->delete();
            }
            $rival->table('orgs')->where('id', $orgId)->delete();
        }
        if ($moduleId !== null) {
            $rival->table('modules')->where('id', $moduleId)->where('handle', 'kitsune/commerce')->delete();
        }
        DB::purge($rivalName);
    });
    $suffix = bin2hex(random_bytes(6));
    $now = OrderFixture::now();
    $orgId = (int) $rival->table('orgs')->insertGetId(['slug' => 'export-current-'.$suffix, 'name' => 'Current export',
        'currency' => 'USD', 'created_at' => $now, 'updated_at' => $now]);
    if (! $rival->table('modules')->where('handle', 'kitsune/commerce')->exists()) {
        $moduleId = (int) $rival->table('modules')->insertGetId(['handle' => 'kitsune/commerce',
            'version' => ModuleDiscovery::version('kitsune/commerce'), 'is_enabled' => false, 'installed_at' => $now]);
    }

    try {
        // Replace RefreshDatabase's empty wrapper to explicitly choose this real transaction's isolation.
        $connection->rollBack();
        $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $connection->beginTransaction();
        expect($connection->transactionLevel())->toBe(1)->and($connection->getPdo()->inTransaction())->toBeTrue()
            ->and($rival->getDriverName())->toBe($connection->getDriverName())
            ->and($rival->getPdo())->not->toBe($connection->getPdo())
            ->and((string) $rival->selectOne('SELECT CONNECTION_ID() AS id')->id)
            ->not->toBe((string) $connection->selectOne('SELECT CONNECTION_ID() AS id')->id)
            ->and($connection->table('sites')->where('org_id', $orgId)->count())->toBe(0)
            ->and($connection->table('commerce_orders')->where('org_id', $orgId)->count())->toBe(0);

        $siteId = (int) $rival->table('sites')->insertGetId(['org_id' => $orgId, 'handle' => 'main', 'slug' => 'export-current-site-'.$suffix,
            'name' => 'Current export site', 'locale' => 'en', 'base_url' => 'https://export-current-'.$suffix.'.example.test',
            'canonical_host' => 'export-current-'.$suffix.'.example.test', 'path_prefix' => '', 'created_at' => $now, 'updated_at' => $now]);
        DB::setDefaultConnection($rivalName);
        try {
            $site = (new Site)->newFromBuilder((array) $rival->table('sites')->where('id', $siteId)->sole(), $rivalName);
            $order = OrderFixture::plant($site);
        } finally {
            DB::setDefaultConnection($default);
        }
        expect($rival->transactionLevel())->toBe(0)->and($rival->getPdo()->inTransaction())->toBeFalse()
            ->and($rival->table('commerce_orders')->where('org_id', $orgId)->count())->toBe(1)
            ->and($connection->table('sites')->where('org_id', $orgId)->count())->toBe(0)
            ->and($connection->table('commerce_orders')->where('org_id', $orgId)->count())->toBe(0);

        $orgRow = $connection->table('orgs')->where('id', $orgId)->lockForUpdate()->sole();
        $siteRow = $connection->table('sites')->where('org_id', $orgId)->where('id', $siteId)->lockForUpdate()->sole();
        $org = (new Org)->newFromBuilder((array) $orgRow, $default);
        $site = (new Site)->newFromBuilder((array) $siteRow, $default);
        $site->setRelation('org', $org);
        $context = app(Context::class)->forget()->setOrg($org);
        if ($scope === 'Site') {
            $context->setSite($site);
        }
        $page = app(OrderHistoryExport::class)->forConsole('jsonl', limit: 1);
        $records = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            explode("\n", rtrim($page->body, "\n")));
        $orders = array_values(array_filter($records, static fn (array $record): bool => $record['type'] === 'order'));
        expect($page->count)->toBe(1)->and($page->nextCursor)->toBeNull()
            ->and(array_column($orders, 'order_reference'))->toBe([$order->reference])
            ->and($orders[0]['site'])->toBe('main')->and($orders[0]['data']['quoted_total_minor'])->toBe('2100')
            // Authoritative export must not alter the semantics of the host's ordinary consistent reads.
            ->and($connection->table('sites')->useWritePdo()->where('org_id', $orgId)->count())->toBe(0)
            ->and($connection->table('commerce_orders')->useWritePdo()->where('org_id', $orgId)->count())->toBe(0)
            ->and($connection->transactionLevel())->toBe(1)->and($connection->getPdo()->inTransaction())->toBeTrue();
    } finally {
        DB::setDefaultConnection($default);
        EntitlementFixture::tearDown();
    }
})->with(['Org', 'Site']);
