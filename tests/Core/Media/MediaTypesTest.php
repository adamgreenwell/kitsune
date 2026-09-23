<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/*
 * A media type is declared once — ADR-042 decision 1.
 *
 * `entry_types.is_media` is set when a type is created and locked afterwards, by the model and in bulk. The entry
 * holds the other half of the lock: it cannot be retyped across the boundary in either direction. `store()` refuses
 * a type that is not one. And the migration that adds the column classifies what #145–#148 already stored.
 *
 * ⚠️ EVERY REFUSAL HERE IS ASSERTED BY ITS OWN MESSAGE, because several guards stand on these writes — the
 * relation veto, the cross-org type refusal, the per-row column list — and a test that asserted only "it threw"
 * would pass on whichever of them happened to fire.
 */

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake(MediaDisks::PRIVATE);

    $this->org = Org::create(['slug' => 'acme', 'name' => 'Acme']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);

    $this->imageType = EntryType::create([
        'org_id' => $this->org->getKey(), 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true,
    ]);
    $this->photoType = EntryType::create([
        'org_id' => $this->org->getKey(), 'handle' => 'photo', 'name' => 'Photo', 'plural_name' => 'Photos', 'is_media' => true,
    ]);
    $this->articleType = EntryType::create([
        'org_id' => $this->org->getKey(), 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles',
    ]);
});

afterEach(function (): void {
    app(Context::class)->forget();

    foreach (glob(sys_get_temp_dir().'/kitsune-types-*') ?: [] as $leftover) {
        @unlink($leftover);
    }
});

function aPngForTypes(): string
{
    $path = tempnam(sys_get_temp_dir(), 'kitsune-types-');
    file_put_contents($path, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    ));

    return $path;
}

function storedIsMedia(EntryType $type): bool
{
    return (bool) DB::table('entry_types')->where('id', $type->getKey())->value('is_media');
}

/** The migration, as a fresh instance: `require` rather than `require_once`, because the file returns one. */
function isMediaMigration(): object
{
    return require __DIR__.'/../../../packages/core/database/migrations/0001_01_01_000008_add_is_media_to_entry_types.php';
}

/**
 * A `media_files` row written straight to the table — the state #145–#148 allowed on any type, which the
 * migration has to classify. `store()` can no longer produce it, and that is the point.
 */
function legacyFileFor(Entry $entry): void
{
    DB::table('media_files')->insert([
        'entry_id' => $entry->getKey(),
        'disk' => MediaDisks::PRIVATE,
        'path' => 'media/legacy/'.$entry->getKey().'.png',
        'mime' => 'image/png',
        'size_bytes' => 1,
        'checksum' => str_repeat('0', 64),
        'visibility' => 'private',
        'created_at' => now(),
    ]);
}

describe('the flag on the type', function (): void {
    it('defaults to false, on the instance as well as in the row', function (): void {
        expect($this->articleType->is_media)->toBeFalse()
            ->and(storedIsMedia($this->articleType))->toBeFalse()
            ->and($this->imageType->is_media)->toBeTrue()
            ->and(storedIsMedia($this->imageType))->toBeTrue();
    });

    it('refuses to make an existing type a media type', function (): void {
        $this->articleType->is_media = true;

        expect(fn () => $this->articleType->save())
            ->toThrow(RuntimeException::class, 'Entry type [article] cannot become a media type after it has been created');

        expect(storedIsMedia($this->articleType))->toBeFalse();
    });

    it('refuses to stop a media type being one', function (): void {
        $this->imageType->is_media = false;

        expect(fn () => $this->imageType->save())
            ->toThrow(RuntimeException::class, 'Entry type [image] cannot stop being a media type after it has been created');

        expect(storedIsMedia($this->imageType))->toBeTrue();
    });

    /** A quiet save suppresses `saving`, and the builder refuses the column the guard never checked. */
    it('refuses the change through a quiet save', function (): void {
        $this->imageType->is_media = false;

        expect(fn () => $this->imageType->saveQuietly())
            ->toThrow(RuntimeException::class, '[is_media]');

        expect(storedIsMedia($this->imageType))->toBeTrue();
    });

    /**
     * ⚠️ A `saving` LISTENER REGISTERED AFTER THE MODEL BOOTS runs after the lock's own `saving` check, and the
     * proof the builder trusts is taken later still — so the lock is judged again where the proof is taken.
     */
    it('refuses the change when a later saving listener makes it', function (): void {
        EntryType::saving(function (EntryType $type): void {
            if ($type->handle === 'article') {
                $type->is_media = true;
            }
        });

        $this->articleType->name = 'Story';

        expect(fn () => $this->articleType->save())
            ->toThrow(RuntimeException::class, 'Entry type [article] cannot become a media type after it has been created');

        expect(storedIsMedia($this->articleType))->toBeFalse();
    });

    it('refuses the change in bulk', function (): void {
        expect(fn () => EntryType::query()->whereKey($this->articleType->getKey())->update(['is_media' => true]))
            ->toThrow(RuntimeException::class, '[is_media] cannot be written in bulk');

        expect(storedIsMedia($this->articleType))->toBeFalse();
    });

    /** The builder's switch is labelled through core's own namespace, which starts here — ADR-042 decision 3. */
    it('labels the builder\'s switch through core\'s own translations', function (): void {
        expect(__('kitsune::media.type.holds_media'))->toBe('Holds media')
            ->and(__('kitsune::media.type.holds_media_locked'))->not->toBe('kitsune::media.type.holds_media_locked');
    });

    /** The control: the lock refuses a change of the flag, not every save of a type that has one. */
    it('still saves every other change to a media type', function (): void {
        $this->imageType->name = 'Picture';
        $this->imageType->is_media = true;
        $this->imageType->save();

        expect(EntryType::query()->whereKey($this->imageType->getKey())->value('name'))->toBe('Picture')
            ->and(storedIsMedia($this->imageType))->toBeTrue();
    });
});

describe('retyping an entry', function (): void {
    it('refuses to move a stored file onto a type that is not a media type', function (): void {
        $entry = MediaLibrary::store(aPngForTypes(), 'logo.png', $this->imageType);

        $entry->entry_type_id = $this->articleType->getKey();

        expect(fn () => $entry->save())
            ->toThrow(RuntimeException::class, 'cannot become a [article]: [image] is a media type and [article] is not');

        expect(Entry::query()->whereKey($entry->getKey())->value('entry_type_id'))->toBe($this->imageType->getKey());
    });

    /** The guard is at the builder, where a quiet save still arrives. */
    it('refuses it through a quiet save too', function (): void {
        $entry = MediaLibrary::store(aPngForTypes(), 'logo.png', $this->imageType);

        $entry->entry_type_id = $this->articleType->getKey();

        expect(fn () => $entry->saveQuietly())
            ->toThrow(RuntimeException::class, 'cannot become a [article]: [image] is a media type and [article] is not');

        expect(Entry::query()->whereKey($entry->getKey())->value('entry_type_id'))->toBe($this->imageType->getKey());
    });

    it('refuses to make an ordinary entry a media entry with no file behind it', function (): void {
        $entry = Entry::create(['entry_type_id' => $this->articleType->getKey(), 'title' => 'Plain', 'slug' => 'plain']);

        $entry->entry_type_id = $this->imageType->getKey();

        expect(fn () => $entry->save())
            ->toThrow(RuntimeException::class, 'cannot become a [image]: [article] is not a media type and [image] is one');

        expect(Entry::query()->whereKey($entry->getKey())->value('entry_type_id'))->toBe($this->articleType->getKey());
    });

    it('refuses the ordinary-to-media direction through a quiet save too', function (): void {
        $entry = Entry::create(['entry_type_id' => $this->articleType->getKey(), 'title' => 'Plain', 'slug' => 'plain']);

        $entry->entry_type_id = $this->imageType->getKey();

        expect(fn () => $entry->saveQuietly())
            ->toThrow(RuntimeException::class, 'cannot become a [image]: [article] is not a media type and [image] is one');

        expect(Entry::query()->whereKey($entry->getKey())->value('entry_type_id'))->toBe($this->articleType->getKey());
    });

    it('refuses a bulk retype across the boundary', function (): void {
        $entry = MediaLibrary::store(aPngForTypes(), 'logo.png', $this->imageType);

        expect(fn () => Entry::query()->whereKey($entry->getKey())->update(['entry_type_id' => $this->articleType->getKey()]))
            ->toThrow(RuntimeException::class, "Entry {$entry->getKey()} cannot become a [article]: [image] is a media type");

        expect(Entry::query()->whereKey($entry->getKey())->value('entry_type_id'))->toBe($this->imageType->getKey());
    });

    /** Within the boundary a bulk retype meets the refusal it always met: the per-row column list. */
    it('leaves a bulk retype within the boundary to the refusal that already covered it', function (): void {
        $entry = MediaLibrary::store(aPngForTypes(), 'logo.png', $this->imageType);

        expect(fn () => Entry::query()->whereKey($entry->getKey())->update(['entry_type_id' => $this->photoType->getKey()]))
            ->toThrow(RuntimeException::class, '[entry_type_id] cannot be written in bulk');
    });

    /**
     * ⚠️ INSIDE THE ESCAPE HATCH, where the per-row column list stands down. The boundary is not a scope question —
     * the hatch decides which path may write a column, not what it may hold — so it holds here too.
     */
    it('refuses a retype across the boundary inside withoutScopeBecause(), in both directions', function (): void {
        $media = MediaLibrary::store(aPngForTypes(), 'logo.png', $this->imageType);
        $plain = Entry::create(['entry_type_id' => $this->articleType->getKey(), 'title' => 'Plain', 'slug' => 'plain']);

        expect(fn () => Entry::withoutScopeBecause('a test of the media boundary', fn ($query) => $query
            ->where('entry_type_id', $this->imageType->getKey())
            ->update(['entry_type_id' => $this->articleType->getKey()])))
            ->toThrow(RuntimeException::class, "Entry {$media->getKey()} cannot become a [article]: [image] is a media type");

        expect(fn () => Entry::withoutScopeBecause('a test of the media boundary', fn ($query) => $query
            ->whereKey($plain->getKey())
            ->update(['entry_type_id' => $this->imageType->getKey()])))
            ->toThrow(RuntimeException::class, "Entry {$plain->getKey()} cannot become a [image]: [article] is not a media type");

        expect(Entry::query()->whereKey($media->getKey())->value('entry_type_id'))->toBe($this->imageType->getKey())
            ->and(Entry::query()->whereKey($plain->getKey())->value('entry_type_id'))->toBe($this->articleType->getKey());
    });

    /** The control for the one above: the hatch still retypes in bulk within the boundary, as it is for. */
    it('still retypes in bulk within the boundary inside withoutScopeBecause()', function (): void {
        $entry = MediaLibrary::store(aPngForTypes(), 'logo.png', $this->imageType);

        Entry::withoutScopeBecause('a test of the media boundary', fn ($query) => $query
            ->whereKey($entry->getKey())
            ->update(['entry_type_id' => $this->photoType->getKey()]));

        expect(Entry::query()->whereKey($entry->getKey())->value('entry_type_id'))->toBe($this->photoType->getKey());
    });

    /** `$extra` is a map of plain assignments, so an arithmetic write can retype as well as an update can. */
    it('refuses a retype carried in an arithmetic write\'s extra columns, inside the hatch and out', function (): void {
        $entry = MediaLibrary::store(aPngForTypes(), 'logo.png', $this->imageType);

        expect(fn () => Entry::withoutScopeBecause('a test of the media boundary', fn ($query) => $query
            ->whereKey($entry->getKey())
            ->increment('id', 0, ['entry_type_id' => $this->articleType->getKey()])))
            ->toThrow(RuntimeException::class, 'cannot become a [article]: [image] is a media type');

        expect(fn () => Entry::withoutScopeBecause('a test of the media boundary', fn () => $entry
            ->increment('id', 0, ['entry_type_id' => $this->articleType->getKey()])))
            ->toThrow(RuntimeException::class, 'cannot become a [article]: [image] is a media type');

        expect(Entry::query()->whereKey($entry->getKey())->value('entry_type_id'))->toBe($this->imageType->getKey());
    });

    it('refuses arithmetic on the type id itself, inside the hatch', function (): void {
        $entry = Entry::create(['entry_type_id' => $this->articleType->getKey(), 'title' => 'Plain', 'slug' => 'plain']);

        expect(fn () => Entry::withoutScopeBecause('a test of the media boundary', fn ($query) => $query
            ->whereKey($entry->getKey())
            ->increment('entry_type_id')))
            ->toThrow(RuntimeException::class, 'Refusing to increment or decrement [entry_type_id]');

        expect(fn () => Entry::withoutScopeBecause('a test of the media boundary', fn ($query) => $query
            ->whereKey($entry->getKey())
            ->incrementEach(['entry_type_id' => 1])))
            ->toThrow(RuntimeException::class, 'Refusing to increment or decrement [entry_type_id]');

        expect(Entry::query()->whereKey($entry->getKey())->value('entry_type_id'))->toBe($this->articleType->getKey());
    });

    /**
     * ⚠️ MYSQL AND MARIADB ROUND WHAT PHP COMPARES. `'13.9'` finds no type in PHP's lookup and is stored as 14, so
     * a value one below a media type's id crossed the boundary unseen. Refused on every engine, because which
     * engine rounds is not a thing a guard should depend on.
     */
    it('refuses a type id that is not written as a whole number', function (): void {
        $entry = Entry::create(['entry_type_id' => $this->articleType->getKey(), 'title' => 'Plain', 'slug' => 'plain']);
        $almostImage = ($this->imageType->getKey() - 1).'.9';

        foreach ([$almostImage, (float) $almostImage, '0'.$this->imageType->getKey(), ' '.$this->imageType->getKey(), 0, -1] as $forged) {
            /* Both saves: the evented one meets the first `saving` listener, the quiet one only the builder. */
            foreach (['save', 'saveQuietly'] as $save) {
                $entry->entry_type_id = $forged;

                expect(fn () => $entry->{$save}())
                    ->toThrow(RuntimeException::class, 'as an entry type: the column holds a type\'s id');

                $entry->refresh();
            }
        }

        expect(fn () => Entry::create(['entry_type_id' => $almostImage, 'title' => 'Forged', 'slug' => 'forged']))
            ->toThrow(RuntimeException::class, 'as an entry type: the column holds a type\'s id');

        /* A quiet create dispatches no listener, so only the builder's copy of the check stands on it. */
        expect(fn () => Entry::createQuietly(['entry_type_id' => $almostImage, 'title' => 'Forged', 'slug' => 'forged']))
            ->toThrow(RuntimeException::class, 'as an entry type: the column holds a type\'s id');

        expect(Entry::query()->whereKey($entry->getKey())->value('entry_type_id'))->toBe($this->articleType->getKey());
    });

    /** The control: a key the form hands over as a string is still a key. */
    it('accepts a type id handed over as a string of digits', function (): void {
        $entry = Entry::create(['entry_type_id' => (string) $this->articleType->getKey(), 'title' => 'Plain', 'slug' => 'plain']);

        expect(Entry::query()->whereKey($entry->getKey())->value('entry_type_id'))->toBe($this->articleType->getKey());
    });

    /** The control: within the boundary a retype is an ordinary retype, and the file goes with the entry. */
    it('moves a stored file between two media types', function (): void {
        $entry = MediaLibrary::store(aPngForTypes(), 'logo.png', $this->imageType);

        $entry->entry_type_id = $this->photoType->getKey();
        $entry->save();

        expect(Entry::query()->whereKey($entry->getKey())->value('type_handle'))->toBe('photo')
            ->and(MediaFile::query()->where('entry_id', $entry->getKey())->exists())->toBeTrue();
    });

    it('moves an ordinary entry between two ordinary types', function (): void {
        $page = EntryType::create([
            'org_id' => $this->org->getKey(), 'handle' => 'page', 'name' => 'Page', 'plural_name' => 'Pages',
        ]);
        $entry = Entry::create(['entry_type_id' => $this->articleType->getKey(), 'title' => 'Plain', 'slug' => 'plain']);

        $entry->entry_type_id = $page->getKey();
        $entry->save();

        expect(Entry::query()->whereKey($entry->getKey())->value('type_handle'))->toBe('page');
    });
});

describe('storing into a type', function (): void {
    it('refuses a type that is not a media type, before anything is written', function (): void {
        expect(fn () => MediaLibrary::store(aPngForTypes(), 'logo.png', $this->articleType))
            ->toThrow(RuntimeException::class, 'Refusing [logo.png]: [article] is not a media type');

        expect(DB::table('entries')->count())->toBe(0)
            ->and(DB::table('media_files')->count())->toBe(0)
            ->and(Storage::disk(MediaDisks::PRIVATE)->allFiles())->toBe([])
            ->and(Storage::disk('public')->allFiles())->toBe([]);
    });

    /** The flag is read from the row, so an attribute set on the instance is not a way in. */
    it('refuses a type whose flag was set on the instance and never stored', function (): void {
        $this->articleType->is_media = true;

        expect(fn () => MediaLibrary::store(aPngForTypes(), 'logo.png', $this->articleType))
            ->toThrow(RuntimeException::class, 'Refusing [logo.png]: [article] is not a media type');

        expect(DB::table('media_files')->count())->toBe(0);
    });

    it('refuses a type that has not been saved', function (): void {
        $unsaved = new EntryType(['handle' => 'draft-type', 'is_media' => true]);

        expect(fn () => MediaLibrary::store(aPngForTypes(), 'logo.png', $unsaved))
            ->toThrow(RuntimeException::class, 'Refusing [logo.png]: [draft-type] is not a media type');
    });
});

describe('the migration that adds the flag', function (): void {
    it('marks nothing when nothing has been stored', function (): void {
        Entry::create(['entry_type_id' => $this->articleType->getKey(), 'title' => 'Plain', 'slug' => 'plain']);

        expect(isMediaMigration()->typesHoldingMedia())->toBe([]);
    });

    it('marks a type whose every entry carries a file, trashed ones included', function (): void {
        $legacy = EntryType::create([
            'org_id' => $this->org->getKey(), 'handle' => 'legacy', 'name' => 'Legacy', 'plural_name' => 'Legacies',
        ]);
        $live = Entry::create(['entry_type_id' => $legacy->getKey(), 'title' => 'Live', 'slug' => 'live']);
        $trashed = Entry::create(['entry_type_id' => $legacy->getKey(), 'title' => 'Trashed', 'slug' => 'trashed']);
        legacyFileFor($live);
        legacyFileFor($trashed);
        $trashed->delete();

        Entry::create(['entry_type_id' => $this->articleType->getKey(), 'title' => 'Plain', 'slug' => 'plain']);

        $migration = isMediaMigration();
        $types = $migration->typesHoldingMedia();

        expect($types)->toBe([$legacy->getKey()]);

        $migration->markAsMedia($types);

        expect(storedIsMedia($legacy))->toBeTrue()
            ->and(storedIsMedia($this->articleType))->toBeFalse();
    });

    it('refuses a type holding entries with files and entries without, naming it', function (): void {
        $legacy = EntryType::create([
            'org_id' => $this->org->getKey(), 'handle' => 'legacy', 'name' => 'Legacy', 'plural_name' => 'Legacies',
        ]);
        legacyFileFor(Entry::create(['entry_type_id' => $legacy->getKey(), 'title' => 'With', 'slug' => 'with']));
        Entry::create(['entry_type_id' => $legacy->getKey(), 'title' => 'Without', 'slug' => 'without']);

        expect(fn () => isMediaMigration()->typesHoldingMedia())
            ->toThrow(RuntimeException::class, "Cannot mark media types: [legacy (id {$legacy->getKey()}): 1 without a file]. That type holds entries with stored files and entries without");
    });

    /** A trashed entry is still a row the flag would lock into the wrong state if it were restored. */
    it('counts a trashed entry without a file as mixing the type', function (): void {
        $legacy = EntryType::create([
            'org_id' => $this->org->getKey(), 'handle' => 'legacy', 'name' => 'Legacy', 'plural_name' => 'Legacies',
        ]);
        legacyFileFor(Entry::create(['entry_type_id' => $legacy->getKey(), 'title' => 'With', 'slug' => 'with']));
        Entry::create(['entry_type_id' => $legacy->getKey(), 'title' => 'Without', 'slug' => 'without'])->delete();

        expect(fn () => isMediaMigration()->typesHoldingMedia())
            ->toThrow(RuntimeException::class, "Cannot mark media types: [legacy (id {$legacy->getKey()}): 1 without a file]");
    });

    /**
     * ⚠️ BEFORE THE COLUMN, which is the order the migration's docblock argues for: on MySQL and MariaDB a schema
     * statement commits on its own, so a refusal after it would leave the column behind and make every retry fail
     * on "duplicate column". The column already exists in this database, so an `up()` that altered the table first
     * would fail there — and a failed statement is never logged, so it is the EXCEPTION that tells: the engine's
     * duplicate-column error is a `QueryException`, which is a `RuntimeException` too, so its class is asserted
     * away explicitly rather than trusted to the message. The log is asserted to hold reads only, and to hold some,
     * so a refusal that ran no query at all cannot pass either.
     */
    it('refuses before it alters the table', function (): void {
        $legacy = EntryType::create([
            'org_id' => $this->org->getKey(), 'handle' => 'legacy', 'name' => 'Legacy', 'plural_name' => 'Legacies',
        ]);
        legacyFileFor(Entry::create(['entry_type_id' => $legacy->getKey(), 'title' => 'With', 'slug' => 'with']));
        Entry::create(['entry_type_id' => $legacy->getKey(), 'title' => 'Without', 'slug' => 'without']);

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = strtolower(ltrim($query->sql));
        });

        $thrown = null;

        try {
            isMediaMigration()->up();
        } catch (Throwable $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeInstanceOf(RuntimeException::class)
            ->and($thrown)->not->toBeInstanceOf(QueryException::class)
            ->and($thrown?->getMessage())->toStartWith('Cannot mark media types')
            ->and($statements)->not->toBeEmpty()
            ->and(array_filter($statements, static fn (string $sql): bool => ! str_starts_with($sql, 'select')))->toBe([]);
    });
});
