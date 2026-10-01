<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Media;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Filesystem\ReadThroughFilesystem;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem as LeagueFilesystem;
use League\Flysystem\PathPrefixer;
use League\Flysystem\WhitespacePathNormalizer;
use LogicException;
use ReflectionProperty;
use Throwable;

/**
 * The byte operations custody is built from, each one saying clearly whether it happened — ADR-042 decision 5.
 *
 * @internal
 *
 * ⚠️ THREE ANSWERS TO "WHAT IS THERE?", NEVER TWO. A file is absent, or it has a hash, or asking failed — and a hash
 * that could not be read is never taken for an absent file, which would let a step decide the only copy was not there.
 * Every comparison goes through `same()`, which is false unless both sides have a hash.
 *
 * ⚠️ A COPY IS WRITTEN BESIDE ITS PATH, READ BACK, AND ONLY THEN MOVED INTO PLACE, on a local disk: a rename replaces the
 * destination whole, so a Kitsune path holds nothing, the file it held before, or a complete copy that has been read
 * back — never part of one. An object store's PUT is all or nothing already. No `fsync`: a copy counts once it is
 * written and read back with a matching hash, the durability `MediaLibrary::store()` gives every file (decision 3,
 * Adam, 2026-09-24).
 */
final class MediaBytes
{
    /** The name a copy is written under, beside its path, before it is read back and renamed into place. */
    public const PARTIAL = '.kitsune-partial';

    public static function local(string $disk): bool
    {
        return self::disk($disk) instanceof LocalFilesystemAdapter;
    }

    /**
     * Whether a disk's listing is a local directory's: a local disk, or a `read-through` disk whose primary — the half it
     * lists — is one, at any depth. Laravel builds such a disk over its primary's adapter, so its own `path()` is under the
     * primary's root, and a name is asked of that volume by stat (review of slice 5c). ⚠️ Under its own prefix alone: its
     * configuration is merged over its primary's, so where both set a prefix the name asked is not the one listed, and a
     * row's own file there is listed as an orphan, on which every forced run fails — recorded, with the read-through
     * question (ADR-042 decision 5).
     */
    public static function listsLocally(string $disk): bool
    {
        $filesystem = self::disk($disk);

        while ($filesystem instanceof ReadThroughFilesystem) {
            $filesystem = (fn (): FilesystemAdapter => $this->primary)->call($filesystem);
        }

        return $filesystem instanceof LocalFilesystemAdapter;
    }

    /**
     * Whether a disk holds a file at a path — and a failure, never false, where it cannot say. On a local disk Flysystem
     * answers by `is_file()`, which is false for a stat refused for any reason: a directory the command's user may not
     * search, an I/O error. Such an answer is asked again why (`statOf()`), so a copy that is there and cannot be reached is
     * never taken for an absent one — the check passed on it, and --force called its file missing and advised erasing
     * the entry (review of slice 5c). So is a `read-through` disk's answer, which is its local halves' `is_file()`.
     */
    public static function present(string $disk, string $path): bool
    {
        try {
            $filesystem = self::disk($disk);

            if ($filesystem->fileExists($path)) {
                return true;
            }

            $names = self::localNames($filesystem, $path);
        } catch (Throwable $failure) {
            throw new MediaCustodyFailure('unknown', $disk, $path, $failure);
        }

        // Nothing there, or something that is not a file, on every local name asked: absent. A stat refused otherwise fails
        // — but for custody's own partial name, which a volume refusing it as too long never held: `copyVerified()` writes
        // it first, and a legal 240-255-byte name failed every trash, erasure and removal beside it (review of slice 5c).
        foreach ($names as $at) {
            try {
                self::statOf($disk, $path, $at);
            } catch (MediaCustodyFailure $failure) {
                if (str_ends_with($path, self::PARTIAL) && self::tooLong($at)) {
                    continue;
                }

                throw $failure;
            }
        }

        return false;
    }

    /**
     * The local names a disk's `fileExists()` read by `is_file()`: its own, where it is local, or each local half's of a
     * `read-through` disk — primary and fallback, at any depth, under each layer's own prefix, as Laravel sends a path
     * down — which answered false alike for a copy there that cannot be reached (review of slice 5c).
     *
     * @return list<string>
     */
    private static function localNames(Filesystem $filesystem, string $path): array
    {
        if ($filesystem instanceof LocalFilesystemAdapter) {
            return [$filesystem->path($path)];
        }

        if (! $filesystem instanceof ReadThroughFilesystem) {
            return [];
        }

        $driver = $filesystem->getDriver();
        $adapter = $driver instanceof LeagueFilesystem ? (fn (): object => $this->adapter)->call($driver) : null;

        // A layer with a prefix of its own Laravel builds over league/flysystem-path-prefixing, which a host installs to use one.
        if ($adapter !== null && is_a($adapter, 'League\\Flysystem\\PathPrefixing\\PathPrefixedAdapter')) {
            $prefixer = (new ReflectionProperty($adapter, 'prefix'))->getValue($adapter);

            if ($prefixer instanceof PathPrefixer) {
                $path = $prefixer->prefixPath($path);
            }
        }

        [$primary, $fallback] = (fn (): array => [$this->primary, $this->fallback])->call($filesystem);

        return [...self::localNames($primary, $path), ...self::localNames($fallback, $path)];
    }

    /**
     * What a local disk holds at a name, by stat: its stat, or null where nothing is there — the name, or a directory above
     * it, absent — and a failure where the volume cannot say. A stat refused for any other reason — I/O, permission, a
     * network mount reconnecting — is never read as an absence: the pass that asks the volume would have left the row's
     * file an orphan, and a forced run removed it once the error cleared (review of slice 5c).
     *
     * @return array<int|string, int>|null
     */
    public static function statOf(string $disk, string $name, string $at): ?array
    {
        $stat = @stat($at);

        if (is_array($stat)) {
            return $stat;
        }

        if (self::absent($at)) {
            return null;
        }

        throw new MediaCustodyFailure('unknown', $disk, $name);
    }

    /**
     * Whether a name a stat refused is absent, itself or a directory above it, as the volume says. ENOENT, 2 on Linux and
     * macOS alike, is an absence. A directory above the name that is not one — the kernel's ENOTDIR — reaches PHP as EIO,
     * since posix_access() resolves the path before it asks (review of slice 5c): so any other answer asks the directory
     * above, and the name is absent where that is not a directory, or is itself absent. One there now is not: a stat
     * refused a moment ago is no absence.
     */
    private static function absent(string $at): bool
    {
        if (function_exists('posix_access')) {
            if (@posix_access($at, POSIX_F_OK)) {
                return false;
            }

            return posix_get_last_error() === 2 || self::belowNonDirectory($at);
        }

        $why = self::unopened($at);

        return $why !== null && (str_ends_with($why, 'No such file or directory') || str_ends_with($why, 'Not a directory'));
    }

    /**
     * Whether the volume refuses a name as too long for it — ENAMETOOLONG, 36 on Linux and 63 on macOS and the BSDs — or
     * PHP does, before the kernel sees it: its file functions refuse a path of `PHP_MAXPATHLEN - 1` bytes or more,
     * `posix_access()` as EIO and `fopen()` as EINVAL. No entry answers to such a name that custody could have written
     * (review of slice 5c).
     */
    public static function tooLong(string $at): bool
    {
        if (strlen($at) >= PHP_MAXPATHLEN - 1) {
            return true;
        }

        if (function_exists('posix_access')) {
            return ! @posix_access($at, POSIX_F_OK) && posix_get_last_error() === (PHP_OS_FAMILY === 'Linux' ? 36 : 63);
        }

        return str_ends_with((string) self::unopened($at), 'File name too long');
    }

    /**
     * Without ext-posix, why a name cannot be opened, from the warning fopen() raises — through a handler of its own, since
     * the application's may keep PHP from recording it — or null where it opens.
     */
    private static function unopened(string $at): ?string
    {
        $message = '';
        set_error_handler(static function (int $level, string $text) use (&$message): bool {
            $message = $text;

            return true;
        });

        try {
            $handle = fopen($at, 'rb');
        } finally {
            restore_error_handler();
        }

        if ($handle === false) {
            return $message;
        }

        fclose($handle);

        return null;
    }

    /** Whether the directory above a name is not one, or is itself absent: the name is then absent too. */
    private static function belowNonDirectory(string $at): bool
    {
        $above = dirname($at);

        if ($above === $at || $above === '.' || $above === '') {
            return false;
        }

        $stat = @stat($above);

        if (is_array($stat)) {
            return ($stat['mode'] & 0170000) !== 0040000;
        }

        return self::absent($above);
    }

    /** The file's SHA-256, or null when it is not there — and a failure, never null, when it is there and unreadable. */
    public static function hash(string $disk, string $path): ?string
    {
        if (! self::present($disk, $path)) {
            return null;
        }

        self::refuseReadThrough($disk, $path);

        try {
            $hash = self::disk($disk)->checksum($path, ['checksum_algo' => 'sha256']);
        } catch (Throwable $failure) {
            throw new MediaCustodyFailure('unreadable', $disk, $path, $failure);
        }

        if (! is_string($hash) || $hash === '') {
            throw new MediaCustodyFailure('unreadable', $disk, $path);
        }

        return $hash;
    }

    /**
     * Whether a copy a disk holds can be read — opened, and its first byte read — without hashing it: the read-only
     * listing's stand-in for the read custody makes before it removes anything (Adam, decision 12, 2026-09-26).
     *
     * ⚠️ ASKED ONLY OF A COPY THE DISK SAYS IT HOLDS, so false means "there, and cannot be read", as `hash()`'s failure
     * does. A copy that opens and then fails part-way reads as readable here; `hash()` finds it. Opened through Laravel's
     * `readStream()`: one open on a local disk; on an S3 disk one GET, abandoned after the first byte only where the disk
     * sets `'stream_reads' => true` — Laravel's default is false, and the SDK then downloads the whole object into
     * `php://temp` before `readStream()` returns, as an FTP or SFTP disk's adapter always does (review of slice 5c, twice).
     *
     * ⚠️ NEVER OF A `read-through` DISK — review of slice 5c. Opened through one, a copy only its fallback holds is copied
     * into its primary, so a read-only check would write; and custody removes no copy there (`delete()`), so no step
     * reads one to remove it, and the check has nothing to stand in for.
     *
     * @throws LogicException for a read-through disk
     */
    public static function readable(string $disk, string $path): bool
    {
        if (self::disk($disk) instanceof ReadThroughFilesystem) {
            throw new LogicException(sprintf('Refusing to open [%s] on the [%s] disk: it is a read-through disk, which would copy it into its primary.', $path, $disk));
        }

        try {
            // A disk configured not to throw answers a copy it cannot open with null.
            $stream = self::disk($disk)->readStream($path);

            if (! is_resource($stream)) {
                return false;
            }

            try {
                return fread($stream, 1) !== false;
            } finally {
                fclose($stream);
            }
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether the disk holds a file under exactly this listed name — on a local disk, a file reached through no link, on
     * itself or on a directory above it — so that removing the name removes what the listing saw (review of slice 5c).
     *
     * ⚠️ A LOCAL DISK LISTS A BACKSLASH IN A FILE'S NAME AS `/`: `a\b.png` is listed as `a/b.png`, which holds nothing, or
     * a link the listing left out, or a file under a linked directory it left out. Removing that name removed nothing and
     * said it had, on every run, or removed what the link reaches — a file outside the media directory.
     */
    public static function held(string $disk, string $path): bool
    {
        if (! self::present($disk, $path)) {
            return false;
        }

        if (! self::local($disk)) {
            return true;
        }

        // Every component below `media/`, where the listing starts: the disk's root and `media` itself may be links — a
        // deployment's shared `storage`, media on another volume — and the listing follows both (review of slice 5c).
        $filesystem = self::disk($disk);
        $parts = explode('/', $path);
        $reached = count($parts) > 1 && $parts[0] === 'media' ? array_shift($parts) : '';

        foreach ($parts as $part) {
            $reached = $reached === '' ? $part : $reached.'/'.$part;

            if (is_link($filesystem->path($reached))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a name reaches the very directory entry a local disk listed — so that removing the listed name removes what
     * the name reaches. The volume decides, by stat, not the names (Codex, #155): another spelling it folds — case, Unicode
     * normalization, a directory spelt otherwise — or a link on the way reaches the entry; a hard link is another entry for
     * the same file, beside it or in another directory, and does not. Where a hard link and another spelling of the name
     * share a directory, the volume does not say which entry the name reaches: it is taken to reach this one — so a row's
     * path keeps it, and a row's partial path takes a partial copy so reached for that row's (review of slice 5c).
     */
    public static function reaches(string $disk, string $listed, string $name): bool
    {
        return self::reachedAmong($disk, $name, [$listed]) === [0];
    }

    /**
     * The listed names, among several a local disk listed, whose very directory entry a name reaches — `reaches()` asked of
     * each, by the same rules.
     *
     * ⚠️ THE NAME RESOLVED ONCE, AND ITS DIRECTORY READ AT MOST ONCE, HOWEVER MANY NAMES ARE ASKED — review of slice 5c.
     * Asked a name at a time, a row's path stat'ed to a file many listed names are hard links of — a dedup tool's links
     * across a media tree — cost a realpath and four stats for each, and where they shared its directory, a read of the
     * directory for each: 400 rows and 400 such names in one month's directory took a minute. Now each listed name costs a
     * stat of it and of its directory, and the name's directory is read once, only where one of them needs it.
     *
     * @param  array<int, string>  $listed  names the disk listed, by any key
     * @return list<int> the keys of those the name reaches
     */
    public static function reachedAmong(string $disk, string $name, array $listed): array
    {
        clearstatcache();

        try {
            $filesystem = self::disk($disk);
            $to = $filesystem->path($name);
        } catch (Throwable) {
            return [];
        }

        // Gone since the pass stat'ed it: it reaches nothing. One the volume cannot answer for fails the run.
        $two = self::statOf($disk, $name, $to);

        if ($two === null) {
            return [];
        }

        $reached = [];
        $real = null;
        $in = false;
        $spelled = null;

        foreach ($listed as $key => $path) {
            try {
                $at = $filesystem->path($path);
            } catch (Throwable) {
                continue;
            }

            $one = self::statOf($disk, $path, $at);

            if ($one === null || $one['dev'] !== $two['dev'] || $one['ino'] !== $two['ino']) {
                continue;
            }

            // One entry has the file: the name ends at it, however it gets there.
            if ((int) $one['nlink'] === 1) {
                $reached[] = $key;

                continue;
            }

            if ($real === null) {
                $real = realpath($to);
                $in = $real === false ? false : @stat(dirname($real));
            }

            $here = @stat(dirname($at));

            // Where the entry the name ends at cannot be told, it is taken for this one: kept.
            if (! is_array($in) || ! is_array($here) || $real === false) {
                $reached[] = $key;

                continue;
            }

            // An entry in another directory: a hard link, which removing this name leaves.
            if ($in['dev'] !== $here['dev'] || $in['ino'] !== $here['ino']) {
                continue;
            }

            // In this directory: this entry, unless the directory holds an entry under exactly the name's own spelling —
            // one directory, read once for every listed name in it.
            if (basename($real) === basename($at)) {
                $reached[] = $key;

                continue;
            }

            $spelled ??= self::entryListed(dirname($at), basename($real));

            if (! $spelled) {
                $reached[] = $key;
            }
        }

        return $reached;
    }

    private static function entryListed(string $directory, string $name): bool
    {
        $handle = @opendir($directory);

        if ($handle === false) {
            return false;
        }

        try {
            while (($entry = readdir($handle)) !== false) {
                if ($entry === $name) {
                    return true;
                }
            }
        } finally {
            closedir($handle);
        }

        return false;
    }

    public static function same(?string $a, ?string $b): bool
    {
        return $a !== null && $b !== null && hash_equals($a, $b);
    }

    public static function partial(string $path): string
    {
        return $path.self::PARTIAL;
    }

    /**
     * Copy a file to another disk and prove the copy is whole: its SHA-256 must be the one expected.
     *
     * @throws MediaCustodyFailure
     */
    public static function copyVerified(string $from, string $to, string $path, string $expected): void
    {
        self::refuseReadThrough($from, $path);
        self::refuseReadThrough($to, $path);

        if (self::sameObject($from, $to, $path)) {
            throw new MediaCustodyFailure('coinciding', $to, $path);
        }

        try {
            $stream = self::disk($from)->readStream($path);
        } catch (Throwable $failure) {
            throw new MediaCustodyFailure('unreadable', $from, $path, $failure);
        }

        if (! is_resource($stream)) {
            throw new MediaCustodyFailure('unreadable', $from, $path);
        }

        self::writeVerified($to, $path, $stream, $expected, keepKey: false);
    }

    /**
     * Copy a disk's file into the system's temporary directory and prove it whole: its SHA-256 must be the one expected —
     * ADR-042 decision 32, which strips a JPEG made public from such a copy. The caller owns the copy and removes it.
     *
     * ⚠️ AND IT IS REMOVED AT PROCESS END WHATEVER HAPPENS. It is the original, its location still in it: a fatal error
     * between this and the caller's `finally` would otherwise leave it in a directory other processes can read.
     *
     * @throws MediaCustodyFailure `unreadable`, `verify`, `copy` or `read-through`
     */
    public static function toTemporary(string $disk, string $path, string $expected): string
    {
        self::refuseReadThrough($disk, $path);

        $temporary = tempnam(sys_get_temp_dir(), 'kitsune-visibility-');

        if ($temporary === false) {
            throw new MediaCustodyFailure('copy', $disk, $path);
        }

        register_shutdown_function(static function () use ($temporary): void {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        });

        try {
            $stream = self::disk($disk)->readStream($path);
        } catch (Throwable $failure) {
            @unlink($temporary);

            throw new MediaCustodyFailure('unreadable', $disk, $path, $failure);
        }

        if (! is_resource($stream)) {
            @unlink($temporary);

            throw new MediaCustodyFailure('unreadable', $disk, $path);
        }

        $out = fopen($temporary, 'wb');
        $copied = $out !== false && stream_copy_to_stream($stream, $out) !== false;
        self::close($stream);

        if ($out !== false) {
            fclose($out);
        }

        if (! $copied) {
            @unlink($temporary);

            throw new MediaCustodyFailure('unreadable', $disk, $path);
        }

        if (! self::same(hash_file('sha256', $temporary) ?: null, $expected)) {
            @unlink($temporary);

            throw new MediaCustodyFailure('verify', $disk, $path);
        }

        return $temporary;
    }

    /**
     * Write a local file's bytes over a disk's path and prove them: its SHA-256 must be the one expected — ADR-042
     * decision 32, which makes a JPEG stripped of its location the file on the private disk.
     *
     * Written beside the path, read back, and renamed over it on a local disk, so the path holds the file it held or the
     * new one, whole; an object store's PUT replaces the key, and is read back.
     *
     * ⚠️ AN OBJECT STORE'S KEY IS THE FILE: a replacement that does not read back is left there, never discarded, for the
     * caller to put back — discarding it would delete the only copy.
     *
     * @throws MediaCustodyFailure `copy`, `verify`, `rename`, `unreadable` or `read-through`
     */
    public static function replaceVerified(string $disk, string $path, string $source, string $expected): void
    {
        self::refuseReadThrough($disk, $path);

        $stream = @fopen($source, 'rb');

        if ($stream === false) {
            throw new MediaCustodyFailure('copy', $disk, $path);
        }

        self::writeVerified($disk, $path, $stream, $expected, keepKey: true);
    }

    /**
     * Write a stream to a disk's path, read it back, and only then move it into place — the tail `copyVerified()` and
     * `replaceVerified()` share.
     *
     * @param  resource  $stream  closed here
     * @param  bool  $keepKey  whether a copy written at the path itself — an object store's key — is left in place when
     *                         it does not verify, because it is the file; a local partial copy is always discarded
     *
     * @throws MediaCustodyFailure
     */
    private static function writeVerified(string $to, string $path, mixed $stream, string $expected, bool $keepKey): void
    {
        $destination = self::local($to) ? self::partial($path) : $path;
        $discard = static function () use ($to, $destination, $path, $keepKey): void {
            if (! $keepKey || $destination !== $path) {
                self::discard($to, $destination);
            }
        };

        try {
            $written = self::disk($to)->writeStream($destination, $stream);
        } catch (Throwable $failure) {
            $discard();

            throw new MediaCustodyFailure('copy', $to, $path, $failure);
        } finally {
            self::close($stream);
        }

        if ($written === false) {
            $discard();

            throw new MediaCustodyFailure('copy', $to, $path);
        }

        try {
            $copied = self::hash($to, $destination);
        } catch (MediaCustodyFailure $failure) {
            $discard();

            throw $failure;
        }

        if (! self::same($expected, $copied)) {
            $discard();

            throw new MediaCustodyFailure('verify', $to, $path);
        }

        if ($destination !== $path) {
            $moved = false;

            try {
                $moved = self::disk($to)->move($destination, $path);
            } catch (Throwable) {
                $moved = false;
            }

            if (! $moved) {
                self::discard($to, $destination);

                throw new MediaCustodyFailure('rename', $to, $path);
            }
        }
    }

    /**
     * Delete a file and confirm it is gone.
     *
     * ⚠️ ONLY A NAME EVERY DISK READS AS ITSELF — review of slice 5c. Flysystem normalizes a path before it acts on it:
     * a name holding `//`, `.` or `..` — an object store's key, or a local file's whose backslashes the listing gave as
     * `/` — is read as another path, and deleting it would delete that one: a row's only file, or a file outside the
     * media directory. Such a name is refused, as `MediaFile` refuses it for a row, and is removed by hand. A local
     * name whose backslashes alone the listing changed reads as itself, and holds nothing: a removal of a listed name
     * asks `held()` first (`MediaCustody::removeOrphan()`).
     *
     * ⚠️ NEVER THROUGH A `read-through` DISK (`refuseReadThrough()`) — but one that holds the path on neither half is
     * asked only that, and nothing is removed; a local half that cannot say fails, as `present()` does.
     *
     * @throws MediaCustodyFailure
     */
    public static function delete(string $disk, string $path): void
    {
        self::refuseUnnamable($disk, $path);

        try {
            $filesystem = self::disk($disk);
        } catch (ReadThroughCycle $cycle) {
            throw new MediaCustodyFailure('unknown', $disk, $path, $cycle);
        }

        // Asked whether it holds the path, a read-through disk that holds it on neither half has nothing to remove and
        // nothing read: a disposal or a withdrawal that deletes from every served disk passes it (review of slice 5c).
        if ($filesystem instanceof ReadThroughFilesystem && ! self::present($disk, $path)) {
            return;
        }

        self::refuseReadThrough($disk, $path);

        try {
            $deleted = $filesystem->delete($path);
        } catch (Throwable $failure) {
            throw new MediaCustodyFailure('delete', $disk, $path, $failure);
        }

        if (! $deleted || self::present($disk, $path)) {
            throw new MediaCustodyFailure('delete', $disk, $path);
        }
    }

    /**
     * Whether two local disks reach the same file at this path — one object on disk, whatever the names.
     *
     * An absent file is never the same object; a disk that is not local is never compared this way.
     */
    public static function sameObject(string $a, string $b, string $path): bool
    {
        if (! self::local($a) || ! self::local($b)) {
            return false;
        }

        $first = @stat(self::disk($a)->path($path));
        $second = @stat(self::disk($b)->path($path));

        return is_array($first) && is_array($second)
            && $first['dev'] === $second['dev'] && $first['ino'] === $second['ino'];
    }

    /**
     * Whether two local disks name one directory entry at this path: their directories for it are one directory, as
     * the filesystem resolves them, so the path on each is the same name for the same file.
     *
     * ⚠️ NOT THE SAME FILE, THE SAME ENTRY — review of slice 5b. A hard link, a symlinked file or a bind mount reaches one
     * file through two entries, and removing the other entry is what takes that name off the web; only one entry under
     * two disk names has nothing to remove. A mount the path's resolution cannot see reads as two entries, never as one.
     */
    public static function sameEntry(string $a, string $b, string $path): bool
    {
        if (! self::local($a) || ! self::local($b)) {
            return false;
        }

        $first = realpath(dirname(self::disk($a)->path($path)));
        $second = realpath(dirname(self::disk($b)->path($path)));

        return $first !== false && $first === $second;
    }

    /**
     * Refuse a name no disk can be asked about as itself: one Flysystem refuses — not UTF-8, or holding a character of
     * Unicode's class C — or reads as another path, as `delete()` explains. Asked before any disk is asked of a listed
     * name, so its refusal is the one given, whatever a disk would have been asked next (review of slice 5c). An orphan's
     * claim under the lock is asked first (`MediaCustody::removeOrphan()`): a name a row claims is kept, and on PostgreSQL
     * a name that is not UTF-8 fails that lookup (22021).
     *
     * @throws MediaCustodyFailure `refused`, or `aliased`
     */
    public static function refuseUnnamable(string $disk, string $path): void
    {
        try {
            $read = (new WhitespacePathNormalizer)->normalizePath($path);
        } catch (Throwable $failure) {
            throw new MediaCustodyFailure('refused', $disk, $path, $failure);
        }

        if ($read !== $path) {
            throw new MediaCustodyFailure('aliased', $disk, $path);
        }
    }

    /**
     * Refuse a row whose path every disk reads as another path, or refuses: written past `MediaFile`'s guard — a direct
     * insert, an import — any step that copied or removed it would act on the path the disks read, another row's file
     * among them (review of slice 5c). Asked before custody asks any disk of the row.
     *
     * @throws MediaCustodyFailure `misnamed`
     */
    public static function refuseMisnamed(string $disk, string $path): void
    {
        try {
            self::refuseUnnamable($disk, $path);
        } catch (MediaCustodyFailure $failure) {
            throw new MediaCustodyFailure('misnamed', $disk, $path, $failure);
        }
    }

    /**
     * Refuse to read or remove a copy through a `read-through` disk — review of slice 5c.
     *
     * ⚠️ ITS READ WRITES, AND ITS DELETE REMOVES WHAT WAS NOT READ. A copy only its fallback holds is copied into its
     * primary as it is read — a hash, a copy's source — and its delete removes the path from both halves, when custody
     * read only one of them, the primary if it holds the file: a copy never hashed went with it, the only one that matched
     * the checksum, or one that differed with no warning naming its hash. So custody asks a read-through disk only whether
     * it holds a file, and a step that would read or remove a copy there fails, with nothing done. It may be the only
     * copy, or the only one that matches the checksum, so a person compares it with the copy where the row belongs, by
     * hand, before removing it through the disk each half is (ADR-042 decision 5, open for Adam).
     *
     * @throws MediaCustodyFailure
     */
    private static function refuseReadThrough(string $disk, string $path): void
    {
        if (self::readsThrough($disk)) {
            throw new MediaCustodyFailure('read-through', $disk, $path);
        }
    }

    /** Whether a disk is built as a `read-through` one — by the configuration, or a driver of the host's own. */
    public static function readsThrough(string $disk): bool
    {
        return self::disk($disk) instanceof ReadThroughFilesystem;
    }

    /**
     * The disk, built — never a `read-through` one whose halves name each other, which Laravel's builder would follow
     * until memory ran out: a fatal error no catch can answer. Every disk this class asks is built here, so no step can
     * build one, whichever disk a row names (review of slice 5c).
     *
     * @throws ReadThroughCycle
     */
    private static function disk(string $disk): Filesystem
    {
        if (MediaDisks::cycles(config(), $disk)) {
            throw new ReadThroughCycle(sprintf('Refusing to build the [%s] disk: its read-through disks form a cycle.', $disk));
        }

        return Storage::disk($disk);
    }

    /** An adapter may already have closed the stream it was handed. */
    private static function close(mixed $stream): void
    {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    /** Remove a copy that did not verify, as far as the disk allows: it is never anything a row names. */
    private static function discard(string $disk, string $path): void
    {
        try {
            self::disk($disk)->delete($path);
        } catch (Throwable) {
            // Left for kitsune:media-prune, which removes a temporary copy under its row's lock.
        }
    }
}
