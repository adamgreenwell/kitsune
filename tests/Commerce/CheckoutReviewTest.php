<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Blueprints\StorefrontBlueprint;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Carts\CartWriter;
use Kitsune\Commerce\Catalogue\ProductWriter;
use Kitsune\Commerce\Checkout\CommerceSetupWriter;
use Kitsune\Commerce\Orders\OrderAccess;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Orders\OrderQuotes;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Orders\OrderRefused;
use Kitsune\Commerce\Orders\OrderWriter;
use Kitsune\Commerce\Payments\CheckoutReview;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Readers\ReaderSessions;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\OrderFixture;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    Fx::boot();
    config(['app.key' => 'base64:'.base64_encode(str_repeat('r', 32))]);
    Fx::declareReaders();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
    $this->org = Org::create(['slug' => 'checkout-review', 'name' => 'Review', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'checkout-review-main', 'name' => 'Review site', 'locale' => 'en', 'base_url' => 'https://review.example.test']);
    app(Context::class)->setSite($this->site);
    Module::create(['handle' => 'kitsune/commerce', 'version' => 'dev-main', 'is_enabled' => true, 'installed_at' => now()]);
    BlueprintApplier::apply(new StorefrontBlueprint);
    $this->product = app(ProductWriter::class)->createProduct(['sku' => 'REVIEW-COURSE', 'price_minor' => '2000', 'stock' => '10'], 'USD', [
        'title' => 'Reviewed course', 'slug' => 'review-course', 'status' => 'published', 'values' => [],
    ], [], true);
    $this->listing = $this->product->listings()->sole();
    $setup = app(CommerceSetupWriter::class);
    $setup->setSettings(['tax_mode' => 'exclusive', 'shipping_tax_mode' => 'exclusive', 'shipping_tax_class' => 'shipping']);
    $this->rate = $setup->saveTaxRate(null, ['country' => 'US', 'region' => '*', 'tax_class' => 'standard', 'rate_parts' => 200000]);
    $setup->setSalesPolicy($this->product->getKey(), ['tax_class' => 'standard', 'delivery_kind' => 'digital']);
    $this->request = OrderFixture::request();
    app(CartWriter::class)->add($this->request, $this->listing->getKey(), 2);
    $quote = app(OrderQuotes::class)->quote($this->request, ['email' => 'review-buyer@example.test', 'billing' => OrderFixture::billing()]);
    $this->order = app(OrderWriter::class)->prepare($this->request, $quote['token'], 'none');
    $this->reviewer = app(CheckoutReview::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Fx::tearDown();
});

it('reviews and approves the exact immutable purchase after its original quote and cart disappear', function (): void {
    $before = (array) DB::table('commerce_orders')->where('id', $this->order->getKey())->first();
    DB::table('commerce_carts')->delete();
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDays(31));
    $review = $this->reviewer->review($this->request, $this->order->reference);
    $approved = app(OrderLocks::class)->run(fn () => $this->reviewer->approve($this->request, $this->order->reference, $review['token']));
    expect($review['token'])->toMatch('/\A[a-f0-9]{64}\z/D')
        ->and($review['expires'])->toBe(CarbonImmutable::now('UTC')->addMinutes(15)->getTimestamp())
        ->and($review['order']['quoted_total_minor'])->toBe('4800')
        ->and($approved['order']->getKey())->toBe($this->order->getKey())
        ->and($approved['orderHash'])->toBe(CheckoutReview::orderHash($approved['record']))
        ->and((array) DB::table('commerce_orders')->where('id', $this->order->getKey())->first())->toBe($before)
        ->and(DB::table('commerce_checkout_attempts')->count())->toBe(0)
        ->and(DB::table('entitlements')->count())->toBe(0)
        ->and((string) DB::table('commerce_sellables')->where('id', $this->product->getKey())->value('stock'))->toBe('10');
});

it('uses exact recovered guest proof without recreating or adopting the original cart', function (): void {
    DB::table('commerce_carts')->delete();
    $fresh = OrderFixture::request();
    $secret = bin2hex(random_bytes(32));
    OrderFixture::token((object) $this->order->getAttributes(), $secret);
    $access = app(OrderAccess::class);
    expect($access->capture($fresh, $this->order->reference, $secret))->toBeTrue()
        ->and($access->consume($fresh, $this->order->reference))->toBeTrue()
        ->and(CartOwner::current($fresh))->toBeNull();
    $review = $this->reviewer->review($fresh, $this->order->reference);
    $approved = app(OrderLocks::class)->run(fn () => $this->reviewer->approve($fresh, $this->order->reference, $review['token']));
    expect($approved['record']['reference'])->toBe($this->order->reference)
        ->and(CartOwner::current($fresh))->toBeNull()->and(DB::table('commerce_carts')->count())->toBe(0);
});

it('refuses a reference without exact ownership and never installs a review', function (): void {
    $fresh = OrderFixture::request();
    expect(fn () => $this->reviewer->review($fresh, $this->order->reference))->toThrow(OrderRefused::class)
        ->and(json_encode($fresh->session()->all(), JSON_THROW_ON_ERROR))->not->toContain('checkout_reviews');
});

it('requires a fresh review when live purchase inputs changed', function (string $mutation): void {
    match ($mutation) {
        'price' => DB::table('commerce_sellables')->where('id', $this->product->getKey())->update(['price_minor' => 2100]),
        'stock' => DB::table('commerce_sellables')->where('id', $this->product->getKey())->update(['stock' => 1]),
        'mapping' => DB::table('commerce_sellables')->where('id', $this->product->getKey())->update(['entitlement_name' => 'course.changed']),
        'listing' => DB::table('commerce_listings')->where('id', $this->listing->getKey())->update(['is_available' => false]),
        'content' => DB::table('entries')->where('id', $this->listing->entry_id)->update(['title' => 'New purchase title']),
        'tax' => DB::table('commerce_tax_rates')->where('id', $this->rate->getKey())->update(['rate_parts' => 100000]),
        'tax mode' => DB::table('commerce_settings')->where('org_id', $this->org->getKey())->update(['tax_mode' => 'inclusive']),
        'credential mode' => DB::table('org_credential_modes')->insert(['org_id' => $this->org->getKey(), 'mode' => 'live', 'changed_at' => now()]),
    };
    expect(fn () => $this->reviewer->review($this->request, $this->order->reference))->toThrow(OrderRefused::class)
        ->and(DB::table('commerce_orders')->where('id', $this->order->getKey())->value('state'))->toBe('prepared')
        ->and(DB::table('commerce_checkout_attempts')->count())->toBe(0);
})->with(['price', 'stock', 'mapping', 'listing', 'content', 'tax', 'tax mode', 'credential mode']);

it('rechecks the calculator fingerprint and purchase at approval rather than trusting the review form', function (string $mutation): void {
    $review = $this->reviewer->review($this->request, $this->order->reference);
    match ($mutation) {
        'price' => DB::table('commerce_sellables')->where('id', $this->product->getKey())->update(['price_minor' => 2100]),
        'stock' => DB::table('commerce_sellables')->where('id', $this->product->getKey())->update(['stock' => 1]),
        'settings revision' => DB::table('commerce_settings')->where('org_id', $this->org->getKey())->increment('version'),
        'contact' => DB::table('commerce_orders')->where('id', $this->order->getKey())->update(['contact_email' => 'changed@example.test']),
        'cancel' => app(OrderWriter::class)->cancel($this->request, $this->order->reference),
        'module' => DB::table('modules')->where('handle', 'kitsune/commerce')->update(['is_enabled' => false]),
    };
    expect(fn () => app(OrderLocks::class)->run(fn () => $this->reviewer->approve($this->request, $this->order->reference, $review['token'])))
        ->toThrow(OrderRefused::class);
})->with(['price', 'stock', 'settings revision', 'contact', 'cancel', 'module']);

it('binds the bounded token to its exact order session and expiry boundary', function (string $case): void {
    $review = $this->reviewer->review($this->request, $this->order->reference);
    $request = $this->request;
    $reference = $this->order->reference;
    $token = $review['token'];
    match ($case) {
        'expiry' => CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC($review['expires'])),
        'other session' => $request = OrderFixture::request(),
        'other order' => $reference = str_repeat('a', 32),
        'malformed' => $token = ['bad'],
    };
    expect(fn () => app(OrderLocks::class)->run(fn () => $this->reviewer->approve($request, $reference, $token)))->toThrow(OrderRefused::class);
})->with(['expiry', 'other session', 'other order', 'malformed']);

it('bounds review slots to five and refuses an unbounded planted review map', function (): void {
    $reviews = [];
    for ($i = 0; $i < 6; $i++) {
        $reviews[] = $this->reviewer->review($this->request, $this->order->reference);
    }
    $key = ReaderSessions::EPHEMERAL.'.checkout_reviews.'.$this->org->getKey().'.'.$this->site->getKey();
    expect($this->request->session()->get($key))->toHaveCount(5)
        ->and(fn () => app(OrderLocks::class)->run(fn () => $this->reviewer->approve($this->request, $this->order->reference, $reviews[0]['token'])))->toThrow(OrderRefused::class);
    $slots = $this->request->session()->get($key);
    $slots[str_repeat('a', 64)] = $slots[$reviews[5]['token']];
    $this->request->session()->put($key, $slots);
    expect(fn () => app(OrderLocks::class)->run(fn () => $this->reviewer->approve($this->request, $this->order->reference, $reviews[5]['token'])))->toThrow(OrderRefused::class);
});

it('does not treat mutable link or attempt history as a new purchase but hashes every purchase value', function (): void {
    $record = app(OrderReaderData::class)->record($this->order->getConnection(), (object) $this->order->getAttributes());
    $hash = CheckoutReview::orderHash($record);
    $record['access_links'] = [['different' => 'history']];
    $record['checkout_attempts'] = [['state' => 'uncertain']];
    $record = array_reverse($record, true);
    expect(CheckoutReview::orderHash($record))->toBe($hash);
    $record['lines'][0]['title'] = 'Changed snapshot';
    expect(CheckoutReview::orderHash($record))->not->toBe($hash);
});

/** Fresh process: physical carrier shopping must be measured at actual transaction level zero. @return array<string,mixed> */
function checkoutReviewPhysicalProbe(string $scenario): array
{
    $root = dirname(__DIR__, 2);
    $temporary = sys_get_temp_dir().'/kitsune-checkout-review-'.bin2hex(random_bytes(8));
    mkdir($temporary, 0700);
    touch($temporary.'/review.sqlite');
    try {
        $process = new Process([PHP_BINARY, __DIR__.'/Fixtures/CheckoutReviewProbe.php', $root, $temporary, $scenario], $root);
        $process->setTimeout(60);
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    } finally {
        (new Filesystem)->deleteDirectory($temporary);
    }
}

it('shops physical shipping outside transactions and requires the same consented carrier service and amount', function (string $scenario, bool $accepted): void {
    $result = checkoutReviewPhysicalProbe($scenario);
    expect($result['accepted'])->toBe($accepted)->and($result['networkLevels'])->toBe([0, 0])
        ->and($result['level'])->toBe(0)->and($result['pdoTransaction'])->toBeFalse()
        ->and($result['unchanged'])->toBeTrue()->and($result['attempts'])->toBe(0);
})->with(['same quote new IDs' => ['same', true], 'different amount' => ['amount', false], 'different carrier' => ['carrier', false],
    'different service' => ['service', false], 'changed during I/O' => ['changed', false]]);
