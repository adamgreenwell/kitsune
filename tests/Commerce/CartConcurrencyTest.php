<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Kitsune\Commerce\Blueprints\StorefrontBlueprint;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Carts\CartRefused;
use Kitsune\Commerce\Carts\CartSessions;
use Kitsune\Commerce\Carts\CartWriter;
use Kitsune\Commerce\Catalogue\ProductWriter;
use Kitsune\Commerce\Models\Cart;
use Kitsune\Commerce\Models\CartLine;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Symfony\Component\Process\Process;

/** Copy disposable native applications without following vendor links or sharing bootstrap caches. */
function cartRaceCopyApplication(string $source, string $target): void
{
    File::ensureDirectoryExists($target);

    foreach (new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS) as $item) {
        if ($item->getFilename() === 'vendor' || $item->isLink()) {
            continue;
        }

        $destination = $target.'/'.$item->getFilename();

        if ($item->isDir()) {
            if (str_ends_with($destination, '/bootstrap/cache')) {
                File::ensureDirectoryExists($destination);
            } else {
                cartRaceCopyApplication($item->getPathname(), $destination);
            }
        } else {
            File::copy($item->getPathname(), $destination);
        }
    }
}

/** @param list<Process> $processes */
function cartRaceBarrier(string $path, array $processes): array
{
    $deadline = microtime(true) + 15;

    while (! is_file($path)) {
        foreach ($processes as $process) {
            if (! $process->isRunning() && ! is_file($path)) {
                throw new RuntimeException('Cart race child exited before '.basename($path).': '.$process->getOutput().$process->getErrorOutput());
            }
        }

        if (microtime(true) > $deadline) {
            throw new RuntimeException('Cart race barrier timed out: '.basename($path));
        }

        usleep(5000);
    }

    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

/** @return array{directory:string,child:Closure(string,string):Process,cleanup:Closure():void} */
function cartRaceApplications(string $scenario): array
{
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir().'/kitsune-cart-race-'.bin2hex(random_bytes(8));
    $processes = [];
    File::ensureDirectoryExists($directory);
    touch($directory.'/race.sqlite');

    foreach (['controller', 'A', 'B', 'read'] as $role) {
        cartRaceCopyApplication($root.'/vendor/orchestra/testbench-core/laravel', $directory.'/app-'.$role);
    }

    return [
        'directory' => $directory,
        'child' => static function (string $mode, string $role) use ($root, $directory, $scenario, &$processes): Process {
            $process = new Process([PHP_BINARY, $root.'/tests/Commerce/Fixtures/CartRaceProbe.php', $root, $directory, $mode, $role, $scenario], $root);
            $process->setTimeout(25);
            $processes[] = $process;

            return $process;
        },
        'cleanup' => static function () use ($directory, &$processes): void {
            if (is_dir($directory)) {
                touch($directory.'/release-A');
            }

            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0.2);
                }
            }

            File::deleteDirectory($directory);
        },
    ];
}

it('serialises actual simultaneous SQLite cart mutations without lost selections', function (string $scenario): void {
    $probe = cartRaceApplications($scenario);
    $directory = $probe['directory'];
    $child = $probe['child'];

    try {
        $child('init', 'controller')->mustRun();
        $initialized = cartRaceBarrier($directory.'/initialized.json', []);
        expect($initialized['level'])->toBe(0)->and($initialized['pdo_in_transaction'])->toBeFalse();
        $first = $child('worker', 'A');
        $first->start();
        $paused = cartRaceBarrier($directory.'/A-paused.json', [$first]);
        expect($paused['level'])->toBeGreaterThanOrEqual(1)->and($paused['pdo_in_transaction'])->toBeTrue();
        $second = $child('worker', 'B');
        $second->start();
        $attempt = cartRaceBarrier($directory.'/B-attempted.json', [$first, $second]);
        expect($attempt['level'])->toBe(1);

        // A holds the actual SQLite write reservation after writing a line. B has entered its conflicting write;
        // it must still be waiting before A is released, rather than reading and replacing A's old snapshot.
        usleep(100000);
        expect($second->isRunning())->toBeTrue()->and(is_file($directory.'/B-result.json'))->toBeFalse();
        touch($directory.'/release-A');
        $first->wait();
        $second->wait();
        expect($first->isSuccessful())->toBeTrue($first->getOutput().$first->getErrorOutput())
            ->and($second->isSuccessful())->toBeTrue($second->getOutput().$second->getErrorOutput());
        $a = cartRaceBarrier($directory.'/A-result.json', []);
        $b = cartRaceBarrier($directory.'/B-result.json', []);
        expect($a['success'])->toBeTrue()->and($b['success'])->toBe($scenario !== 'stale-update');

        if ($scenario === 'stale-update') {
            expect($b['reason'])->toBe('stale');
        }

        $child('read', 'read')->mustRun();
        $stored = cartRaceBarrier($directory.'/readback.json', []);
        expect($stored['cart_count'])->toBe(1)->and($stored['version'])->toBe(2)
            ->and($stored['quantities'])->toBe(match ($scenario) {
                'distinct-create' => [2, 3], 'duplicate-create' => [5], 'stale-update' => [2],
            });

        foreach ([$a, $b, $stored] as $state) {
            expect($state['level'])->toBe(0)->and($state['pdo_in_transaction'])->toBeFalse();
        }
    } finally {
        $probe['cleanup']();
    }
})->with(['distinct-create', 'duplicate-create', 'stale-update'])->skip(
    fn (): bool => env('DB_CONNECTION', 'testing') !== 'testing',
    'This disposable process probe measures SQLite locks; it does not qualify server-engine lock waits.',
);

it('retains the guest handle across actual outer rollback and consumes it once on retry', function (): void {
    $probe = cartRaceApplications('adopt-rollback');

    try {
        $probe['child']('init', 'controller')->mustRun();
        $probe['child']('worker', 'A')->mustRun();
        $state = cartRaceBarrier($probe['directory'].'/adoption.json', []);
        expect($state['outer_level'])->toBe(1)->and($state['guest_during_outer'])->toBeTrue()
            ->and($state['pending_during_outer'])->toBeTrue()->and($state['source_after_rollback'])->toBe(1)
            ->and($state['reader_after_rollback'])->toBe(0)->and($state['guest_after_rollback'])->toBeTrue()
            ->and($state['pending_after_rollback'])->toBeTrue()->and($state['retry_blocked'])->toBeFalse()
            ->and($state['source_after_retry'])->toBe(0)->and($state['reader_after_retry'])->toBe(1)
            ->and($state['guest_after_retry'])->toBeFalse()->and($state['pending_after_retry'])->toBeFalse()
            ->and($state['quantities'])->toBe([2])->and($state['version'])->toBe(1)
            ->and($state['level'])->toBe(0)->and($state['pdo_in_transaction'])->toBeFalse();
    } finally {
        $probe['cleanup']();
    }
})->skip(
    fn (): bool => env('DB_CONNECTION', 'testing') !== 'testing',
    'The isolated native application starts at actual SQLite level zero.',
);

/** Restore original model listeners even when the assertion throws. */
function cartWithObserver(string $class, string $event, Closure $listener, Closure $work): void
{
    $name = 'eloquent.'.$event.': '.$class;
    $events = $class::getEventDispatcher();
    $original = $events->getRawListeners()[$name] ?? [];
    $class::{$event}($listener);

    try {
        $work();
    } finally {
        $events->forget($name);
        foreach ($original as $registered) {
            $events->listen($name, $registered);
        }
    }
}

describe('atomic cart refusal', function (): void {
    beforeEach(function (): void {
        Fx::boot();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('r', 32))]);
        Fx::declareReaders();
        $this->org = Org::create(['slug' => 'cart-atomic', 'name' => 'Atomic carts', 'currency' => 'USD']);
        app(Context::class)->setOrg($this->org);
        $this->site = Site::create(['handle' => 'main', 'slug' => 'cart-atomic-main', 'name' => 'Main', 'locale' => 'en']);
        app(Context::class)->setSite($this->site);
        Module::create(['handle' => 'kitsune/commerce', 'version' => 'dev-main', 'is_enabled' => true, 'installed_at' => now()]);
        BlueprintApplier::apply(new StorefrontBlueprint);
        CartSessions::listen(app(Dispatcher::class));
        $product = app(ProductWriter::class)->createProduct(['sku' => 'ATOMIC', 'price_minor' => '2000', 'stock' => null], 'USD', [
            'title' => 'Atomic course', 'slug' => 'atomic', 'status' => 'published', 'values' => [],
        ], [], true);
        $this->listing = (int) $product->listings()->firstOrFail()->getKey();
        $this->request = Request::create('/cart');
        $this->request->setLaravelSession(app('session.store'));
        $this->request->session()->start();
        $this->writer = app(CartWriter::class);
    });

    afterEach(function (): void {
        Fx::tearDown();
    });

    it('rolls back a creation when a cart or line observer vetoes it', function (string $class): void {
        cartWithObserver($class, 'creating', static fn () => false, function (): void {
            expect(fn () => $this->writer->add($this->request, $this->listing, 2))->toThrow(CartRefused::class);
        });
        expect(Cart::query()->count())->toBe(0)->and(CartLine::query()->count())->toBe(0);
    })->with([Cart::class, CartLine::class]);

    it('rolls back both the quantity and revision when an update observer vetoes either row', function (string $class): void {
        $this->writer->add($this->request, $this->listing, 2);
        $before = $this->writer->snapshot($this->request);
        cartWithObserver($class, 'updating', static fn () => false, function () use ($before): void {
            expect(fn () => $this->writer->update($this->request, $before['items'][0]['lineId'], 3, $before['version']))->toThrow(CartRefused::class);
        });
        expect($this->writer->snapshot($this->request))->toBe($before);
    })->with([Cart::class, CartLine::class]);

    it('rolls back a line removal veto without refreshing expiry or version', function (): void {
        $this->writer->add($this->request, $this->listing, 2);
        $before = $this->writer->snapshot($this->request);
        $expires = Cart::query()->firstOrFail()->getRawOriginal('expires_at');
        cartWithObserver(CartLine::class, 'deleting', static fn () => false, function () use ($before): void {
            expect(fn () => $this->writer->remove($this->request, $before['items'][0]['lineId'], $before['version']))->toThrow(CartRefused::class);
        });
        expect($this->writer->snapshot($this->request))->toBe($before)
            ->and(Cart::query()->firstOrFail()->getRawOriginal('expires_at'))->toBe($expires);
    });

    it('keeps the guest handle and both selections when source deletion is vetoed during adoption', function (): void {
        $this->writer->add($this->request, $this->listing, 2);
        $reader = Fx::reader();
        $slot = CartOwner::sessionKey($this->org->getKey(), $this->site->getKey());
        $secret = $this->request->session()->get($slot);
        $pending = CartSessions::pendingKey($this->org->getKey(), $this->site->getKey());
        $this->request->session()->put($pending, CartOwner::readerKey((string) $reader->getKey()));
        cartWithObserver(Cart::class, 'deleting', static fn () => false, function () use ($reader): void {
            expect(fn () => $this->writer->adopt($this->request->session(), (string) $reader->getKey()))->toThrow(CartRefused::class);
        });
        expect(Cart::query()->count())->toBe(1)->and(CartLine::query()->count())->toBe(1)
            ->and(Cart::query()->firstOrFail()->reader_id)->toBeNull()->and($this->request->session()->get($slot))->toBe($secret)
            ->and($this->request->session()->has($pending))->toBeTrue();
        $this->writer->adopt($this->request->session(), (string) $reader->getKey());
        expect(Cart::query()->count())->toBe(1)->and(CartLine::query()->count())->toBe(1)
            ->and(Cart::query()->firstOrFail()->reader_id)->toBe((string) $reader->getKey())
            ->and($this->request->session()->has($slot))->toBeFalse()->and($this->request->session()->has($pending))->toBeFalse();
    });

    it('refuses an incomplete or malformed retained cart instead of returning or merging a partial selection', function (string $fault): void {
        $this->writer->add($this->request, $this->listing, 2);
        $line = CartLine::query()->firstOrFail();
        $other = Site::create(['handle' => 'sibling', 'slug' => 'cart-atomic-sibling', 'name' => 'Sibling', 'locale' => 'en']);
        if ($fault === 'cart org') {
            $otherOrg = Org::create(['slug' => 'cart-atomic-other', 'name' => 'Other', 'currency' => 'USD']);
            DB::table('commerce_carts')->where('id', $line->cart_id)->update(['org_id' => $otherOrg->getKey()]);
        } else {
            $value = $fault === 'site' ? $other->getKey() : 0;
            DB::table('commerce_cart_lines')->where('id', $line->getKey())->update([$fault === 'site' ? 'site_id' : 'quantity' => $value]);
        }
        $reader = Fx::reader();
        $slot = CartOwner::sessionKey($this->org->getKey(), $this->site->getKey());
        $secret = $this->request->session()->get($slot);
        $before = DB::table('commerce_cart_lines')->get()->all();
        expect(fn () => $this->writer->snapshot($this->request))->toThrow(CartRefused::class)
            ->and(fn () => $this->writer->adopt($this->request->session(), (string) $reader->getKey()))->toThrow(CartRefused::class)
            ->and(DB::table('commerce_cart_lines')->get()->all())->toEqual($before)
            ->and(DB::table('commerce_carts')->count())->toBe(1)->and($this->request->session()->get($slot))->toBe($secret);
    })->with(['site', 'quantity', 'cart org']);

    it('refuses malformed SQLite expiry before the instant cast normalizes or truncates it', function (string $expiry): void {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite stores arbitrary datetime text; server engines constrain this raw shape themselves.');
        }

        $this->writer->add($this->request, $this->listing, 2);
        DB::table('commerce_carts')->update(['expires_at' => $expiry]);
        $reader = Fx::reader();
        $slot = CartOwner::sessionKey($this->org->getKey(), $this->site->getKey());
        $secret = $this->request->session()->get($slot);

        expect(fn () => $this->writer->snapshot($this->request))->toThrow(CartRefused::class)
            ->and(fn () => $this->writer->adopt($this->request->session(), (string) $reader->getKey()))->toThrow(CartRefused::class)
            ->and(DB::table('commerce_carts')->count())->toBe(1)->and(DB::table('commerce_cart_lines')->count())->toBe(1)
            ->and($this->request->session()->get($slot))->toBe($secret);
    })->with(['normalized calendar' => '2099-02-30 12:00:00', 'truncated suffix' => '2099-01-01 12:00:00 trailing']);
});
