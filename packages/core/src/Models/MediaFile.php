<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kitsune\Core\Tenancy\Attributes\Unscoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Contracts\FixesColumnsAtCreation;
use League\Flysystem\WhitespacePathNormalizer;
use RuntimeException;
use Throwable;

/**
 * The bytes behind a media entry — ADR-016, with ADR-041's visibility.
 *
 * ⚠️ `#[Unscoped]` BECAUSE IT IS REACHED ONLY THROUGH ITS ENTRY, WHICH IS SCOPED. That is `EntryRevision`'s
 * justification verbatim, and it applies here for the same reason: the row is 1:1 with an entry carrying both
 * scope keys, so a copy of them here would be a second answer free to disagree with the first. `Unscoped`'s own
 * docblock reserves it for things that are *genuinely* global — modules, system entry types, migrations — and
 * this is not one; the attribute is carried with the reason attached so that a reader does not take it as a
 * claim that a media file belongs to everybody.
 *
 * ⚠️ THE FILE IS NOT REMOVED BY THE FOREIGN KEY. `media_files.entry_id` cascades, so a force-deleted entry
 * takes this ROW with it — and a cascade happens inside the database, where nothing can reach a disk. Bytes
 * are removed by the disposal path ADR-041 decides, and a row disappearing is precisely the event that path
 * cannot observe. That asymmetry is why disposal is not written as a `deleting` hook on this model.
 *
 * ⚠️ ONLY `disk` MOVES AFTER CREATION — ADR-042 decision 5. Custody moves a file between the public and private disks
 * and writes where it now is, through the query builder under its own lock; every other column is what `store()`
 * wrote, and `columnsFixedAtCreation()` says why each one stays so.
 *
 * ⚠️ A PATH IS WRITTEN AS THE DISKS READ IT, AND IS ONE ROW'S (Adam, decision 8, 2026-09-25). Custody acts on a file by
 * its path on every disk, so a path must name this file and no other row's. `media_files_path_unique` refuses a second
 * row naming one; and because Flysystem normalises every location — `/media/x`, `media//x` and `media\x` are all the file
 * at `media/x` — a row is refused here, before anything reaches the engine, unless its path is already in the form the
 * disks read. `path` is fixed after creation, so creation is the only model door. A mass or raw insert passes neither
 * the model nor this check: ADR-042 records that insert path as not yet guarded.
 *
 * @property int $id
 * @property int $entry_id
 * @property string $disk
 * @property string $path
 * @property string $mime
 * @property int $size_bytes
 * @property string $checksum
 * @property string $visibility
 * @property int|null $width
 * @property int|null $height
 * @property int|null $duration_ms
 * @property CarbonInterface $created_at
 */
#[Unscoped]
class MediaFile extends Model implements FixesColumnsAtCreation
{
    use EnforcesScope;

    public const TABLE = 'media_files';

    /** ADR-041: private is the default, and public is an explicit act. */
    public const VISIBILITIES = ['private', 'public'];

    protected $table = self::TABLE;

    protected $guarded = [];

    /** `created_at` only: nothing but custody writes the row again, and only to say which disk the file is on. */
    public const UPDATED_AT = null;

    protected $casts = [
        'size_bytes' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'duration_ms' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $file): void {
            $path = (string) $file->getAttribute('path');

            try {
                $read = (new WhitespacePathNormalizer)->normalizePath($path);
            } catch (Throwable) {
                $read = null;
            }

            if ($read !== $path) {
                throw new RuntimeException(sprintf(
                    'Refusing to store a media file at [%s]: %s, so the path would not be this file\'s alone (ADR-042 '
                    .'decision 5; Adam, decision 8, 2026-09-25). Nothing was written.',
                    $path,
                    $read === null ? 'no disk can read it' : "every disk reads it as [{$read}]",
                ));
            }
        });
    }

    /** @return BelongsTo<Entry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }

    public static function columnsFixedAtCreation(): array
    {
        return [
            'entry_id' => 'a file belongs to the entry it was stored for; moving it would hand one entry\'s bytes to another.',
            'path' => 'custody withdraws, publishes and disposes of a file by its path on every disk, so a changed path '
                .'strands every copy at the old one (ADR-042 decision 5).',
            'checksum' => 'it is the hash of the bytes as stored, and custody keeps only a copy that matches it; a changed '
                .'checksum would make a different file the one kept (ADR-042 decision 5).',
            'mime' => 'delivery sends it as the Content-Type, and it was read from the bytes when they were stored.',
            'size_bytes' => 'it is the size of the bytes as stored.',
            'visibility' => 'it decides which disk the file belongs on, and nothing yet moves a file when it changes '
                .'(ADR-041, ADR-042 decision 5).',
            'width' => 'it was read from the bytes when they were stored.',
            'height' => 'it was read from the bytes when they were stored.',
            'duration_ms' => 'it was read from the bytes when they were stored.',
            'created_at' => 'it records when the file was stored.',
        ];
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'public';
    }
}
