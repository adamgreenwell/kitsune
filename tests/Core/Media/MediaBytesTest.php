<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaBytes;
use Kitsune\Core\Media\MediaCustodyFailure;
use Kitsune\Core\Tests\Fixtures\RefusingDisk;
use League\Flysystem\Filesystem;

/*
 * The byte operations custody is built from — ADR-042 decision 5 (T11).
 *
 * ⚠️ A COPY NEVER APPEARS INCOMPLETE AT ITS PATH on a local disk: it is written beside it, read back, and renamed into
 * place. Asserted from what the disk was actually asked to do, in order, not from the end state alone.
 */

beforeEach(function (): void {
    $this->roots = [];
    $this->root = function (string $name): string {
        $root = sys_get_temp_dir().'/kitsune-bytes-'.$name.'-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        $this->roots[] = $root;

        return $root;
    };

    RefusingDisk::forgetLog();
    $this->source = RefusingDisk::install('bytes-source', ($this->root)('source'));
    Storage::disk('bytes-source')->put('media/1/2026/09/photo.png', 'the bytes of a photo');
    $this->hash = hash('sha256', 'the bytes of a photo');
});

afterEach(function (): void {
    foreach ($this->roots as $root) {
        exec('rm -rf '.escapeshellarg($root));
    }
});

/** @return list<array{event: string, path: ?string}> */
function operationsOn(string $disk): array
{
    return array_values(array_map(
        static fn (array $entry): array => ['event' => $entry['event'], 'path' => $entry['path']],
        array_filter(RefusingDisk::$log, static fn (array $entry): bool => $entry['disk'] === $disk && $entry['bytes']),
    ));
}

it('writes beside the path, reads the copy back, and renames it into place on a local disk', function (): void {
    RefusingDisk::install('bytes-local', ($this->root)('local'));

    MediaBytes::copyVerified('bytes-source', 'bytes-local', 'media/1/2026/09/photo.png', $this->hash);

    expect(operationsOn('bytes-local'))->toBe([
        ['event' => 'writeStream', 'path' => 'media/1/2026/09/photo.png.kitsune-partial'],
        ['event' => 'move', 'path' => 'media/1/2026/09/photo.png.kitsune-partial -> media/1/2026/09/photo.png'],
    ])
        ->and(Storage::disk('bytes-local')->get('media/1/2026/09/photo.png'))->toBe('the bytes of a photo')
        ->and(Storage::disk('bytes-local')->exists('media/1/2026/09/photo.png.kitsune-partial'))->toBeFalse();
});

it('leaves neither the path nor a partial copy when the copy comes out short', function (): void {
    RefusingDisk::install('bytes-local', ($this->root)('local'))->truncateWritesTo = 5;

    expect(fn () => MediaBytes::copyVerified('bytes-source', 'bytes-local', 'media/1/2026/09/photo.png', $this->hash))
        ->toThrow(MediaCustodyFailure::class, 'does not match');

    expect(Storage::disk('bytes-local')->exists('media/1/2026/09/photo.png'))->toBeFalse()
        ->and(Storage::disk('bytes-local')->exists('media/1/2026/09/photo.png.kitsune-partial'))->toBeFalse();
});

it('writes an object store\'s copy at its path, whose PUT is all or nothing', function (): void {
    $adapter = new RefusingDisk(($this->root)('store'), 'bytes-store');
    Storage::set('bytes-store', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['driver' => 'local']));

    MediaBytes::copyVerified('bytes-source', 'bytes-store', 'media/1/2026/09/photo.png', $this->hash);

    expect(operationsOn('bytes-store'))->toBe([['event' => 'writeStream', 'path' => 'media/1/2026/09/photo.png']]);
});

/** Two disks that reach one file: the "copy" would be the file itself, so nothing is written. */
it('refuses to copy a file onto itself through a symlink, before writing anything', function (): void {
    $other = ($this->root)('linked');
    mkdir($other.'/media/1/2026/09', 0777, true);
    symlink($this->source->root().'/media/1/2026/09/photo.png', $other.'/media/1/2026/09/photo.png');
    RefusingDisk::install('bytes-linked', $other);

    expect(fn () => MediaBytes::copyVerified('bytes-source', 'bytes-linked', 'media/1/2026/09/photo.png', $this->hash))
        ->toThrow(MediaCustodyFailure::class, 'the same file');

    expect(operationsOn('bytes-linked'))->toBe([]);
});

/** Laravel answers an unreadable checksum with `false`, or rethrows it when the disk is configured to throw: both. */
it('tells an absent file from one it cannot read', function (bool $throws): void {
    $source = $throws ? RefusingDisk::install('bytes-source', $this->source->root(), ['throw' => true]) : $this->source;
    $source->unreadable = ['media/1/2026/09/photo.png'];

    expect(MediaBytes::hash('bytes-source', 'media/1/2026/09/missing.png'))->toBeNull()
        ->and(fn () => MediaBytes::hash('bytes-source', 'media/1/2026/09/photo.png'))->toThrow(MediaCustodyFailure::class, 'cannot be read')
        ->and(MediaBytes::same(null, null))->toBeFalse();
})->with(['a disk that answers false' => false, 'a disk configured to throw' => true]);

it('confirms a delete by looking again, and refuses one the disk would not do', function (): void {
    MediaBytes::delete('bytes-source', 'media/1/2026/09/photo.png');
    expect(MediaBytes::present('bytes-source', 'media/1/2026/09/photo.png'))->toBeFalse();

    Storage::disk('bytes-source')->put('media/1/2026/09/other.png', 'x');
    $this->source->failDeletes = true;

    expect(fn () => MediaBytes::delete('bytes-source', 'media/1/2026/09/other.png'))->toThrow(MediaCustodyFailure::class, 'could not be deleted');
});
