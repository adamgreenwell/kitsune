<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kitsune\Commerce\Models\CheckoutAttempt;
use Kitsune\Commerce\Payments\StripeCheckout;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\OrderFixture;

/** @return array<string,mixed> */
function checkoutSchemaRow(object $order): array
{
    return ['org_id' => $order->org_id, 'site_id' => $order->site_id, 'order_id' => $order->id,
        'reference' => bin2hex(random_bytes(16)), 'active_order_id' => $order->id, 'mode' => 'test', 'currency' => 'USD',
        'account_id' => 'acct_private_schema', 'api_version' => StripeCheckout::API_VERSION, 'credential_witness' => str_repeat('c', 64), 'state' => 'creating',
        'request_payload' => '{"private":"purchase"}', 'request_hash' => str_repeat('d', 64), 'order_hash' => str_repeat('e', 64),
        'session_id' => null, 'checkout_url' => null, 'reviewed_at' => OrderFixture::now(),
        'expires_at' => now()->addMinutes(30)->format('Y-m-d H:i:s'), 'lease_token' => str_repeat('f', 64),
        'lease_until' => now()->addSeconds(30)->format('Y-m-d H:i:s'), 'created_at' => OrderFixture::now(), 'updated_at' => OrderFixture::now()];
}

beforeEach(function (): void {
    Fx::boot();
    $this->org = Org::create(['slug' => 'attempt-schema', 'name' => 'Attempt schema', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'first', 'slug' => 'attempt-schema-first', 'name' => 'First', 'base_url' => 'https://attempts.example.test']);
    $this->sibling = Site::create(['handle' => 'second', 'slug' => 'attempt-schema-second', 'name' => 'Second', 'base_url' => 'https://attempts2.example.test']);
    $this->foreign = Org::create(['slug' => 'attempt-foreign', 'name' => 'Foreign', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->foreign);
    $this->foreignSite = Site::create(['handle' => 'foreign', 'slug' => 'attempt-schema-foreign', 'name' => 'Foreign', 'base_url' => 'https://foreign-attempts.example.test']);
    app(Context::class)->setSite($this->site);
    $this->order = OrderFixture::plant($this->site);
    $this->siblingOrder = OrderFixture::plant($this->sibling);
    $this->foreignOrder = OrderFixture::plant($this->foreignSite);
    $this->row = checkoutSchemaRow($this->order);
    $this->attemptId = DB::table('commerce_checkout_attempts')->insertGetId($this->row);
    DB::table('commerce_checkout_attempts')->insert(checkoutSchemaRow($this->siblingOrder));
    DB::table('commerce_checkout_attempts')->insert(checkoutSchemaRow($this->foreignOrder));
});

afterEach(function (): void {
    Fx::tearDown();
});

it('declares and enforces exact-site checkout custody across both isolation boundaries', function (): void {
    expect((new ReflectionClass(CheckoutAttempt::class))->getAttributes(SiteScoped::class))->toHaveCount(1)
        ->and(class_uses_recursive(CheckoutAttempt::class))->toContain(EnforcesScope::class)
        ->and(CheckoutAttempt::query()->count())->toBe(1)
        ->and(CheckoutAttempt::query()->where('order_id', $this->siblingOrder->id)->exists())->toBeFalse()
        ->and(CheckoutAttempt::query()->where('order_id', $this->foreignOrder->id)->exists())->toBeFalse();
});

it('fails closed without a complete context', function (bool $orgOnly): void {
    app(Context::class)->forget();
    if ($orgOnly) {
        app(Context::class)->setOrg($this->org);
    }
    expect(CheckoutAttempt::query()->count())->toBe(0);
})->with([false, true]);

it('keeps a planted mismatched parent or scope stamp hidden through the scope hatch', function (string $column, string $source): void {
    $value = match ($source) {
        'org' => $this->foreign->getKey(), 'site' => $this->foreignSite->getKey(), 'order' => $this->siblingOrder->id,
    };
    DB::table('commerce_checkout_attempts')->where('id', $this->attemptId)->update([$column => $value]);
    expect(CheckoutAttempt::query()->count())->toBe(0)
        ->and(CheckoutAttempt::withoutScopeBecause('Probe the independent parent custody fence.', fn ($query) => $query->whereKey($this->attemptId)->exists()))->toBeFalse();
})->with([['org_id', 'org'], ['site_id', 'site'], ['order_id', 'order']]);

it('refuses constructed evented quiet replicated and bulk ORM writes', function (): void {
    $row = CheckoutAttempt::query()->sole();
    expect(fn () => $row->save())->toThrow(RuntimeException::class)
        ->and(fn () => $row->saveQuietly())->toThrow(RuntimeException::class)
        ->and(fn () => $row->delete())->toThrow(RuntimeException::class)
        ->and(fn () => $row->deleteQuietly())->toThrow(RuntimeException::class)
        ->and(fn () => $row->replicate()->save())->toThrow(RuntimeException::class)
        ->and(fn () => (new CheckoutAttempt)->save())->toThrow(RuntimeException::class);
    CheckoutAttempt::withoutScopeBecause('Probe immutable attempt writes through the hatch.', function ($query): void {
        expect(fn () => (clone $query)->update(['state' => 'open']))->toThrow(RuntimeException::class)
            ->and(fn () => (clone $query)->delete())->toThrow(RuntimeException::class)
            ->and(fn () => (clone $query)->forceDelete())->toThrow(RuntimeException::class)
            ->and(fn () => (clone $query)->insert([]))->toThrow(RuntimeException::class)
            ->and(fn () => (clone $query)->insertGetId([]))->toThrow(RuntimeException::class)
            ->and(fn () => (clone $query)->insertOrIgnore([]))->toThrow(RuntimeException::class)
            ->and(fn () => (clone $query)->upsert([], ['id']))->toThrow(RuntimeException::class)
            ->and(fn () => (clone $query)->increment('id'))->toThrow(RuntimeException::class)
            ->and(fn () => (clone $query)->decrement('id'))->toThrow(RuntimeException::class);
    });
    expect(CheckoutAttempt::query()->count())->toBe(1);
});

it('hides provider identifiers request custody checkout URLs and worker leases from automatic serialization', function (): void {
    DB::table('commerce_checkout_attempts')->where('id', $this->attemptId)->update(['session_id' => 'cs_test_private', 'checkout_url' => 'https://checkout.stripe.com/c/pay/private']);
    $serialized = CheckoutAttempt::query()->sole()->toArray();
    foreach (['account_id', 'api_version', 'credential_witness', 'request_payload', 'request_hash', 'order_hash', 'session_id', 'checkout_url', 'lease_token', 'lease_until'] as $key) {
        expect($serialized)->not->toHaveKey($key);
    }
});

it('restricts deletion of an order that has even a terminal retained attempt', function (): void {
    DB::table('commerce_checkout_attempts')->where('id', $this->attemptId)->update(['state' => 'expired', 'active_order_id' => null]);
    expect(fn () => DB::transaction(fn () => DB::table('commerce_orders')->where('id', $this->order->id)->delete()))->toThrow(QueryException::class);
    expect(DB::table('commerce_orders')->where('id', $this->order->id)->exists())->toBeTrue();
});

it('refuses destructive rollback before removing retained attempt custody', function (): void {
    app(Context::class)->forget();
    $migration = require __DIR__.'/../../packages/commerce/database/migrations/0001_01_01_000206_create_commerce_checkout_attempts.php';
    expect(fn () => $migration->down())->toThrow(RuntimeException::class)
        ->and(DB::table('commerce_checkout_attempts')->count())->toBe(3);
});

it('keeps public active-order and remote-session claims unique with Site-leading indexes', function (array $claim): void {
    $row = [...$this->row, 'reference' => bin2hex(random_bytes(16)), 'active_order_id' => null];
    foreach ($claim as $column) {
        $row[$column] = $this->row[$column];
    }
    if (in_array('session_id', $claim, true)) {
        DB::table('commerce_checkout_attempts')->where('id', $this->attemptId)->update(['session_id' => 'cs_test_same']);
        $row['session_id'] = 'cs_test_same';
    }
    expect(fn () => DB::transaction(fn () => DB::table('commerce_checkout_attempts')->insert($row)))->toThrow(QueryException::class);
    $indexes = DB::connection()->getSchemaBuilder()->getIndexes('commerce_checkout_attempts');
    expect(array_values(array_filter($indexes, fn ($index) => $index['unique'] && $index['columns'] === ['site_id', ...$claim])))->toHaveCount(1);
})->with([[['reference']], [['active_order_id']], [['account_id', 'session_id']]]);

it('compiles bounded distinct index and foreign-key names for a maximum-length prefixed MySQL table', function (bool $prefixIndexes): void {
    $prefix = str_repeat('p', 37).'_';
    // A local PDO prevents Laravel's pre-query reconnect; pretend() compiles MySQL SQL without executing it.
    $connection = new MySqlConnection(new PDO('sqlite::memory:'), 'schema_probe', $prefix, ['driver' => 'mysql', 'prefix_indexes' => $prefixIndexes]);
    $builder = $connection->getSchemaBuilder();
    $original = Schema::getFacadeRoot();
    try {
        Schema::swap($builder);
        $migration = require __DIR__.'/../../packages/commerce/database/migrations/0001_01_01_000206_create_commerce_checkout_attempts.php';
        $queries = $connection->pretend(fn () => $migration->up());
    } finally {
        Schema::swap($original);
    }
    $sql = implode("\n", array_column($queries, 'query'));
    preg_match_all('/(?:constraint|unique|index) `([^`]+)`/i', $sql, $matches);
    expect($matches[1])->toHaveCount(8)->and(array_unique($matches[1]))->toHaveCount(8);
    foreach ($matches[1] as $name) {
        expect(strlen($name))->toBeLessThanOrEqual(64)->and($name)->toStartWith($prefix);
    }
    expect($sql)->toContain('`'.$prefix.'commerce_checkout_attempts`', '`checkout_url` varchar(4096)');
})->with([false, true]);
