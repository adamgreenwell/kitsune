<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Fields\Control;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Fields\Types\BaseFieldType;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

beforeEach(function (): void {
    $this->org = Org::create(['name' => 'Promoted writes', 'slug' => 'promoted-writes']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['org_id' => $this->org->id, 'handle' => 'main', 'slug' => 'promoted-main', 'name' => 'Main']);
    app(Context::class)->setSite($this->site);
    $this->type = EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
    $this->storage = FieldStorage::create(['org_id' => $this->org->id, 'handle' => 'permalink', 'type' => 'slug', 'pii_class' => 'none', 'cardinality' => 1]);
    Field::create(['entry_type_id' => $this->type->id, 'field_storage_id' => $this->storage->id, 'label' => 'Permalink']);
    $this->entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Before', 'slug' => 'Before Slug']);
});

afterEach(fn () => app(Context::class)->forget());

it('keeps canonical model saves normalized and records their promoted provenance', function (bool $quiet): void {
    $this->entry->slug = 'After Slug';
    $quiet ? $this->entry->saveQuietly() : $this->entry->save();

    expect($this->entry->fresh()->slug)->toBe('after-slug')
        ->and($this->entry->fresh()->promoted_by['slug'])->toBe($this->storage->id);
})->with([false, true]);

it('refuses an alternate promoted-column spelling on an ordinary or quiet model save', function (string $column, bool $quiet): void {
    $before = AuditLog::count();
    $this->entry->setAttribute($column, 'After Slug');

    expect(fn () => $quiet ? $this->entry->saveQuietly() : $this->entry->save())
        ->toThrow(RuntimeException::class, 'Set [slug] itself.')
        ->and(DB::table('entries')->where('id', $this->entry->id)->value('slug'))->toBe('before-slug')
        ->and(AuditLog::count())->toBe($before);
})->with(['SLUG', 'sLuG', 'entries.slug'])->with([false, true]);

it('refuses promoted data through every bulk or arithmetic assignment door', function (string $column, string $door): void {
    $before = AuditLog::count();
    $query = Entry::query()->whereKey($this->entry->id);
    $write = match ($door) {
        'update' => fn () => $query->update([$column => 'After Slug']),
        'loaded prototype' => fn () => $query->setModel($this->entry)->update([$column => 'After Slug']),
        'increment extra' => fn () => $query->increment('id', 0, [$column => 'After Slug']),
        'decrement extra' => fn () => $query->decrement('id', 0, [$column => 'After Slug']),
        'incrementEach extra' => fn () => $query->incrementEach(['id' => 0], [$column => 'After Slug']),
        'decrementEach extra' => fn () => $query->decrementEach(['id' => 0], [$column => 'After Slug']),
    };

    expect($write)->toThrow(RuntimeException::class, '[slug] cannot be written in bulk')
        ->and(DB::table('entries')->where('id', $this->entry->id)->value('slug'))->toBe('before-slug')
        ->and(AuditLog::count())->toBe($before);
})->with(['slug', 'SLUG', 'entries.slug'])->with(['update', 'loaded prototype', 'increment extra', 'decrement extra', 'incrementEach extra', 'decrementEach extra']);

it('still permits an audited bulk title update that writes no promoted field data', function (): void {
    expect(Entry::query()->whereKey($this->entry->id)->update(['title' => 'After']))->toBe(1)
        ->and($this->entry->fresh()->title)->toBe('After')
        ->and($this->entry->fresh()->slug)->toBe('before-slug')
        ->and(AuditLog::for($this->entry)->where('action', 'entry.updated')->count())->toBe(1);
});

it('does not choose one conversion for a bulk write spanning types with different field configurations', function (): void {
    $unconfigured = EntryType::create(['org_id' => $this->org->id, 'handle' => 'note', 'name' => 'Note', 'plural_name' => 'Notes']);
    $other = Entry::create(['entry_type_id' => $unconfigured->id, 'title' => 'No promoted field']);
    $before = AuditLog::count();

    expect(fn () => Entry::query()->whereKey([$this->entry->id, $other->id])->update(['slug' => 'After Slug']))
        ->toThrow(RuntimeException::class, '[slug] cannot be written in bulk')
        ->and($this->entry->fresh()->slug)->toBe('before-slug')
        ->and($other->fresh()->slug)->toBeNull()
        ->and(AuditLog::count())->toBe($before);
});

it('uses registered promoted columns rather than a hardcoded slug list', function (): void {
    app(FieldTypeRegistry::class)->register(new class extends BaseFieldType
    {
        public static function handle(): string
        {
            return 'promoted_title_fixture';
        }

        public static function label(): string
        {
            return 'Promoted title fixture';
        }

        public function control(): Control
        {
            return Control::Line;
        }

        public function strategy(): StorageStrategy
        {
            return StorageStrategy::Promoted;
        }

        public function promotedColumn(): string
        {
            return 'title';
        }
    });

    expect(fn () => Entry::query()->whereKey($this->entry->id)->update(['title' => 'After']))
        ->toThrow(RuntimeException::class, '[title] cannot be written in bulk')
        ->and($this->entry->fresh()->title)->toBe('Before');
});
