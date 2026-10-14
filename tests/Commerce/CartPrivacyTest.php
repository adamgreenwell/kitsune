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
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\CommerceServiceProvider;
use Kitsune\Commerce\Models\Cart;
use Kitsune\Commerce\Models\CartLine;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Readers\ReaderErasure;
use Kitsune\Core\Readers\ReaderExport;
use Kitsune\Core\Readers\ReaderModuleDataUnavailable;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;

beforeEach(function (): void {
    config(AppServiceProvider::readerConfig());
    config(['hashing.bcrypt.rounds' => 4]);
    $this->world = ReaderFixture::world();
    $this->org = $this->world['golfdom'];
    $this->reader = ReaderFixture::reader($this->org, 'private-cart@example.test');
    app(Context::class)->setOrg($this->org)->setSite($this->world['sites']['golfdom']);
    Module::create(['handle' => 'kitsune/commerce', 'version' => 'old-installed-version', 'is_enabled' => false, 'installed_at' => now()]);
});

afterEach(function (): void {
    ReaderFixture::forget();
});

function retainedReaderCart(?string $reader): Cart
{
    $cart = Cart::create(['reader_id' => $reader,
        'owner_key' => $reader === null ? CartOwner::anonymousKey(bin2hex(random_bytes(32))) : CartOwner::readerKey($reader),
        'expires_at' => CarbonImmutable::parse('2020-01-01 12:00:00', 'UTC')]);
    CartLine::create(['cart_id' => $cart->getKey(), 'listing_id' => null, 'quantity' => 2]);

    return $cart;
}

it('exports and erases real retained cart rows through disabled stale commerce without booting its runtime', function (): void {
    $reader = (string) $this->reader->getKey();
    $main = retainedReaderCart($reader);
    $guest = retainedReaderCart(null);
    app(Context::class)->setSite($this->world['sites']['golfdom-fr']);
    $sibling = retainedReaderCart($reader);
    app(Context::class)->setOrg($this->world['rival'])->setSite($this->world['sites']['rival']);
    $rival = retainedReaderCart($reader);
    app(Context::class)->setOrg($this->org)->setSite($this->world['sites']['golfdom']);
    EntitlementFixture::plant($this->world['sites']['golfdom'], $reader, 'course.retained');
    $export = app(ReaderExport::class)->for($reader);

    expect($export['account'])->not->toBeNull()
        ->and(array_column($export['module_data']['kitsune/commerce']['carts'], 'site'))->toBe(['golfdom', 'golfdom-fr'])
        ->and(json_encode($export['module_data']))->not->toContain('owner_key', 'reader_id', 'private-cart@example.test', CartOwner::readerKey($reader))
        ->and(app()->getProviders(CommerceServiceProvider::class))->toBe([]);

    expect(app(ReaderErasure::class)->erase($reader))->toBe(['grants' => 1, 'links' => 0, 'account' => true])
        ->and(DB::table('commerce_carts')->orderBy('id')->pluck('id')->all())->toBe([$guest->getKey(), $rival->getKey()])
        ->and(DB::table('commerce_cart_lines')->whereIn('cart_id', [$main->getKey(), $sibling->getKey()])->count())->toBe(0)
        ->and(Reader::query()->count())->toBe(0);
});

it('rolls back grants, cart lines and the host account when a real cart deletion is cancelled', function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('This cancellation trigger is a disposable SQLite failure probe.');
    }

    $reader = (string) $this->reader->getKey();
    retainedReaderCart($reader);
    EntitlementFixture::plant($this->world['sites']['golfdom'], $reader, 'course.retained');
    DB::unprepared('CREATE TRIGGER refuse_cart_erasure BEFORE DELETE ON commerce_carts BEGIN SELECT RAISE(IGNORE); END');

    try {
        expect(fn () => app(ReaderErasure::class)->erase($reader))->toThrow(ReaderModuleDataUnavailable::class)
            ->and(DB::table('commerce_carts')->count())->toBe(1)->and(DB::table('commerce_cart_lines')->count())->toBe(1)
            ->and(DB::table('entitlements')->count())->toBe(1)->and(Reader::query()->count())->toBe(1)
            ->and(DB::table('audit_log')->where('action', 'entitlement.erased')->count())->toBe(0);
    } finally {
        DB::unprepared('DROP TRIGGER refuse_cart_erasure');
    }
});

it('refuses complete reader privacy operations for an oversized retained cart and preserves the account and grants', function (): void {
    $reader = (string) $this->reader->getKey();
    $cart = retainedReaderCart($reader);

    foreach (range(1, 20) as $i) {
        CartLine::create(['cart_id' => $cart->getKey(), 'listing_id' => null, 'quantity' => 1]);
    }

    EntitlementFixture::plant($this->world['sites']['golfdom'], $reader, 'course.retained');

    expect(fn () => app(ReaderExport::class)->for($reader))->toThrow(ReaderModuleDataUnavailable::class)
        ->and(fn () => app(ReaderErasure::class)->erase($reader))->toThrow(ReaderModuleDataUnavailable::class)
        ->and(DB::table('commerce_carts')->count())->toBe(1)->and(DB::table('commerce_cart_lines')->count())->toBe(21)
        ->and(DB::table('entitlements')->count())->toBe(1)->and(Reader::query()->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'entitlement.erased')->count())->toBe(0);
});
