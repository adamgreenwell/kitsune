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
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

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
