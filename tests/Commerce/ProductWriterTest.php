<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Kitsune\Commerce\Blueprints\StorefrontBlueprint;
use Kitsune\Commerce\Catalogue\CatalogueWriter;
use Kitsune\Commerce\Catalogue\ProductType;
use Kitsune\Commerce\Catalogue\ProductWriter;
use Kitsune\Commerce\Models\Listing;
use Kitsune\Commerce\Models\Sellable;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Database\EntryWriteReads;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRelation;
use Kitsune\Core\Models\EntryRevision;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Schema\RecordedRevisions;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture;
use Kitsune\Core\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);
    $this->org = Org::create(['slug' => 'products', 'name' => 'Products', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'products-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);
    Module::create(['handle' => 'kitsune/commerce', 'version' => 'dev-main', 'is_enabled' => true, 'installed_at' => now()]);
    BlueprintApplier::apply(new StorefrontBlueprint);
    $this->type = ProductType::current();
    $this->writer = app(ProductWriter::class);
    $this->commerce = ['sku' => 'BOARD', 'price_minor' => '2000', 'stock' => '5'];
    $this->content = ['title' => 'Main board', 'slug' => 'board', 'status' => 'draft', 'values' => ['product_description' => '<p>First site</p>']];
});

afterEach(function (): void {
    Auth::guard('web')->logout();
    app(Context::class)->forget();
    Permissions::forget();
});

/** Persisted evidence, including audit and revision bytes rather than model instances. @return array<string, mixed> */
function productState(): array
{
    $tables = ['commerce_sellables', 'commerce_listings', 'entries', 'entry_relations', 'entry_revisions', 'audit_log'];

    return array_combine($tables, array_map(static fn (string $table): array => DB::table($table)->orderBy('id')->get()->all(), $tables));
}

/** The current site's product content. */
function productEntry(Sellable $sellable): Entry
{
    return $sellable->listings()->firstOrFail()->entry()->firstOrFail();
}

it('creates and edits one atomic product with one coherent content revision per submission', function (): void {
    $imageType = EntryType::query()->where('handle', 'product_image')->firstOrFail();
    $image = Entry::create(['entry_type_id' => $imageType->getKey(), 'site_id' => null, 'title' => 'Private image']);
    $image = $image->fresh();
    $row = $this->writer->createProduct($this->commerce, 'USD', $this->content, ['product_images' => [$image->getKey()]]);
    $entry = productEntry($row);
    $storage = FieldStorage::query()->where('org_id', $this->org->getKey())->where('handle', 'product_images')->firstOrFail();

    expect($row->price_minor)->toBe('2000')->and($row->stock)->toBe('5')
        ->and($entry->site_id)->toBe($this->site->getKey())->and($entry->title)->toBe('Main board')
        ->and($entry->revisions()->count())->toBe(1)
        ->and($entry->revisions()->first()->relation_state)->toBe([(string) $storage->getKey() => [$image->getKey()]])
        ->and($image->fresh()->getAttributes())->toEqual($image->getAttributes())
        ->and(app(RecordedRevisions::class)->held())->toBe(0);

    $this->writer->updateProduct($row->getKey(), [...$this->commerce, 'price_minor' => 2100], 'USD',
        [...$this->content, 'title' => 'Changed', 'status' => 'published'], ['product_images' => []], true);
    $entry = productEntry($row);
    expect($row->fresh()->price_minor)->toBe('2100')->and($entry->title)->toBe('Changed')
        ->and($entry->revisions()->count())->toBe(2)->and($entry->revisions()->latest('id')->first()->relation_state)->toBe([])
        ->and($row->listings()->first()->is_available)->toBeTrue()->and(app(RecordedRevisions::class)->held())->toBe(0);
});

it('shares commerce while creating distinct content and availability on a sibling site', function (): void {
    $row = $this->writer->createProduct($this->commerce, 'USD', $this->content);
    $first = productEntry($row);
    $other = Site::create(['handle' => 'second', 'slug' => 'products-second', 'name' => 'Second', 'locale' => 'fr']);
    app(Context::class)->setSite($other);
    $this->writer->updateProduct($row->getKey(), [...$this->commerce, 'price_minor' => '2500'], 'USD',
        ['title' => 'Une planche', 'slug' => 'planche', 'status' => 'published', 'values' => ['product_description' => '<p>Second site</p>']], [], true);
    $second = productEntry($row);

    expect($second->getKey())->not->toBe($first->getKey())->and($second->title)->toBe('Une planche')
        ->and(Sellable::query()->count())->toBe(1)->and($row->listings()->first()->is_available)->toBeTrue();
    app(Context::class)->setSite($this->site);
    expect(productEntry($row)->title)->toBe('Main board')->and($row->fresh()->price_minor)->toBe('2500')
        ->and($row->listings()->first()->is_available)->toBeFalse();
});

it('preserves the current stock when a stale or forged product edit submits another balance', function (mixed $submitted): void {
    $row = $this->writer->createProduct($this->commerce, 'USD', $this->content);
    // A committed inventory write happened after the owner's form was loaded. This raw fixture isolates that race.
    DB::table('commerce_sellables')->where('id', $row->getKey())->update(['stock' => '2']);
    $saved = $this->writer->updateProduct($row->getKey(), [...$this->commerce, 'stock' => $submitted, 'price_minor' => '2500'],
        'USD', [...$this->content, 'title' => 'Updated content']);

    expect($saved->stock)->toBe('2')->and($saved->fresh()->stock)->toBe('2')
        ->and($saved->price_minor)->toBe('2500')->and(productEntry($saved)->title)->toBe('Updated content');
})->with(['stale balance' => ['5'], 'forged balance' => ['99'], 'untracked' => [null], 'malformed' => [['unsafe']]]);

it('uses the receipt ID after an owner renames the product type', function (): void {
    $this->type->update(['handle' => 'boards', 'name' => 'Board']);
    expect(ProductType::current()->getKey())->toBe($this->type->getKey());
    $row = $this->writer->createProduct($this->commerce, 'USD', $this->content);
    expect(productEntry($row)->type_handle)->toBe('boards');
});

it('validates adapted promoted slugs against the prospective product type with absent or misleading ambient binding', function (bool $misleadingBinding): void {
    $article = EntryType::create(['handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
    Entry::create(['entry_type_id' => $article->getKey(), 'title' => 'Article', 'slug' => 'board']);
    $storage = FieldStorage::create(['org_id' => $this->org->getKey(), 'handle' => 'product_permalink', 'type' => 'slug', 'pii_class' => 'none']);
    Field::create(['entry_type_id' => $this->type->getKey(), 'field_storage_id' => $storage->getKey(), 'label' => 'Permalink']);

    if ($misleadingBinding) {
        app()->instance(EntryType::class, $article);
    } else {
        app()->forgetInstance(EntryType::class);
    }

    $row = $this->writer->createProduct($this->commerce, 'USD', $this->content);
    expect(productEntry($row)->slug)->toBe('board')->and(productEntry($row)->entry_type_id)->toBe($this->type->getKey());
    $before = productState();
    expect(fn () => $this->writer->createProduct([...$this->commerce, 'sku' => 'DUPLICATE'], 'USD', $this->content))
        ->toThrow(ValidationException::class)->and(productState())->toEqual($before);
})->with([false, true]);

it('refuses a final content identity changed by a later host observer', function (string $failure): void {
    $article = EntryType::create(['handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
    Entry::creating(static function (Entry $entry) use ($failure, $article): void {
        if ($failure === 'type') {
            $entry->entry_type_id = $article->getKey();
        } else {
            $entry->site_id = null;
        }
    });
    $before = productState();
    expect(fn () => $this->writer->createProduct($this->commerce, 'USD', $this->content))
        ->toThrow(RuntimeException::class)->and(productState())->toEqual($before)
        ->and(app(RecordedRevisions::class)->held())->toBe(0);
})->with(['type', 'shared']);

it('refuses incomplete malformed duplicate foreign and media product receipts', function (string $failure): void {
    $receipt = Blueprint::receiptFor('storefront');
    $manifest = $receipt->manifest;

    match ($failure) {
        'unfinished' => $receipt->applied_at = null,
        'wrong version' => $manifest['version'] = 'other',
        'not list' => $manifest['entry_types'] = ['product' => $manifest['entry_types'][0]],
        'float ID' => $manifest['entry_types'][0]['id'] = (float) $this->type->getKey(),
        'fraction ID' => $manifest['entry_types'][0]['id'] = $this->type->getKey().'.5',
        'duplicate product' => $manifest['entry_types'][] = $manifest['entry_types'][0],
        'media ID' => $manifest['entry_types'][0]['id'] = EntryType::query()->where('handle', 'product_image')->value('id'),
        'foreign ID' => $manifest['entry_types'][0]['id'] = (function (): int {
            $other = Org::create(['slug' => 'other', 'name' => 'Other']);
            app(Context::class)->setOrg($other);
            $type = EntryType::create(['handle' => 'product', 'name' => 'Product', 'plural_name' => 'Products']);
            app(Context::class)->setSite($this->site);

            return $type->getKey();
        })(),
    };
    $receipt->manifest = $manifest;
    $receipt->save();

    if ($failure === 'float ID') {
        // MySQL treats JSON integer N and double N.0 as equal on UPDATE and keeps the existing integer.
        // Clear it first so this fixture really stores a float; the resolver judges persisted data.
        DB::table('blueprints')->where('id', $receipt->getKey())->update(['manifest' => null]);
        DB::table('blueprints')->where('id', $receipt->getKey())->update(['manifest' => json_encode($manifest, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)]);
        expect(Blueprint::receiptFor('storefront')->manifest['entry_types'][0]['id'])->toBe((float) $this->type->getKey());
    }

    $before = productState();
    expect(ProductType::current())->toBeNull()
        ->and(fn () => $this->writer->createProduct($this->commerce, 'USD', $this->content))->toThrow(RuntimeException::class)
        ->and(productState())->toEqual($before);
})->with(['unfinished', 'wrong version', 'not list', 'float ID', 'fraction ID', 'duplicate product', 'media ID', 'foreign ID']);

it('refuses disabled module or product type and signed in nonowners', function (string $failure): void {
    match ($failure) {
        'module' => Module::query()->where('handle', 'kitsune/commerce')->firstOrFail()->update(['is_enabled' => false]),
        'type' => EntryTypeAvailability::create(['entry_type_id' => $this->type->getKey(), 'scope_type' => 'site', 'scope_id' => $this->site->getKey(), 'is_enabled' => false]),
        'member' => CredentialFixture::member(owner: false),
    };
    $before = productState();
    expect(fn () => $this->writer->createProduct($this->commerce, 'USD', $this->content))->toThrow(RuntimeException::class)
        ->and(productState())->toEqual($before);
})->with(['module', 'type', 'member']);

it('rejects foreign sellable IDs without creating site content', function (): void {
    $other = Org::create(['slug' => 'other', 'name' => 'Other', 'currency' => 'USD']);
    app(Context::class)->setOrg($other);
    $row = app(CatalogueWriter::class)->createSellable('FOREIGN', 20, null, 'USD');
    app(Context::class)->setSite($this->site);
    $before = productState();
    expect(fn () => $this->writer->updateProduct($row->getKey(), $this->commerce, 'USD', $this->content))->toThrow(RuntimeException::class)
        ->and(productState())->toEqual($before);
});

it('refuses a retained listing for another type or now hidden content without relinking', function (string $failure): void {
    $row = app(CatalogueWriter::class)->createSellable('BOARD', 20, null, 'USD');
    $otherType = EntryType::create(['handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
    $entry = Entry::create(['entry_type_id' => $failure === 'wrong type' ? $otherType->getKey() : $this->type->getKey(), 'title' => 'Retained']);
    app(CatalogueWriter::class)->createListing($row->getKey(), $entry->getKey());

    if ($failure === 'trashed') {
        $entry->delete();
    } elseif ($failure === 'shared') {
        DB::table('entries')->where('id', $entry->getKey())->update(['site_id' => null]);
    }

    $before = productState();
    expect(fn () => $this->writer->updateProduct($row->getKey(), $this->commerce, 'USD', $this->content))->toThrow(RuntimeException::class)
        ->and(productState())->toEqual($before);
})->with(['wrong type', 'trashed', 'shared']);

it('validates adapted required fields and relations for published availability while allowing incomplete drafts', function (): void {
    $description = $this->type->fields()->whereHas('fieldStorage', fn ($query) => $query->where('handle', 'product_description'))->firstOrFail();
    $description->update(['is_required' => true]);
    $images = $this->type->fields()->whereHas('fieldStorage', fn ($query) => $query->where('handle', 'product_images'))->firstOrFail();
    $images->update(['is_required' => true]);
    $draft = $this->writer->createProduct($this->commerce, 'USD', ['title' => 'Incomplete', 'values' => []]);
    $before = productState();
    expect(fn () => $this->writer->updateProduct($draft->getKey(), $this->commerce, 'USD', ['title' => 'Complete?', 'status' => 'published', 'values' => []], [], true))
        ->toThrow(ValidationException::class)->and(productState())->toEqual($before);
    expect(fn () => $this->writer->updateProduct($draft->getKey(), $this->commerce, 'USD', ['title' => 'Draft'], [], true))
        ->toThrow(Exception::class)->and(productState())->toEqual($before);
});

it('validates scalar field constraints and whole relation IDs without coercing them', function (string $failure): void {
    $storage = FieldStorage::create(['org_id' => $this->org->getKey(), 'handle' => 'short_code', 'type' => 'text', 'pii_class' => 'none', 'settings' => ['maxLength' => 3]]);
    Field::create(['entry_type_id' => $this->type->getKey(), 'field_storage_id' => $storage->getKey(), 'label' => 'Code']);
    $before = productState();
    $content = $failure === 'scalar' ? [...$this->content, 'values' => ['short_code' => 'too long']] : $this->content;
    $relations = match ($failure) {
        'float' => ['product_images' => [1.0]], 'fraction' => ['product_images' => ['1.5']],
        'unknown' => ['invented' => []], 'object' => ['product_images' => ['key' => 1]], default => [],
    };
    expect(fn () => $this->writer->createProduct($this->commerce, 'USD', $content, $relations))->toThrow(Exception::class)
        ->and(productState())->toEqual($before);
})->with(['scalar', 'float', 'fraction', 'unknown', 'object']);

it('rolls back content revisions relations shared fields and audits at every late failure', function (string $failure): void {
    $row = $this->writer->createProduct($this->commerce, 'USD', $this->content);
    $entry = productEntry($row);
    $before = productState();

    match ($failure) {
        'entry save' => Entry::updating(static fn () => false),
        'link save' => Listing::updating(static fn () => false),
        'content audit' => AuditLog::creating(static fn (AuditLog $audit) => $audit->action === 'commerce.product_content_saved' ? false : null),
        'availability audit' => AuditLog::creating(static function (AuditLog $audit): void {
            if ($audit->action === 'commerce.listing_availability_changed') {
                throw new RuntimeException('Fixture audit failed.');
            }
        }),
        'relation' => EntryRelation::creating(static function (): void {
            throw new RuntimeException('Fixture relation failed.');
        }),
    };
    $relations = [];

    if ($failure === 'relation') {
        $target = Entry::create(['entry_type_id' => EntryType::query()->where('handle', 'product_image')->value('id'), 'site_id' => null, 'title' => 'Image']);
        $relations = ['product_images' => [$target->getKey()]];
        $before = productState();
    }

    expect(fn () => $this->writer->updateProduct($row->getKey(), [...$this->commerce, 'price_minor' => '2500'], 'USD',
        [...$this->content, 'title' => 'After', 'status' => 'published'], $relations, true))
        ->toThrow(RuntimeException::class)->and(productState())->toEqual($before)
        ->and(app(RecordedRevisions::class)->held())->toBe(0)->and($entry->fresh()->title)->toBe('Main board');
})->with(['entry save', 'link save', 'content audit', 'availability audit', 'relation']);

it('rolls back failed creates and safely closes the revision window before a later write', function (): void {
    Listing::creating(static fn () => false);
    $before = productState();
    expect(fn () => $this->writer->createProduct($this->commerce, 'USD', $this->content))->toThrow(RuntimeException::class)
        ->and(productState())->toEqual($before)->and(app(RecordedRevisions::class)->held())->toBe(0);
    $entry = Entry::create(['entry_type_id' => $this->type->getKey(), 'title' => 'Unrelated']);
    expect($entry->revisions()->count())->toBe(1)->and(app(RecordedRevisions::class)->held())->toBe(0);
});

it('rolls back a cancelled content revision rather than returning an unversioned product', function (string $failure): void {
    $row = $failure === 'update' ? $this->writer->createProduct($this->commerce, 'USD', $this->content) : null;
    EntryRevision::creating(static fn () => false);
    $before = productState();
    $level = DB::connection()->transactionLevel();
    $write = $row === null
        ? fn () => $this->writer->createProduct($this->commerce, 'USD', $this->content)
        : fn () => $this->writer->updateProduct($row->getKey(), $this->commerce, 'USD', [...$this->content, 'title' => 'After']);

    expect($write)->toThrow(RuntimeException::class, 'revision')
        ->and(productState())->toEqual($before)->and(DB::connection()->transactionLevel())->toBe($level)
        ->and(app(RecordedRevisions::class)->held())->toBe(0);
})->with(['create', 'update']);

it('rolls back a cancelled revision reconciliation after writing the relations', function (): void {
    $row = $this->writer->createProduct($this->commerce, 'USD', $this->content);
    $image = Entry::create(['entry_type_id' => EntryType::query()->where('handle', 'product_image')->value('id'), 'site_id' => null, 'title' => 'Image']);
    EntryRevision::updating(static fn () => false);
    $before = productState();
    expect(fn () => $this->writer->updateProduct($row->getKey(), $this->commerce, 'USD',
        [...$this->content, 'title' => 'After'], ['product_images' => [$image->getKey()]]))
        ->toThrow(RuntimeException::class, 'revision')->and(productState())->toEqual($before)
        ->and(app(RecordedRevisions::class)->held())->toBe(0);
});

it('checks required fields after conversion before making content available', function (): void {
    $field = $this->type->fields()->whereHas('fieldStorage', fn ($query) => $query->where('handle', 'product_description'))->firstOrFail();
    $field->update(['is_required' => true]);
    $before = productState();
    expect(fn () => $this->writer->createProduct($this->commerce, 'USD',
        [...$this->content, 'status' => 'published', 'values' => ['product_description' => '<script></script>']], [], true))
        ->toThrow(ValidationException::class)->and(productState())->toEqual($before)
        ->and(app(RecordedRevisions::class)->held())->toBe(0);
});

it('refuses foreign and sibling site relations and targets outside the adapted field types', function (string $failure): void {
    $type = EntryType::query()->where('handle', $failure === 'wrong type' ? 'product' : 'product_image')->firstOrFail();

    if ($failure === 'sibling site') {
        $site = Site::create(['handle' => 'second', 'slug' => 'products-second', 'name' => 'Second', 'locale' => 'en']);
        app(Context::class)->setSite($site);
    } elseif ($failure === 'foreign org') {
        $org = Org::create(['slug' => 'other', 'name' => 'Other']);
        app(Context::class)->setOrg($org);
        $type = EntryType::create(['handle' => 'product_image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);
    }

    $target = Entry::create(['entry_type_id' => $type->getKey(), 'title' => 'Unavailable image']);
    app(Context::class)->setSite($this->site);
    $before = productState();
    expect(fn () => $this->writer->createProduct($this->commerce, 'USD', $this->content, ['product_images' => [$target->getKey()]]))
        ->toThrow(ValidationException::class)->and(productState())->toEqual($before);
})->with(['wrong type', 'sibling site', 'foreign org']);

it('refuses an adapted schema planted against another orgs field storage', function (): void {
    $other = Org::create(['slug' => 'other', 'name' => 'Other']);
    $foreign = FieldStorage::create(['org_id' => $other->getKey(), 'handle' => 'foreign', 'type' => 'text', 'pii_class' => 'none']);
    $field = $this->type->fields()->firstOrFail();
    DB::table('fields')->where('id', $field->getKey())->update(['field_storage_id' => $foreign->getKey()]);
    $before = productState();
    expect(fn () => $this->writer->createProduct($this->commerce, 'USD', $this->content))
        ->toThrow(RuntimeException::class, 'another org')->and(productState())->toEqual($before);
});

it('requires an actual boolean for site availability', function (mixed $available): void {
    $before = productState();
    expect(fn () => $this->writer->createProduct($this->commerce, 'USD', $this->content, [], $available))
        ->toThrow(RuntimeException::class, 'boolean')->and(productState())->toEqual($before);
})->with([null, 0, 1, 1.0, '0', '1', 'true', [[]]]);

it('rejects duplicate SKUs stale quotes invalid money and payload ownership keys without partial records', function (string $failure): void {
    $this->writer->createProduct($this->commerce, 'USD', $this->content);
    $before = productState();
    $commerce = match ($failure) {
        'float money' => [...$this->commerce, 'sku' => 'SECOND', 'price_minor' => 10.5],
        'ownership' => [...$this->commerce, 'sku' => 'SECOND', 'org_id' => 99],
        default => $this->commerce,
    };
    expect(fn () => $this->writer->createProduct($commerce, $failure === 'stale quote' ? 'JPY' : 'USD', [...$this->content, 'slug' => 'second']))
        ->toThrow(Exception::class)->and(productState())->toEqual($before);
})->with(['duplicate', 'stale quote', 'float money', 'ownership']);

it('discards all revision notes and closes a failed create window without needing its key', function (): void {
    $register = app(RecordedRevisions::class);
    $register->open();
    $register->note(123, 456);
    $register->discard();
    $register->note(789, 1011);
    expect($register->held())->toBe(0)->and($register->take(123))->toBeNull();
});

it('rejects final callback corruption and restores the entire atomic product write', function (string $operation, string $failure): void {
    $owner = CredentialFixture::member();
    $target = Entry::create(['entry_type_id' => EntryType::query()->where('handle', 'product_image')->value('id'), 'site_id' => null, 'title' => 'Image']);
    $row = $operation === 'update' ? $this->writer->createProduct($this->commerce, 'USD', $this->content) : null;
    $tables = ['commerce_sellables', 'commerce_listings', 'entries', 'entry_relations', 'entry_revisions', 'audit_log', 'fields', 'field_storage', 'blueprints', 'entry_type_availability', 'modules', 'org_user', 'role_user', 'orgs'];
    $state = static fn (): array => array_combine($tables, array_map(static fn ($table) => DB::table($table)->orderBy(in_array($table, ['org_user', 'role_user'], true) ? 'user_id' : 'id')->get()->all(), $tables));
    $before = $state();
    $mutations = 0;
    AuditLog::created(function (AuditLog $audit) use ($failure, $owner, &$mutations): void {
        if (! in_array($audit->action, ['commerce.listing_created', 'commerce.listing_availability_changed'], true)) {
            return;
        }
        $listing = DB::table('commerce_listings')->where('id', $audit->target_id)->first();
        $mutations++;
        match ($failure) {
            'field' => DB::table('fields')->where('entry_type_id', $this->type->getKey())->update(['is_required' => true]),
            'storage' => DB::table('field_storage')->where('org_id', $this->org->getKey())->where('handle', 'product_description')->update(['handle' => 'tampered']),
            'receipt' => DB::table('blueprints')->where('handle', 'storefront')->update(['manifest' => null]),
            'disabled' => DB::table('entry_type_availability')->insert(['entry_type_id' => $this->type->getKey(), 'scope_type' => 'site', 'scope_id' => $this->site->getKey(), 'is_enabled' => false]),
            'storage unlocked' => DB::table('field_storage')->where('org_id', $this->org->getKey())->update(['is_locked' => false]),
            'stock' => DB::table('commerce_sellables')->where('id', $listing->sellable_id)->update(['stock' => 999]),
            'entry' => DB::table('entries')->where('id', $listing->entry_id)->update(['title' => 'Tampered']),
            'revision' => DB::table('entry_revisions')->where('entry_id', $listing->entry_id)->update(['title' => 'Tampered']),
            'relations' => DB::table('entry_relations')->where('source_entry_id', $listing->entry_id)->delete(),
            'audit' => DB::table('audit_log')->where('action', 'commerce.product_content_saved')->delete(),
            'module' => DB::table('modules')->where('handle', 'kitsune/commerce')->update(['is_enabled' => false]),
            'membership' => DB::table('org_user')->where('user_id', $owner->getKey())->delete(),
            'role' => DB::table('role_user')->where('user_id', $owner->getKey())->delete(),
            'actor' => Auth::guard('web')->forgetUser(),
            'currency' => DB::table('orgs')->where('id', $this->org->getKey())->update(['currency' => 'EUR']),
        };
    });
    $write = $row === null
        ? fn () => $this->writer->createProduct($this->commerce, 'USD', [...$this->content, 'status' => 'published'], ['product_images' => [$target->getKey()]], true)
        : fn () => $this->writer->updateProduct($row->getKey(), [...$this->commerce, 'price_minor' => '2300'], 'USD', [...$this->content, 'title' => 'After', 'status' => 'published'], ['product_images' => [$target->getKey()]], true);
    expect($write)->toThrow(RuntimeException::class)->and($mutations)->toBe(1)->and($state())->toEqual($before)
        ->and(EntryWriteReads::active())->toBeFalse()->and(app(RecordedRevisions::class)->held())->toBe(0);
})->with(['create', 'update'])->with(['field', 'storage', 'receipt', 'disabled', 'stock', 'entry', 'revision', 'relations', 'audit', 'module', 'membership', 'role', 'actor', 'currency', 'storage unlocked']);

it('preserves an already earned storage lock when this product carries no field data', function (): void {
    $this->writer->createProduct([...$this->commerce, 'sku' => 'HOLDER'], 'USD', $this->content);
    $before = [productState(), DB::table('field_storage')->orderBy('id')->get()->all()];
    AuditLog::created(function (AuditLog $audit): void {
        if ($audit->action === 'commerce.listing_created') {
            DB::table('field_storage')->where('org_id', $this->org->getKey())->update(['is_locked' => false]);
        }
    });
    expect(fn () => $this->writer->createProduct($this->commerce, 'USD', [...$this->content, 'slug' => 'empty', 'values' => []]))
        ->toThrow(RuntimeException::class)->and([productState(), DB::table('field_storage')->orderBy('id')->get()->all()])->toEqual($before);
});
