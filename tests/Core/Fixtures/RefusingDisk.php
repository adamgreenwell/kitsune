<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Closure;
use Illuminate\Filesystem\LocalFilesystemAdapter as LaravelLocalAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToProvideChecksum;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use RuntimeException;

/**
 * A local disk that fails on command, and says what was done to it — ADR-042 decision 5's test harness.
 *
 * ⚠️ A REAL LOCAL ADAPTER, NOT A MOCK. Custody asks whether a disk is local to decide how it may copy and rename, so the
 * stand-in must be one; wrapped in Laravel's `LocalFilesystemAdapter` it is also one to `Storage`. To stand for an
 * object store instead, wrap it in the plain `FilesystemAdapter`.
 *
 * Every operation goes into one shared log, with the path and whether it changed bytes, so a test can put what the
 * disks did beside what the database did (`note()` takes the database's half) and assert the order.
 */
class RefusingDisk extends LocalFilesystemAdapter
{
    /** @var list<array{event: string, path: ?string, bytes: bool, disk: ?string}> */
    public static array $log = [];

    public bool $failWrites = false;

    public bool $failDeletes = false;

    /** Answer a delete as done and keep the file — a disk that acknowledges what it did not do. */
    public bool $keepOnDelete = false;

    public bool $failMoves = false;

    /** Write only this many bytes of anything written, as an interrupted copy leaves it. */
    public ?int $truncateWritesTo = null;

    /**
     * Write part of a stream and then fail, as a full disk does: Flysystem's local adapter reports the failure only after
     * `file_put_contents()` has created the file and copied what fitted. As many bytes as `truncateWritesTo`, or one.
     */
    public bool $failAfterWriting = false;

    /** @var list<string> Paths that exist but cannot be read. */
    public array $unreadable = [];

    /** @var list<string> Paths whose presence cannot be told: asking whether one exists throws, as a store's failure does. */
    public array $unknown = [];

    /** @var list<string> Paths the disk answers absent for while the file stays: an object store's momentary 404. */
    public array $hidden = [];

    /**
     * @var list<string> Paths that open and then fail on the first read, as a failing sector or mount does: a stream
     *                   opens (the file's directory) and its first read fails; a whole-file read or checksum fails as
     *                   `$unreadable` does.
     */
    public array $failReads = [];

    /** Fail a listing once it has listed this many files, as a store that fails part-way through does. */
    public ?int $failListingAfter = null;

    /** The listing `$failListingAfter` fails (1 for the first); every listing when null. */
    public ?int $failListingAfterOn = null;

    /** How many times the disk has been listed — and, set, the listing that fails at once (1 for the first). */
    public int $listings = 0;

    public ?int $failListing = null;

    /** List in descending path order — an order no sort can have come from. */
    public bool $reverseListing = false;

    /** Log each file listed as `list`, so a test can see how far a listing ran before something else happened. */
    public bool $logListing = false;

    /** @var list<string> Names listed as files, first, that are not on the disk — ones the test's filesystem cannot hold. */
    public array $phantoms = [];

    /** @var list<array{n: int, kind: string, callback: Closure(string, ?string): void}> */
    private array $triggers = [];

    private int $byteOperations = 0;

    private int $operations = 0;

    public function __construct(private readonly string $root, public readonly string $name = 'refusing')
    {
        parent::__construct($root);
    }

    /**
     * Install it under a disk name, as custody will resolve it.
     *
     * @param  array<string, mixed>  $config  added to the disk's configuration: `throw` makes Laravel rethrow what it
     *                                        would otherwise answer `false` for
     */
    public static function install(string $name, string $root, array $config = []): self
    {
        $adapter = new self($root, $name);

        Storage::set($name, new LaravelLocalAdapter(new Filesystem($adapter), $adapter, ['driver' => 'local', 'root' => $root, ...$config]));

        /*
         * ⚠️ AND THE CONFIGURATION NAMES THE SAME ROOT, when the disk is configured as a local one. Custody asks the
         * configuration whether a disk can hold anything before it builds one — `MediaDisks::mayHold()` — so a disk
         * installed here while its configured root pointed elsewhere was asked only if that other directory happened to
         * exist: in `vendor/`, where an earlier test had built the real disk (review of slice 5b).
         */
        if (is_array(config("filesystems.disks.{$name}")) && (config("filesystems.disks.{$name}.driver") ?? 'local') === 'local') {
            config(["filesystems.disks.{$name}.root" => $root]);
        }

        return $adapter;
    }

    /** Record the database's half of the timeline beside the disks'. */
    public static function note(string $event, ?string $path = null): void
    {
        self::$log[] = ['event' => $event, 'path' => $path, 'bytes' => false, 'disk' => null];
    }

    public static function forgetLog(): void
    {
        self::$log = [];
    }

    /**
     * Run a callback on the nth operation of a kind — `byte` for those that change bytes, `any` for all — before it
     * is performed.
     *
     * @param  Closure(string, ?string): void  $callback  given the operation and its path
     */
    public function onOperation(int $n, Closure $callback, string $kind = 'byte'): self
    {
        // Counted from now: the disk has usually done work of its own — a fixture's store, a cleanup — before this.
        $done = $kind === 'byte' ? $this->byteOperations : $this->operations;
        $this->triggers[] = ['n' => $done + $n, 'kind' => $kind, 'callback' => $callback];

        return $this;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->operation('write', $path, true);

        if ($this->failWrites) {
            throw UnableToWriteFile::atLocation($path, 'refused by the test');
        }

        parent::write($path, $this->truncateWritesTo === null ? $contents : substr($contents, 0, $this->truncateWritesTo), $config);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->operation('writeStream', $path, true);

        if ($this->failWrites) {
            throw UnableToWriteFile::atLocation($path, 'refused by the test');
        }

        if ($this->failAfterWriting) {
            parent::write($path, (string) fread($contents, max(1, $this->truncateWritesTo ?? 1)), $config);

            throw UnableToWriteFile::atLocation($path, 'refused by the test after writing');
        }

        if ($this->truncateWritesTo !== null) {
            parent::write($path, (string) fread($contents, max(1, $this->truncateWritesTo)), $config);

            return;
        }

        parent::writeStream($path, $contents, $config);
    }

    public function delete(string $path): void
    {
        $this->operation('delete', $path, true);

        if ($this->keepOnDelete) {
            return;
        }

        if ($this->failDeletes) {
            throw UnableToDeleteFile::atLocation($path, 'refused by the test');
        }

        parent::delete($path);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->operation('move', $source.' -> '.$destination, true);

        if ($this->failMoves) {
            throw UnableToMoveFile::fromLocationTo($source, $destination);
        }

        parent::move($source, $destination, $config);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->operation('copy', $source.' -> '.$destination, true);

        if ($this->failWrites) {
            throw UnableToCopyFile::fromLocationTo($source, $destination);
        }

        parent::copy($source, $destination, $config);
    }

    public function read(string $path): string
    {
        $this->operation('read', $path, false);

        if (in_array($path, $this->unreadable, true) || in_array($path, $this->failReads, true)) {
            throw UnableToReadFile::fromLocation($path, 'unreadable by the test');
        }

        return parent::read($path);
    }

    public function readStream(string $path)
    {
        $this->operation('readStream', $path, false);

        if (in_array($path, $this->unreadable, true)) {
            throw UnableToReadFile::fromLocation($path, 'unreadable by the test');
        }

        // The file's directory opens, and its first read fails (EISDIR) on macOS and Linux alike.
        return parent::readStream(in_array($path, $this->failReads, true) ? dirname($path) : $path);
    }

    public function checksum(string $path, Config $config): string
    {
        $this->operation('checksum', $path, false);

        if (in_array($path, $this->unreadable, true) || in_array($path, $this->failReads, true)) {
            throw new UnableToProvideChecksum('unreadable by the test', $path);
        }

        return parent::checksum($path, $config);
    }

    public function fileExists(string $location): bool
    {
        $this->operation('fileExists', $location, false);

        if (in_array($location, $this->unknown, true)) {
            throw UnableToCheckFileExistence::forLocation($location);
        }

        if (in_array($location, $this->hidden, true)) {
            return false;
        }

        return parent::fileExists($location);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $listing = ++$this->listings;
        $failAfter = $this->failListingAfterOn === null || $this->failListingAfterOn === $listing ? $this->failListingAfter : null;

        if ($listing === $this->failListing) {
            throw UnableToListContents::atLocation($path, $deep, new RuntimeException('the store could not be listed'));
        }

        $items = parent::listContents($path, $deep);

        if ($this->reverseListing) {
            $items = iterator_to_array($items, false);
            usort($items, static fn (StorageAttributes $a, StorageAttributes $b): int => strcmp($b->path(), $a->path()));
        }

        $files = 0;

        // Lazily, as the parent lists: a test that watches how far a listing ran must not see this one run ahead.
        $listed = (function () use ($items): iterable {
            foreach ($this->phantoms as $phantom) {
                yield new FileAttributes($phantom);
            }

            yield from $items;
        })();

        foreach ($listed as $item) {
            if ($item->isFile()) {
                if ($files === $failAfter) {
                    throw UnableToListContents::atLocation($path, $deep, new RuntimeException('the store failed part-way'));
                }

                $files++;

                if ($this->logListing) {
                    self::$log[] = ['event' => 'list', 'path' => $item->path(), 'bytes' => false, 'disk' => $this->name];
                }
            }

            yield $item;
        }
    }

    public function root(): string
    {
        return $this->root;
    }

    private function operation(string $event, ?string $path, bool $bytes): void
    {
        self::$log[] = ['event' => $event, 'path' => $path, 'bytes' => $bytes, 'disk' => $this->name];

        $this->operations++;

        if ($bytes) {
            $this->byteOperations++;
        }

        foreach ($this->triggers as $i => $trigger) {
            $count = $trigger['kind'] === 'byte' ? $this->byteOperations : $this->operations;

            if (($trigger['kind'] === 'any' || $bytes) && $count === $trigger['n']) {
                unset($this->triggers[$i]);
                ($trigger['callback'])($event, $path);
            }
        }
    }
}
