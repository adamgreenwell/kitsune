<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Kitsune\Commerce\Blueprints\StorefrontBlueprint;
use Kitsune\Commerce\Catalogue\ProductType;
use Kitsune\Commerce\Catalogue\ProductWriter;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Database\EntryWriteReads;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Schema\RecordedRevisions;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;

/*
 * A real rival commits the Site and catalogue after the host pins its InnoDB snapshot. The writer must prove
 * current parents without changing the consistent-read scopes used by ordinary presentation queries.
 */

it('keeps product writes current and atomic beneath an older host snapshot', function (string $failure): void {
    $connection = DB::connection();
    if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('This two-connection regression measures MySQL/MariaDB repeatable-read semantics.');
    }
    Fx::boot();
    config(['app.key' => 'base64:'.base64_encode(str_repeat('w', 32)), 'cache.default' => 'array']);
    Notification::fake();
    $default = (string) config('database.default');
    config(['database.connections.product-current-rival' => config('database.connections.'.$default)]);
    $rival = DB::connection('product-current-rival');
    $orgId = null;
    $ownerId = null;
    // Register before the first committed fixture, so failure during setup cannot leak rows to the next engine test.
    $this->afterRollback(function () use ($rival, &$orgId, &$ownerId): void {
        if ($orgId !== null) {
            foreach (['commerce_listings', 'commerce_sellables'] as $table) {
                $rival->table($table)->where('org_id', $orgId)->delete();
            }
            $rival->table('orgs')->where('id', $orgId)->delete();
        }
        if ($ownerId !== null) {
            $rival->table('users')->where('id', $ownerId)->delete();
        }
        $rival->table('modules')->where('handle', 'kitsune/commerce')->delete();
        DB::purge('product-current-rival');
    });
    $now = now();
    $orgId = (int) $rival->table('orgs')->insertGetId(['slug' => 'product-current-rival', 'name' => 'Current Products',
        'currency' => 'USD', 'created_at' => $now, 'updated_at' => $now]);
    // Commit authority first, then prove any later revocation with current reads inside the product write.
    DB::setDefaultConnection('product-current-rival');
    try {
        app(Context::class)->setOrg(Org::query()->findOrFail($orgId));
        $owner = Fx::owner('current-product-owner@example.test');
        Module::create(['handle' => 'kitsune/commerce', 'version' => ModuleDiscovery::version('kitsune/commerce'), 'is_enabled' => true, 'installed_at' => now()]);
        ModuleKernel::flush();
        ModuleKernel::boot(app());
        $ownerId = $owner->getKey();
        $owner->setConnection($default);
    } finally {
        DB::setDefaultConnection($default);
    }

    // Replace only RefreshDatabase's empty wrapper to choose this transaction's actual isolation explicitly.
    $connection->rollBack();
    $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connection->beginTransaction();
    expect($connection->transactionLevel())->toBe(1)
        ->and($connection->table('sites')->where('org_id', $orgId)->count())->toBe(0);

    $siteId = (int) $rival->table('sites')->insertGetId(['org_id' => $orgId, 'handle' => 'current-main', 'slug' => 'product-current-main',
        'name' => 'Current main', 'locale' => 'en', 'base_url' => 'https://current-products.example.test',
        'canonical_host' => 'current-products.example.test', 'path_prefix' => '', 'created_at' => $now, 'updated_at' => $now]);
    // The rival applies the real adaptable schema and writes a product before the host can see either.
    DB::setDefaultConnection('product-current-rival');
    try {
        $committedOrg = Org::query()->findOrFail($orgId);
        app(Context::class)->setOrg($committedOrg);
        $committedSite = Site::query()->whereKey($siteId)->firstOrFail();
        $committedSite->setRelation('org', $committedOrg);
        app(Context::class)->setSite($committedSite);
        BlueprintApplier::apply(new StorefrontBlueprint);
        $product = app(ProductWriter::class)->createProduct(['sku' => 'CURRENT-PRODUCT', 'price_minor' => '2000', 'stock' => '5'], 'USD', [
            'title' => 'Current course', 'slug' => 'current-course', 'status' => 'published', 'values' => [],
        ], [], true);
        $listingId = $rival->table('commerce_listings')->where('org_id', $orgId)->where('site_id', $siteId)
            ->where('sellable_id', $product->getKey())->lockForUpdate()->value('id');
        $type = ProductType::current();
        foreach (['product_description', 'product_images'] as $handle) {
            $storage = FieldStorage::query()->where('org_id', $orgId)->where('handle', $handle)->firstOrFail();
            Field::query()->where('entry_type_id', $type->getKey())->where('field_storage_id', $storage->getKey())->update(['is_required' => true]);
            if ($handle === 'product_images') {
                $storage->update(['cardinality' => 1]);
                $imageStorageId = $storage->getKey();
            }
        }
        $permalink = FieldStorage::create(['org_id' => $orgId, 'handle' => 'product_permalink', 'type' => 'slug', 'pii_class' => 'none']);
        Field::create(['entry_type_id' => $type->getKey(), 'field_storage_id' => $permalink->getKey(), 'label' => 'Permalink']);
        $imageType = EntryType::query()->where('handle', 'product_image')->firstOrFail();
        $imageA = Entry::create(['entry_type_id' => $imageType->getKey(), 'site_id' => null, 'title' => 'Current image A'])->getKey();
        $imageB = Entry::create(['entry_type_id' => $imageType->getKey(), 'site_id' => null, 'title' => 'Current image B'])->getKey();
        match ($failure) {
            'success' => null,
            'module disabled' => $rival->table('modules')->where('handle', 'kitsune/commerce')->update(['is_enabled' => false]),
            'owner role revoked' => $rival->table('role_user')->where('user_id', $ownerId)->delete(),
            'membership revoked' => $rival->table('org_user')->where('user_id', $ownerId)->delete(),
            'type disabled' => EntryTypeAvailability::create(['entry_type_id' => $type->getKey(), 'scope_type' => 'site', 'scope_id' => $siteId, 'is_enabled' => false]),
            'malformed receipt' => $rival->table('blueprints')->where('org_id', $orgId)->where('handle', 'storefront')->update(['manifest' => null]),
            'malformed storage' => $rival->table('field_storage')->where('id', $imageStorageId)->update(['cardinality' => 0]),
            'moved entry' => $rival->table('entries')->where('id', $rival->table('commerce_listings')->where('id', $listingId)->value('entry_id'))->update(['site_id' => null]),
        };
        $product->setConnection($default);
    } finally {
        DB::setDefaultConnection($default);
    }
    $orgRow = $connection->table('orgs')->where('id', $orgId)->lockForUpdate()->first();
    $siteRow = $connection->table('sites')->where('org_id', $orgId)->where('id', $siteId)->lockForUpdate()->first();
    expect($orgRow)->not->toBeNull()->and($siteRow)->not->toBeNull()
        ->and($connection->table('sites')->where('org_id', $orgId)->count())->toBe(0);
    $org = (new Org)->newFromBuilder((array) $orgRow);
    $site = (new Site)->newFromBuilder((array) $siteRow);
    $site->setRelation('org', $org);
    app(Context::class)->setSite($site);
    // The pinned snapshot cannot see the rival's storage; the write's selected current witnesses must find it.
    expect(FieldStorage::query()->whereKey($imageStorageId)->first())->toBeNull();

    try {
        Auth::guard('web')->setUser($owner);
        Fx::forget();
        $writer = app(ProductWriter::class);
        if ($failure !== 'success') {
            $tables = ['commerce_sellables', 'commerce_listings', 'entries', 'entry_relations', 'entry_revisions', 'audit_log', 'field_storage', 'fields'];
            $state = static fn (): array => array_combine($tables, array_map(static fn ($table) => $connection->table($table)->orderBy('id')->lockForUpdate()->get()->all(), $tables));
            $before = $state();
            $message = match ($failure) {
                'module disabled', 'owner role revoked', 'membership revoked' => 'require an enabled commerce module',
                'type disabled', 'malformed receipt' => 'Apply and enable Storefront',
                'malformed storage' => 'Cardinality 0',
                'moved entry' => 'requires its own site',
            };
            expect(fn () => $writer->updateProduct($product->getKey(), ['sku' => 'CURRENT-PRODUCT', 'price_minor' => '2200', 'stock' => '999'], 'USD', [
                'title' => 'Refused', 'status' => 'published', 'values' => ['product_description' => '<p>Current</p>'],
            ], ['product_images' => [$imageA]], true))->toThrow(RuntimeException::class, $message)
                ->and($state())->toEqual($before)->and(EntryWriteReads::active())->toBeFalse()
                ->and(app(RecordedRevisions::class)->held())->toBe(0);

            return;
        }
        $writer->updateProduct($product->getKey(), ['sku' => 'CURRENT-PRODUCT', 'price_minor' => '2200', 'stock' => '999'], 'USD', [
            'title' => 'Edited rival course', 'slug' => 'current-course', 'status' => 'published', 'values' => ['product_description' => '<p>Current rival</p>'],
        ], ['product_images' => [$imageA]], false);
        expect((int) $connection->table('commerce_sellables')->where('id', $product->getKey())->lockForUpdate()->value('stock'))->toBe(5);
        $created = $writer->createProduct(['sku' => 'NEW-CURRENT', 'price_minor' => '3000', 'stock' => '7'], 'USD', [
            'title' => 'New course', 'slug' => 'new-course', 'status' => 'published', 'values' => ['product_description' => '<p>Current<script>alert(1)</script></p>'],
        ], ['product_images' => [$imageA]], true);
        $writer->updateProduct($created->getKey(), ['sku' => 'NEW-CURRENT', 'price_minor' => '3500', 'stock' => '999'], 'USD', [
            'title' => 'Edited course', 'slug' => 'edited-course', 'status' => 'published', 'values' => ['product_description' => '<p>Edited</p>'],
        ], ['product_images' => [$imageB]], false);
        $listing = $connection->table('commerce_listings')->where('sellable_id', $created->getKey())->lockForUpdate()->first();
        $entry = $connection->table('entries')->where('id', $listing->entry_id)->lockForUpdate()->first();
        expect($entry->title)->toBe('Edited course')->and($entry->type_handle)->toBe('product')
            ->and(json_decode($entry->promoted_by, true)['slug'])->toBe($permalink->getKey())
            ->and((int) $connection->table('commerce_sellables')->where('id', $created->getKey())->lockForUpdate()->value('stock'))->toBe(7)
            ->and($connection->table('entry_revisions')->where('entry_id', $entry->id)->lockForUpdate()->get())->toHaveCount(2)
            ->and($connection->table('entry_relations')->where('source_entry_id', $entry->id)->lockForUpdate()->pluck('target_entry_id')->map(fn ($id) => (int) $id)->all())->toBe([(int) $imageB])
            ->and(json_decode($connection->table('entry_revisions')->where('entry_id', $entry->id)->orderByDesc('id')->lockForUpdate()->value('relation_state'), true))->toBe([(string) $imageStorageId => [(int) $imageB]])
            ->and((bool) $connection->table('field_storage')->where('id', $imageStorageId)->lockForUpdate()->value('is_locked'))->toBeTrue()
            ->and(Field::query()->count())->toBe(0)
            // InnoDB exposes our own arming update; untouched Field/Entry/Site rows still use the old snapshot.
            ->and(FieldStorage::query()->whereKey($imageStorageId)->first()?->is_locked)->toBeTrue()
            ->and(Entry::query()->whereKey($imageB)->first())->toBeNull()
            ->and($connection->table('sites')->where('id', $siteId)->count())->toBe(0);
        $initial = $connection->table('entry_revisions')->where('entry_id', $entry->id)->orderBy('id')->lockForUpdate()->first();
        expect(json_decode($initial->values, true)['product_description'])->not->toContain('<script>')
            ->and(json_decode($initial->unsanitized_values, true)['product_description'])->toContain('<script>');

    } finally {
        Fx::tearDown();
    }
})->with(['success', 'module disabled', 'owner role revoked', 'membership revoked', 'type disabled', 'malformed receipt', 'malformed storage', 'moved entry']);
