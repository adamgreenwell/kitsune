<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Kitsune\Commerce\Catalogue\CatalogueWriter;
use Kitsune\Commerce\Catalogue\OrgCurrency;
use Kitsune\Commerce\Models\Listing;
use Kitsune\Commerce\Models\Sellable;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Fx;

it('retains catalogue data on uninstall refusal and removes products without deleting site content', function (): void {
    Fx::boot();
    try {
        $org = Fx::org('catalogue-lifecycle');
        $site = Site::create(['handle' => 'main', 'slug' => 'catalogue-lifecycle-main', 'name' => 'Main', 'locale' => 'en']);
        app(Context::class)->setSite($site);
        Fx::member();
        $type = EntryType::create(['org_id' => $org->id, 'handle' => 'course', 'name' => 'Course', 'plural_name' => 'Courses']);
        $entry = Entry::create(['entry_type_id' => $type->id, 'title' => 'A site-owned course', 'status' => 'draft']);
        ModuleKernel::flush();
        ModuleLifecycle::install(app(), 'kitsune/commerce');
        ModuleLifecycle::enable(app(), 'kitsune/commerce');
        app(OrgCurrency::class)->set('USD');
        $writer = app(CatalogueWriter::class);
        $sellable = $writer->createSellable('COURSE', '2000', null, 'USD');
        $listing = $writer->createListing($sellable->id, $entry->id);
        ModuleLifecycle::disable(app(), 'kitsune/commerce');

        expect(fn () => ModuleLifecycle::uninstall(app(), 'kitsune/commerce'))
            ->toThrow(RuntimeException::class, 'still holds data');
        expect(Schema::hasTable('commerce_sellables'))->toBeTrue()
            ->and(Schema::hasTable('commerce_listings'))->toBeTrue()
            ->and(Module::query()->where('handle', 'kitsune/commerce')->exists())->toBeTrue();

        ModuleLifecycle::enable(app(), 'kitsune/commerce');
        $writer->removeListing($listing->id);
        $writer->removeSellable($sellable->id);
        ModuleLifecycle::disable(app(), 'kitsune/commerce');
        // No teardown DDL inside RefreshDatabase: MySQL would commit the host rows under the wrapper.
        expect(Sellable::query()->count())->toBe(0)
            ->and(Listing::query()->count())->toBe(0)
            ->and(Entry::query()->whereKey($entry->id)->value('title'))->toBe('A site-owned course')
            ->and($org->fresh()->currency)->toBe('USD');
    } finally {
        Fx::tearDown();
    }
});
