<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Http\Controllers\CheckoutController;
use Kitsune\Commerce\Orders\OrderAccess;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Readers\ReaderMail;
use Kitsune\Core\Readers\ReaderSessions;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\OrderFixture as Orders;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;

beforeEach(function (): void {
    config(AppServiceProvider::readerConfig());
    config(['hashing.bcrypt.rounds' => 4, 'app.key' => 'base64:'.base64_encode(str_repeat('o', 32)),
        'cache.default' => 'array', 'mail.default' => 'array', 'mail.from.address' => 'readers@kitsunecms.org']);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
    Cache::flush();
    Notification::fake();
    Cookie::flushQueuedCookies();
    $this->world = ReaderFixture::world();
    DB::table('orgs')->update(['currency' => 'USD']);
    $this->org = $this->world['golfdom'];
    $this->site = $this->world['sites']['golfdom'];
    app(Context::class)->setOrg($this->org)->setSite($this->site);
    Module::create(['handle' => 'kitsune/commerce', 'version' => 'dev-main', 'is_enabled' => true, 'installed_at' => now()]);
    $this->access = app(OrderAccess::class);
});

afterEach(function (): void {
    app(DeferredCallbackCollection::class)->invoke();
    CarbonImmutable::setTestNow();
    Cookie::flushQueuedCookies();
    ReaderFixture::forget();
});

it('requires the exact site guest owner or a fresh canonical reader rather than a known reference or matching email', function (): void {
    $one = Orders::request();
    $owner = CartOwner::current($one, true);
    $order = Orders::plant($this->site, ownerKey: $owner->key);
    $two = Orders::request();
    expect($this->access->owned($two, $order->reference))->toBeNull()
        ->and($this->access->owned($one, $order->reference)?->getKey())->toBe($order->id);
    $reader = ReaderFixture::reader($this->org, $order->contact_email);
    $request = Orders::request(app('session.store'));
    app(Context::class)->setOrg($this->org)->setSite($this->site);
    Auth::guard(ReaderFixture::GUARD)->login($reader);
    expect($this->access->owned($request, $order->reference))->toBeNull();
    $readerOrder = Orders::plant($this->site, (string) $reader->getKey());
    expect($this->access->owned($request, $readerOrder->reference)?->getKey())->toBe($readerOrder->id);
    DB::table('readers')->where('id', $reader->getKey())->delete();
    // The guard still holds its cached object; primary custody must nevertheless refuse it.
    expect(Auth::guard(ReaderFixture::GUARD)->user())->not->toBeNull()
        ->and($this->access->owned($request, $readerOrder->reference))->toBeNull();
});

it('captures only a token hash under a rotated session ID and consumes it once on the explicit confirmation', function (): void {
    $order = Orders::plant($this->site);
    $secret = bin2hex(random_bytes(32));
    $token = Orders::token($order, $secret);
    $request = Orders::request();
    $id = $request->session()->getId();
    expect($this->access->capture($request, $order->reference, $secret))->toBeTrue()
        ->and($request->session()->getId())->not->toBe($id)
        ->and(json_encode($request->session()->all(), JSON_THROW_ON_ERROR))->not->toContain($secret)
        ->and(json_encode($request->session()->all(), JSON_THROW_ON_ERROR))->toContain(hash('sha256', $secret))
        ->and($this->access->pending($request, $order->reference))->toBeTrue()
        ->and(DB::table('commerce_order_access_tokens')->where('id', $token)->value('consumed_at'))->toBeNull()
        ->and($this->access->owned($request, $order->reference))->toBeNull();

    expect($this->access->consume($request, $order->reference))->toBeTrue()
        ->and($this->access->pending($request, $order->reference))->toBeFalse()
        ->and($this->access->owned($request, $order->reference)?->getKey())->toBe($order->id)
        ->and(DB::table('commerce_order_access_tokens')->where('id', $token)->value('consumed_at'))->toBe(Orders::now());
    $replay = Orders::request();
    $this->access->capture($replay, $order->reference, $secret);
    expect($this->access->consume($replay, $order->reference))->toBeFalse()->and($this->access->owned($replay, $order->reference))->toBeNull();
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(OrderAccess::GRANT_MINUTES));
    expect($this->access->owned($request, $order->reference))->toBeNull();
});

it('cannot replay a captured token or installed grant into a sibling site, another org or another order', function (): void {
    $order = Orders::plant($this->site);
    $otherOrder = Orders::plant($this->site);
    // A valid victim row exists under the same public reference at both other boundaries.
    Orders::plant($this->world['sites']['golfdom-fr'], overrides: ['reference' => $order->reference]);
    Orders::plant($this->world['sites']['rival'], overrides: ['reference' => $order->reference]);
    $secret = bin2hex(random_bytes(32));
    Orders::token($order, $secret);
    $request = Orders::request();
    $this->access->capture($request, $order->reference, $secret);
    app(Context::class)->setSite($this->world['sites']['golfdom-fr']);
    expect($this->access->consume($request, $order->reference))->toBeFalse()->and($this->access->pending($request, $order->reference))->toBeFalse();
    app(Context::class)->setOrg($this->world['rival'])->setSite($this->world['sites']['rival']);
    expect($this->access->consume($request, $order->reference))->toBeFalse();
    app(Context::class)->setOrg($this->org)->setSite($this->site);
    expect($this->access->consume($request, $order->reference))->toBeTrue()
        ->and($this->access->owned($request, $otherOrder->reference))->toBeNull();
    app(Context::class)->setSite($this->world['sites']['golfdom-fr']);
    expect($this->access->owned($request, $order->reference))->toBeNull();
    app(Context::class)->setOrg($this->world['rival'])->setSite($this->world['sites']['rival']);
    expect($this->access->owned($request, $order->reference))->toBeNull();
});

it('refuses expiry exactly at the boundary and a token belonging to a reader-owned order', function (string $case): void {
    $order = Orders::plant($this->site, $case === 'reader-owned' ? 'reader-key' : null);
    $secret = bin2hex(random_bytes(32));
    Orders::token($order, $secret, $case === 'expired' ? ['expires_at' => Orders::now()] : []);
    $request = Orders::request();
    $this->access->capture($request, $order->reference, $secret);
    expect($this->access->consume($request, $order->reference))->toBeFalse()->and($this->access->owned($request, $order->reference))->toBeNull()
        ->and(DB::table('commerce_order_access_tokens')->value('consumed_at'))->toBeNull();
})->with(['expired', 'reader-owned']);

it('keeps pending proof and leaves no grant or consumed row after an enclosing rollback', function (): void {
    $order = Orders::plant($this->site);
    $secret = bin2hex(random_bytes(32));
    Orders::token($order, $secret);
    $request = Orders::request();
    $this->access->capture($request, $order->reference, $secret);
    try {
        DB::transaction(function () use ($request, $order): void {
            expect($this->access->consume($request, $order->reference))->toBeTrue()
                ->and($this->access->owned($request, $order->reference))->toBeNull();
            throw new RuntimeException('Rollback the disposable confirmation.');
        });
    } catch (RuntimeException $failure) {
        expect($failure->getMessage())->toBe('Rollback the disposable confirmation.');
    }
    expect($this->access->pending($request, $order->reference))->toBeTrue()
        ->and($this->access->owned($request, $order->reference))->toBeNull()
        ->and(DB::table('commerce_order_access_tokens')->value('consumed_at'))->toBeNull();
});

it('uses a recovered guest proof for singular export and clears its exact proof after committed complete erasure', function (): void {
    $order = Orders::plant($this->site);
    $secret = bin2hex(random_bytes(32));
    Orders::token($order, $secret);
    $request = Orders::request();
    $this->access->capture($request, $order->reference, $secret);
    $this->access->consume($request, $order->reference);
    $privacy = app(OrderReaderData::class);
    expect($privacy->exportGuest($request, $order->reference)['reference'])->toBe($order->reference)
        ->and($privacy->eraseGuest($request, $order->reference))->toBe(1)
        ->and($this->access->owned($request, $order->reference))->toBeNull()
        ->and($request->session()->get(ReaderSessions::EPHEMERAL.'.order_grants'))->toBe([])
        ->and(DB::table('commerce_orders')->count())->toBe(0)->and(DB::table('commerce_order_lines')->count())->toBe(0)
        ->and(DB::table('commerce_order_access_tokens')->count())->toBe(0);
});

it('bounds pending hashes and refuses malformed references and token shapes before reading an order', function (): void {
    $request = Orders::request();
    foreach (range(1, 25) as $i) {
        $this->access->capture($request, bin2hex(random_bytes(16)), bin2hex(random_bytes(32)));
    }
    expect($request->session()->get(ReaderSessions::EPHEMERAL.'.order_links'))->toHaveCount(OrderAccess::MAX_SLOTS);
    $order = Orders::plant($this->site);
    expect($this->access->capture($request, $order->reference, bin2hex(random_bytes(32))))->toBeTrue();
    foreach ([null, 1, $order->id, strtoupper($order->reference), '../'.$order->reference, str_repeat('a', 1000)] as $reference) {
        expect($this->access->owned($request, $reference))->toBeNull()->and($this->access->consume($request, $reference))->toBeFalse();
    }
    foreach ([null, [], 1, str_repeat('a', 63), str_repeat('A', 64), str_repeat('g', 64), str_repeat('a', 1000)] as $token) {
        expect($this->access->capture($request, $order->reference, $token))->toBeFalse()
            ->and($this->access->pending($request, $order->reference))->toBeFalse();
    }
    expect($this->access->owned(Request::create('/'), $order->reference))->toBeNull();
});

it('silently removes malformed unbounded or forged grant slots rather than treating session data as ownership', function (): void {
    $order = Orders::plant($this->site);
    $request = Orders::request();
    $key = hash('sha256', $this->org->getKey()."\0".$this->site->getKey()."\0".$order->reference);
    foreach ([
        ['expires' => CarbonImmutable::now('UTC')->addDay()->timestamp],
        ['expires' => '9999999999'],
        ['expires' => CarbonImmutable::now('UTC')->timestamp],
        ['expires' => CarbonImmutable::now('UTC')->addMinute()->timestamp, 'org' => $this->org->getKey(), 'site' => $this->site->getKey(), 'reference' => $order->reference, 'owner_key' => 'wrong'],
    ] as $grant) {
        $request->session()->put(ReaderSessions::EPHEMERAL.'.order_grants', [$key => $grant]);
        expect($this->access->owned($request, $order->reference))->toBeNull();
    }
});

it('defers minting and mail until both commit and deferred response work using only the stored contact and canonical site', function (): void {
    app(Context::class)->setOrg($this->world['rival'])->setSite($this->world['sites']['shop']);
    $order = Orders::plant($this->world['sites']['shop']);
    $request = Orders::request();
    $request->headers->set('Host', 'attacker.test');
    $request->request->set('email', 'replacement@example.test');
    DB::transaction(function () use ($request, $order): void {
        $this->access->requestLink($request, $order->reference);
        app(DeferredCallbackCollection::class)->invoke();
        expect(DB::table('commerce_order_access_tokens')->count())->toBe(0);
        Notification::assertNothingSent();
    });
    expect(DB::table('commerce_order_access_tokens')->count())->toBe(0);
    Notification::assertNothingSent();
    app(DeferredCallbackCollection::class)->invoke();
    expect(DB::table('commerce_order_access_tokens')->count())->toBe(1);
    Notification::assertSentOnDemand(ReaderMail::class, function (ReaderMail $mail, array $channels, object $notifiable) use ($order): bool {
        expect($notifiable->routes['mail'])->toBe($order->contact_email)
            ->and($mail->body)->toContain('https://rival.test/shop/cart/orders/'.$order->reference.'/access?token=')
            ->and($mail->body)->not->toContain('attacker.test', 'replacement@example.test');
        preg_match('/token=([a-f0-9]{64})/', $mail->body, $matches);
        expect(DB::table('commerce_order_access_tokens')->value('token_hash'))->toBe(hash('sha256', $matches[1]))
            ->and(DB::table('commerce_order_access_tokens')->value('expires_at'))->toBe(CarbonImmutable::now('UTC')->addMinutes(OrderAccess::LINK_MINUTES)->format('Y-m-d H:i:s'));

        return true;
    });
});

it('does not mint or send from a rolled-back order-mail scheduling transaction', function (): void {
    $order = Orders::plant($this->site);
    $request = Orders::request();
    try {
        DB::transaction(function () use ($request, $order): void {
            $this->access->requestLink($request, $order->reference);
            throw new RuntimeException('Roll back scheduling.');
        });
    } catch (RuntimeException) {
        // The nested transaction's callback must be discarded with its write.
    }
    app(DeferredCallbackCollection::class)->invoke();
    expect(DB::table('commerce_order_access_tokens')->count())->toBe(0);
    Notification::assertNothingSent();
});

it('rechecks captured site, order, contact and enablement before deferred minting', function (string $change): void {
    $order = Orders::plant($this->site);
    $request = Orders::request();
    $this->access->requestLink($request, $order->reference);
    match ($change) {
        'contact' => DB::table('commerce_orders')->where('id', $order->id)->update(['contact_email' => 'changed@example.test']),
        'order' => DB::table('commerce_orders')->where('id', $order->id)->update(['snapshot_hash' => str_repeat('f', 64)]),
        'site' => DB::table('sites')->where('id', $this->site->getKey())->update(['name' => 'Changed']),
        'receipt' => DB::table('modules')->where('handle', 'kitsune/commerce')->update(['version' => 'old-installed-version']),
        'disabled' => DB::table('modules')->where('handle', 'kitsune/commerce')->update(['is_enabled' => false]),
        'context' => app(Context::class)->setSite($this->world['sites']['golfdom-fr']),
        'erased' => app(OrderLocks::class)->run(fn () => app(OrderReaderData::class)->eraseRecord(DB::connection(), $order)),
    };
    app(DeferredCallbackCollection::class)->invoke();
    expect(DB::table('commerce_order_access_tokens')->count())->toBe(0);
    Notification::assertNothingSent();
})->with(['contact', 'order', 'site', 'disabled', 'receipt', 'context', 'erased']);

it('keeps resend uniform and silently throttled while replacing old one-use tokens with a bounded new token', function (): void {
    $order = Orders::plant($this->site);
    Orders::token($order, bin2hex(random_bytes(32)));
    $oldHash = DB::table('commerce_order_access_tokens')->value('token_hash');
    $request = Orders::request();
    expect($this->access->requestLink($request, bin2hex(random_bytes(16))))->toBeNull();
    $this->access->requestLink($request, $order->reference);
    $this->access->requestLink($request, $order->reference);
    app(DeferredCallbackCollection::class)->invoke();
    Notification::assertSentOnDemandTimes(ReaderMail::class, 1);
    expect(DB::table('commerce_order_access_tokens')->count())->toBe(1)
        ->and(DB::table('commerce_order_access_tokens')->value('token_hash'))->not->toBe($oldHash);
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(5));
    $this->access->requestLink($request, $order->reference);
    app(DeferredCallbackCollection::class)->invoke();
    Notification::assertSentOnDemandTimes(ReaderMail::class, 2);
    expect(DB::table('commerce_order_access_tokens')->count())->toBe(1);
});

it('logs only a generic scheduling failure and returns uniformly when stored data is unavailable', function (): void {
    $order = Orders::plant($this->site, overrides: ['billing' => '{"private_captured_payload":true}']);
    $request = Orders::request();
    Log::spy();
    expect($this->access->requestLink($request, $order->reference))->toBeNull();
    Log::shouldHaveReceived('warning')->once()->with('An order access email could not be scheduled; no address, order, link or error is logged.');
    expect(DB::table('commerce_order_access_tokens')->count())->toBe(0);
    Notification::assertNothingSent();
});

it('refuses deferred replacement when a real token deletion is cancelled', function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('The cancellation trigger is a disposable SQLite probe.');
    }
    $order = Orders::plant($this->site);
    Orders::token($order, bin2hex(random_bytes(32)));
    $old = DB::table('commerce_order_access_tokens')->first();
    $request = Orders::request();
    DB::unprepared('CREATE TRIGGER refuse_order_token_replacement BEFORE DELETE ON commerce_order_access_tokens BEGIN SELECT RAISE(IGNORE); END');
    try {
        $this->access->requestLink($request, $order->reference);
        app(DeferredCallbackCollection::class)->invoke();
        expect(DB::table('commerce_order_access_tokens')->get()->all())->toEqual([$old]);
        Notification::assertNothingSent();
    } finally {
        DB::unprepared('DROP TRIGGER refuse_order_token_replacement');
    }
});

it('uses current locked custody and reader reads inside an enclosing transaction instead of its old snapshot', function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('The SQLite grammar witness checks requested locks; server isolation is proved separately.');
    }
    $reader = ReaderFixture::reader($this->org, 'current-reader@example.test');
    $request = Orders::request(app('session.store'));
    app(Context::class)->setOrg($this->org)->setSite($this->site);
    Auth::guard(ReaderFixture::GUARD)->login($reader);
    $order = Orders::plant($this->site, (string) $reader->getKey());
    Orders::token($order, bin2hex(random_bytes(32)));
    $connection = DB::connection();
    $original = $connection->getQueryGrammar();
    $connection->setQueryGrammar(new class($connection) extends SQLiteGrammar
    {
        protected function compileLock(Builder $query, $value)
        {
            return '/* current-order-read */';
        }
    });
    $queries = [];
    DB::listen(static function (QueryExecuted $event) use (&$queries): void {
        $queries[] = $event->sql;
    });
    try {
        expect($connection->transactionLevel())->toBeGreaterThan(0)
            ->and($this->access->owned($request, $order->reference)?->getKey())->toBe($order->id);
        foreach (['orgs', 'sites', 'commerce_orders', 'commerce_order_lines', 'commerce_order_access_tokens', 'readers'] as $table) {
            $custody = array_values(array_filter($queries, static fn (string $sql): bool => str_starts_with($sql, 'select ')
                && str_contains($sql, 'from "'.$table.'"') && str_contains($sql, 'current-order-read')));
            expect($custody, $table.' has a current lock witness')->not->toBeEmpty();
        }
    } finally {
        $connection->setQueryGrammar($original);
    }
});

it('cannot turn committed scheduling into a failing response when transport configuration becomes malformed', function (): void {
    $order = Orders::plant($this->site);
    $request = Orders::request();
    Log::spy();
    $committed = DB::transaction(function () use ($request, $order): string {
        $this->access->requestLink($request, $order->reference);
        // ReaderMail resolves its chain when this transaction's afterCommit callback is invoked.
        config(['mail.mailers.array.url' => 'smtp://invalid.test:999999']);

        return 'committed';
    });
    expect($committed)->toBe('committed')->and(DB::table('commerce_orders')->count())->toBe(1)
        ->and(DB::table('commerce_order_access_tokens')->count())->toBe(0);
    Log::shouldHaveReceived('warning')->once()->with('An order access email could not be scheduled; no address, order, link or error is logged.');
    Notification::assertNothingSent();
});

it('rejects absent and foreign checkout references without an Org fence', function (string $action, string $method, string $scope): void {
    $reference = $scope === 'unknown' ? str_repeat('f', 32)
        : Orders::plant($this->world['sites'][$scope === 'sibling' ? 'golfdom-fr' : 'rival'])->reference;
    $request = Orders::request();
    $request->setMethod($method);
    $path = '/cart/orders/'.$reference.'/payment';
    $route = new Route($method, '/cart/orders/{reference}/payment', []);
    $route->bind(Request::create($path, $method));
    $request->setRouteResolver(static fn () => $route);
    $fences = [];
    DB::listen(static function (QueryExecuted $query) use (&$fences): void {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'for update') || str_starts_with($sql, 'update')) {
            $fences[] = $query->sql;
        }
    });
    $response = app(CheckoutController::class)->$action($request);
    expect($response->getStatusCode())->toBe(404)->and($fences)->toBe([]);
})->with(['show', 'returned', 'cancelled'])->with(['GET', 'HEAD'])->with(['unknown', 'sibling', 'foreign']);

it('rechecks ownership after a non-authorizing checkout existence probe', function (bool $readerOwned): void {
    $request = Orders::request(app('session.store'));
    $reader = $readerOwned ? ReaderFixture::reader($this->org, 'probe-reader@example.test') : null;
    app(Context::class)->setOrg($this->org)->setSite($this->site);
    if ($reader !== null) {
        Auth::guard(ReaderFixture::GUARD)->login($reader);
    }
    $owner = CartOwner::current($request, true);
    $order = Orders::plant($this->site, $reader === null ? null : (string) $reader->getKey(), ownerKey: $owner->key);
    $route = new Route('GET', '/cart/orders/{reference}/payment/return', []);
    $route->bind(Request::create('/cart/orders/'.$order->reference.'/payment/return'));
    $request->setRouteResolver(static fn () => $route);
    $changed = false;
    DB::listen(static function (QueryExecuted $query) use ($request, $reader, &$changed): void {
        if (! $changed && str_starts_with(strtolower($query->sql), 'select exists') && str_contains($query->sql, 'commerce_orders')) {
            $changed = true;
            if ($reader === null) {
                $request->session()->forget(ReaderSessions::EPHEMERAL);
            } else {
                DB::table('readers')->where('id', $reader->getKey())->delete();
            }
        }
    });
    $response = app(CheckoutController::class)->returned($request);
    expect($changed)->toBeTrue()->and($response->getStatusCode())->toBe(404);
})->with([false, true]);
