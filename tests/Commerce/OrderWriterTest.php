<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Kitsune\Commerce\Blueprints\StorefrontBlueprint;
use Kitsune\Commerce\Carts\CartWriter;
use Kitsune\Commerce\Catalogue\ProductWriter;
use Kitsune\Commerce\Checkout\CommerceSetupWriter;
use Kitsune\Commerce\Models\Cart;
use Kitsune\Commerce\Models\OrderLine;
use Kitsune\Commerce\Offers\OfferTerms;
use Kitsune\Commerce\Orders\OrderAccess;
use Kitsune\Commerce\Orders\OrderJson;
use Kitsune\Commerce\Orders\OrderQuotes;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Orders\OrderRefused;
use Kitsune\Commerce\Orders\OrderWriter;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\OrderFixture;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    Fx::boot();
    config(['app.key' => 'base64:'.base64_encode(str_repeat('o', 32))]);
    Fx::declareReaders();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
    $this->org = Org::create(['slug' => 'order-writer', 'name' => 'Orders', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'order-writer-main', 'name' => 'Order site', 'locale' => 'en', 'base_url' => 'https://orders.example.test']);
    app(Context::class)->setSite($this->site);
    Module::create(['handle' => 'kitsune/commerce', 'version' => 'dev-main', 'is_enabled' => true, 'installed_at' => now()]);
    BlueprintApplier::apply(new StorefrontBlueprint);
    $this->product = app(ProductWriter::class)->createProduct(['sku' => 'ORDER-COURSE', 'price_minor' => '2000', 'stock' => '10', 'entitlement_name' => 'course.order',
        'offer_terms' => ['version' => 1, 'kind' => 'permanent']], 'USD', [
            'title' => 'Original course title', 'slug' => 'order-course', 'status' => 'published', 'values' => [],
        ], [], true);
    $this->listing = $this->product->listings()->firstOrFail();
    $this->setup = app(CommerceSetupWriter::class);
    $this->settings = $this->setup->setSettings(['tax_mode' => 'exclusive', 'shipping_tax_mode' => 'exclusive', 'shipping_tax_class' => 'shipping']);
    $this->rate = $this->setup->saveTaxRate(null, ['country' => 'US', 'region' => '*', 'tax_class' => 'standard', 'rate_parts' => 200000]);
    $this->policy = $this->setup->setSalesPolicy($this->product->getKey(), ['tax_class' => 'standard', 'delivery_kind' => 'digital']);
    $this->request = Request::create('https://orders.example.test/cart');
    $this->request->setLaravelSession(app('session.store'));
    $this->request->session()->start();
    $this->carts = app(CartWriter::class);
    $this->quotes = app(OrderQuotes::class);
    $this->writer = app(OrderWriter::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Fx::tearDown();
});

/** @return array<string, mixed> */
function orderWriterDetails(): array
{
    return ['email' => 'private-order-buyer@example.test', 'billing' => ['name' => 'Private Order Buyer',
        'address' => ['line1' => '42 Private Invoice Lane', 'city' => 'Honolulu', 'region' => 'HI', 'postal_code' => '96801', 'country' => 'US']]];
}

it('prepares immutable exact digital invoice lines without reserving stock granting access or claiming payment', function (): void {
    $this->carts->add($this->request, $this->listing->getKey(), 2);
    $quote = $this->quotes->quote($this->request, orderWriterDetails());
    $order = $this->writer->prepare($this->request, $quote['token'], 'none');
    $raw = $order->getAttributes();
    $line = OrderLine::query()->sole()->getAttributes();
    expect($raw['state'])->toBe('prepared')->and($raw['confirmed_total_minor'])->toBeNull()
        ->and((string) $raw['quoted_item_net_minor'])->toBe('4000')->and((string) $raw['quoted_shipping_net_minor'])->toBe('0')
        ->and((string) $raw['quoted_tax_minor'])->toBe('800')->and((string) $raw['quoted_total_minor'])->toBe('4800')
        ->and($raw['delivery'])->toBeNull()->and($raw['shipping'])->toBeNull()
        ->and($line['title'])->toBe('Original course title')->and($line['sku'])->toBe('ORDER-COURSE')
        ->and((int) $line['quantity'])->toBe(2)->and((string) $line['unit_price_minor'])->toBe('2000')
        ->and((string) $line['tax_minor'])->toBe('800')->and($line['entitlement_name'])->toBe('course.order')
        ->and(OfferTerms::stored($line['offer_terms']))->toBe(['version' => 1, 'kind' => 'permanent'])
        ->and($this->product->fresh()->stock)->toBe('10')
        ->and(DB::table('entitlements')->count())->toBe(0)->and(Cart::query()->count())->toBe(1)
        ->and(app(OrderAccess::class)->owned($this->request, $raw['reference'])->getKey())->toBe($order->getKey());
});

it('refuses a new quote for mapped products whose retained access duration remains unknown', function (): void {
    $this->carts->add($this->request, $this->listing->getKey(), 1);
    $this->product->update(['offer_terms' => null]);
    expect(fn () => $this->quotes->quote($this->request, orderWriterDetails()))->toThrow(OrderRefused::class);
    expect(DB::table('commerce_orders')->count())->toBe(0)->and(DB::table('entitlements')->count())->toBe(0);
});

it('refuses preparing a prior quote when shared access terms changed before submission', function (): void {
    $this->carts->add($this->request, $this->listing->getKey(), 1);
    $quote = $this->quotes->quote($this->request, orderWriterDetails());
    $this->product->update(['offer_terms' => ['version' => 1, 'kind' => 'fixed', 'days' => 30]]);
    expect(fn () => $this->writer->prepare($this->request, $quote['token'], 'none'))->toThrow(OrderRefused::class)
        ->and(DB::table('commerce_orders')->count())->toBe(0)->and(DB::table('entitlements')->count())->toBe(0);
});

it('retains fixed access terms as immutable JSON and accepts database key reordering', function (): void {
    $terms = ['version' => 1, 'kind' => 'fixed', 'days' => 30];
    $this->product->update(['offer_terms' => $terms]);
    $this->carts->add($this->request, $this->listing->getKey(), 1);
    $quote = $this->quotes->quote($this->request, orderWriterDetails());
    AuditLog::creating(static function (AuditLog $audit): void {
        if ($audit->action === 'commerce.order_prepared') {
            DB::table('commerce_order_lines')->update(['offer_terms' => '{"days":30,"kind":"fixed","version":1}']);
        }
    });
    try {
        $order = $this->writer->prepare($this->request, $quote['token'], 'none');
    } finally {
        AuditLog::flushEventListeners();
        AuditLog::clearBootedModels();
    }
    $record = app(OrderReaderData::class)->record(DB::connection(), (object) $order->getAttributes());
    $this->product->update(['offer_terms' => ['version' => 1, 'kind' => 'permanent']]);
    expect($record['lines'][0]['offer_terms'])->toBe($terms)
        ->and(app(OrderReaderData::class)->record(DB::connection(), (object) $order->getAttributes())['lines'][0]['offer_terms'])->toBe($terms)
        ->and(DB::table('entitlements')->count())->toBe(0);
});

it('rolls back an invoice when audit code substitutes purchased access terms', function (string $replacement): void {
    $this->carts->add($this->request, $this->listing->getKey(), 1);
    $quote = $this->quotes->quote($this->request, orderWriterDetails());
    AuditLog::creating(static function (AuditLog $audit) use ($replacement): void {
        if ($audit->action === 'commerce.order_prepared') {
            DB::table('commerce_order_lines')->update(['offer_terms' => $replacement]);
        }
    });
    try {
        expect(fn () => $this->writer->prepare($this->request, $quote['token'], 'none'))->toThrow(OrderRefused::class)
            ->and(DB::table('commerce_orders')->count())->toBe(0)->and(DB::table('commerce_order_lines')->count())->toBe(0);
    } finally {
        AuditLog::flushEventListeners();
        AuditLog::clearBootedModels();
    }
    // MySQL leaves an existing JSON integer unchanged when assigned an equal integral float. A string is a real
    // persisted type change on every supported engine; PHP-boundary float refusal has its own pre-encoding tests.
})->with(['coherent replacement' => ['{"version":1,"kind":"fixed","days":36500}'], 'coerced scalar type' => ['{"version":"1","kind":"permanent"}']]);

/** Restore the original listeners, including all model guards, after a hostile host callback. */
function orderWriterAudit(Closure $listener, Closure $work): void
{
    $name = 'eloquent.creating: '.AuditLog::class;
    $events = AuditLog::getEventDispatcher();
    $original = $events->getRawListeners()[$name] ?? [];
    AuditLog::creating($listener);
    try {
        $work();
    } finally {
        $events->forget($name);
        foreach ($original as $registered) {
            $events->listen($name, $registered);
        }
    }
}

it('replays the exact prepared request after its quote and cart have expired without another audit or order', function (): void {
    $this->carts->add($this->request, $this->listing->getKey(), 2);
    $quote = $this->quotes->quote($this->request, orderWriterDetails());
    $order = $this->writer->prepare($this->request, $quote['token'], 'none');
    DB::table('commerce_carts')->delete();
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDays(31));
    expect($this->writer->prepare($this->request, $quote['token'], 'none')->getKey())->toBe($order->getKey())
        ->and(DB::table('commerce_orders')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'commerce.order_prepared')->count())->toBe(1);
    expect(fn () => $this->writer->prepare($this->request, $quote['token'], 'different-rate'))
        ->toThrow(OrderRefused::class)
        ->and(DB::table('commerce_orders')->count())->toBe(1);
});

it('permits only one active invoice for a cart revision and frees it by an idempotent cancellation', function (): void {
    $this->carts->add($this->request, $this->listing->getKey(), 1);
    $first = $this->quotes->quote($this->request, orderWriterDetails());
    $second = $this->quotes->quote($this->request, orderWriterDetails());
    $order = $this->writer->prepare($this->request, $first['token'], 'none');
    expect(fn () => $this->writer->prepare($this->request, $second['token'], 'none'))->toThrow(OrderRefused::class);
    $this->writer->cancel($this->request, $order->reference);
    $this->writer->cancel($this->request, $order->reference);
    expect($order->fresh()->state)->toBe('cancelled')->and($order->fresh()->getRawOriginal('active_cart_key'))->toBeNull()
        ->and(DB::table('audit_log')->where('action', 'commerce.order_cancelled')->count())->toBe(1);
    $replacement = $this->writer->prepare($this->request, $second['token'], 'none');
    expect($replacement->getKey())->not->toBe($order->getKey())->and(DB::table('commerce_orders')->count())->toBe(2)
        ->and($this->product->fresh()->stock)->toBe('10');
});

it('does not cancel or replay an order from a fresh guest session', function (): void {
    $this->carts->add($this->request, $this->listing->getKey(), 1);
    $quote = $this->quotes->quote($this->request, orderWriterDetails());
    $order = $this->writer->prepare($this->request, $quote['token'], 'none');
    $attacker = Request::create('https://orders.example.test/cart');
    $attacker->setLaravelSession(new Store('order-attacker', new ArraySessionHandler(120)));
    $attacker->session()->start();
    expect(app(OrderAccess::class)->owned($attacker, $order->reference))->toBeNull()
        ->and(fn () => $this->writer->cancel($attacker, $order->reference))->toThrow(OrderRefused::class)
        ->and(fn () => $this->writer->prepare($attacker, $quote['token'], 'none'))->toThrow(OrderRefused::class)
        ->and($order->fresh()->state)->toBe('prepared');
});

it('requires a fresh quote after any priced selection or calculator configuration changes', function (string $change): void {
    $this->carts->add($this->request, $this->listing->getKey(), 2);
    $quote = $this->quotes->quote($this->request, orderWriterDetails());
    match ($change) {
        'price' => DB::table('commerce_sellables')->where('id', $this->product->getKey())->update(['price_minor' => '2100']),
        'stock' => DB::table('commerce_sellables')->where('id', $this->product->getKey())->update(['stock' => '1']),
        'listing' => DB::table('commerce_listings')->where('id', $this->listing->getKey())->update(['is_available' => false]),
        'tax rate' => $this->setup->saveTaxRate($this->rate->getKey(), ['country' => 'US', 'region' => '*', 'tax_class' => 'standard', 'rate_parts' => 100000], 1),
        'tax mode' => $this->setup->setSettings(['tax_mode' => 'inclusive', 'shipping_tax_mode' => 'exclusive', 'shipping_tax_class' => 'shipping'], 1),
        'policy' => $this->setup->setSalesPolicy($this->product->getKey(), ['tax_class' => 'other', 'delivery_kind' => 'digital'], 1),
        'quantity' => $this->carts->add($this->request, $this->listing->getKey(), 3),
        'site title' => DB::table('sites')->where('id', $this->site->getKey())->update(['name' => 'Changed brand']),
    };
    expect(fn () => $this->writer->prepare($this->request, $quote['token'], 'none'))
        ->toThrow(OrderRefused::class)
        ->and(DB::table('commerce_orders')->count())->toBe(0)->and(DB::table('commerce_order_lines')->count())->toBe(0);
})->with(['price', 'stock', 'listing', 'tax rate', 'tax mode', 'policy', 'quantity', 'site title']);

it('stores complete-line half-up tax under either explicitly chosen tax mode', function (string $mode, int $rate, string $unit, int $quantity, string $net, string $tax, string $gross): void {
    $this->setup->setSettings(['tax_mode' => $mode, 'shipping_tax_mode' => 'exclusive', 'shipping_tax_class' => 'shipping'], 1);
    $this->setup->saveTaxRate($this->rate->getKey(), ['country' => 'US', 'region' => '*', 'tax_class' => 'standard', 'rate_parts' => $rate], 1);
    DB::table('commerce_sellables')->where('id', $this->product->getKey())->update(['price_minor' => $unit]);
    $this->carts->add($this->request, $this->listing->getKey(), $quantity);
    $quote = $this->quotes->quote($this->request, orderWriterDetails());
    $order = $this->writer->prepare($this->request, $quote['token'], 'none');
    $line = OrderLine::query()->sole();
    expect((string) $order->quoted_item_net_minor)->toBe($net)->and((string) $order->quoted_tax_minor)->toBe($tax)
        ->and((string) $order->quoted_total_minor)->toBe($gross)->and((string) $line->net_minor)->toBe($net)
        ->and((string) $line->tax_minor)->toBe($tax)->and((string) $line->gross_minor)->toBe($gross)
        ->and($order->confirmed_total_minor)->toBeNull();
})->with([
    'exclusive complete-line tie' => ['exclusive', 500000, '1', 3, '3', '2', '5'],
    'inclusive complete-line tie' => ['inclusive', 1000000, '1', 3, '1', '2', '3'],
    'inclusive fractional rate' => ['inclusive', 200000, '1200', 2, '2000', '400', '2400'],
    'explicit zero rate' => ['exclusive', 0, '1999', 2, '3998', '0', '3998'],
]);

it('uses an exact regional rate ahead of an explicit wildcard and never silently substitutes zero', function (): void {
    $this->setup->saveTaxRate(null, ['country' => 'US', 'region' => 'HI', 'tax_class' => 'standard', 'rate_parts' => 50000]);
    $this->carts->add($this->request, $this->listing->getKey(), 1);
    $quote = $this->quotes->quote($this->request, orderWriterDetails());
    $order = $this->writer->prepare($this->request, $quote['token'], 'none');
    expect((string) $order->quoted_tax_minor)->toBe('100')->and((int) OrderLine::query()->sole()->tax_rate_parts)->toBe(50000);
    $details = orderWriterDetails();
    $details['billing']['address']['country'] = 'CA';
    expect(fn () => $this->quotes->quote($this->request, $details))->toThrow(OrderRefused::class);
});

it('refuses tax overflow before persisting any invoice and preserves the cart', function (): void {
    DB::table('commerce_sellables')->where('id', $this->product->getKey())->update(['price_minor' => '9223372036854775807']);
    $this->carts->add($this->request, $this->listing->getKey(), 1);
    expect(fn () => $this->quotes->quote($this->request, orderWriterDetails()))->toThrow(OrderRefused::class)
        ->and(DB::table('commerce_orders')->count())->toBe(0)->and(Cart::query()->count())->toBe(1);
});

it('expires a quote at the exact fifteen-minute boundary', function (): void {
    $this->carts->add($this->request, $this->listing->getKey(), 1);
    $quote = $this->quotes->quote($this->request, orderWriterDetails());
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(15));
    expect(fn () => $this->writer->prepare($this->request, $quote['token'], 'none'))->toThrow(OrderRefused::class)
        ->and(DB::table('commerce_orders')->count())->toBe(0);
});

it('rolls back preparation and cancellation if the mandatory audit is cancelled or throws', function (bool $cancel): void {
    $this->carts->add($this->request, $this->listing->getKey(), 1);
    $quote = $this->quotes->quote($this->request, orderWriterDetails());
    orderWriterAudit(static function ($audit) use ($cancel): ?bool {
        if ($audit->action === 'commerce.order_prepared') {
            if ($cancel) {
                return false;
            }
            throw new RuntimeException('Private Invoice Lane audit failure');
        }

        return null;
    }, function () use ($quote): void {
        expect(fn () => $this->writer->prepare($this->request, $quote['token'], 'none'))->toThrow(OrderRefused::class);
    });
    expect(DB::table('commerce_orders')->count())->toBe(0)->and(DB::table('commerce_order_lines')->count())->toBe(0);
    $order = $this->writer->prepare($this->request, $quote['token'], 'none');
    orderWriterAudit(static function ($audit) use ($cancel): ?bool {
        if ($audit->action === 'commerce.order_cancelled') {
            if ($cancel) {
                return false;
            }
            throw new RuntimeException('Private Invoice Lane audit failure');
        }

        return null;
    }, function () use ($order): void {
        expect(fn () => $this->writer->cancel($this->request, $order->reference))->toThrow(OrderRefused::class);
    });
    expect($order->fresh()->state)->toBe('prepared')->and($order->fresh()->getRawOriginal('active_cart_key'))->not->toBeNull();
})->with([true, false]);

it('rejects late audit listeners replacing an invoice its lines or JSON scalar types', function (string $mutation): void {
    $this->carts->add($this->request, $this->listing->getKey(), 1);
    $quote = $this->quotes->quote($this->request, orderWriterDetails());
    orderWriterAudit(static function ($audit) use ($mutation): void {
        if ($audit->action !== 'commerce.order_prepared') {
            return;
        }
        if ($mutation === 'line') {
            DB::table('commerce_order_lines')->update(['title' => 'Injected title']);
        } elseif ($mutation === 'extra line') {
            $line = (array) DB::table('commerce_order_lines')->first();
            unset($line['id']);
            DB::table('commerce_order_lines')->insert($line);
        } elseif ($mutation === 'JSON type') {
            DB::table('commerce_orders')->update(['site_snapshot' => '{"name":"Order site","base_url":123}']);
        } else {
            DB::table('commerce_orders')->update(['quoted_total_minor' => '1']);
        }
    }, function () use ($quote): void {
        expect(fn () => $this->writer->prepare($this->request, $quote['token'], 'none'))->toThrow(OrderRefused::class);
    });
    expect(DB::table('commerce_orders')->count())->toBe(0)->and(DB::table('commerce_order_lines')->count())->toBe(0)
        ->and(DB::table('audit_log')->where('action', 'commerce.order_prepared')->count())->toBe(0);
})->with(['line', 'extra line', 'JSON type', 'amount']);

it('ignores JSON object ordering while retaining strict scalar types and list order', function (): void {
    expect(OrderJson::same('{"a":1,"b":{"x":true,"y":null}}', '{"b":{"y":null,"x":true},"a":1}'))->toBeTrue()
        ->and(OrderJson::same('{"a":1}', '{"a":"1"}'))->toBeFalse()
        ->and(OrderJson::same('{"a":false}', '{"a":0}'))->toBeFalse()
        ->and(OrderJson::same('[1,2]', '[2,1]'))->toBeFalse();
});

it('does not reuse a copied guest quote through a sibling site or another org', function (bool $crossOrg): void {
    $this->carts->add($this->request, $this->listing->getKey(), 1);
    $quote = $this->quotes->quote($this->request, orderWriterDetails());
    if ($crossOrg) {
        app(Context::class)->setOrg(Org::create(['slug' => 'order-rival', 'name' => 'Rival', 'currency' => 'USD']));
    }
    app(Context::class)->setSite(Site::create(['handle' => 'other', 'slug' => 'order-other', 'name' => 'Other', 'locale' => 'en', 'base_url' => '/other-orders']));
    expect(fn () => $this->writer->prepare($this->request, $quote['token'], 'none'))->toThrow(OrderRefused::class)
        ->and(DB::table('commerce_orders')->count())->toBe(0);
})->with([false, true]);

it('refuses a cached reader whose authoritative account disappeared after quoting', function (): void {
    $reader = Fx::reader();
    Auth::guard(Fx::GUARD)->setUser($reader);
    $this->carts->add($this->request, $this->listing->getKey(), 1);
    $quote = $this->quotes->quote($this->request, orderWriterDetails());
    DB::table($reader->getTable())->where('id', $reader->getKey())->delete();
    expect(fn () => $this->writer->prepare($this->request, $quote['token'], 'none'))->toThrow(OrderRefused::class)
        ->and(DB::table('commerce_orders')->count())->toBe(0);
});

/** Copy native Testbench hosts without following dependency links or sharing bootstrap caches. */
function orderRaceCopy(string $source, string $target): void
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
                orderRaceCopy($item->getPathname(), $destination);
            }
        } else {
            File::copy($item->getPathname(), $destination);
        }
    }
}

function orderRaceBarrier(string $path, array $processes = []): array
{
    $deadline = microtime(true) + 15;
    while (! is_file($path)) {
        foreach ($processes as $process) {
            if (! $process->isRunning()) {
                throw new RuntimeException('Order child exited before '.basename($path).': '.$process->getOutput().$process->getErrorOutput());
            }
        }
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Order barrier timed out: '.basename($path));
        }
        usleep(5000);
    }

    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

function orderRaceApplications(string $scenario): array
{
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir().'/kitsune-order-race-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($directory);
    touch($directory.'/race.sqlite');
    $processes = [];
    foreach (['controller', 'A', 'B', 'read'] as $role) {
        orderRaceCopy($root.'/vendor/orchestra/testbench-core/laravel', $directory.'/app-'.$role);
    }

    return ['directory' => $directory, 'child' => static function (string $mode, string $role) use ($root, $directory, $scenario, &$processes): Process {
        $process = new Process([PHP_BINARY, __DIR__.'/Fixtures/OrderRaceProbe.php', $root, $directory, $mode, $role, $scenario], $root);
        $process->setTimeout(25);
        $processes[] = $process;

        return $process;
    }, 'cleanup' => static function () use ($directory, &$processes): void {
        if (is_dir($directory)) {
            touch($directory.'/release-A');
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0.2);
            }
        }
        File::deleteDirectory($directory);
    }];
}

it('serialises simultaneous native preparations before reading an exact replay or another cart revision claim', function (string $scenario): void {
    $probe = orderRaceApplications($scenario);
    $child = $probe['child'];
    $directory = $probe['directory'];
    try {
        $child('init', 'controller')->mustRun();
        $initialized = orderRaceBarrier($directory.'/initialized.json');
        expect($initialized['level'])->toBe(0)->and($initialized['pdoTransaction'])->toBeFalse();
        $a = $child('worker', 'A');
        $a->start();
        $paused = orderRaceBarrier($directory.'/A-paused.json', [$a]);
        expect($paused['level'])->toBeGreaterThanOrEqual(1)->and($paused['pdoTransaction'])->toBeTrue();
        $b = $child('worker', 'B');
        $b->start();
        expect(orderRaceBarrier($directory.'/B-attempted.json', [$a, $b])['level'])->toBe(1);
        usleep(100000);
        expect($b->isRunning())->toBeTrue()->and(is_file($directory.'/B-result.json'))->toBeFalse();
        touch($directory.'/release-A');
        $a->wait();
        $b->wait();
        expect($a->isSuccessful())->toBeTrue($a->getOutput().$a->getErrorOutput())->and($b->isSuccessful())->toBeTrue($b->getOutput().$b->getErrorOutput());
        $first = orderRaceBarrier($directory.'/A-result.json');
        $second = orderRaceBarrier($directory.'/B-result.json');
        expect($first['success'])->toBeTrue()->and($second['success'])->toBe($scenario === 'same-request');
        if ($scenario === 'same-request') {
            expect($second['id'])->toBe($first['id']);
        } else {
            expect($second['reason'])->toBe('existing');
        }
        $child('read', 'read')->mustRun();
        $stored = orderRaceBarrier($directory.'/readback.json');
        expect($stored)->toBe(['orders' => 1, 'lines' => 1, 'audits' => 1, 'stock' => '5', 'grants' => 0, 'level' => 0, 'pdoTransaction' => false]);
        foreach ([$first, $second] as $result) {
            expect($result['level'])->toBe(0)->and($result['pdoTransaction'])->toBeFalse();
        }
    } finally {
        ($probe['cleanup'])();
    }
})->with(['same-request', 'distinct-request']);

it('retries a native preparation after its enclosing transaction rolls back without retaining an audit or partial invoice', function (): void {
    $probe = orderRaceApplications('outer-rollback');
    try {
        $child = $probe['child'];
        $child('init', 'controller')->mustRun();
        $child('worker', 'A')->mustRun();
        expect(orderRaceBarrier($probe['directory'].'/rollback.json'))->toBe(['during' => 1, 'after' => 0, 'retry' => 'prepared',
            'orders' => 1, 'lines' => 1, 'audits' => 1, 'level' => 0, 'pdoTransaction' => false]);
    } finally {
        ($probe['cleanup'])();
    }
});

it('rolls back preparation if an audit listener inserts an unsolicited access token', function (): void {
    $this->carts->add($this->request, $this->listing->getKey(), 1);
    $quote = $this->quotes->quote($this->request, orderWriterDetails());
    orderWriterAudit(static function ($audit): void {
        if ($audit->action === 'commerce.order_prepared') {
            $row = DB::table('commerce_orders')->where('id', $audit->target_id)->first();
            OrderFixture::token($row, str_repeat('a', 64));
        }
    }, function () use ($quote): void {
        expect(fn () => $this->writer->prepare($this->request, $quote['token'], 'none'))->toThrow(OrderRefused::class);
    });
    expect(DB::table('commerce_orders')->count())->toBe(0)->and(DB::table('commerce_order_access_tokens')->count())->toBe(0)
        ->and(DB::table('commerce_order_lines')->count())->toBe(0)->and(DB::table('audit_log')->where('action', 'commerce.order_prepared')->count())->toBe(0);
});

it('refuses cancellation when its audit changes a frozen line or access-token history', function (string $mutation): void {
    $this->carts->add($this->request, $this->listing->getKey(), 1);
    $quote = $this->quotes->quote($this->request, orderWriterDetails());
    $order = $this->writer->prepare($this->request, $quote['token'], 'none');
    $raw = DB::table('commerce_orders')->where('id', $order->getKey())->first();
    OrderFixture::token($raw, str_repeat('a', 64));
    $beforeLines = DB::table('commerce_order_lines')->get()->map(static fn ($row) => (array) $row)->all();
    $beforeTokens = DB::table('commerce_order_access_tokens')->get()->map(static fn ($row) => (array) $row)->all();
    orderWriterAudit(static function ($audit) use ($mutation, $raw): void {
        if ($audit->action !== 'commerce.order_cancelled') {
            return;
        }
        match ($mutation) {
            'line title' => DB::table('commerce_order_lines')->where('order_id', $raw->id)->update(['title' => 'Replaced invoice item']),
            'token consumption' => DB::table('commerce_order_access_tokens')->where('order_id', $raw->id)->update(['consumed_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s')]),
            'extra token' => OrderFixture::token($raw, str_repeat('b', 64)),
        };
    }, function () use ($order): void {
        expect(fn () => $this->writer->cancel($this->request, $order->reference))->toThrow(OrderRefused::class);
    });
    expect($order->fresh()->state)->toBe('prepared')
        ->and(DB::table('commerce_order_lines')->get()->map(static fn ($row) => (array) $row)->all())->toBe($beforeLines)
        ->and(DB::table('commerce_order_access_tokens')->get()->map(static fn ($row) => (array) $row)->all())->toBe($beforeTokens)
        ->and(DB::table('audit_log')->where('action', 'commerce.order_cancelled')->count())->toBe(0);
})->with(['line title', 'token consumption', 'extra token']);

it('persists tax in exact minor units for zero-digit and three-digit org currencies', function (string $currency, string $unit, string $tax, string $total): void {
    $org = Org::create(['slug' => 'order-currency-'.strtolower($currency), 'name' => 'Currency orders', 'currency' => $currency]);
    app(Context::class)->setOrg($org);
    app(Context::class)->setSite(Site::create(['handle' => 'currency', 'slug' => 'order-currency-'.strtolower($currency), 'name' => 'Currency site', 'locale' => 'en', 'base_url' => '/order-currency-'.strtolower($currency)]));
    BlueprintApplier::apply(new StorefrontBlueprint);
    $product = app(ProductWriter::class)->createProduct(['sku' => 'CURRENCY-COURSE', 'price_minor' => $unit, 'stock' => null], $currency, [
        'title' => 'Currency course', 'slug' => 'currency-course', 'status' => 'published', 'values' => [],
    ], [], true);
    $this->setup->setSettings(['tax_mode' => 'exclusive', 'shipping_tax_mode' => 'exclusive', 'shipping_tax_class' => 'shipping']);
    $this->setup->saveTaxRate(null, ['country' => 'US', 'region' => '*', 'tax_class' => 'standard', 'rate_parts' => 100000]);
    $this->setup->setSalesPolicy($product->getKey(), ['tax_class' => 'standard', 'delivery_kind' => 'digital']);
    $this->carts->add($this->request, $product->listings()->firstOrFail()->getKey(), 1);
    $quote = $this->quotes->quote($this->request, orderWriterDetails());
    $order = $this->writer->prepare($this->request, $quote['token'], 'none');
    expect($order->currency)->toBe($currency)->and((string) $order->quoted_item_net_minor)->toBe($unit)
        ->and((string) $order->quoted_tax_minor)->toBe($tax)->and((string) $order->quoted_total_minor)->toBe($total)
        ->and((string) OrderLine::query()->sole()->unit_price_minor)->toBe($unit);
})->with(['JPY' => ['JPY', '5', '1', '6'], 'KWD' => ['KWD', '2001', '200', '2201']]);

it('preserves money above binary-float precision through the signed bigint boundary', function (string $minor): void {
    DB::table('commerce_sellables')->where('id', $this->product->getKey())->update(['price_minor' => $minor]);
    $this->setup->saveTaxRate($this->rate->getKey(), ['country' => 'US', 'region' => '*', 'tax_class' => 'standard', 'rate_parts' => 0], 1);
    $this->carts->add($this->request, $this->listing->getKey(), 1);
    $quote = $this->quotes->quote($this->request, orderWriterDetails());
    $order = $this->writer->prepare($this->request, $quote['token'], 'none');
    expect((string) $order->quoted_item_net_minor)->toBe($minor)->and((string) $order->quoted_tax_minor)->toBe('0')
        ->and((string) $order->quoted_total_minor)->toBe($minor)->and((string) OrderLine::query()->sole()->unit_price_minor)->toBe($minor);
})->with(['9007199254740993', '9223372036854775807']);

it('rolls back a prepared invoice if its audit changes the catalogue or calculator after the reviewed snapshot', function (string $mutation): void {
    $this->carts->add($this->request, $this->listing->getKey(), 2);
    $quote = $this->quotes->quote($this->request, orderWriterDetails());
    orderWriterAudit(static function ($audit) use ($mutation): void {
        if ($audit->action !== 'commerce.order_prepared') {
            return;
        }
        match ($mutation) {
            'price' => DB::table('commerce_sellables')->update(['price_minor' => '2500']),
            'stock' => DB::table('commerce_sellables')->update(['stock' => '1']),
            'tax' => DB::table('commerce_tax_rates')->update(['rate_parts' => 0]),
        };
    }, function () use ($quote): void {
        expect(fn () => $this->writer->prepare($this->request, $quote['token'], 'none'))->toThrow(OrderRefused::class);
    });
    expect(DB::table('commerce_orders')->count())->toBe(0)->and(DB::table('commerce_order_lines')->count())->toBe(0)
        ->and($this->product->fresh()->price_minor)->toBe('2000')->and($this->product->fresh()->stock)->toBe('10')
        ->and((int) $this->rate->fresh()->rate_parts)->toBe(200000);
})->with(['price', 'stock', 'tax']);

it('retains only the public origin and prefix when the configured site URL contains credentials', function (): void {
    $this->site->base_url = 'https://private-url-user:private-url-password@orders.example.test/shop';
    $this->site->saveOrFail();
    app(Context::class)->setSite($this->site);
    $this->carts->add($this->request, $this->listing->getKey(), 1);
    $quote = $this->quotes->quote($this->request, orderWriterDetails());
    $order = $this->writer->prepare($this->request, $quote['token'], 'none');
    $record = app(OrderReaderData::class)->record($order->getConnection(), (object) $order->getAttributes());
    expect($record['site_snapshot']['base_url'])->toBe('https://orders.example.test/shop')
        ->and(json_encode($record, JSON_THROW_ON_ERROR))->not->toContain('private-url-user', 'private-url-password');
});
