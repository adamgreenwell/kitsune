<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Expression;
use Kitsune\Core\Entitlements\EntitlementName;
use Kitsune\Core\Media\MediaBytes;
use Kitsune\Core\Tenancy\Attributes\Unscoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Contracts\FixesColumnsAtCreation;
use Kitsune\Core\Tenancy\Contracts\RefusesInsertedRows;
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
 * ⚠️ ~~ONLY `disk` MOVES AFTER CREATION~~ ONLY `disk` MOVES AFTER CREATION — ADR-042 decision 5 — BUT FOR THE VISIBILITY
 * SWITCH (decision 32). Custody moves a file between the public and private disks and writes where it now is, through
 * the query builder under its own lock; *Make public* and *Make private* write `visibility`, and for a JPEG made public
 * `checksum` and `size_bytes` with the bytes it strips, below the model under the same lock (`MediaVisibility`). Every
 * other column is what `store()` wrote, every model door still refuses all of them, and `columnsFixedAtCreation()` says
 * why each one stays so. *And `reader_entitlement`, written by *Offer to readers* through the same one writer (ADR-041
 * decision 1, Adam, 2026-10-11); no row is created offered.*
 *
 * ⚠️ A PATH IS WRITTEN AS THE DISKS READ IT, AND IS ONE ROW'S (Adam, decision 8, 2026-09-25). Custody acts on a file by
 * its path on every disk, so a path must name this file and no other row's. `media_files_path_unique` refuses a second
 * row naming one; and because Flysystem normalises every location — `/media/x`, `media//x` and `media\x` are all the file
 * at `media/x` — a row is refused here, before anything reaches the engine, unless its path is already in the form the
 * disks read. `path` is fixed after creation, so creation is the only model door. A mass or raw insert passes neither
 * the model nor this check: ADR-042 records that insert path as not yet guarded.
 *
 * ⚠️ AND NEVER CUSTODY'S SCRATCH NAME (review of slice 5c). A path ending in `MediaBytes::PARTIAL` is the name custody
 * writes a copy under beside another path: a trash, a disposal, settle and prune remove it as that path's partial copy,
 * and a row there would lose its only file to a step acting for another row. So is one ending in any spelling a volume
 * that folds case reads as it — `.KITSUNE-PARTIAL`, the Kelvin sign for `k`, the long s for `s` — compared after a full
 * Unicode case fold: on APFS, NTFS or SMB such a row's file is the very entry custody writes and removes (review of 5c).
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
 * @property string|null $reader_entitlement
 * @property CarbonInterface $created_at
 */
#[Unscoped]
class MediaFile extends Model implements FixesColumnsAtCreation, RefusesInsertedRows
{
    use EnforcesScope;

    public const TABLE = 'media_files';

    /** ADR-041: private is the default, and public is an explicit act. */
    public const VISIBILITIES = ['private', 'public'];

    protected $table = self::TABLE;

    protected $guarded = [];

    /**
     * `created_at` only: nothing but custody writes the row again — to say which disk the file is on — and the visibility
     * switch, to say what it is (ADR-042 decision 32).
     */
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

            if ($read === $path && str_ends_with(mb_convert_case($path, MB_CASE_FOLD, 'UTF-8'), MediaBytes::PARTIAL)) {
                throw new RuntimeException(sprintf(
                    'Refusing to store a media file at [%s]: a path ending in [%s], in any case a volume reads as it, is the '
                    .'name custody writes a copy under beside another path, and removes as that path\'s partial copy (ADR-042 '
                    .'decision 5). Nothing was written.',
                    $path,
                    MediaBytes::PARTIAL,
                ));
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

    /**
     * ⚠️ NO ROW IS CREATED OFFERED: *Offer to readers* decides from the locked rows that a file is private and kept to one
     * site, and a row stored offered would skip both (ADR-041 decision 2). `ScopedBuilder` asks it of every row an
     * Eloquent insert carries — `create()`, a quiet save, `insert()`, `insertGetId()`, `insertOrIgnore()` and an upsert's
     * insert half — keyed by column as the database writes it, and refuses a subquery insert, whose rows it cannot read.
     * `DB::table()` stays out of reach, the boundary the builder states. Not a `creating` listener: the builder already
     * runs on every path the listener does, and a listener is skipped by the quiet ones (review).
     */
    public static function refuseInsertedRow(array $row): void
    {
        if (($row['reader_entitlement'] ?? null) !== null) {
            throw new RuntimeException(
                'Refusing to store a media file offered to readers: a file is offered only by Offer to readers, which '
                .'decides that it is private and kept to one site (ADR-041 decisions 1 and 2). Nothing was written.',
            );
        }
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
                .'checksum would make a different file the one kept (ADR-042 decision 5). Only making a JPEG public '
                .'rewrites it, with the bytes it strips, in the statement that writes its visibility (decision 32).',
            'mime' => 'delivery sends it as the Content-Type, and it was read from the bytes when they were stored.',
            'size_bytes' => 'it is the size of the bytes as stored, sent as Content-Length; only making a JPEG public '
                .'rewrites it, with those bytes (ADR-042 decision 32).',
            'visibility' => 'it decides which disk the file belongs on, so only Make public and Make private change it, '
                .'below the model and under custody\'s lock, moving the file as they do (ADR-041; ADR-042 decisions 5 and '
                .'32).',
            'width' => 'it was read from the bytes when they were stored.',
            'height' => 'it was read from the bytes when they were stored.',
            'duration_ms' => 'it was read from the bytes when they were stored.',
            'created_at' => 'it records when the file was stored.',
            'reader_entitlement' => 'only Offer to readers, Change and Stop offering write it, below the model and under '
                .'custody\'s lock, after deciding that the file is private and kept to one site (ADR-041 decisions 1 and 2).',
        ];
    }

    /**
     * `created_at` set to itself, for every UPDATE of the row below the model to carry.
     *
     * ⚠️ MARIADB BEFORE 10.10 WOULD MOVE IT OTHERWISE. With `explicit_defaults_for_timestamp=0`, its default until 10.10
     * and CI's 10.6, the server gives a table's first TIMESTAMP `ON UPDATE CURRENT_TIMESTAMP`, and this is that column: an
     * UPDATE that leaves it out stores the server's clock as when the file was stored. Measured on 10.6.28 — custody's move,
     * a withdrawal, the visibility switch and the offer each did. Naming it as itself keeps it there and is a no-op on every
     * other engine, as the credentials' explicit `changed_at` is (ADR-040's *Credential timestamp portability*). An UPDATE
     * that changes no column moves nothing, so the lock `MediaCustody::locked()` takes on SQLite needs none.
     *
     * @return array{created_at: Expression}
     */
    public static function keepingCreatedAt(Connection $connection): array
    {
        return ['created_at' => new Expression($connection->getQueryGrammar()->wrap('created_at'))];
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'public';
    }

    /**
     * The entitlement a reader needs to download this file, or null when it is offered to no one (ADR-041 decision 1).
     *
     * ⚠️ A STORED VALUE THAT IS NOT A NAME IS NO OFFER. Only the writer stores one, and it refuses anything else; a value
     * written below it would otherwise reach `holds()` and decide who downloads a file.
     */
    public function readerEntitlement(): ?string
    {
        $name = $this->getAttribute('reader_entitlement');

        return is_string($name) && EntitlementName::isName($name) ? $name : null;
    }
}
