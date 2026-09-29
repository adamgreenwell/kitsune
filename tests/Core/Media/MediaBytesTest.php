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
 * The byte operations custody is built from — ADR-042 decision 5 (T11; slice 5c: T134, T168).
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

/*
 * T134. The listing's stand-in for a read: a copy opens and yields its first byte, or it cannot be read — through a disk
 * that answers null and one configured to throw alike — and nothing is hashed (Adam, decision 12, 2026-09-26).
 */
it('says whether a copy can be read by opening it, without hashing it', function (bool $throws): void {
    $source = $throws ? RefusingDisk::install('bytes-source', $this->source->root(), ['throw' => true]) : $this->source;
    Storage::disk('bytes-source')->put('media/1/2026/09/empty.png', '');
    $source->unreadable = ['media/1/2026/09/photo.png'];
    RefusingDisk::forgetLog();

    expect(MediaBytes::readable('bytes-source', 'media/1/2026/09/photo.png'))->toBeFalse()
        ->and(MediaBytes::readable('bytes-source', 'media/1/2026/09/empty.png'))->toBeTrue()
        ->and(MediaBytes::readable('bytes-source', 'media/1/2026/09/missing.png'))->toBeFalse();

    $source->unreadable = [];
    // One that opens and fails on its first read — which is why a byte is read, not only the file opened.
    $source->failReads = ['media/1/2026/09/photo.png'];

    expect(MediaBytes::readable('bytes-source', 'media/1/2026/09/photo.png'))->toBeFalse();

    $source->failReads = [];

    expect(MediaBytes::readable('bytes-source', 'media/1/2026/09/photo.png'))->toBeTrue()
        ->and(array_values(array_unique(array_column(RefusingDisk::$log, 'event'))))->toBe(['readStream']);
})->with(['a disk that answers null' => false, 'a disk configured to throw' => true]);

/*
 * T168. Custody asks a read-through disk only whether it holds a file: read — opened, hashed, copied from — a copy only
 * its fallback holds is copied into its primary; deleted, a file goes from both halves, only one of which custody read
 * (review of slice 5c). Custody does not hold one (ADR-042 decision 5, open for Adam).
 */
it('refuses to open, hash, copy or delete a copy through a read-through disk, writing nothing', function (): void {
    $primary = RefusingDisk::install('rt-primary', ($this->root)('rt-primary'));
    RefusingDisk::install('rt-fallback', ($this->root)('rt-fallback'));
    config(['filesystems.disks.rt' => ['driver' => 'read-through', 'primary' => 'rt-primary', 'fallback' => 'rt-fallback']]);
    $path = 'media/1/2026/09/photo.png';
    Storage::disk('rt-fallback')->put($path, 'bytes');
    RefusingDisk::forgetLog();

    expect(fn () => MediaBytes::readable('rt', $path))->toThrow(LogicException::class, 'it is a read-through disk')
        ->and(fn () => MediaBytes::hash('rt', $path))->toThrow(MediaCustodyFailure::class, 'it is a read-through disk, which custody neither reads nor removes a copy through')
        ->and(fn () => MediaBytes::copyVerified('rt', 'bytes-source', $path, hash('sha256', 'bytes')))->toThrow(MediaCustodyFailure::class, 'on the [rt] disk: it is a read-through disk')
        ->and(fn () => MediaBytes::copyVerified('bytes-source', 'rt', 'media/1/2026/09/photo.png', $this->hash))->toThrow(MediaCustodyFailure::class, 'on the [rt] disk: it is a read-through disk')
        ->and(fn () => MediaBytes::delete('rt', $path))->toThrow(MediaCustodyFailure::class, 'it is a read-through disk, which custody neither reads nor removes a copy through')
        ->and(MediaBytes::present('rt', $path))->toBeTrue()
        ->and(array_filter(RefusingDisk::$log, static fn (array $event): bool => $event['bytes'] || $event['event'] === 'readStream'))->toBe([])
        ->and(is_file($primary->root().'/'.$path))->toBeFalse()
        ->and(Storage::disk('rt-fallback')->exists($path))->toBeTrue();
});

it('confirms a delete by looking again, and refuses one the disk would not do', function (): void {
    MediaBytes::delete('bytes-source', 'media/1/2026/09/photo.png');
    expect(MediaBytes::present('bytes-source', 'media/1/2026/09/photo.png'))->toBeFalse();

    Storage::disk('bytes-source')->put('media/1/2026/09/other.png', 'x');
    $this->source->failDeletes = true;

    expect(fn () => MediaBytes::delete('bytes-source', 'media/1/2026/09/other.png'))->toThrow(MediaCustodyFailure::class, 'could not be deleted');
});

/*
 * A name reaches the very entry a disk listed only where the volume says so, by stat: the entry itself, or a link to it;
 * never another file, nor a hard link — another entry for the same file — which removing the listed name leaves (Codex,
 * #155, and review of the fix).
 */
it('reaches only the very entry a disk listed', function (): void {
    $root = Storage::disk('bytes-source')->path('');
    $dir = 'media/1/2026/09';
    @mkdir($root.$dir, 0777, true);
    file_put_contents($root.$dir.'/listed.png', 'this file');
    file_put_contents($root.$dir.'/another.png', 'another file');
    // One entry alone: the name that reaches it needs no more asking — but only a name that reaches it.
    file_put_contents($root.$dir.'/sole.png', 'a file of one entry');
    link($root.$dir.'/listed.png', $root.$dir.'/linked.png');
    symlink($root.$dir.'/listed.png', $root.$dir.'/pointer.png');

    expect(MediaBytes::reaches('bytes-source', $dir.'/listed.png', $dir.'/listed.png'))->toBeTrue()
        ->and(MediaBytes::reaches('bytes-source', $dir.'/listed.png', $dir.'/pointer.png'))->toBeTrue()
        ->and(MediaBytes::reaches('bytes-source', $dir.'/listed.png', $dir.'/another.png'))->toBeFalse()
        ->and(MediaBytes::reaches('bytes-source', $dir.'/listed.png', $dir.'/linked.png'))->toBeFalse()
        ->and(MediaBytes::reaches('bytes-source', $dir.'/listed.png', $dir.'/missing.png'))->toBeFalse()
        ->and(MediaBytes::reaches('bytes-source', $dir.'/sole.png', $dir.'/sole.png'))->toBeTrue()
        ->and(MediaBytes::reaches('bytes-source', $dir.'/sole.png', $dir.'/another.png'))->toBeFalse();
});

/*
 * ...and one inode on one device, not one inode number on two: a disk whose media tree spans two devices — a mount below
 * `media/` — may hold two files with one inode number, which only the device tells apart (review of slice 5c). A stream
 * wrapper stands in for the two devices: no test can choose the inode numbers of real files.
 */
it('reaches no entry of another device with the same inode number', function (): void {
    if (! in_array('kitsunestat', stream_get_wrappers(), true)) {
        stream_wrapper_register('kitsunestat', MediaBytesStatWrapper::class);
    }

    MediaBytesStatWrapper::$stats = [
        'kitsunestat://root/media/a.png' => ['dev' => 1, 'ino' => 77, 'nlink' => 1, 'mode' => 0100644],
        'kitsunestat://root/media/b.png' => ['dev' => 2, 'ino' => 77, 'nlink' => 1, 'mode' => 0100644],
        'kitsunestat://root/media/c.png' => ['dev' => 1, 'ino' => 77, 'nlink' => 1, 'mode' => 0100644],
    ];
    config(['filesystems.disks.bytes-stat' => ['driver' => 'local', 'root' => 'kitsunestat://root']]);
    Storage::set('bytes-stat', new FilesystemAdapter(new Filesystem($this->source), $this->source, ['driver' => 'local', 'root' => 'kitsunestat://root']));

    // One device and one inode: reached — so the test does not pass because the wrapper answered nothing.
    expect(MediaBytes::reaches('bytes-stat', 'media/a.png', 'media/c.png'))->toBeTrue()
        ->and(MediaBytes::reaches('bytes-stat', 'media/a.png', 'media/b.png'))->toBeFalse();
});

/*
 * ...and where the volume cannot say which entry a name ends at — realpath() fails, or a directory cannot be stat'ed, as a
 * rename between the stat and realpath() or a resolved path past MAXPATHLEN does — a listed name of the same file with a
 * hard link is taken for the row's own, and kept (review of slice 5c). realpath() answers false for a stream wrapper's
 * path, which is how this reaches the branch.
 */
it('keeps a listed name where the entry the name ends at cannot be told', function (): void {
    if (! in_array('kitsunestat', stream_get_wrappers(), true)) {
        stream_wrapper_register('kitsunestat', MediaBytesStatWrapper::class);
    }

    MediaBytesStatWrapper::$stats = [
        'kitsunestat://root/media/a.png' => ['dev' => 1, 'ino' => 78, 'nlink' => 2, 'mode' => 0100644],
        'kitsunestat://root/media/c.png' => ['dev' => 1, 'ino' => 78, 'nlink' => 2, 'mode' => 0100644],
        'kitsunestat://root/media/d.png' => ['dev' => 1, 'ino' => 79, 'nlink' => 2, 'mode' => 0100644],
    ];
    config(['filesystems.disks.bytes-stat' => ['driver' => 'local', 'root' => 'kitsunestat://root']]);
    Storage::set('bytes-stat', new FilesystemAdapter(new Filesystem($this->source), $this->source, ['driver' => 'local', 'root' => 'kitsunestat://root']));

    // Another file is still not reached, so the test does not pass because every name is kept.
    expect(MediaBytes::reaches('bytes-stat', 'media/a.png', 'media/d.png'))->toBeFalse()
        ->and(MediaBytes::reaches('bytes-stat', 'media/a.png', 'media/c.png'))->toBeTrue();
});

/*
 * ...and a name the volume cannot reach is no absent one: a stat refused for a reason other than absence — a directory
 * the command's user may not search — fails, where one below a file, or in a directory that is not there, is absent
 * (review of slice 5c). PHP reports a directory above the name that is not one as EIO, not ENOTDIR: the directory above
 * is asked.
 */
it('tells a name that is absent from one that cannot be reached', function (): void {
    $root = Storage::disk('bytes-source')->path('');
    $dir = 'media/1/2026/09';
    file_put_contents($root.$dir.'/file.png', 'a file');

    expect(MediaBytes::present('bytes-source', $dir.'/missing.png'))->toBeFalse()
        ->and(MediaBytes::statOf('bytes-source', $dir.'/file.png/x.png', $root.$dir.'/file.png/x.png'))->toBeNull()
        ->and(MediaBytes::statOf('bytes-source', $dir.'/file.png/sub/x.png', $root.$dir.'/file.png/sub/x.png'))->toBeNull()
        ->and(MediaBytes::statOf('bytes-source', 'media/9/x.png', $root.'media/9/x.png'))->toBeNull();

    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        return;
    }

    chmod($root.$dir, 0000);

    try {
        expect(fn () => MediaBytes::present('bytes-source', $dir.'/photo.png'))->toThrow(MediaCustodyFailure::class, 'whether it exists cannot be told');
    } finally {
        chmod($root.$dir, 0755);
    }
});

/*
 * ...but custody's own partial name, which the volume refuses as too long — or PHP does, a path of `PHP_MAXPATHLEN - 1`
 * bytes or more, which it reports as EIO — is absent: custody writes a copy under that name first, so none is there, and a
 * legal name 240-255 bytes long failed every trash, erasure and removal beside it. A row's own name so refused still fails
 * (review of slice 5c).
 */
it('reads custody\'s partial name refused as too long as absent, and no other name', function (string $case): void {
    $root = Storage::disk('bytes-source')->path('');
    $dir = 'media/1/2026/09';

    if ($case === 'path') {
        while (strlen($root.$dir) < PHP_MAXPATHLEN - 200) {
            $dir .= '/'.str_repeat('d', 150);
        }

        mkdir($root.$dir, 0777, true);
        $name = $dir.'/'.str_repeat('f', PHP_MAXPATHLEN - 2 - strlen($root.$dir));
    } else {
        $name = $dir.'/'.str_repeat('a', 260).'.png';
    }

    expect(MediaBytes::present('bytes-source', $name.MediaBytes::PARTIAL))->toBeFalse()
        ->and(fn () => MediaBytes::present('bytes-source', $name))->toThrow(MediaCustodyFailure::class, 'whether it exists cannot be told');
})->with(['a name longer than the volume takes' => 'name', 'a path as long as PHP takes' => 'path']);

/*
 * ...and so is a read-through disk's, which answers by its local halves' `is_file()`: a copy on either half that the
 * command cannot reach — under the disk's own prefix, and below a read-through primary — fails, never absent, where the
 * check passed on a trashed file's copy left on a served one (review of slice 5c).
 */
it('tells a name a read-through disk does not hold from one a half of it cannot reach', function (string $locked, array $rt): void {
    require_once dirname(__DIR__).'/Fixtures/PathPrefixedAdapter.php';
    $halves = [];

    foreach (['rt-primary', 'rt-fallback', 'rt-outer'] as $name) {
        $halves[$name] = RefusingDisk::install($name, ($this->root)($name))->root();
    }

    $nested = (bool) ($rt['nested'] ?? false);
    unset($rt['nested']);
    config(['filesystems.disks.rt-inner' => ['driver' => 'read-through', 'primary' => 'rt-primary', 'fallback' => 'rt-fallback']]);
    config(['filesystems.disks.rt' => $nested
        ? ['driver' => 'read-through', 'primary' => 'rt-inner', 'fallback' => 'rt-outer']
        : ['driver' => 'read-through', 'primary' => 'rt-primary', 'fallback' => 'rt-fallback', ...$rt]]);
    $dir = (isset($rt['prefix']) ? $rt['prefix'].'/' : '').'media/1/2026/09';

    foreach ($halves as $root) {
        mkdir($root.'/'.$dir, 0755, true);
    }

    expect(MediaBytes::present('rt', 'media/1/2026/09/photo.png'))->toBeFalse();

    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        return;
    }

    chmod($halves[$locked].'/'.$dir, 0000);

    try {
        expect(fn () => MediaBytes::present('rt', 'media/1/2026/09/photo.png'))->toThrow(MediaCustodyFailure::class, 'whether it exists cannot be told');
    } finally {
        chmod($halves[$locked].'/'.$dir, 0755);
    }
})->with([
    'its primary' => ['rt-primary', []],
    'its fallback' => ['rt-fallback', []],
    'its fallback, under the disk\'s own prefix' => ['rt-fallback', ['prefix' => 'layer']],
    'the fallback of its read-through primary' => ['rt-fallback', ['nested' => true]],
]);

/*
 * ...and never a name every disk reads as another path: Flysystem normalizes a path before it deletes it, so deleting the
 * name deleted that path — here, the photo (review of slice 5c).
 */
it('refuses to delete a name every disk reads as another path', function (string $name): void {
    RefusingDisk::forgetLog();

    expect(fn () => MediaBytes::delete('bytes-source', $name))->toThrow(MediaCustodyFailure::class, 'every disk reads its name as another path')
        ->and(Storage::disk('bytes-source')->exists('media/1/2026/09/photo.png'))->toBeTrue()
        ->and(operationsOn('bytes-source'))->toBe([]);
})->with([
    'a parent segment, as a local disk lists y\\..\\photo.png' => 'media/1/2026/09/y/../photo.png',
    'a backslash, which every disk reads as a slash' => 'media/1/2026/09\\photo.png',
    'an empty segment' => 'media/1/2026/09//photo.png',
    'a dot' => 'media/1/2026/09/./photo.png',
]);

/*
 * ...and a copy the disk answers absent for is deleted all the same — an object store's momentary 404 would otherwise leave
 * a copy on the web — where only a read-through disk that holds the path on neither half is asked first (review of 5c).
 */
it('deletes a copy the disk answers absent for, asking first only a read-through disk', function (bool $local): void {
    $path = 'media/1/2026/09/photo.png';

    if (! $local) {
        Storage::set('bytes-source', new FilesystemAdapter(new Filesystem($this->source), $this->source, ['driver' => 's3']));
    }

    $this->source->hidden = [$path];
    RefusingDisk::forgetLog();

    MediaBytes::delete('bytes-source', $path);

    expect(array_values(array_filter(RefusingDisk::$log, static fn (array $e): bool => $e['event'] === 'delete' && $e['path'] === $path)))->not->toBe([])
        ->and(is_file($this->source->root().'/'.$path))->toBeFalse();
})->with(['a local disk' => true, 'an object store' => false]);

/* A name under a `media` directory that is itself a link is held, as one under a linked root is; a link below it is not. */
it('holds a file under a linked media directory, and not one under a link below it', function (): void {
    $root = ($this->root)('linked');
    $volume = ($this->root)('volume');
    symlink($volume, $root.'/media');
    RefusingDisk::install('bytes-linked', $root);
    mkdir($volume.'/1/2026/09', 0777, true);
    file_put_contents($volume.'/1/2026/09/photo.png', 'bytes');
    $outside = ($this->root)('outside');
    file_put_contents($outside.'/photo.png', 'bytes');
    symlink($outside, $volume.'/1/2026/link');

    expect(MediaBytes::held('bytes-linked', 'media/1/2026/09/photo.png'))->toBeTrue()
        ->and(MediaBytes::held('bytes-linked', 'media/1/2026/link/photo.png'))->toBeFalse();
});

/** A disk that says it deleted a file and did not: only looking again finds it — review found nothing asked this. */
it('refuses a delete the disk reported and did not do', function (): void {
    $this->source->keepOnDelete = true;

    expect(fn () => MediaBytes::delete('bytes-source', 'media/1/2026/09/photo.png'))->toThrow(MediaCustodyFailure::class, 'could not be deleted');

    expect(Storage::disk('bytes-source')->exists('media/1/2026/09/photo.png'))->toBeTrue();
});

/** Answers `stat()` from a table, for the two devices a test cannot make (review of slice 5c). */
final class MediaBytesStatWrapper
{
    /** @var array<string, array<string, int>> */
    public static array $stats = [];

    /** @var resource|null */
    public $context;

    /** @return array<string, int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        return self::$stats[$path] ?? false;
    }
}
