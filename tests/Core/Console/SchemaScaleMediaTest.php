<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Kitsune\Benchmarks\SchemaScaleMedia;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

require_once dirname(__DIR__, 3).'/bin/benchmarks/SchemaScaleMedia.php';

beforeEach(function (): void {
    $requested = sys_get_temp_dir().'/kitsune-scale-media-'.bin2hex(random_bytes(8));
    mkdir($requested, 0700);
    $this->scaleOutput = realpath($requested);
});

afterEach(function (): void {
    app(Context::class)->forget();
    File::deleteDirectory($this->scaleOutput);
});

it('gives every media entry real separate bytes and metadata the library also writes', function (): void {
    $fixtures = new SchemaScaleMedia($this->scaleOutput);
    $first = $fixtures->write(45);
    $second = $fixtures->write(46);
    expect($first['path'])->not->toBe($second['path'])
        ->and($fixtures->absolutePath($first['path']))->toStartWith($this->scaleOutput.'/')
        ->and(hash_file('sha256', $fixtures->absolutePath($first['path'])))->toBe($first['checksum'])
        ->and(filesize($fixtures->absolutePath($first['path'])))->toBe($first['size_bytes']);
    $size = getimagesize($fixtures->absolutePath($first['path']));
    expect([$size[0], $size[1]])->toBe([1, 1]);

    Storage::fake(MediaDisks::PRIVATE);
    $org = Org::create(['slug' => 'scale-media-control', 'name' => 'Synthetic media control']);
    app(Context::class)->setOrg($org);
    $site = Site::create(['handle' => 'control', 'slug' => 'scale-media-control', 'name' => 'Synthetic media control']);
    app(Context::class)->setSite($site);
    $type = EntryType::create(['handle' => 'asset', 'name' => 'Asset', 'plural_name' => 'Assets', 'is_media' => true]);
    $entry = MediaLibrary::store($fixtures->absolutePath($first['path']), 'synthetic.png', $type);
    $stored = MediaFile::where('entry_id', $entry->id)->firstOrFail();
    foreach (['disk', 'mime', 'size_bytes', 'checksum', 'visibility', 'width', 'height', 'duration_ms'] as $key) {
        expect($first[$key])->toEqual($stored->getAttribute($key));
    }
    expect($entry->site_id)->toBeNull()->and($entry->slug)->toBeNull()->and($entry->status)->toBe('published');
});

it('refuses an existing file instead of overwriting a fixture or unrelated output', function (): void {
    $fixtures = new SchemaScaleMedia($this->scaleOutput);
    $row = $fixtures->write(2);
    $path = $fixtures->absolutePath($row['path']);
    file_put_contents($path, 'already owned by another operation');
    expect(fn () => $fixtures->write(2))->toThrow(RuntimeException::class)
        ->and(file_get_contents($path))->toBe('already owned by another operation');
});

it('refuses path traversal and a link out of the owned root', function (): void {
    $fixtures = new SchemaScaleMedia($this->scaleOutput);
    expect(fn () => $fixtures->absolutePath('../elsewhere.png'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $fixtures->write(0))->toThrow(InvalidArgumentException::class);
    $outside = $this->scaleOutput.'/outside';
    mkdir($outside, 0700);
    $root = $fixtures->root();
    mkdir($root.'/media', 0700, true);
    symlink($outside, $root.'/media/scale');
    expect(fn () => $fixtures->write(1))->toThrow(RuntimeException::class)
        ->and(scandir($outside))->toBe(['.', '..']);
});
