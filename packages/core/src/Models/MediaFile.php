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
class MediaFile extends Model
{
    use EnforcesScope;

    public const TABLE = 'media_files';

    /** ADR-041: private is the default, and public is an explicit act. */
    public const VISIBILITIES = ['private', 'public'];

    protected $table = self::TABLE;

    protected $guarded = [];

    /** `created_at` only; a media file is written once and never updated in place. */
    public const UPDATED_AT = null;

    protected $casts = [
        'size_bytes' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'duration_ms' => 'integer',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<Entry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'public';
    }
}
