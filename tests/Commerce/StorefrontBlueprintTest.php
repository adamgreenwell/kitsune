<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Kitsune\Commerce\Blueprints\StorefrontBlueprint;
use Kitsune\Commerce\Catalogue\CatalogueWriter;
use Kitsune\Commerce\Catalogue\OrgCurrency;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Blueprints\BlueprintDefinition;
use Kitsune\Core\Blueprints\BlueprintRegistry;
use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;
use Kitsune\Core\Blueprints\Declarations\FieldDeclaration;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Media\MediaRefused;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Schema\SchemaManager;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Fx;
use Kitsune\Core\Tests\Fixtures\SchemaManagerStandIn;
use Kitsune\Core\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);
    SchemaManagerStandIn::install()->recordOnly();
    Storage::fake('public');
    Storage::fake(MediaDisks::PRIVATE);
    $this->org = Org::create(['slug' => 'storefront', 'name' => 'Storefront']);
    app(Context::class)->setOrg($this->org);
});

afterEach(function (): void {
    app()->forgetInstance(SchemaManager::class);
    app(Context::class)->forget();
});

function storefrontReceipt(bool $enabled): void
{
    Module::create([
        'handle' => 'kitsune/commerce', 'version' => ModuleDiscovery::version('kitsune/commerce'),
        'is_enabled' => $enabled, 'installed_at' => now(),
    ]);
    ModuleKernel::flush();
    ModuleKernel::boot(app());
}

function storefrontType(string $handle): EntryType
{
    return EntryType::query()->where('org_id', app(Context::class)->orgId())->where('handle', $handle)->firstOrFail();
}

function storefrontImage(EntryType $type, string $bytes, string $name): Entry
{
    $source = tempnam(sys_get_temp_dir(), 'kitsune-storefront-image-');

    try {
        file_put_contents($source, $bytes);

        return MediaLibrary::store($source, $name, $type);
    } finally {
        unlink($source);
    }
}

function storefrontPng(): string
{
    return "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\x0f\0\0\x01\x01\0\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82";
}

/** @return array<string, mixed> Stable declaration records, without installation-specific row ids or outcome prose. */
function storefrontManifest(): array
{
    $manifest = Blueprint::receiptFor('storefront')->manifest;
    unset($manifest['outcome']);

    foreach (['entry_types', 'roles'] as $key) {
        foreach ($manifest[$key] as $index => $row) {
            unset($manifest[$key][$index]['id']);
        }
    }

    return $manifest;
}

it('registers Storefront only through enabled commerce and provisions no content automatically', function (bool $enabled): void {
    storefrontReceipt($enabled);

    if ($enabled) {
        expect(app(BlueprintRegistry::class)->get('storefront'))->toBeInstanceOf(StorefrontBlueprint::class);
    } else {
        expect(app(BlueprintRegistry::class)->get('storefront'))->toBeNull();
    }

    expect(Blueprint::query()->count())->toBe(0)->and(EntryType::query()->count())->toBe(0);
})->with([false, true]);

it('creates adaptable org-owned content fields without money fields or extra roles', function (): void {
    BlueprintApplier::apply(new StorefrontBlueprint);
    $product = storefrontType('product');
    $image = storefrontType('product_image');
    $fields = $product->fields()->with('fieldStorage')->orderBy('ordering')->get();

    expect($product->name)->toBe('Product content')->and($product->plural_name)->toBe('Product content')
        ->and($product->is_media)->toBeFalse()->and($image->is_media)->toBeTrue()
        ->and($image->settings)->toBe(['accepts' => ['jpeg', 'png', 'gif', 'webp', 'avif', 'svg']])
        ->and($fields->pluck('fieldStorage.handle')->all())->toBe(['product_description', 'product_images'])
        ->and($fields->pluck('fieldStorage.type')->all())->toBe(['rich_text', 'relation'])
        ->and($fields->pluck('is_required')->all())->toBe([false, false])
        ->and($fields->last()->fieldStorage->settings)->toBe(['targetTypes' => ['product_image']])
        ->and($fields->last()->fieldStorage->cardinality)->toBe(-1)
        ->and(DB::table('roles')->count())->toBe(0)
        ->and(Blueprint::receiptFor('storefront')->applied_at)->not->toBeNull();
});

it('keeps the same version frozen and reapplication idempotent against its commerce-local baseline', function (): void {
    $release = require __DIR__.'/Fixtures/Released/Storefront100.php';
    $current = new StorefrontBlueprint;
    BlueprintApplier::apply($release);
    $before = storefrontManifest();
    $repeated = BlueprintApplier::apply($current);
    $other = Org::create(['slug' => 'fresh-storefront', 'name' => 'Fresh']);
    app(Context::class)->setOrg($other);
    BlueprintApplier::apply($current);

    expect($repeated['skipped'])->toBe(['already applied at this version'])
        ->and(storefrontManifest())->toEqual($before);
});

it('refuses both pre-existing product handles without adopting or rewriting their schema', function (string $handle, bool $media): void {
    $existing = EntryType::create([
        'org_id' => $this->org->getKey(), 'handle' => $handle, 'name' => 'Theirs', 'plural_name' => 'Theirs', 'is_media' => $media,
    ]);

    expect(fn () => BlueprintApplier::apply(new StorefrontBlueprint))->toThrow(RuntimeException::class, 'onCollision: fail');
    expect($existing->fresh()->name)->toBe('Theirs')
        ->and(EntryType::query()->count())->toBe(1)->and(FieldStorage::query()->count())->toBe(0)
        ->and(Blueprint::receiptFor('storefront')->applied_at)->toBeNull();
})->with([['product', false], ['product', true], ['product_image', false], ['product_image', true]]);

it('accepts images through normal media custody and rejects documents and unsanitized SVG', function (): void {
    BlueprintApplier::apply(new StorefrontBlueprint);
    $image = storefrontType('product_image');
    $stored = storefrontImage($image, storefrontPng(), 'course.png');

    expect($stored->site_id)->toBeNull()->and($stored->mediaFile->visibility)->toBe('private');
    expect(fn () => storefrontImage($image, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n", 'terms.pdf'))->toThrow(MediaRefused::class);
    expect(fn () => storefrontImage($image, '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>', 'course.svg'))->toThrow(MediaRefused::class);
});

it('lets each site describe and choose images for one shared twenty-dollar course independently', function (): void {
    BlueprintApplier::apply(new StorefrontBlueprint);
    storefrontReceipt(true);
    Fx::member();
    app(OrgCurrency::class)->set('USD');
    $writer = app(CatalogueWriter::class);
    $sellable = $writer->createSellable('COURSE', '2000', null, 'USD');
    $firstSite = Site::create(['handle' => 'first', 'slug' => 'storefront-first', 'name' => 'First']);
    $secondSite = Site::create(['handle' => 'second', 'slug' => 'storefront-second', 'name' => 'Second']);
    $image = storefrontImage(storefrontType('product_image'), storefrontPng(), 'course.png');
    $type = storefrontType('product');
    $storage = FieldStorage::query()->where('handle', 'product_images')->firstOrFail();
    app(Context::class)->setSite($firstSite);
    $first = Entry::create(['entry_type_id' => $type->getKey(), 'title' => 'Learn to surf', 'values' => ['product_description' => '<p>Beach basics.</p>']]);
    $first->related()->attach($image->getKey(), ['field_storage_id' => $storage->getKey()]);
    $writer->createListing($sellable->getKey(), $first->getKey());
    app(Context::class)->setSite($secondSite);
    $second = Entry::create(['entry_type_id' => $type->getKey(), 'title' => 'Ocean skills', 'values' => ['product_description' => '<p>Another site, another description.</p>']]);
    $writer->createListing($sellable->getKey(), $second->getKey());

    expect($first->getKey())->not->toBe($second->getKey())
        ->and($second->related()->count())->toBe(0)
        ->and(DB::table('entry_relations')->where('source_entry_id', $first->getKey())->count())->toBe(1)
        ->and(DB::table('commerce_listings')->where('sellable_id', $sellable->getKey())->count())->toBe(2)
        ->and($sellable->fresh()->price_minor)->toBe('2000')->and($sellable->fresh()->stock)->toBeNull();
});

it('refuses reversal while product entries remain, including the trash', function (): void {
    BlueprintApplier::apply(new StorefrontBlueprint);
    $site = Site::create(['handle' => 'main', 'slug' => 'storefront-main', 'name' => 'Main']);
    app(Context::class)->setSite($site);
    $entry = Entry::create(['entry_type_id' => storefrontType('product')->getKey(), 'title' => 'Draft course']);
    $entry->delete();

    expect(fn () => BlueprintApplier::reverse('storefront'))->toThrow(RuntimeException::class, 'in the trash');
    expect(Blueprint::receiptFor('storefront'))->not->toBeNull()->and(EntryType::query()->count())->toBe(2);
});

it('removes unused blueprint configuration while retaining an unlisted sellable and its org currency', function (): void {
    BlueprintApplier::apply(new StorefrontBlueprint);
    storefrontReceipt(true);
    Fx::member();
    app(OrgCurrency::class)->set('USD');
    $sellable = app(CatalogueWriter::class)->createSellable('COURSE', '2000', null, 'USD');
    BlueprintApplier::reverse('storefront');

    expect(Blueprint::receiptFor('storefront'))->toBeNull()->and(EntryType::query()->count())->toBe(0)
        ->and($sellable->fresh()->price_minor)->toBe('2000')->and($this->org->fresh()->currency)->toBe('USD');
});

it('merges an additive later version without overwriting adapted labels or image formats', function (): void {
    BlueprintApplier::apply(new StorefrontBlueprint);
    storefrontType('product')->update(['name' => 'Course', 'plural_name' => 'Courses']);
    storefrontType('product_image')->update(['settings' => ['accepts' => ['png']]]);
    $later = new class implements BlueprintDefinition
    {
        public function handle(): string
        {
            return 'storefront';
        }

        public function version(): string
        {
            return '1.1.0';
        }

        public function roles(): array
        {
            return [];
        }

        public function entryTypes(): array
        {
            $types = (new StorefrontBlueprint)->entryTypes();
            $product = $types[0];
            $types[0] = new EntryTypeDeclaration(
                handle: $product->handle, name: $product->name, pluralName: $product->pluralName,
                fields: [...$product->fields, new FieldDeclaration(handle: 'product_summary', type: 'textarea', label: 'Summary', piiClass: 'none')],
                icon: $product->icon, description: $product->description, ordering: $product->ordering,
            );

            return $types;
        }
    };
    BlueprintApplier::apply($later);

    expect(storefrontType('product')->name)->toBe('Course')
        ->and(storefrontType('product_image')->settings)->toBe(['accepts' => ['png']])
        ->and(storefrontType('product')->fields()->count())->toBe(3)
        ->and(Blueprint::receiptFor('storefront')->version)->toBe('1.1.0');
});
