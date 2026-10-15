<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Models\AccessEffect;
use Kitsune\Commerce\Models\AccessWork;
use Kitsune\Commerce\Models\Sellable;
use Kitsune\Commerce\Orders\OrderClaims;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\CheckoutRefused;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Core\Entitlements\EntitlementRefusal;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Entitlements\EntitlementWriter;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Readers\ReaderErasure;
use Kitsune\Core\Readers\ReaderExport;
use Kitsune\Core\Readers\ReaderModuleDataUnavailable;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\PaidOrderFixture as PaidFx;

beforeEach(function (): void {
    Fx::boot();
    CarbonImmutable::setTestNow('2026-10-15 12:00:00 UTC');
    $this->org = Org::create(['slug' => 'purchased-access', 'name' => 'Purchased access', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'purchased-access-main', 'name' => 'Main', 'base_url' => 'https://access.example.test']);
    app(Context::class)->setSite($this->site);
    Fx::declareReaders();
    $this->reader = Fx::reader();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Fx::tearDown();
});

/** A real immutable reader purchase followed by its durable allocation, with no provider I/O. */
function accessPurchase(Site $site, ?string $reader, ?array $terms = ['version' => 1, 'kind' => 'fixed', 'days' => 30], ?string $stock = null, int $quantity = 1, array $extra = [], ?Sellable $sellable = null): object
{
    $attempt = PaidFx::attempt($site, $stock, $quantity, $sellable);
    $order = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
    if ($reader !== null) {
        $owner = CartOwner::readerKey($reader);
        DB::table('commerce_orders')->where('id', $order->id)->update(['reader_id' => $reader, 'owner_key' => $owner,
            'active_cart_key' => hash('sha256', $owner."\0".$order->cart_ref."\0".$order->cart_version)]);
    }
    DB::table('commerce_order_lines')->where('order_id', $order->id)->update(['entitlement_name' => 'course.purchased',
        'offer_terms' => $terms === null ? null : json_encode($terms, JSON_THROW_ON_ERROR)]);
    $base = (array) DB::table('commerce_order_lines')->where('order_id', $order->id)->sole();
    unset($base['id']);
    foreach ($extra as $line) {
        DB::table('commerce_order_lines')->insert([...$base, ...$line]);
    }
    if ($extra !== []) {
        DB::table('commerce_orders')->where('id', $order->id)->update(['quoted_item_net_minor' => (string) (2000 * $quantity * (1 + count($extra))),
            'quoted_tax_minor' => (string) (100 * $quantity * (1 + count($extra))), 'quoted_total_minor' => (string) (2100 * $quantity * (1 + count($extra)))]);
    }
    $attempt = PaidFx::refresh($attempt);
    app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm(DB::connection(),
        DB::table('commerce_orders')->where('id', $order->id)->sole(), $attempt, PaidFx::session($attempt)));

    return DB::table('commerce_orders')->where('id', $order->id)->sole();
}

function accessObserve(Closure $observer, Closure $work, bool $creating = false): mixed
{
    $dispatcher = AuditLog::getEventDispatcher();
    AuditLog::setEventDispatcher(clone $dispatcher);
    $creating ? AuditLog::creating($observer) : AuditLog::created($observer);
    try {
        return $work();
    } finally {
        AuditLog::setEventDispatcher($dispatcher);
    }
}

it('freezes first confirmation and recovers one order source without repeating stock or refunds', function (): void {
    $order = accessPurchase($this->site, (string) $this->reader->id, stock: '2', quantity: 3);
    expect(DB::table('entitlements')->count())->toBe(0)->and(DB::table(AccessFulfillment::WORK)->value('state'))->toBe('pending');
    CarbonImmutable::setTestNow('2026-10-20 08:00:00 UTC');
    $before = [DB::table('commerce_stock_movements')->count(), DB::table(PaidOrders::ATTEMPTS)->count()];
    $service = app(AccessFulfillment::class);
    expect($service->reconcile($order->reference))->toMatchArray(['state' => 'processed', 'processed' => 1]);
    expect($service->reconcile($order->reference))->toMatchArray(['state' => 'processed', 'processed' => 0]);
    $grant = DB::table('entitlements')->sole();
    expect($grant->source)->toBe('commerce.order:'.$order->id)->and($grant->expires_at)->toBe('2026-11-14 12:00:00')
        ->and((string) $grant->reader_id)->toBe((string) $this->reader->id)
        ->and(DB::table('audit_log')->where('action', EntitlementWriter::GRANTED)->count())->toBe(1)
        ->and([DB::table('commerce_stock_movements')->count(), DB::table(PaidOrders::ATTEMPTS)->count()])->toBe($before);
});

it('keeps the ordinary writer and generic recovery closed to a reader request without clearing their session', function (): void {
    $order = accessPurchase($this->site, (string) $this->reader->id, ['version' => 1, 'kind' => 'permanent']);
    Fx::signIn($this->reader);
    expect(fn () => app(EntitlementWriter::class)->grant($this->reader->id, 'course.arbitrary', 'commerce.order:999', null))
        ->toThrow(EntitlementRefused::class);
    expect(fn () => app(AccessFulfillment::class)->reconcile($order->reference))->toThrow(CheckoutRefused::class);
    auth(Fx::GUARD)->forgetUser();
    expect(app(AccessFulfillment::class)->reconcile($order->reference)['state'])->toBe('processed');
    $audit = DB::table('audit_log')->where('action', EntitlementWriter::GRANTED)->sole();
    expect($audit->actor_type)->toBeNull()->and($audit->actor_id)->toBeNull()
        ->and(DB::table('entitlements')->value('expires_at'))->toBeNull();
});

it('makes a delayed ended fixed term terminal without granting or restarting its clock', function (): void {
    $order = accessPurchase($this->site, (string) $this->reader->id, ['version' => 1, 'kind' => 'fixed', 'days' => 1]);
    CarbonImmutable::setTestNow('2026-10-16 12:00:00 UTC');
    expect(app(AccessFulfillment::class)->reconcile($order->reference)['state'])->toBe('processed')
        ->and(DB::table(AccessFulfillment::EFFECTS)->value('state'))->toBe('expired')
        ->and(DB::table('entitlements')->count())->toBe(0)
        ->and(DB::table('audit_log')->where('action', 'like', 'entitlement.%')->count())->toBe(0);
});

it('holds historic unknown terms rather than guessing permanent access', function (): void {
    $order = accessPurchase($this->site, (string) $this->reader->id, null);
    expect(app(AccessFulfillment::class)->reconcile($order->reference))->toMatchArray(['state' => 'held', 'category' => 'unknown_terms'])
        ->and(DB::table(AccessFulfillment::EFFECTS)->value('state'))->toBe('held')
        ->and(DB::table('entitlements')->count())->toBe(0);
    expect(app(AccessFulfillment::class)->reconcile($order->reference))->toMatchArray(['state' => 'held', 'category' => 'unknown_terms']);
});

it('combines repeated names by the latest frozen end and permanent wins without multiplying by units', function (bool $permanent): void {
    $last = $permanent ? ['version' => 1, 'kind' => 'permanent'] : ['version' => 1, 'kind' => 'fixed', 'days' => 60];
    $order = accessPurchase($this->site, (string) $this->reader->id, quantity: 3,
        extra: [['offer_terms' => json_encode($last, JSON_THROW_ON_ERROR)]]);
    expect(app(AccessFulfillment::class)->reconcile($order->reference)['state'])->toBe('processed')
        ->and(DB::table(AccessFulfillment::EFFECTS)->count())->toBe(1)->and(DB::table('entitlements')->count())->toBe(1)
        ->and(DB::table('entitlements')->value('expires_at'))->toBe($permanent ? null : '2026-12-14 12:00:00');
})->with([false, true]);

it('holds only an unknown named effect while granting the other explicitly configured access', function (): void {
    $order = accessPurchase($this->site, (string) $this->reader->id, null,
        extra: [['entitlement_name' => 'course.other', 'offer_terms' => json_encode(['version' => 1, 'kind' => 'permanent'], JSON_THROW_ON_ERROR)]]);
    expect(app(AccessFulfillment::class)->reconcile($order->reference))->toMatchArray(['state' => 'held', 'category' => 'unknown_terms', 'processed' => 1])
        ->and(DB::table('entitlements')->count())->toBe(1)->and(DB::table('entitlements')->value('entitlement'))->toBe('course.other')
        ->and(DB::table(AccessFulfillment::EFFECTS)->where('entitlement', 'course.purchased')->value('state'))->toBe('held');
});

it('lets independent purchases overlap without carrying another source\'s remaining time', function (): void {
    $first = accessPurchase($this->site, (string) $this->reader->id);
    app(AccessFulfillment::class)->reconcile($first->reference);
    CarbonImmutable::setTestNow('2026-11-01 12:00:00 UTC');
    $second = accessPurchase($this->site, (string) $this->reader->id);
    app(AccessFulfillment::class)->reconcile($second->reference);
    expect(DB::table('entitlements')->where('source', 'commerce.order:'.$first->id)->value('expires_at'))->toBe('2026-11-14 12:00:00')
        ->and(DB::table('entitlements')->where('source', 'commerce.order:'.$second->id)->value('expires_at'))->toBe('2026-12-01 12:00:00');
});

it('grants the same shared product independently on each actual selling site', function (): void {
    $first = accessPurchase($this->site, (string) $this->reader->id);
    $shared = Sellable::query()->sole();
    $other = Site::create(['handle' => 'brand', 'slug' => 'access-brand', 'name' => 'Brand', 'base_url' => 'https://access-brand.test']);
    $second = accessPurchase($other, (string) $this->reader->id, sellable: $shared);
    app(AccessFulfillment::class)->reconcile($second->reference);
    app(Context::class)->setSite($this->site);
    app(AccessFulfillment::class)->reconcile($first->reference);
    expect(DB::table('entitlements')->where('source', 'commerce.order:'.$first->id)->value('site_id'))->toBe($this->site->id)
        ->and(DB::table('entitlements')->where('source', 'commerce.order:'.$second->id)->value('site_id'))->toBe($other->id)
        ->and(DB::table('commerce_sellables')->count())->toBe(1);
});

it('queues an unclaimed guest purchase without giving access to the signed in reader or matching email', function (): void {
    $order = accessPurchase($this->site, null);
    Fx::signIn($this->reader);
    expect(fn () => app(AccessFulfillment::class)->reconcile($order->reference))->toThrow(CheckoutRefused::class)
        ->and(DB::table('entitlements')->count())->toBe(0)->and(DB::table('commerce_orders')->value('reader_id'))->toBeNull();
});

it('keeps an independently discoverable immutable claim marker and refuses partial recipient corruption', function (string $mutation): void {
    $order = accessPurchase($this->site, null);
    DB::table(OrderClaims::TABLE)->insert(['org_id' => $order->org_id, 'site_id' => $order->site_id, 'order_id' => $order->id,
        'reader_id' => (string) $this->reader->id, 'owner_key' => $order->owner_key, 'order_reference' => $order->reference,
        'snapshot_hash' => $order->snapshot_hash, 'proof_kind' => 'browser', 'claimed_at' => '2026-10-15 12:00:00']);
    app(OrderLocks::class)->run(fn () => app(AccessFulfillment::class)->claimed(DB::connection(), $order));
    expect(DB::table(AccessFulfillment::WORK)->value('claim_reader_id'))->toBe((string) $this->reader->id)
        ->and(app(AccessFulfillment::class)->forReader(DB::connection(), $this->org->id, (string) $this->reader->id))->toHaveCount(1);
    if ($mutation === 'claim') {
        DB::table(OrderClaims::TABLE)->update(['reader_id' => '999']);
        expect(fn () => app(AccessFulfillment::class)->forReader(DB::connection(), $this->org->id, (string) $this->reader->id))->toThrow(CheckoutRefused::class);
    } else {
        DB::table(AccessFulfillment::WORK)->update(['claim_reader_id' => '999']);
        expect(fn () => app(AccessFulfillment::class)->projection(DB::connection(), $this->org->id, $order))->toThrow(CheckoutRefused::class);
    }
    expect(fn () => app(ReaderExport::class)->for((string) $this->reader->id))->toThrow(ReaderModuleDataUnavailable::class)
        ->and(fn () => app(ReaderErasure::class)->erase((string) $this->reader->id))->toThrow(ReaderModuleDataUnavailable::class)
        ->and(DB::table('entitlements')->count())->toBe(0)
        ->and(DB::table($this->reader->getTable())->where('id', $this->reader->id)->exists())->toBeTrue();
})->with(['claim', 'marker']);

it('never grants zero awarded units even when their unavailable-unit refund is still owed', function (): void {
    $order = accessPurchase($this->site, (string) $this->reader->id, stock: '0');
    expect(app(AccessFulfillment::class)->reconcile($order->reference)['state'])->toBe('processed')
        ->and(DB::table(AccessFulfillment::EFFECTS)->count())->toBe(0)->and(DB::table('entitlements')->count())->toBe(0)
        ->and(DB::table(PaidOrders::ATTEMPTS)->value('state'))->toBe('owed');
});

it('records an actionable missing reader and can retry the unchanged purchase after the reader returns', function (): void {
    $order = accessPurchase($this->site, (string) $this->reader->id);
    $row = $this->reader->getAttributes();
    DB::table($this->reader->getTable())->where('id', $this->reader->id)->delete();
    expect(app(AccessFulfillment::class)->reconcile($order->reference))->toMatchArray(['state' => 'held', 'category' => 'unknown_reader'])
        ->and(DB::table('entitlements')->count())->toBe(0);
    DB::table($this->reader->getTable())->insert($row);
    expect(app(AccessFulfillment::class)->reconcile($order->reference)['state'])->toBe('processed')
        ->and(DB::table('entitlements')->value('expires_at'))->toBe('2026-11-14 12:00:00');
});

it('retries a race or uncertain database outcome using the same frozen source and end', function (bool $applied): void {
    $order = accessPurchase($this->site, (string) $this->reader->id);
    $real = app(EntitlementWriter::class);
    app()->instance(EntitlementWriter::class, new class($real, $applied)
    {
        public function __construct(private readonly EntitlementWriter $real, private readonly bool $applied) {}

        public function grantVerifiedOrder(mixed ...$args): never
        {
            if ($this->applied) {
                $this->real->grantVerifiedOrder(...$args);
            }
            throw EntitlementRefused::because($this->applied ? EntitlementRefusal::Database : EntitlementRefusal::Race,
                EntitlementRefused::GRANT, 'course.purchased', ['state' => 'HY000', 'commit' => $this->applied]);
        }
    });
    expect(app(AccessFulfillment::class)->reconcile($order->reference))->toMatchArray(['state' => 'held', 'category' => 'retryable'])
        ->and(DB::table(AccessFulfillment::EFFECTS)->value('state'))->toBe('pending');
    app()->instance(EntitlementWriter::class, $real);
    expect(app(AccessFulfillment::class)->reconcile($order->reference)['state'])->toBe('processed')
        ->and(DB::table('entitlements')->count())->toBe(1)->and(DB::table('audit_log')->where('action', EntitlementWriter::GRANTED)->count())->toBe(1)
        ->and(DB::table('entitlements')->value('expires_at'))->toBe('2026-11-14 12:00:00');
})->with([false, true]);

it('keeps a revoked order source revoked while preserving another source', function (): void {
    $order = accessPurchase($this->site, (string) $this->reader->id);
    app(EntitlementWriter::class)->grant($this->reader->id, 'course.purchased', 'commerce.order:'.$order->id, CarbonImmutable::parse('2026-11-14 12:00:00 UTC'));
    app(EntitlementWriter::class)->grant($this->reader->id, 'course.purchased', 'import.legacy:another', null);
    app(EntitlementWriter::class)->revoke($this->reader->id, 'course.purchased', 'commerce.order:'.$order->id);
    $before = DB::table('audit_log')->count();
    expect(app(AccessFulfillment::class)->reconcile($order->reference)['state'])->toBe('processed')
        ->and(DB::table(AccessFulfillment::EFFECTS)->value('state'))->toBe('revoked')
        ->and(DB::table('audit_log')->count())->toBe($before)
        ->and(DB::table('entitlements')->where('source', 'import.legacy:another')->value('revoked_at'))->toBeNull();
});

it('does not regenerate an erased entitlement after its durable effect completed', function (): void {
    $order = accessPurchase($this->site, (string) $this->reader->id);
    app(AccessFulfillment::class)->reconcile($order->reference);
    app(EntitlementWriter::class)->forget($this->reader->id);
    expect(app(AccessFulfillment::class)->reconcile($order->reference)['processed'])->toBe(0)
        ->and(DB::table('entitlements')->count())->toBe(0);
});

it('refuses missing or rewritten processed effect custody without regenerating it', function (string $mutation): void {
    $order = accessPurchase($this->site, (string) $this->reader->id);
    app(AccessFulfillment::class)->reconcile($order->reference);
    if ($mutation === 'effect') {
        DB::table(AccessFulfillment::EFFECTS)->delete();
    } elseif ($mutation === 'work') {
        DB::table(AccessFulfillment::EFFECTS)->delete();
        DB::table(AccessFulfillment::WORK)->delete();
    } elseif ($mutation === 'source') {
        DB::table(AccessFulfillment::EFFECTS)->update(['source' => 'commerce.order:999']);
    } else {
        DB::table(AccessFulfillment::EFFECTS)->update(['processed_at' => null]);
    }
    expect(fn () => app(AccessFulfillment::class)->reconcile($order->reference))->toThrow(CheckoutRefused::class)
        ->and(DB::table('entitlements')->count())->toBe(1);
})->with(['effect', 'work', 'source', 'processed_at']);

it('does not use changed or removed catalogue products after their paid allocation', function (): void {
    $order = accessPurchase($this->site, (string) $this->reader->id);
    DB::table('commerce_listings')->delete();
    DB::table('commerce_sellables')->delete();
    expect(app(AccessFulfillment::class)->reconcile($order->reference)['state'])->toBe('processed')
        ->and(DB::table('entitlements')->value('entitlement'))->toBe('course.purchased');
});

it('refuses another site and another org without touching the actual order work', function (bool $foreign): void {
    $order = accessPurchase($this->site, (string) $this->reader->id);
    if ($foreign) {
        app(Context::class)->setOrg(Org::create(['slug' => 'foreign-access', 'name' => 'Foreign', 'currency' => 'USD']));
    }
    $other = Site::create(['handle' => 'other', 'slug' => 'access-other', 'name' => 'Other', 'base_url' => 'https://access-other.test']);
    app(Context::class)->setSite($other);
    expect(fn () => app(AccessFulfillment::class)->reconcile($order->reference))->toThrow(CheckoutRefused::class)
        ->and(DB::table('entitlements')->count())->toBe(0)->and(DB::table(AccessFulfillment::WORK)->value('state'))->toBe('pending');
})->with([false, true]);

it('detects a foreign planted effect for this order instead of filtering it out', function (): void {
    $order = accessPurchase($this->site, (string) $this->reader->id);
    $other = Site::create(['handle' => 'planted', 'slug' => 'access-planted', 'name' => 'Other', 'base_url' => 'https://access-planted.test']);
    $effect = (array) DB::table(AccessFulfillment::EFFECTS)->sole();
    unset($effect['id']);
    DB::table(AccessFulfillment::EFFECTS)->insert([...$effect, 'site_id' => $other->id]);
    expect(fn () => app(AccessFulfillment::class)->reconcile($order->reference))->toThrow(CheckoutRefused::class)
        ->and(DB::table('entitlements')->count())->toBe(0);
});

it('rolls back producer access and refuses to mark completion if its audit is cancelled', function (): void {
    $order = accessPurchase($this->site, (string) $this->reader->id);
    accessObserve(static fn ($audit): mixed => $audit->action === EntitlementWriter::GRANTED ? false : null, function () use ($order): void {
        expect(app(AccessFulfillment::class)->reconcile($order->reference))->toMatchArray(['state' => 'held', 'category' => 'grant_refused']);
    }, true);
    expect(DB::table('entitlements')->count())->toBe(0)->and(DB::table(AccessFulfillment::EFFECTS)->value('state'))->toBe('pending')
        ->and(DB::table('audit_log')->where('action', EntitlementWriter::GRANTED)->count())->toBe(0);
});

it('rejects persisted producer audit changes after a host observer runs', function (string $field): void {
    $order = accessPurchase($this->site, (string) $this->reader->id);
    accessObserve(function ($audit) use ($field): void {
        if ($audit->action === EntitlementWriter::GRANTED) {
            DB::table('audit_log')->where('id', $audit->id)->update([$field => 'forged']);
        }
    }, function () use ($order): void {
        expect(app(AccessFulfillment::class)->reconcile($order->reference)['state'])->toBe('held');
    });
    expect(DB::table('entitlements')->count())->toBe(0)->and(DB::table(AccessFulfillment::EFFECTS)->value('state'))->toBe('pending');
})->with(['action', 'target_type', 'target_id', 'actor_type', 'actor_id']);

it('rejects changed persisted grant fields after a host observer runs', function (string $field): void {
    $order = accessPurchase($this->site, (string) $this->reader->id);
    accessObserve(function ($audit) use ($field): void {
        if ($audit->action === EntitlementWriter::GRANTED) {
            DB::table('entitlements')->update([$field => match ($field) {
                'source' => 'import.forged:another', 'entitlement' => 'course.forged', 'reader_id' => '999',
                'expires_at' => null, 'revoked_at' => '2026-10-15 12:00:00', 'changed_at' => '2026-10-16 12:00:00',
            }]);
        }
    }, function () use ($order): void {
        expect(app(AccessFulfillment::class)->reconcile($order->reference)['state'])->toBe('held');
    });
    expect(DB::table('entitlements')->count())->toBe(0)->and(DB::table(AccessFulfillment::EFFECTS)->value('state'))->toBe('pending');
})->with(['source', 'entitlement', 'reader_id', 'expires_at', 'revoked_at', 'changed_at']);

it('rolls back a grant when an audit callback rewrites the frozen effect or purchase', function (string $table): void {
    $order = accessPurchase($this->site, (string) $this->reader->id);
    accessObserve(function ($audit) use ($table): void {
        if ($audit->action === EntitlementWriter::GRANTED) {
            $table === AccessFulfillment::EFFECTS
                ? DB::table($table)->update(['expires_at' => null])
                : DB::table($table)->update(['contact_email' => 'forged@example.test']);
        }
    }, function () use ($order): void {
        expect(fn () => app(AccessFulfillment::class)->reconcile($order->reference))->toThrow(CheckoutRefused::class);
    });
    expect(DB::table('entitlements')->count())->toBe(0)->and(DB::table(AccessFulfillment::EFFECTS)->value('state'))->toBe('pending')
        ->and(DB::table('commerce_orders')->value('contact_email'))->toBe('historical-buyer@example.test');
})->with([AccessFulfillment::EFFECTS, 'commerce_orders']);

it('keeps access models scoped read only and private by default', function (): void {
    accessPurchase($this->site, (string) $this->reader->id);
    foreach ([new AccessWork, new AccessEffect] as $model) {
        expect((new ReflectionClass($model))->getAttributes(SiteScoped::class))->toHaveCount(1)
            ->and(class_uses_recursive($model))->toContain(EnforcesScope::class)
            ->and($model->get()->count())->toBe(1)->and($model->first()->toArray())->toBe([])
            ->and(fn () => $model->newQuery()->update(['state' => 'processed']))->toThrow(RuntimeException::class)
            ->and(fn () => $model->first()->delete())->toThrow(RuntimeException::class);
    }
    app(Context::class)->forget();
    expect(AccessWork::query()->count())->toBe(0)->and(AccessEffect::query()->count())->toBe(0);
});
