<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Media\MediaVisibility;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\AuditorStandIn;

/*
 * A media file's row: only `disk` moves after creation — ADR-042 decision 5 (T22).
 *
 * ⚠️ AT EVERY DOOR, INSIDE THE ESCAPE HATCH TOO. Custody finds a file's copies by its path and keeps the one matching
 * its checksum, so a row whose path or checksum changed would strand or replace the file. Each refusal is asserted
 * from the row as stored afterwards, not from the exception alone.
 */

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake(MediaDisks::PRIVATE);

    $org = Org::create(['slug' => 'fixed', 'name' => 'Fixed']);
    app(Context::class)->setOrg($org);
    app(Context::class)->setSite(Site::create(['handle' => 'main', 'slug' => 'fixed-main', 'name' => 'Main', 'locale' => 'en']));
    $type = EntryType::create(['org_id' => $org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);

    $store = static function () use ($type): MediaFile {
        $source = tempnam(sys_get_temp_dir(), 'kitsune-fixed-');
        file_put_contents($source, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $entry = MediaLibrary::store($source, 'fixed.png', $type, 'public');
        unlink($source);

        return MediaFile::query()->where('entry_id', $entry->getKey())->firstOrFail();
    };

    $this->file = $store();
    $this->other = $store();
    $this->stored = (array) DB::table('media_files')->where('id', $this->file->getKey())->first();
});

afterEach(fn () => app(Context::class)->forget());

it('refuses to change a column fixed at creation, at every door', function (string $column, string $door): void {
    $value = match ($column) {
        'entry_id' => $this->other->entry_id,
        'path' => 'media/elsewhere.png',
        'checksum' => str_repeat('0', 64),
        'mime' => 'application/pdf',
        'size_bytes' => 1,
        'visibility' => 'private',
        'width', 'height' => 7,
        'duration_ms' => 5,
        'created_at' => now()->subYear(),
    };
    $id = $this->file->getKey();

    $write = match ($door) {
        'the instance' => function () use ($id, $column, $value): void {
            $file = MediaFile::query()->findOrFail($id);
            $file->{$column} = $value;
            $file->save();
        },
        'a quiet save' => function () use ($id, $column, $value): void {
            $file = MediaFile::query()->findOrFail($id);
            $file->{$column} = $value;
            $file->saveQuietly();
        },
        'a bulk update' => fn () => MediaFile::query()->whereKey($id)->update([$column => $value]),
        'the escape hatch' => fn () => MediaFile::withoutScopeBecause('a test of the fixed columns', fn ($query) => $query
            ->whereKey($id)->update([$column => $value])),
    };

    expect($write)->toThrow(RuntimeException::class, "[{$column}] is fixed when MediaFile is created");

    expect((array) DB::table('media_files')->where('id', $id)->first())->toBe($this->stored);
})->with(['entry_id', 'path', 'checksum', 'mime', 'size_bytes', 'visibility', 'width', 'height', 'duration_ms', 'created_at'])
    ->with(['the instance', 'a quiet save', 'a bulk update', 'the escape hatch']);

/** The control: the one column custody moves is still written, by a bulk update and by the instance. */
it('writes the disk a file is on', function (): void {
    MediaFile::query()->whereKey($this->file->getKey())->update(['disk' => MediaDisks::PRIVATE]);

    expect(DB::table('media_files')->where('id', $this->file->getKey())->value('disk'))->toBe(MediaDisks::PRIVATE);

    $file = MediaFile::query()->findOrFail($this->file->getKey());
    $file->disk = 'public';
    $file->save();

    expect(DB::table('media_files')->where('id', $this->file->getKey())->value('disk'))->toBe('public');
});

/*
 * ADR-042 decision 32 lifts `visibility` — and, for a JPEG made public, `checksum` and `size_bytes` — through one door,
 * below the model, under custody's lock (`MediaVisibility::writeRow()`). Every model door still refuses all three: the
 * forms of a write the dataset above does not spell, here.
 */
it('refuses the columns the visibility switch writes at every model door', function (string $door): void {
    $id = $this->file->getKey();

    $write = match ($door) {
        'a qualified bulk update' => fn () => MediaFile::query()->whereKey($id)->update(['media_files.visibility' => 'private']),
        'an increment of the size' => fn () => MediaFile::query()->whereKey($id)->increment('size_bytes'),
        'an increment\'s extra columns' => fn () => MediaFile::query()->whereKey($id)->increment('width', 0, ['checksum' => str_repeat('0', 64)]),
        'an upsert\'s update half, in the escape hatch' => fn () => MediaFile::withoutScopeBecause('a test of the fixed columns', fn ($query) => $query
            ->upsert([[...$this->stored, 'visibility' => 'private']], ['id'], ['visibility'])),
    };

    expect($write)->toThrow(RuntimeException::class, 'is fixed when MediaFile is created');

    expect((array) DB::table('media_files')->where('id', $id)->first())->toBe($this->stored);
})->with(['a qualified bulk update', 'an increment of the size', 'an increment\'s extra columns', 'an upsert\'s update half, in the escape hatch']);

/*
 * ...and below the model, one method writes them: a second writer anywhere in core — a query-builder update naming one, a
 * raw statement setting one — fails here, so the lift stays "for that path alone".
 */
it('writes visibility, checksum and size_bytes after creation in one method alone', function (): void {
    $writers = [];
    $raw = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 3).'/packages/core/src', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! str_ends_with((string) $file, '.php')) {
            continue;
        }

        $lines = file((string) $file) ?: [];

        foreach ($lines as $i => $line) {
            // A string that sets one of them is SQL written by hand.
            foreach ([...(preg_match_all('/\'[^\']*\'|"[^"]*"/', $line, $strings) > 0 ? $strings[0] : [])] as $string) {
                if (preg_match('/\bset\b.*\b(visibility|checksum|size_bytes)\b\s*=/i', $string) === 1) {
                    $raw[] = basename((string) $file).':'.($i + 1);
                }
            }

            if (preg_match('/[\'"](visibility|checksum|size_bytes)[\'"]\s*(=>|\]\s*=(?!=))/', $line) !== 1) {
                continue;
            }

            $where = 'the class body';

            for ($j = $i; $j >= 0; $j--) {
                if (preg_match('/function\s+(\w+)\s*\(/', $lines[$j], $named) === 1) {
                    $where = $named[1].'()';

                    break;
                }

                if (preg_match('/^\s*(?:protected|private|public)\s+(?:static\s+)?(?:array\s+)?\$(\w+)/', $lines[$j], $property) === 1) {
                    $where = '$'.$property[1];

                    break;
                }
            }

            $writers[basename((string) $file, '.php').'::'.$where] = true;
        }
    }

    $writers = array_keys($writers);
    sort($writers);

    expect($raw)->toBe([])
        ->and($writers)->toBe([
            // A disk's configuration — `filesystems.disks.*.visibility` — not the column.
            'MediaDisks::resolvedEntry()',
            // The model's casts and its reasons, which write nothing.
            'MediaFile::$casts',
            'MediaFile::columnsFixedAtCreation()',
            // The row as `store()` creates it.
            'MediaLibrary::write()',
            // The switch, below the model, under custody's lock — ADR-042 decision 32.
            'MediaVisibility::writeRow()',
        ]);

    $writeRow = new ReflectionMethod(MediaVisibility::class, 'writeRow');

    expect($writeRow->isPrivate())->toBeTrue()->and($writeRow->isStatic())->toBeTrue();
});

/*
 * ...and the switch opens no window: while it holds the lock, a model write of the same columns — to another row, or to
 * the very row it is switching — is refused as it always is, and the other row is untouched.
 */
it('opens no window for a model write while a file is switched', function (): void {
    $other = $this->other->getKey();
    $otherBefore = (array) DB::table('media_files')->where('id', $other)->first();
    $refused = [];

    AuditorStandIn::install()->beforeRecording(function () use ($other, &$refused): void {
        foreach ([
            fn () => MediaFile::query()->whereKey($other)->update(['visibility' => 'private']),
            function (): void {
                $file = MediaFile::query()->findOrFail($this->file->getKey());
                $file->checksum = str_repeat('0', 64);
                $file->save();
            },
        ] as $write) {
            try {
                $write();
            } catch (RuntimeException $failure) {
                $refused[] = str_contains($failure->getMessage(), 'is fixed when MediaFile is created');
            }
        }
    });

    MediaVisibility::makePrivate($this->file->entry);

    expect($refused)->toBe([true, true])
        ->and(DB::table('media_files')->where('id', $this->file->getKey())->value('visibility'))->toBe('private')
        ->and((array) DB::table('media_files')->where('id', $other)->first())->toBe($otherBefore);
});
