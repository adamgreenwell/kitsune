<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Fields\Control;
use Kitsune\Core\Fields\FieldConfig;
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

describe('a promoted column with an Eloquent date cast', function (): void {
    beforeEach(function (): void {
        $this->dateType = new class extends BaseFieldType
        {
            public ?Closure $duringConversion = null;

            public static function handle(): string
            {
                return 'promoted_date_fixture';
            }

            public static function label(): string
            {
                return 'Promoted date fixture';
            }

            public function control(): Control
            {
                return Control::DateTime;
            }

            public function strategy(): StorageStrategy
            {
                return StorageStrategy::Promoted;
            }

            public function promotedColumn(): string
            {
                return 'published_at';
            }

            protected function castToStorage(mixed $input, FieldConfig $config): mixed
            {
                if ($this->duringConversion !== null) {
                    ($this->duringConversion)($config);
                }

                return $input;
            }
        };
        app(FieldTypeRegistry::class)->register($this->dateType);
        $storage = FieldStorage::create(['org_id' => $this->org->id, 'handle' => 'release_date', 'type' => 'promoted_date_fixture', 'pii_class' => 'none', 'cardinality' => 1]);
        Field::create(['entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id, 'label' => 'Release date']);

        // Seed the populated case below Eloquent so the setup does not exercise the save under test.
        DB::table('entries')->where('id', $this->entry->id)->update(['published_at' => '2026-01-02 03:04:05']);
        $this->entry->refresh();
    });

    it('preserves a populated date while saving a date, another promoted field or an ordinary field', function (string $door): void {
        $quiet = str_starts_with($door, 'quiet_');
        $operation = str_replace('quiet_', '', $door);

        if ($operation === 'create') {
            $attributes = ['org_id' => $this->org->id, 'site_id' => $this->site->id, 'entry_type_id' => $this->type->id,
                'title' => 'Dated', 'slug' => 'Dated Slug', 'published_at' => '2026-01-02 03:04:05'];
            $entry = $quiet ? Entry::createQuietly($attributes) : Entry::create($attributes);
        } else {
            $entry = $this->entry;
            $entry->setAttribute(match ($operation) {
                'date' => 'published_at',
                'slug' => 'slug',
                'title' => 'title',
            }, match ($operation) {
                'date' => '2026-02-03 04:05:06',
                'slug' => 'After Slug',
                'title' => 'After',
            });
            $quiet ? $entry->saveQuietly() : $entry->save();
        }

        expect($entry->fresh()->published_at->format('Y-m-d H:i:s'))
            ->toBe($operation === 'date' ? '2026-02-03 04:05:06' : '2026-01-02 03:04:05')
            ->and($entry->fresh()->slug)->toBe(match ($operation) {
                'create' => 'dated-slug',
                'slug' => 'after-slug',
                default => 'before-slug',
            })
            ->and(AuditLog::for($entry)->latest('id')->first()->action)
            ->toBe($operation === 'create' ? 'entry.created' : 'entry.updated');
    })->with(['create', 'quiet_create', 'date', 'quiet_date', 'slug', 'quiet_slug', 'title', 'quiet_title']);

    it('also preserves an immutable date cast through an ordinary or quiet save', function (bool $quiet): void {
        $entry = $this->entry->mergeCasts(['published_at' => 'immutable_datetime']);
        expect($entry->published_at)->toBeInstanceOf(CarbonImmutable::class);
        $entry->published_at = CarbonImmutable::parse('2026-02-03 04:05:06', 'UTC');

        $quiet ? $entry->saveQuietly() : $entry->save();

        expect($entry->published_at)->toBeInstanceOf(CarbonImmutable::class)
            ->and(DB::table('entries')->where('id', $entry->id)->value('published_at'))->toBe('2026-02-03 04:05:06')
            ->and(AuditLog::for($entry)->latest('id')->first()->action)->toBe('entry.updated');
    })->with([false, true]);

    it('refuses a date changed after its save proof was recorded', function (): void {
        $this->dateType->duringConversion = static function (FieldConfig $config): void {
            $config->record->published_at = '2026-03-04 05:06:07';
        };
        $before = AuditLog::count();
        $this->entry->forceFill(['slug' => 'After Slug', 'published_at' => '2026-02-03 04:05:06']);

        expect(fn () => $this->entry->save())->toThrow(RuntimeException::class, 'cannot be written in bulk')
            ->and(DB::table('entries')->where('id', $this->entry->id)->value('published_at'))->toBe('2026-01-02 03:04:05')
            ->and($this->entry->fresh()->slug)->toBe('before-slug')
            ->and(AuditLog::count())->toBe($before);
    });
});
