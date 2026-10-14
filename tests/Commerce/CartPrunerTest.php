<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Carts\CartPruner;
use Kitsune\Commerce\Carts\CartPruneUnavailable;
use Kitsune\Commerce\Models\Cart;
use Kitsune\Commerce\Models\CartLine;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Tenancy\Context;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
    $this->org = Org::create(['slug' => 'cart-prune', 'name' => 'Pruning', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'cart-prune-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);
    Module::create(['handle' => 'kitsune/commerce', 'version' => ModuleDiscovery::version('kitsune/commerce'), 'is_enabled' => true, 'installed_at' => now()]);
    $this->pruner = new CartPruner;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    app(Context::class)->forget();
});

function pruneCart(string $expiry = '2026-10-14 12:00:00', ?string $reader = null, int $lines = 1): Cart
{
    $cart = Cart::create(['reader_id' => $reader,
        'owner_key' => $reader === null ? CartOwner::anonymousKey(bin2hex(random_bytes(32))) : CartOwner::readerKey($reader),
        'expires_at' => CarbonImmutable::parse($expiry, 'UTC')]);

    foreach (range(1, $lines) as $i) {
        CartLine::create(['cart_id' => $cart->getKey(), 'listing_id' => null, 'quantity' => $i]);
    }

    return $cart;
}

it('prunes a bounded number of parent carts across orgs at the exact UTC expiry boundary', function (): void {
    config(['app.timezone' => 'Pacific/Honolulu']);
    $first = pruneCart(lines: 2);
    $future = pruneCart('2026-10-14 12:00:01');
    $other = Org::create(['slug' => 'cart-prune-rival', 'name' => 'Rival', 'currency' => 'USD']);
    app(Context::class)->setOrg($other);
    app(Context::class)->setSite(Site::create(['handle' => 'rival', 'slug' => 'cart-prune-rival', 'name' => 'Rival', 'locale' => 'en']));
    $rival = pruneCart('2026-10-14 11:59:59', reader: 'private-prune-reader', lines: 2);
    app(Context::class)->forget();

    expect($this->pruner->prune(1))->toBe(1)
        ->and(DB::table('commerce_carts')->orderBy('id')->pluck('id')->all())->toBe([$future->getKey(), $rival->getKey()])
        ->and(DB::table('commerce_cart_lines')->where('cart_id', $first->getKey())->count())->toBe(0)
        ->and(DB::table('commerce_cart_lines')->where('cart_id', $rival->getKey())->count())->toBe(2)
        ->and($this->pruner->prune(1000))->toBe(1)
        ->and($this->pruner->prune())->toBe(0)
        ->and(DB::table('commerce_carts')->pluck('id')->all())->toBe([$future->getKey()]);
});

it('rechecks expiry after selecting candidates so a refreshed cart survives', function (): void {
    $cart = pruneCart();
    $refreshed = false;
    DB::listen(function (QueryExecuted $query) use ($cart, &$refreshed): void {
        if (! $refreshed && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'commerce_carts')
            && str_contains($query->sql, 'expires_at') && str_contains($query->sql, 'limit')) {
            $refreshed = true;
            DB::table('commerce_carts')->where('id', $cart->getKey())->update(['expires_at' => '2026-11-13 12:00:00', 'version' => 1]);
        }
    });

    expect($this->pruner->prune())->toBe(0)->and($refreshed)->toBeTrue()
        ->and(DB::table('commerce_carts')->where('id', $cart->getKey())->value('version'))->toBe(1)
        ->and(DB::table('commerce_cart_lines')->count())->toBe(1);
});

it('takes SQLite write reservation before transactional custody reads', function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite write-reservation ordering is engine-specific.');
    }

    pruneCart();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    expect($this->pruner->prune())->toBe(1);
    $reservation = array_find_key($queries, static fn (string $sql): bool => str_starts_with($sql, 'update "orgs"'));
    $orgRead = array_find_key($queries, static fn (string $sql): bool => str_starts_with($sql, 'select * from "orgs"'));

    expect($reservation)->toBeInt()->and($orgRead)->toBeInt()->and($reservation)->toBeLessThan($orgRead);
});

it('refuses corrupt parent or child custody without deleting that cart or exposing its reader', function (string $fault): void {
    $cart = pruneCart(reader: 'private-prune-reader');
    $rival = Org::create(['slug' => 'cart-prune-rival', 'name' => 'Rival', 'currency' => 'USD']);
    $sibling = Site::create(['handle' => 'sibling', 'slug' => 'cart-prune-sibling', 'name' => 'Sibling', 'locale' => 'en']);
    match ($fault) {
        'parent hash' => DB::table('commerce_carts')->where('id', $cart->getKey())->update(['owner_key' => str_repeat('b', 64)]),
        'parent site' => DB::table('commerce_carts')->where('id', $cart->getKey())->update(['org_id' => $rival->getKey()]),
        'child org' => DB::table('commerce_cart_lines')->where('cart_id', $cart->getKey())->update(['org_id' => $rival->getKey()]),
        'child site' => DB::table('commerce_cart_lines')->where('cart_id', $cart->getKey())->update(['site_id' => $sibling->getKey()]),
        'child quantity' => DB::table('commerce_cart_lines')->where('cart_id', $cart->getKey())->update(['quantity' => 100]),
    };

    try {
        $this->pruner->prune();
        $this->fail('Corrupt cart custody was removed.');
    } catch (CartPruneUnavailable $failure) {
        expect($failure->getPrevious())->toBeNull()->and($failure->getMessage())->not->toContain('private-prune-reader', str_repeat('b', 64));
    }

    expect(DB::table('commerce_carts')->count())->toBe(1)->and(DB::table('commerce_cart_lines')->count())->toBe(1);
})->with(['parent hash', 'parent site', 'child org', 'child site', 'child quantity']);

it('rolls back child removals when a parent deletion is cancelled', function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('This cancellation trigger is a disposable SQLite failure probe.');
    }

    pruneCart(lines: 2);
    DB::unprepared('CREATE TRIGGER refuse_prune BEFORE DELETE ON commerce_carts BEGIN SELECT RAISE(IGNORE); END');

    try {
        expect(fn () => $this->pruner->prune())->toThrow(CartPruneUnavailable::class)
            ->and(DB::table('commerce_carts')->count())->toBe(1)->and(DB::table('commerce_cart_lines')->count())->toBe(2);
    } finally {
        DB::unprepared('DROP TRIGGER refuse_prune');
    }
});

it('refuses an oversized corrupt cart without deleting any of its retained lines', function (): void {
    pruneCart(lines: 21);

    expect(fn () => $this->pruner->prune())->toThrow(CartPruneUnavailable::class)
        ->and(DB::table('commerce_carts')->count())->toBe(1)->and(DB::table('commerce_cart_lines')->count())->toBe(21);
});

it('keeps retained carts when disabled or a runtime receipt needs upgrade', function (string $fault): void {
    pruneCart();
    DB::table('modules')->where('handle', 'kitsune/commerce')->update($fault === 'disabled' ? ['is_enabled' => false] : ['version' => 'old-version']);

    expect(fn () => $this->pruner->prune())->toThrow(CartPruneUnavailable::class)
        ->and(DB::table('commerce_carts')->count())->toBe(1)->and(DB::table('commerce_cart_lines')->count())->toBe(1);
})->with(['disabled', 'stale']);

it('registers its command through enabled module boot and reports parent counts', function (): void {
    pruneCart(lines: 2);
    ModuleKernel::boot(app());

    expect(Artisan::call('kitsune:cart-prune', ['--limit' => '1']))->toBe(0)
        ->and(Artisan::output())->toContain('Removed 1 expired cart.')
        ->and(DB::table('commerce_carts')->count())->toBe(0)->and(DB::table('commerce_cart_lines')->count())->toBe(0);
});

it('refuses malformed CLI limits before any cart removal', function (string $limit): void {
    pruneCart();
    ModuleKernel::boot(app());

    expect(Artisan::call('kitsune:cart-prune', ['--limit' => $limit]))->toBe(1)
        ->and(Artisan::output())->toContain('integer from 1 through 1000')
        ->and(DB::table('commerce_carts')->count())->toBe(1)->and(DB::table('commerce_cart_lines')->count())->toBe(1);
})->with(['0', '1001', '-1', '01', '+1', '1.0', '1e2', ' 1', '100000000000000000000', '']);

it('maps database cleanup failure to a safe SQLSTATE diagnostic', function (): void {
    pruneCart();
    ModuleKernel::boot(app());
    DB::connection()->beforeExecuting(static function (string $sql): void {
        if (str_starts_with($sql, 'select') && str_contains($sql, 'commerce_carts')) {
            throw new QueryException('probe', 'select private reader contents', ['private-prune-reader'], new PDOException('private-prune-reader', 23000));
        }
    });

    expect(Artisan::call('kitsune:cart-prune'))->toBe(1)
        ->and(Artisan::output())->toContain('SQLSTATE')
        ->and(Artisan::output())->not->toContain('private-prune-reader', 'select private reader contents');
});
