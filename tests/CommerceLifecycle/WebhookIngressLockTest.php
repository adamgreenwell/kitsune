<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Http\Controllers\StripeWebhookController;
use Kitsune\Core\Http\BoundedWebhookRequest;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\StripeInboxFixture as Inbox;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

afterEach(function (): void {
    if (array_key_exists('webhook-lock-rival', config('database.connections') ?? [])) {
        DB::disconnect('webhook-lock-rival');
        DB::purge('webhook-lock-rival');
    }
    Fx::tearDown();
    ModuleKernel::flush();
});

it('keeps impossible webhook envelopes out of a contended checkout lock while plausible signatures retain the lock', function (bool $plausible): void {
    $connection = DB::connection();
    if ($connection->getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite compiles row locks away; query-free ingress is covered on the bare-clone suite.');
    }
    Fx::boot();
    $org = Fx::org('webhook-lock');
    Site::create(['handle' => 'ingress', 'slug' => 'locked-ingress', 'name' => 'Ingress', 'base_url' => 'https://locked.example.test']);
    Inbox::enable();
    ModuleKernel::boot($this->app);
    expect($connection->transactionLevel())->toBe(0);
    config(['database.connections.webhook-lock-rival' => $connection->getConfig()]);
    $rival = DB::connection('webhook-lock-rival');
    $rival->beginTransaction();
    // An ordinary checkout writer needs FOR UPDATE. This real independent shared hold makes that wait.
    $rival->table('orgs')->where('id', $org->id)->sharedLock()->value('id');
    $connection->getDriverName() === 'pgsql'
        ? $connection->statement("set lock_timeout = '500ms'")
        : $connection->statement('set session innodb_lock_wait_timeout = 1');
    $queries = [];
    $connection->beforeExecuting(static function (string $sql) use (&$queries): void {
        $queries[] = $sql;
    });
    $signature = $plausible ? 't='.time().',v1='.str_repeat('0', 64) : '';
    $request = BoundedWebhookRequest::fromBase(SymfonyRequest::create('https://locked.example.test/cart/stripe/webhook', 'POST', [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], '{}'), '/cart/stripe/webhook', 'Stripe-Signature');
    expect($request)->toBeInstanceOf(Request::class);
    app(Context::class)->forget();
    $started = hrtime(true);
    try {
        $response = app(StripeWebhookController::class)($request);
        $seconds = (hrtime(true) - $started) / 1e9;
    } finally {
        $rival->rollBack();
        $connection->getDriverName() === 'pgsql'
            ? $connection->statement('reset lock_timeout')
            : $connection->statement('set session innodb_lock_wait_timeout = default');
    }
    // The reset itself is irrelevant to the ingress's query budget.
    array_pop($queries);
    expect($response->getStatusCode())->toBe(400)->and(app(Context::class)->hasOrg())->toBeFalse();
    if ($plausible) {
        expect(implode(' ', $queries))->toContain('for update')->and($seconds)->toBeGreaterThan(0.4);
    } else {
        expect($queries)->toBe([]);
    }
})->with(['unsigned probe' => false, 'unverified plausible signature' => true]);
