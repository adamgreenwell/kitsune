<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Blueprints\StorefrontBlueprint;
use Kitsune\Commerce\Catalogue\CatalogueWriter;
use Kitsune\Commerce\Catalogue\ProductType;
use Kitsune\Commerce\Catalogue\ProductWriter;
use Kitsune\Commerce\Inventory\StockRefused;
use Kitsune\Commerce\Inventory\StockWriter;
use Kitsune\Commerce\Models\StockMovement;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Attributes\OrgScopedThroughPivot;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\OrderFixture;
use Kitsune\Core\Tests\Fixtures\TestUser;

/** A host user whose panel admission is independently restricted by site membership. */
#[OrgScopedThroughPivot(table: 'org_user', foreignKey: 'user_id')]
final class StockSiteUser extends TestUser implements HasTenants
{
    public function getTenants(Panel $panel): array
    {
        $site = app(Context::class)->site();

        return $site !== null && $this->canAccessTenant($site) ? [$site] : [];
    }

    public function canAccessTenant(Model $site): bool
    {
        return DB::table('site_user')->where('site_id', $site->getKey())->where('user_id', $this->getKey())->exists();
    }
}

beforeEach(function (): void {
    Fx::boot();
    $this->org = Org::create(['slug' => 'stock-writer', 'name' => 'Stock writer', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'stock-main', 'name' => 'Main', 'base_url' => 'https://stock.example.test']);
    $this->sibling = Site::create(['handle' => 'other', 'slug' => 'stock-other', 'name' => 'Other', 'base_url' => 'https://other-stock.example.test']);
    app(Context::class)->setSite($this->site);
    Module::create(['handle' => 'kitsune/commerce', 'version' => ModuleDiscovery::version('kitsune/commerce'), 'is_enabled' => true, 'installed_at' => now()]);
    BlueprintApplier::apply(new StorefrontBlueprint);
    $this->product = app(ProductWriter::class)->createProduct(['sku' => 'SHARED-BOARD', 'price_minor' => '2000', 'stock' => '5'], 'USD', [
        'title' => 'Board', 'slug' => 'board', 'status' => 'draft', 'values' => [],
    ]);
    $this->owner = Fx::owner();
    $this->writer = app(StockWriter::class);
    $this->key = (string) $this->product->getKey();
});

afterEach(fn () => Fx::tearDown());

function stockWriterAudit(Closure $observer, Closure $work): mixed
{
    $dispatcher = AuditLog::getEventDispatcher();
    AuditLog::setEventDispatcher(clone $dispatcher);
    AuditLog::creating($observer);
    try {
        return $work();
    } finally {
        AuditLog::setEventDispatcher($dispatcher);
    }
}

it('requires a reviewed balance and records actor reason and shared stock history atomically', function (): void {
    $movement = $this->writer->adjust($this->key, '5', '8', 'Received three boards.');
    expect($this->product->fresh()->stock)->toBe('8')->and($movement['beforeStock'])->toBe('5')->and($movement['afterStock'])->toBe('8')
        ->and($movement['reason'])->toBe('Received three boards.')->and($movement['actorId'])->toBe((string) $this->owner->getKey())
        ->and($movement['quantity'])->toBeNull()->and($this->writer->history($this->key))->toBe([$movement])
        ->and(DB::table('audit_log')->where('action', 'commerce.stock_adjusted')->count())->toBe(1);
    app(Context::class)->setSite($this->sibling);
    expect($this->writer->current($this->key)['stock'])->toBe('8')->and($this->writer->history($this->key))->toBe([$movement]);
    expect(fn () => $this->writer->adjust($this->key, '5', '9', 'Stale screen.'))->toThrow(StockRefused::class)
        ->and($this->product->fresh()->stock)->toBe('8')->and(DB::table(StockWriter::TABLE)->count())->toBe(1);
});

it('supports exact nullable tracking transitions and signed-bigint balances without floats', function (): void {
    $this->writer->adjust($this->key, '5', null, 'No stock limit for this offer.');
    $this->writer->adjust($this->key, null, '9223372036854775807', 'Tracked opening balance.');
    expect($this->product->fresh()->stock)->toBe('9223372036854775807')
        ->and($this->writer->history($this->key)[0]['beforeStock'])->toBeNull();
});

it('refuses empty oversized control-character reasons and fake changes', function (string $reason): void {
    expect(fn () => $this->writer->adjust($this->key, '5', '6', $reason))->toThrow(StockRefused::class)
        ->and(DB::table(StockWriter::TABLE)->count())->toBe(0)->and($this->product->fresh()->stock)->toBe('5');
})->with(['', '   ', "New\nstock", str_repeat('x', 501), "bad\0reason", "bad\u{2028}reason"]);

it('refuses malformed balances keys and history bounds', function (): void {
    foreach (['01', '-1', '1.5', '1e2', '9223372036854775808'] as $invalid) {
        expect(fn () => $this->writer->adjust($this->key, '5', $invalid, 'Reason.'))->toThrow(StockRefused::class)
            ->and(fn () => $this->writer->adjust($this->key, $invalid, '6', 'Reason.'))->toThrow(StockRefused::class);
    }
    foreach (['0', '01', '-1', '1.5'] as $key) {
        expect(fn () => $this->writer->history($key))->toThrow(StockRefused::class);
    }
    expect(fn () => $this->writer->adjust($this->key, '5', '5', 'No change.'))->toThrow(StockRefused::class)
        ->and(fn () => $this->writer->history($this->key, 101))->toThrow(StockRefused::class)
        ->and(fn () => $this->writer->products(-1))->toThrow(StockRefused::class);
});

it('admits product editors through view and update grants only for coherent current-site listings', function (): void {
    $member = Fx::member();
    $role = Role::create(['handle' => 'stock_editor', 'name' => 'Stock editor']);
    $type = ProductType::current();
    $role->grant(Permissions::forEntryType($type->handle, 'view'));
    $role->assignTo($member->getKey());
    expect($this->writer->canAccess())->toBeFalse()->and($this->writer->canAdjust($this->key))->toBeFalse();
    $role->grant(Permissions::forEntryType($type->handle, 'update'));
    expect($this->writer->canAccess())->toBeTrue()->and($this->writer->products())->toBe([['reference' => $this->key, 'sku' => 'SHARED-BOARD', 'stock' => '5']]);
    $this->writer->adjust($this->key, '5', '4', 'One damaged board.');
    app(Context::class)->setSite($this->sibling);
    expect($this->writer->canAccess())->toBeTrue()->and($this->writer->canAdjust($this->key))->toBeFalse()->and($this->writer->products())->toBe([])
        ->and(fn () => $this->writer->history($this->key))->toThrow(StockRefused::class);
});

it('refuses anonymous missing-context disabled and stale-runtime stock operations', function (string $condition): void {
    match ($condition) {
        'anonymous' => Auth::guard('web')->logout(),
        'context' => app(Context::class)->forget(),
        'disabled' => DB::table('modules')->where('handle', 'kitsune/commerce')->update(['is_enabled' => false]),
        'stale' => DB::table('modules')->where('handle', 'kitsune/commerce')->update(['version' => '0.0.0']),
    };
    expect($this->writer->canAccess())->toBeFalse()->and($this->writer->canAdjust($this->key))->toBeFalse()
        ->and(fn () => $this->writer->adjust($this->key, '5', '6', 'Reason.'))->toThrow(StockRefused::class)
        ->and(fn () => $this->writer->history($this->key))->toThrow(StockRefused::class)
        ->and(DB::table(StockWriter::TABLE)->count())->toBe(0);
})->with(['anonymous', 'context', 'disabled', 'stale']);

it('keeps another org products and stock movements invisible to an attacker owner', function (): void {
    $this->writer->adjust($this->key, '5', '6', 'New stock.');
    $other = Org::create(['slug' => 'stock-foreign', 'name' => 'Foreign', 'currency' => 'USD']);
    app(Context::class)->setOrg($other);
    app(Context::class)->setSite(Site::create(['handle' => 'foreign', 'slug' => 'stock-foreign', 'name' => 'Foreign']));
    Fx::owner('foreign-stock@example.test');
    expect(StockMovement::query()->count())->toBe(0)->and($this->writer->canAdjust($this->key))->toBeFalse()
        ->and(fn () => $this->writer->history($this->key))->toThrow(StockRefused::class)
        ->and(fn () => $this->writer->adjust($this->key, '6', '100', 'Forged stock.'))->toThrow(StockRefused::class);
});

it('requires a declared host site admission and rechecks it after audit callbacks', function (): void {
    $actor = (new StockSiteUser)->newFromBuilder($this->owner->getAttributes());
    Auth::guard('web')->setUser($actor);
    Permissions::forget();
    expect(Permissions::isOwner($actor))->toBeTrue()->and($this->writer->canAdjust($this->key))->toBeFalse();
    DB::table('site_user')->insert(['site_id' => $this->site->id, 'user_id' => $actor->getKey()]);
    expect($this->writer->canAdjust($this->key))->toBeTrue();
    stockWriterAudit(function ($audit) use ($actor): void {
        if ($audit->action === 'commerce.stock_adjusted') {
            DB::table('site_user')->where('site_id', $this->site->id)->where('user_id', $actor->getKey())->delete();
        }
    }, function (): void {
        expect(fn () => $this->writer->adjust($this->key, '5', '6', 'Restock.'))->toThrow(StockRefused::class);
    });
    expect($this->product->fresh()->stock)->toBe('5')->and(DB::table(StockWriter::TABLE)->count())->toBe(0);
});

it('rolls back stock history and audit when callbacks change authority custody or the saved audit', function (string $mutation): void {
    stockWriterAudit(function ($audit) use ($mutation): mixed {
        if ($audit->action !== 'commerce.stock_adjusted') {
            return null;
        }

        return match ($mutation) {
            'cancel audit' => false,
            'audit identity' => $audit->action = 'forged.stock',
            'audit actor' => $audit->actor_id = 'another-staff-member',
            'stock' => DB::table('commerce_sellables')->where('id', $this->key)->update(['stock' => '100']),
            'movement' => DB::table(StockWriter::TABLE)->update(['reason' => 'Forged reason.']),
            'membership' => DB::table('org_user')->where('org_id', $this->org->getKey())->where('user_id', $this->owner->getKey())->delete(),
            'module' => DB::table('modules')->where('handle', 'kitsune/commerce')->update(['is_enabled' => false]),
        };
    }, function (): void {
        expect(fn () => $this->writer->adjust($this->key, '5', '6', 'Restock.'))->toThrow(StockRefused::class);
    });
    expect($this->product->fresh()->stock)->toBe('5')->and(DB::table(StockWriter::TABLE)->count())->toBe(0)
        ->and(DB::table('audit_log')->where('action', 'commerce.stock_adjusted')->count())->toBe(0);
})->with(['cancel audit', 'audit identity', 'audit actor', 'stock', 'movement', 'membership', 'module']);

it('prevents catalogue and direct-model stock changes including caller-synchronized original attributes', function (): void {
    expect(fn () => app(CatalogueWriter::class)->updateSellable($this->key, 'SHARED-BOARD', '2000', '6', 'USD'))->toThrow(RuntimeException::class);
    $row = $this->product->fresh();
    $row->stock = '6';
    $row->syncOriginal();
    $row->price_minor = '2100';
    expect(fn () => $row->save())->toThrow(RuntimeException::class)->and($this->product->fresh()->stock)->toBe('5');
    $this->product->fresh()->update(['price_minor' => '2100']);
    expect($this->product->fresh()->price_minor)->toBe('2100')->and(DB::table(StockWriter::TABLE)->count())->toBe(0);
});

it('decrements only a checked awarded line and repeats it without consuming stock twice', function (bool $untracked): void {
    if ($untracked) {
        $this->writer->adjust($this->key, '5', null, 'Untracked offer.');
    }
    $order = OrderFixture::plant($this->site, lineOverrides: ['sellable_ref' => $this->product->getKey(), 'quantity' => 3]);
    $line = (string) DB::table('commerce_order_lines')->where('order_id', $order->id)->value('id');
    $before = DB::table('commerce_sellables')->where('id', $this->key)->first();
    $work = fn () => $this->writer->recordSale(DB::connection(), $this->org->id, $this->site->id, $before, 2, $order->reference, $line);
    app(OrderLocks::class)->run($work);
    app(OrderLocks::class)->run($work);
    expect($this->product->fresh()->stock)->toBe($untracked ? null : '3')
        ->and(DB::table(StockWriter::TABLE)->where('kind', 'sale')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'commerce.stock_allocated')->count())->toBe(1);
    expect(fn () => app(OrderLocks::class)->run(fn () => $this->writer->recordSale(DB::connection(), $this->org->id, $this->site->id,
        $before, 1, $order->reference, $line)))->toThrow(StockRefused::class);
})->with([false, true]);

it('refuses overselling mismatched lines and stale sale snapshots', function (string $case): void {
    $order = OrderFixture::plant($this->site, lineOverrides: ['sellable_ref' => $this->product->getKey(), 'quantity' => 9]);
    $line = (string) DB::table('commerce_order_lines')->where('order_id', $order->id)->value('id');
    $before = DB::table('commerce_sellables')->where('id', $this->key)->first();
    $quantity = $case === 'oversell' ? 6 : 1;
    if ($case === 'stale') {
        $before->stock = '8';
    } elseif ($case === 'line') {
        DB::table('commerce_order_lines')->where('id', $line)->update(['site_id' => $this->sibling->id]);
    }
    expect(fn () => app(OrderLocks::class)->run(fn () => $this->writer->recordSale(DB::connection(), $this->org->id, $this->site->id,
        $before, $quantity, $order->reference, $line)))->toThrow(StockRefused::class)
        ->and($this->product->fresh()->stock)->toBe('5')->and(DB::table(StockWriter::TABLE)->count())->toBe(0);
})->with(['oversell', 'stale', 'line']);

it('rolls back a sale if its audit changes the frozen order line parent or audit actor', function (string $mutation): void {
    $order = OrderFixture::plant($this->site, lineOverrides: ['sellable_ref' => $this->product->getKey()]);
    $line = (string) DB::table('commerce_order_lines')->where('order_id', $order->id)->value('id');
    $before = DB::table('commerce_sellables')->where('id', $this->key)->first();
    stockWriterAudit(function ($audit) use ($mutation, $order, $line): void {
        if ($audit->action !== 'commerce.stock_allocated') {
            return;
        }
        match ($mutation) {
            'order' => DB::table('commerce_orders')->where('id', $order->id)->update(['contact_email' => 'replacement@example.test']),
            'line' => DB::table('commerce_order_lines')->where('id', $line)->update(['title' => 'Replaced item']),
            'actor' => $audit->actor_id = 'someone-else',
        };
    }, function () use ($before, $order, $line): void {
        expect(fn () => app(OrderLocks::class)->run(fn () => $this->writer->recordSale(DB::connection(), $this->org->id,
            $this->site->id, $before, 1, $order->reference, $line)))->toThrow(StockRefused::class);
    });
    expect($this->product->fresh()->stock)->toBe('5')->and(DB::table(StockWriter::TABLE)->count())->toBe(0)
        ->and(DB::table('audit_log')->where('action', 'commerce.stock_allocated')->count())->toBe(0);
})->with(['order', 'line', 'actor']);

it('retains history when its product is removed and refuses incoherent parent stamps and destructive rollback', function (): void {
    $this->writer->adjust($this->key, '5', '6', 'Restock.');
    $listing = $this->product->listings()->firstOrFail();
    app(CatalogueWriter::class)->removeListing($listing->id);
    app(CatalogueWriter::class)->removeSellable($this->key);
    expect(StockMovement::query()->count())->toBe(1);
    $migration = require __DIR__.'/../../packages/commerce/database/migrations/0001_01_01_000208_create_commerce_stock_movements.php';
    app(Context::class)->forget();
    expect(fn () => $migration->down())->toThrow(RuntimeException::class)->and(DB::table(StockWriter::TABLE)->count())->toBe(1);
    $foreign = Org::create(['slug' => 'stock-history-foreign', 'name' => 'Foreign', 'currency' => 'USD']);
    app(Context::class)->setOrg($foreign);
    $site = Site::create(['handle' => 'foreign', 'slug' => 'stock-history-foreign', 'name' => 'Foreign']);
    DB::table(StockWriter::TABLE)->update(['site_id' => $site->id]);
    app(Context::class)->setSite($this->site);
    expect(StockMovement::query()->count())->toBe(0)
        ->and(StockMovement::withoutScopeBecause('Probe malformed historical parent custody.', fn ($query) => $query->count()))->toBe(0);
});

it('declares an enforced org scope and refuses all ORM history mutations even through the scope hatch', function (): void {
    $this->writer->adjust($this->key, '5', '6', 'Restock.');
    expect((new ReflectionClass(StockMovement::class))->getAttributes(OrgScoped::class))->toHaveCount(1)
        ->and(class_uses_recursive(StockMovement::class))->toContain(EnforcesScope::class);
    $row = StockMovement::query()->sole();
    expect(fn () => $row->save())->toThrow(RuntimeException::class)->and(fn () => $row->saveQuietly())->toThrow(RuntimeException::class)
        ->and(fn () => $row->delete())->toThrow(RuntimeException::class)->and(fn () => $row->deleteQuietly())->toThrow(RuntimeException::class)
        ->and(fn () => $row->replicate()->save())->toThrow(RuntimeException::class);
    StockMovement::withoutScopeBecause('Probe the immutable stock ledger.', function ($query): void {
        foreach ([fn () => (clone $query)->update(['reason' => 'Replaced.']), fn () => (clone $query)->delete(),
            fn () => (clone $query)->insert([]), fn () => (clone $query)->upsert([], ['id']), fn () => (clone $query)->increment('id')] as $mutation) {
            expect($mutation)->toThrow(RuntimeException::class);
        }
    });
    app(Context::class)->forget();
    expect(StockMovement::query()->count())->toBe(0)
        ->and(StockMovement::withoutScopeBecause('Probe the independent stock custody fence.', fn ($query) => $query->count()))->toBe(0);
});
