<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRevision;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

beforeEach(function (): void {
    $this->orgA = Org::create(['name' => 'Golfdom', 'slug' => 'golfdom']);
    $this->orgB = Org::create(['name' => 'Rival', 'slug' => 'rival']);

    app(Context::class)->setOrg($this->orgA);
    $this->siteA = Site::create(['org_id' => $this->orgA->id, 'handle' => 'a', 'slug' => 'a', 'name' => 'A']);

    app(Context::class)->setOrg($this->orgB);
    $this->siteB = Site::create(['org_id' => $this->orgB->id, 'handle' => 'b', 'slug' => 'b', 'name' => 'B']);

    $this->article = EntryType::create([
        'org_id' => $this->orgA->id, 'handle' => 'article',
        'name' => 'Article', 'plural_name' => 'Articles',
    ]);

    app(Context::class)->setSite($this->siteA);
});

afterEach(fn () => app(Context::class)->forget());

describe('one model, many types (ADR-010)', function (): void {
    it('denormalises type_handle from the type it points at', function (): void {
        $entry = Entry::create(['entry_type_id' => $this->article->id, 'title' => 'Hello']);

        expect($entry->type_handle)->toBe('article');
    });

    it('filters by type without a separate model or table', function (): void {
        $product = EntryType::create(['org_id' => $this->orgA->id, 'handle' => 'product', 'name' => 'Product', 'plural_name' => 'Products']);

        Entry::create(['entry_type_id' => $this->article->id, 'title' => 'An article']);
        Entry::create(['entry_type_id' => $product->id, 'title' => 'A product']);

        expect(Entry::ofType('article')->count())->toBe(1);
        expect(Entry::ofType('product')->count())->toBe(1);
        // Cross-type queries are trivial, which is the upside of one table.
        expect(Entry::count())->toBe(2);
    });
});

describe('entries obey the scope boundary', function (): void {
    it('does not leak entries to another org', function (): void {
        Entry::create(['entry_type_id' => $this->article->id, 'title' => 'Confidential']);

        app(Context::class)->setSite($this->siteB);

        expect(Entry::count())->toBe(0);
    });

    it('shares org-shared entries within the org but not beyond it', function (): void {
        Entry::create(['entry_type_id' => $this->article->id, 'title' => 'Shared asset', 'site_id' => null]);

        // Same org, no site row of its own: still visible.
        expect(Entry::where('title', 'Shared asset')->exists())->toBeTrue();

        app(Context::class)->setSite($this->siteB);
        expect(Entry::where('title', 'Shared asset')->exists())->toBeFalse();
    });
});

describe('relations are a real table (ADR-015)', function (): void {
    it('answers "what references this?" without scanning JSON', function (): void {
        $asset = Entry::create(['entry_type_id' => $this->article->id, 'title' => 'Photo']);
        $a = Entry::create(['entry_type_id' => $this->article->id, 'title' => 'Article A']);
        $b = Entry::create(['entry_type_id' => $this->article->id, 'title' => 'Article B']);

        $a->related()->attach($asset->id, ['org_id' => $this->orgA->id]);
        $b->related()->attach($asset->id, ['org_id' => $this->orgA->id]);

        // Deleting an image should be able to name the articles using it.
        expect($asset->referencedBy()->pluck('title')->sort()->values()->all())
            ->toBe(['Article A', 'Article B']);
    });
});

describe('field storage fails closed on classification (ADR-020)', function (): void {
    it('refuses to save a field with no pii_class', function (): void {
        expect(fn () => FieldStorage::create([
            'org_id' => $this->orgA->id, 'handle' => 'body', 'type' => 'rich_text',
        ]))->toThrow(RuntimeException::class, 'has no pii_class');
    });

    it('refuses an unknown classification', function (): void {
        expect(fn () => FieldStorage::create([
            'org_id' => $this->orgA->id, 'handle' => 'body', 'type' => 'text', 'pii_class' => 'maybe',
        ]))->toThrow(RuntimeException::class, 'Unknown pii_class');
    });

    it('saves once classified', function (): void {
        $storage = FieldStorage::create([
            'org_id' => $this->orgA->id, 'handle' => 'price', 'type' => 'number', 'pii_class' => 'none',
        ]);

        expect($storage->exists)->toBeTrue();
    });
});

describe('storage locks once data exists (ADR-006)', function (): void {
    it('refuses to change the shape of a locked field', function (): void {
        $storage = FieldStorage::create([
            'org_id' => $this->orgA->id, 'handle' => 'price', 'type' => 'number',
            'pii_class' => 'none', 'is_locked' => true,
        ]);

        $storage->type = 'text';

        expect(fn () => $storage->save())->toThrow(RuntimeException::class, 'is locked');
    });

    it('still allows toggling the index, which changes no data', function (): void {
        $storage = FieldStorage::create([
            'org_id' => $this->orgA->id, 'handle' => 'price', 'type' => 'number',
            'pii_class' => 'none', 'is_locked' => true,
        ]);

        $storage->is_indexed = true;

        // Expensive, not unsafe (field-types.md §8).
        expect(fn () => $storage->save())->not->toThrow(RuntimeException::class);
    });

    it('knows a multi-value field cannot be indexed by projection', function (): void {
        $storage = FieldStorage::create([
            'org_id' => $this->orgA->id, 'handle' => 'tags', 'type' => 'text',
            'pii_class' => 'none', 'cardinality' => -1,
        ]);

        expect($storage->isIndexable())->toBeFalse();
    });
});

describe('reserved handles (ADR-012)', function (): void {
    it('flags a handle that would collide with a route segment', function (): void {
        $type = new EntryType(['handle' => 'create']);

        expect($type->isReservedHandle())->toBeTrue();
    });

    it('accepts an ordinary handle', function (): void {
        expect((new EntryType(['handle' => 'article']))->isReservedHandle())->toBeFalse();
    });
});

describe('revisions are redactable, not immutable (ADR-020)', function (): void {
    it('replaces a value in place without destroying the record', function (): void {
        $entry = Entry::create(['entry_type_id' => $this->article->id, 'title' => 'Profile']);
        $revision = EntryRevision::create([
            'entry_id' => $entry->id,
            'values' => ['name' => 'Alex Doe', 'city' => 'Berlin'],
        ]);

        $revision->redact('name');

        // Compared order-insensitively on purpose: MySQL does not preserve
        // JSON object key order, while PostgreSQL and SQLite do. Nothing in
        // Kitsune may depend on that order — field order comes from the
        // `fields.ordering` column, never from the shape of the JSON.
        $values = $revision->fresh()->values;
        ksort($values);

        expect($values)->toBe(['city' => 'Berlin', 'name' => null]);
    });
});

describe('global system types', function (): void {
    it('are available to every org', function (): void {
        EntryType::create(['org_id' => null, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images']);

        app(Context::class)->setOrg($this->orgB);

        $handles = EntryType::availableToCurrentOrg()->pluck('handle')->all();

        expect($handles)->toContain('image')->not->toContain('article');
    });
});
