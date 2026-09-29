<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace League\Flysystem\PathPrefixing;

use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\PathPrefixer;
use League\Flysystem\StorageAttributes;

/*
 * A stand-in for league/flysystem-path-prefixing, which Laravel builds a disk with a `prefix` — its own, or a scoped
 * disk's — over, and which this repository does not install. Required by a test that needs such a disk, and declared only
 * where the package is not there: what it does is what the package does, the prefix added on the way in and taken off
 * a listing on the way out.
 */
if (! class_exists(PathPrefixedAdapter::class)) {
    final class PathPrefixedAdapter implements FilesystemAdapter
    {
        private PathPrefixer $prefix;

        public function __construct(private readonly FilesystemAdapter $adapter, string $prefix)
        {
            $this->prefix = new PathPrefixer($prefix);
        }

        public function fileExists(string $path): bool
        {
            return $this->adapter->fileExists($this->prefix->prefixPath($path));
        }

        public function directoryExists(string $path): bool
        {
            return $this->adapter->directoryExists($this->prefix->prefixDirectoryPath($path));
        }

        public function write(string $path, string $contents, Config $config): void
        {
            $this->adapter->write($this->prefix->prefixPath($path), $contents, $config);
        }

        public function writeStream(string $path, $contents, Config $config): void
        {
            $this->adapter->writeStream($this->prefix->prefixPath($path), $contents, $config);
        }

        public function read(string $path): string
        {
            return $this->adapter->read($this->prefix->prefixPath($path));
        }

        public function readStream(string $path)
        {
            return $this->adapter->readStream($this->prefix->prefixPath($path));
        }

        public function delete(string $path): void
        {
            $this->adapter->delete($this->prefix->prefixPath($path));
        }

        public function deleteDirectory(string $path): void
        {
            $this->adapter->deleteDirectory($this->prefix->prefixDirectoryPath($path));
        }

        public function createDirectory(string $path, Config $config): void
        {
            $this->adapter->createDirectory($this->prefix->prefixDirectoryPath($path), $config);
        }

        public function setVisibility(string $path, string $visibility): void
        {
            $this->adapter->setVisibility($this->prefix->prefixPath($path), $visibility);
        }

        public function visibility(string $path): FileAttributes
        {
            return $this->adapter->visibility($this->prefix->prefixPath($path));
        }

        public function mimeType(string $path): FileAttributes
        {
            return $this->adapter->mimeType($this->prefix->prefixPath($path));
        }

        public function lastModified(string $path): FileAttributes
        {
            return $this->adapter->lastModified($this->prefix->prefixPath($path));
        }

        public function fileSize(string $path): FileAttributes
        {
            return $this->adapter->fileSize($this->prefix->prefixPath($path));
        }

        public function listContents(string $path, bool $deep): iterable
        {
            foreach ($this->adapter->listContents($this->prefix->prefixDirectoryPath($path), $deep) as $item) {
                /** @var StorageAttributes $item */
                yield $item instanceof DirectoryAttributes
                    ? $item->withPath($this->prefix->stripDirectoryPrefix($item->path()))
                    : $item->withPath($this->prefix->stripPrefix($item->path()));
            }
        }

        public function move(string $source, string $destination, Config $config): void
        {
            $this->adapter->move($this->prefix->prefixPath($source), $this->prefix->prefixPath($destination), $config);
        }

        public function copy(string $source, string $destination, Config $config): void
        {
            $this->adapter->copy($this->prefix->prefixPath($source), $this->prefix->prefixPath($destination), $config);
        }
    }
}
