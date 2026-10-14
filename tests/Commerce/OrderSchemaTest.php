<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Models\OrderAccessToken;
use Kitsune\Commerce\Models\OrderLine;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\OrderFixture;

beforeEach(function (): void {
    Fx::boot();
    $this->org = Org::create(['slug' => 'order-schema', 'name' => 'Order schema', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'first', 'slug' => 'order-schema-first', 'name' => 'First', 'base_url' => 'https://schema.example.test']);
    $this->sibling = Site::create(['handle' => 'second', 'slug' => 'order-schema-second', 'name' => 'Second', 'base_url' => 'https://sibling-schema.example.test']);
    $this->otherOrg = Org::create(['slug' => 'other-order-schema', 'name' => 'Other', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->otherOrg);
    $this->otherSite = Site::create(['handle' => 'other', 'slug' => 'order-schema-other', 'name' => 'Other', 'base_url' => 'https://other-schema.example.test']);
    app(Context::class)->setSite($this->site);
    $this->order = OrderFixture::plant($this->site);
    OrderFixture::token($this->order, str_repeat('a', 64));
    OrderFixture::plant($this->sibling);
    OrderFixture::plant($this->otherSite);
});

afterEach(function (): void {
    Fx::tearDown();
});

it('declares and actually enforces exact-site scopes for every order projection', function (string $model): void {
    expect((new ReflectionClass($model))->getAttributes(SiteScoped::class))->toHaveCount(1)
        ->and(class_uses_recursive($model))->toContain(EnforcesScope::class)
        ->and($model::query()->count())->toBe(1);
})->with([Order::class, OrderLine::class, OrderAccessToken::class]);

it('keeps sibling-site and other-org order references invisible from the attacker site', function (): void {
    $siblings = DB::table('commerce_orders')->where('site_id', $this->sibling->getKey())->first();
    $other = DB::table('commerce_orders')->where('site_id', $this->otherSite->getKey())->first();
    expect(Order::query()->where('reference', $siblings->reference)->first())->toBeNull()
        ->and(Order::query()->where('reference', $other->reference)->first())->toBeNull()
        ->and(OrderLine::query()->where('order_id', $siblings->id)->count())->toBe(0)
        ->and(OrderLine::query()->where('order_id', $other->id)->count())->toBe(0);
});

it('fails closed with no context and with only an org', function (bool $orgOnly): void {
    app(Context::class)->forget();
    if ($orgOnly) {
        app(Context::class)->setOrg($this->org);
    }
    foreach ([Order::class, OrderLine::class, OrderAccessToken::class] as $model) {
        expect($model::query()->count())->toBe(0);
    }
})->with([false, true]);

it('keeps corrupt parent and child scope stamps hidden even through the documented scope hatch', function (): void {
    DB::table('commerce_order_lines')->where('order_id', $this->order->id)->update(['org_id' => $this->otherOrg->getKey()]);
    DB::table('commerce_order_access_tokens')->where('order_id', $this->order->id)->update(['site_id' => $this->otherSite->getKey()]);
    expect(OrderLine::query()->count())->toBe(0)->and(OrderAccessToken::query()->count())->toBe(0);
    DB::table('commerce_orders')->where('id', $this->order->id)->update(['org_id' => $this->otherOrg->getKey()]);
    expect(Order::query()->count())->toBe(0)
        ->and(Order::withoutScopeBecause('Probe the independent coherence fence.', fn ($query) => $query->count()))->toBe(0);
});

it('refuses every model save and delete including quiet constructed stale or replicated instances', function (string $model): void {
    $row = $model::query()->firstOrFail();
    expect(fn () => $row->save())->toThrow(RuntimeException::class)
        ->and(fn () => $row->saveQuietly())->toThrow(RuntimeException::class)
        ->and(fn () => $row->delete())->toThrow(RuntimeException::class)
        ->and(fn () => $row->deleteQuietly())->toThrow(RuntimeException::class)
        ->and(fn () => $row->replicate()->save())->toThrow(RuntimeException::class)
        ->and(fn () => (new $model)->save())->toThrow(RuntimeException::class)
        ->and($model::query()->count())->toBe(1);
})->with([Order::class, OrderLine::class, OrderAccessToken::class]);

it('refuses bulk saves deletes inserts upserts and arithmetic including inside the scope hatch', function (string $model, bool $hatch): void {
    $probe = function ($query): void {
        expect(fn () => (clone $query)->update(['org_id' => $this->otherOrg->getKey()]))->toThrow(RuntimeException::class)
            ->and(fn () => (clone $query)->delete())->toThrow(RuntimeException::class)
            ->and(fn () => (clone $query)->forceDelete())->toThrow(RuntimeException::class)
            ->and(fn () => (clone $query)->insert([]))->toThrow(RuntimeException::class)
            ->and(fn () => (clone $query)->insertGetId([]))->toThrow(RuntimeException::class)
            ->and(fn () => (clone $query)->insertOrIgnore([]))->toThrow(RuntimeException::class)
            ->and(fn () => (clone $query)->upsert([], ['id']))->toThrow(RuntimeException::class)
            ->and(fn () => (clone $query)->increment('id'))->toThrow(RuntimeException::class)
            ->and(fn () => (clone $query)->decrement('id'))->toThrow(RuntimeException::class);
    };
    if ($hatch) {
        $model::withoutScopeBecause('Probe immutable ORM writes through the hatch.', $probe);
    } else {
        $probe($model::query());
    }
})->with([Order::class, OrderLine::class, OrderAccessToken::class])->with([false, true]);

it('retains its own database currency witness with no catalogue rows or enabled runtime', function (): void {
    expect(DB::table('commerce_sellables')->count())->toBe(0)->and(DB::table('modules')->where('handle', 'kitsune/commerce')->exists())->toBeFalse();
    foreach (['EUR', null] as $currency) {
        expect(fn () => DB::transaction(fn () => DB::table('orgs')->where('id', $this->org->getKey())->update(['currency' => $currency])))
            ->toThrow(QueryException::class);
    }
    expect(DB::table('orgs')->where('id', $this->org->getKey())->value('currency'))->toBe('USD');
    DB::table('commerce_orders')->where('org_id', $this->org->getKey())->delete();
    DB::table('orgs')->where('id', $this->org->getKey())->update(['currency' => 'EUR']);
    expect(DB::table('commerce_order_lines')->where('org_id', $this->org->getKey())->count())->toBe(0)
        ->and(DB::table('commerce_order_access_tokens')->where('org_id', $this->org->getKey())->count())->toBe(0)
        ->and(DB::table('orgs')->where('id', $this->org->getKey())->value('currency'))->toBe('EUR');
});

it('refuses destructive order rollback before touching any retained snapshot without org context', function (): void {
    app(Context::class)->forget();
    $migration = require __DIR__.'/../../packages/commerce/database/migrations/0001_01_01_000205_create_commerce_orders.php';
    expect(fn () => $migration->down())->toThrow(RuntimeException::class)
        ->and(DB::table('commerce_orders')->count())->toBe(3)
        ->and(DB::table('commerce_order_lines')->count())->toBe(3)
        ->and(DB::table('commerce_order_access_tokens')->count())->toBe(1);
});

it('keeps idempotency active-cart and public reference claims site-leading and unique', function (string $column): void {
    $raw = (array) $this->order;
    unset($raw['id']);
    foreach (['reference' => bin2hex(random_bytes(16)), 'request_key' => bin2hex(random_bytes(32)), 'active_cart_key' => bin2hex(random_bytes(32))] as $key => $value) {
        if ($key !== $column) {
            $raw[$key] = $value;
        }
    }
    expect(fn () => DB::transaction(fn () => DB::table('commerce_orders')->insert($raw)))->toThrow(QueryException::class);
    $indexes = DB::connection()->getSchemaBuilder()->getIndexes('commerce_orders');
    $unique = array_values(array_filter($indexes, fn ($index) => $index['unique'] && $index['columns'] === ['site_id', $column]));
    expect($unique)->toHaveCount(1);
})->with(['reference', 'request_key', 'active_cart_key']);
