<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Imports;

use InvalidArgumentException;
use Kitsune\Core\Media\MediaIntake;

/** @internal */
final readonly class ImportMediaAsset
{
    public function __construct(
        public string $sourceType,
        public string $sourceId,
        public string $path,
        public string $originalName,
        public int $size,
        public string $sha256,
        public ?int $entryTypeId = null,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._:-]{0,95}$/D', $sourceType) !== 1
            || $sourceId === '' || strlen($sourceId) > 4096
            || $path === '' || str_contains($path, '\\') || str_contains($path, "\0")
            || array_intersect(explode('/', $path), ['', '.', '..']) !== []
            || $originalName === '' || strpbrk($originalName, "/\\\0") !== false
            || $size < 1 || $size > MediaIntake::MAX_BYTES || ($entryTypeId !== null && $entryTypeId < 1)
            || preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1) {
            throw new InvalidArgumentException('The import media descriptor is invalid.');
        }
    }

    /** @return array{source_type:string, source_id:string, path:string, original_name:string, size:int, sha256:string, entry_type_id:?int} */
    public function descriptor(): array
    {
        return [
            'source_type' => $this->sourceType, 'source_id' => $this->sourceId,
            'path' => $this->path, 'original_name' => $this->originalName, 'size' => $this->size, 'sha256' => $this->sha256, 'entry_type_id' => $this->entryTypeId,
        ];
    }

    public function descriptorHash(): string
    {
        return hash('sha256', json_encode($this->descriptor(), JSON_THROW_ON_ERROR));
    }

    public static function fromDescriptor(mixed $input): self
    {
        if (! $input instanceof \stdClass
            || array_diff(array_keys(get_object_vars($input)), ['source_type', 'source_id', 'path', 'original_name', 'size', 'sha256', 'entry_type_id']) !== []
            || ! is_string($input->source_type ?? null) || ! is_string($input->source_id ?? null)
            || ! is_string($input->path ?? null) || ! is_string($input->original_name ?? null)
            || ! is_int($input->size ?? null) || ! is_string($input->sha256 ?? null)
            || (isset($input->entry_type_id) && ! is_int($input->entry_type_id))) {
            throw new InvalidArgumentException('The import media descriptor is invalid.');
        }

        return new self($input->source_type, $input->source_id, $input->path, $input->original_name, $input->size, $input->sha256, $input->entry_type_id ?? null);
    }
}
