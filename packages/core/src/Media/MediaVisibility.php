<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Media;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Tenancy\Context;
use LogicException;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * A stored file made public or private — Adam, ADR-042 decision 32.
 *
 * @internal
 *
 * ⚠️ ONE DOOR, BOTH WAYS, AND IT IS THE ONLY ONE. `visibility` is fixed at creation at every model door
 * (`MediaFile::columnsFixedAtCreation()`); this writes it below the model, under custody's lock, in `writeRow()` alone —
 * and, for a JPEG made public, `checksum` and `size_bytes` in the same statement, from the bytes being made the file.
 * Each switch decides from the rows it locked: the entry live and in scope, its stored type's `publish` held, the
 * instance not moved underneath it. Each is audited, and the entry itself is not written.
 *
 * ⚠️ MADE PRIVATE AS A TRASH WITHDRAWS: the row first, then every copy on a disk the web serves removed once a verified
 * copy is on the private disk — all before the commit, in the trash's own words when it cannot be (`MediaWithdrawal`).
 *
 * ⚠️ MADE PUBLIC AS A RESTORE PUBLISHES — AFTER THE COMMIT — AND A JPEG LOSES ITS LOCATION FIRST (decision 30). Its one
 * copy, on the private disk, is read and proved, stripped, and the stripped copy made the file there as the last step
 * before the commit; publication then finds the recorded checksum on the private disk and copies nothing else. So a
 * JPEG is made public only once it is settled — its row naming the private disk, its copy there, and no other copy at
 * its path on any disk custody asks — or an original left beside it could be the copy a later fallback publishes.
 */
final class MediaVisibility
{
    /** The file's visibility was changed. */
    public const SWITCHED = 'switched';

    /** The file already had that visibility: nothing written, nothing audited. */
    public const UNCHANGED = 'unchanged';

    /** The audit actions, targeting the entry. */
    public const MADE_PUBLIC = 'media.made_public';

    public const MADE_PRIVATE = 'media.made_private';

    /**
     * Whether a user may change the visibility of a file of this type: whoever may publish it (decision 32). A null user is
     * the system acting on its own, as for publication (`Entry::publishingRefused()`).
     */
    public static function mayChange(?Authenticatable $user, string $typeHandle): bool
    {
        return $user === null
            || ($typeHandle !== '' && Permissions::allows($user, Permissions::forEntryType($typeHandle, 'publish')));
    }

    /**
     * Make an entry's file public: published after the commit, a JPEG stripped of its location before it.
     *
     * @return string `SWITCHED` or `UNCHANGED`
     *
     * @throws MediaRefused where a JPEG's location cannot be removed with certainty, in words an editor is shown
     * @throws MediaVisibilityRefused in words an editor is shown
     * @throws LogicException inside an open transaction
     * @throws RuntimeException for anything else, whose message is not for an editor
     */
    public static function makePublic(Entry $entry): string
    {
        $connection = $entry->getConnection();
        $id = self::keyOf($entry, $connection, 'public');

        /*
         * ⚠️ OUTSIDE ANY TRANSACTION, OR IT REFUSES. A JPEG's file is rewritten on the private disk before the commit, and
         * only this call's own rollback writes the original back: an enclosing transaction rolling back afterwards would
         * leave the file stripped under a row that still records the original.
         */
        if (! MediaCustody::isOutermost($connection)) {
            throw new LogicException(sprintf(
                'Refusing to make entry %d public inside an open transaction: its file\'s bytes are rewritten before the '
                .'commit and published after it, and no enclosing rollback restores them (ADR-042 decision 32). Call it '
                .'outside one.',
                $id,
            ));
        }

        self::refuseWithoutOrg($id, 'public');
        MediaCustody::refuseSharedPaths($connection);

        $original = $stripped = $recorded = $kept = $checksum = $private = $path = null;
        $size = null;
        $rowWritten = $replacing = false;

        try {
            $outcome = MediaCustody::locked($connection, $id, static function (?stdClass $locked, ?stdClass $file) use (
                $connection, $entry, $id, &$original, &$stripped, &$recorded, &$kept, &$checksum, &$size, &$private, &$path,
                &$rowWritten, &$replacing,
            ): string {
                $row = self::guard($connection, $entry, $id, $file, 'public');

                /** @var stdClass $file */
                if ($file->visibility === 'public') {
                    return self::UNCHANGED;
                }

                $config = self::config();
                $private = MediaDisks::configured($config, 'private');
                self::refuseUnsafeDisks($config, $id, 'public');

                $path = (string) $file->path;
                $format = MediaLocation::formatOf($path, is_string($file->mime) ? $file->mime : null);

                /*
                 * ⚠️ AND THE BYTES DECIDE TOO — review of decision 32. A row written past the model can call a JPEG
                 * anything, and read as another format it would skip everything below and be published with its
                 * location. So a file its row does not call a JPEG is asked for its first bytes, on the disk the row
                 * names: a JPEG's marker makes it one, and a copy that is not there is taken as one — the checks below
                 * then refuse it in words that say where it is — rather than published unread.
                 */
                if (! MediaLocation::strips($format) && self::mayBeJpeg($id, $file, $path)) {
                    $format = 'jpeg';
                }

                if (MediaLocation::strips($format)) {
                    self::refuseUnsettled($config, $id, $file, $private);

                    try {
                        // The private disk alone: the refusals above left no other copy anywhere custody asks.
                        $keeper = MediaCustody::keeper($file, $private, $private, [$private]);
                    } catch (MediaCustodyFailure $failure) {
                        throw self::refused($id, $failure->reason === 'read-through' ? MediaVisibilityRefused::READ_THROUGH : MediaVisibilityRefused::UNREADABLE, 'public', $failure);
                    }

                    if ($keeper->disk === null || $keeper->expected === null) {
                        throw new MediaVisibilityRefused($id, MediaVisibilityRefused::MISSING, 'public', $private);
                    }

                    $recorded = (string) $file->checksum;
                    $kept = $keeper->expected;

                    try {
                        $original = MediaBytes::toTemporary($private, $path, $kept);
                    } catch (MediaCustodyFailure $failure) {
                        throw self::refused($id, match ($failure->reason) {
                            'verify' => MediaVisibilityRefused::CHANGED,
                            'read-through' => MediaVisibilityRefused::READ_THROUGH,
                            default => MediaVisibilityRefused::UNREADABLE,
                        }, 'public', $failure);
                    }

                    $title = is_string($row->title) && $row->title !== '' ? $row->title : '#'.$id;

                    try {
                        $stripped = MediaLocation::strippedCopy($original, $format, $title, stored: true);
                    } catch (MediaRefused $refused) {
                        throw $refused;
                    } catch (RuntimeException $failure) {
                        Log::warning(sprintf(
                            'Media visibility, entry %d: its copy of [%s] without the location failed — %s (ADR-042 decision 32).',
                            $id,
                            $path,
                            $failure->getMessage(),
                        ));

                        throw new MediaVisibilityRefused($id, MediaVisibilityRefused::STRIP_FAILED, 'public', previous: $failure);
                    }

                    $made = $stripped ?? $original;
                    $checksum = (string) hash_file('sha256', $made);
                    $size = (int) filesize($made);
                }

                // ⚠️ THE ROW, THE AUDIT AND THE PUBLICATION, AND THE BYTES LAST: a failure before them leaves nothing to undo.
                self::writeRow($connection, $id, 'public', checksum: $checksum, sizeBytes: $size);
                $rowWritten = true;

                app(Auditor::class)->recordOrFail(self::MADE_PUBLIC, $entry);
                MediaCustody::whenOutermost($connection, static fn () => MediaCustody::publish($connection, [$id]));

                if ($stripped !== null) {
                    $replacing = true;

                    try {
                        MediaBytes::replaceVerified($private, $path, $stripped, $checksum);
                    } catch (MediaCustodyFailure $failure) {
                        throw self::refused($id, $failure->reason === 'read-through' ? MediaVisibilityRefused::READ_THROUGH : MediaVisibilityRefused::COPY_FAILED, 'public', $failure);
                    }
                }

                return self::SWITCHED;
            });
        } catch (Throwable $failure) {
            // ⚠️ AN ORIGINAL THAT COULD NOT BE WRITTEN BACK IS KEPT, never removed with the temporaries below: on an
            // object store the failed write is the file now, and the temporary is the only copy of what was uploaded.
            if ($replacing && ! self::putBack($connection, $id, $recorded, $original, $kept)) {
                self::preserve($id, (string) $original);
                $original = null;
            }

            /*
             * A COMMIT that reported failure may have landed: publication was registered on the transaction that rolled
             * back, so it is registered again, and settle's own re-check under the lock decides — as a restore's is.
             */
            if ($rowWritten) {
                MediaCustody::whenOutermost($connection, static fn () => MediaCustody::publish($connection, [$id]));
            }

            throw $failure;
        } finally {
            foreach ([$original, $stripped] as $temporary) {
                if (is_string($temporary) && is_file($temporary)) {
                    @unlink($temporary);
                }
            }
        }

        if ($outcome === self::SWITCHED && $stripped !== null) {
            Log::info(sprintf(
                'Media visibility, entry %d: made public, its GPS data removed as it was, so its checksum is now [%s] where '
                .'it was [%s] (ADR-042 decisions 30 and 32).',
                $id,
                (string) $checksum,
                (string) $recorded,
            ));
        }

        return $outcome;
    }

    /**
     * Make an entry's file private: withdrawn from every disk the web serves before the commit, as a trash withdraws it.
     *
     * Like a trash, it may run inside a host's transaction: what it moved is put back when that rolls back.
     *
     * @return string `SWITCHED` or `UNCHANGED`
     *
     * @throws MediaWithdrawalRefused where its file could not be withdrawn, in the trash's words
     * @throws MediaVisibilityRefused in words an editor is shown
     * @throws RuntimeException for anything else, whose message is not for an editor
     */
    public static function makePrivate(Entry $entry): string
    {
        $connection = $entry->getConnection();
        $id = self::keyOf($entry, $connection, 'private');

        self::refuseWithoutOrg($id, 'private');

        $withdrawal = new MediaWithdrawal($connection, 'make private');

        try {
            return MediaCustody::locked($connection, $id, static function (?stdClass $locked, ?stdClass $file) use ($connection, $entry, $id, $withdrawal): string {
                self::guard($connection, $entry, $id, $file, 'private');

                /** @var stdClass $file */
                if ($file->visibility !== 'public') {
                    return self::UNCHANGED;
                }

                // ⚠️ THE ROW FIRST, naming the private disk before any byte moves, as a trash writes its own.
                self::writeRow($connection, $id, 'private', disk: MediaDisks::configured(self::config(), 'private'));
                $withdrawal->afterVisibilityWrite($file);

                app(Auditor::class)->recordOrFail(self::MADE_PRIVATE, $entry);

                return self::SWITCHED;
            });
        } catch (Throwable $failure) {
            $withdrawal->compensate();

            throw $failure;
        }
    }

    /**
     * The key of the row this switch locks, switches and audits — refusing an instance that would make those two rows, or
     * two connections (review of decision 32).
     *
     * ⚠️ ONE ROW: an instance whose key was edited since it was loaded would switch the row it was loaded as and audit the
     * one its key now names, as `Entry::restoreRevision()` refuses for the same reason. ⚠️ ONE CONNECTION: the audit row
     * is written where the audit log's model writes it, and on any other connection it would commit apart from the switch
     * — left behind by a switch that rolled back, or rolled back under one that did not.
     *
     * @throws RuntimeException
     */
    private static function keyOf(Entry $entry, Connection $connection, string $to): int
    {
        if ((string) $entry->getKey() !== (string) $entry->getKeyForAuthorization()) {
            throw new RuntimeException(sprintf(
                'Refusing to make entry %s %s: this instance was loaded as entry %s and its key has been changed since, so '
                .'the row it would switch and the row it would audit are not the same row (ADR-020). Load the entry you '
                .'mean to change.',
                (string) $entry->getKey(),
                $to,
                (string) $entry->getKeyForAuthorization(),
            ));
        }

        $audited = (new AuditLog)->getConnectionName() ?? DB::getDefaultConnection();

        if ($connection->getName() !== $audited) {
            throw new RuntimeException(sprintf(
                'Refusing to make entry %s %s on the [%s] connection: its audit row is written on [%s], outside the '
                .'switch\'s transaction, and an unauditable write is refused (ADR-020; ADR-042 decision 32).',
                (string) $entry->getKeyForAuthorization(),
                $to,
                $connection->getName(),
                $audited,
            ));
        }

        return (int) $entry->getKeyForAuthorization();
    }

    /**
     * Whether a file its row does not call a JPEG may be one: its bytes begin as one does, or the disk its row names does
     * not hold it, so its bytes cannot be asked.
     *
     * @throws MediaVisibilityRefused
     */
    private static function mayBeJpeg(int $id, stdClass $file, string $path): bool
    {
        $named = (string) $file->disk;

        try {
            // Before any disk is asked: read, a path the disks read as another would be that one.
            MediaBytes::refuseMisnamed($named, $path);
            $head = MediaBytes::head($named, $path, 3);
        } catch (MediaCustodyFailure $failure) {
            throw self::refused($id, match ($failure->reason) {
                'misnamed' => MediaVisibilityRefused::MISNAMED,
                'read-through' => MediaVisibilityRefused::READ_THROUGH,
                default => MediaVisibilityRefused::UNREADABLE,
            }, 'public', $failure);
        }

        return $head === null || str_starts_with($head, "\xFF\xD8\xFF");
    }

    /** @throws RuntimeException with no organisation in context: the switch is audited, and that would fail after bytes moved */
    private static function refuseWithoutOrg(int $id, string $to): void
    {
        if (app(Context::class)->orgId() !== null) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to make entry %d %s with no organisation in context: the switch is audited, and a write that cannot '
            .'be audited is refused (ADR-020). Set the context first.',
            $id,
            $to,
        ));
    }

    /**
     * Decide from the locked rows whether this switch may happen at all — in this order, so each refusal is the one that
     * names what is wrong first.
     *
     * @return stdClass the entry's locked row
     *
     * @throws MediaVisibilityRefused
     * @throws RuntimeException when the instance was moved or retyped since it was loaded
     */
    private static function guard(Connection $connection, Entry $entry, int $id, ?stdClass $file, string $to): stdClass
    {
        // Read again, for what custody's lock does not read: it took the row, and a row gone since it was loaded is null here.
        $row = $connection->table('entries')->where('id', $id)->lockForUpdate()
            ->first(['org_id', 'site_id', 'type_handle', 'title', 'deleted_at']);

        if ($row === null) {
            throw new MediaVisibilityRefused($id, MediaVisibilityRefused::GONE, $to);
        }

        // ⚠️ FROM THE LOCKED ROW, NEVER FROM THE INSTANCE: an instance's attributes are anybody's to set.
        $context = app(Context::class);

        if ((int) $row->org_id !== $context->orgId()
            || ($row->site_id !== null && (int) $row->site_id !== $context->siteId())) {
            throw new MediaVisibilityRefused($id, MediaVisibilityRefused::OUT_OF_SCOPE, $to);
        }

        $entry->refuseIfTheRowMovedUnderneath('change the visibility of');

        $handle = (string) $row->type_handle;

        if (! self::mayChange(Permissions::currentUser(), $handle)) {
            throw new MediaVisibilityRefused($id, MediaVisibilityRefused::NOT_PERMITTED, $to, detail: $handle);
        }

        if ($row->deleted_at !== null) {
            throw new MediaVisibilityRefused($id, MediaVisibilityRefused::TRASHED, $to);
        }

        if ($file === null) {
            throw new MediaVisibilityRefused($id, MediaVisibilityRefused::NO_FILE, $to);
        }

        return $row;
    }

    /** The configuration's refusal, as this switch's. */
    private static function refuseUnsafeDisks(Repository $config, int $id, string $to): void
    {
        try {
            MediaDisks::refuseUnsafeMediaDisks($config);
        } catch (RuntimeException $unsafe) {
            throw new MediaVisibilityRefused($id, MediaVisibilityRefused::UNSAFE_DISKS, $to, MediaDisks::configured($config, 'private'), $unsafe->getMessage(), $unsafe);
        }
    }

    /**
     * Refuse to make a JPEG public until it is settled: its row naming the configured private disk, and no copy at its path
     * on any other disk custody asks — on a disk the web serves, nor its partial copy.
     *
     * ⚠️ AN ORIGINAL LEFT ANYWHERE CUSTODY LOOKS COULD BE PUBLISHED LATER. Only the private disk's copy is stripped; a
     * copy on core's private disk after a host moved the private disk, or on a served disk from an older configuration,
     * would keep its location, and settle's fallback — the first copy in Adam's order, when none matches — could choose it
     * the day the stripped copy reads as absent. With no other copy, a transient absence is a missing file, which settle
     * logs and moves nothing for.
     *
     * @throws MediaVisibilityRefused
     */
    private static function refuseUnsettled(Repository $config, int $id, stdClass $file, string $private): void
    {
        $path = (string) $file->path;

        try {
            MediaBytes::refuseMisnamed($private, $path);
        } catch (MediaCustodyFailure $failure) {
            throw self::refused($id, MediaVisibilityRefused::MISNAMED, 'public', $failure);
        }

        if ((string) $file->disk !== $private) {
            throw new MediaVisibilityRefused($id, MediaVisibilityRefused::UNSETTLED, 'public', (string) $file->disk, $private);
        }

        $served = MediaDisks::servedDisks($config);
        $others = [];

        foreach (array_values(array_unique([...$served, MediaDisks::PRIVATE])) as $disk) {
            if ($disk === $private) {
                continue;
            }

            try {
                if (! MediaDisks::mayHold($config, $disk)) {
                    continue;
                }
            } catch (MediaCustodyFailure $failure) {
                throw self::refused($id, MediaVisibilityRefused::UNKNOWN_ROOT, 'public', $failure);
            }

            // The private disk under another name holds its copy, not another (as `removeBeside()` reads it).
            $place = MediaDisks::onePlace($config, $private, $disk);

            if ($place === true) {
                continue;
            }

            if ($place === null) {
                try {
                    MediaDisks::refuseCoincidingMediaDisks($config, $private, $disk);
                } catch (RuntimeException $coinciding) {
                    throw new MediaVisibilityRefused($id, MediaVisibilityRefused::UNSAFE_DISKS, 'public', $disk, $coinciding->getMessage(), $coinciding);
                }
            }

            $others[] = $disk;
        }

        foreach ($others as $disk) {
            $isServed = in_array($disk, $served, true);

            try {
                $held = MediaBytes::present($disk, $path);
                $partial = ! $held && $isServed && MediaBytes::present($disk, MediaBytes::partial($path));
            } catch (MediaCustodyFailure $failure) {
                throw self::refused($id, MediaVisibilityRefused::UNREADABLE, 'public', $failure);
            }

            if ($held) {
                throw new MediaVisibilityRefused($id, $isServed ? MediaVisibilityRefused::EXPOSED : MediaVisibilityRefused::STRAY, 'public', $disk);
            }

            // A write that did not finish, which prune removes and reconcile does not (review of decision 32).
            if ($partial) {
                throw new MediaVisibilityRefused($id, MediaVisibilityRefused::EXPOSED, 'public', $disk, MediaVisibilityRefused::PARTIAL);
            }
        }
    }

    /**
     * ⚠️ THE ONE WRITE OF `visibility`, `checksum` AND `size_bytes` AFTER CREATION — ADR-042 decision 32. Below the model,
     * as `kitsune:media-types --force` writes `is_media`, because every model door refuses them; under custody's lock,
     * which every caller holds. Nothing below the model checks a value, so this does, and the write must land on exactly
     * the one row locked. A test reads the source for any other write naming these columns.
     */
    private static function writeRow(
        Connection $connection,
        int $entryId,
        string $visibility,
        ?string $disk = null,
        ?string $checksum = null,
        ?int $sizeBytes = null,
    ): void {
        if (! in_array($visibility, MediaFile::VISIBILITIES, true)
            || ($checksum !== null && preg_match('/^[0-9a-f]{64}$/', $checksum) !== 1)
            || ($sizeBytes !== null && $sizeBytes < 0)
            || (($checksum === null) !== ($sizeBytes === null))
            || $disk === '') {
            throw new LogicException(sprintf('Refusing to write entry %d\'s file row: the values are not a file\'s (ADR-042 decision 32).', $entryId));
        }

        $columns = ['visibility' => $visibility];

        if ($disk !== null) {
            $columns['disk'] = $disk;
        }

        if ($checksum !== null) {
            $columns['checksum'] = $checksum;
            $columns['size_bytes'] = $sizeBytes;
        }

        $written = $connection->table(MediaFile::TABLE)->where('entry_id', $entryId)->update($columns);

        if ($written !== 1) {
            throw new LogicException(sprintf('Refusing to go on: entry %d\'s file row was written %d times, not once (ADR-042 decision 32).', $entryId, $written));
        }
    }

    /**
     * Write the original back over a stripped copy a switch that did not land left on the private disk — while the
     * process lives, a private file keeps what it was uploaded with (decision 30).
     *
     * ⚠️ LOCKED, AND ASKED AGAIN: the row still private, still recording the original, still naming the private disk — and
     * the disk not already holding the original. A COMMIT that landed though it reported failure made the stripped copy
     * the file, and it is left alone. Never thrown: what it follows has already failed, and that failure is the one
     * reported.
     */
    private static function putBack(Connection $connection, int $id, ?string $recorded, ?string $original, ?string $originalHash): bool
    {
        if ($recorded === null || $original === null || $originalHash === null || ! is_file($original)) {
            return true;
        }

        try {
            MediaCustody::locked($connection, $id, static function (?stdClass $entry, ?stdClass $file) use ($original, $originalHash, $recorded): void {
                $private = MediaDisks::configured(self::config(), 'private');

                if ($file === null || $file->visibility !== 'private' || ! MediaBytes::same((string) $file->checksum, $recorded)
                    || (string) $file->disk !== $private) {
                    return;
                }

                $path = (string) $file->path;
                $there = MediaBytes::hash($private, $path);

                if (MediaBytes::same($there, $originalHash)) {
                    return;
                }

                MediaCustody::noteDiffering($private, $path, $there, new MediaKeeper($private, $originalHash, MediaKeeper::MATCH, true, []), 'overwriting');
                MediaBytes::replaceVerified($private, $path, $original, $originalHash);
            });
        } catch (Throwable $failure) {
            Log::warning(sprintf(
                'Media visibility, entry %d: making it public did not land, and the copy it was made from could not be '
                .'written back — %s. Its row still records [%s]; the original is kept, as the next line says (ADR-042 '
                .'decision 32).',
                $id,
                $failure->getMessage(),
                (string) $recorded,
            ));

            return false;
        }

        return true;
    }

    /**
     * Keep an original the put-back could not write back, out of the temporary directory — whose files are removed at
     * process end — in a directory of the application's own that no disk names and nothing serves, readable by the
     * process's own user alone: it still carries where it was made. An operator copies it back by hand.
     */
    private static function preserve(int $id, string $original): void
    {
        $directory = storage_path('app/kitsune-recovery');
        $kept = sprintf('%s/entry-%d-%s', $directory, $id, bin2hex(random_bytes(6)));

        // `tempnam()` made it 0600, and a rename keeps that.
        if ((is_dir($directory) || @mkdir($directory, 0700, true)) && @rename($original, $kept)) {
            Log::warning(sprintf(
                'Media visibility, entry %d: the original it was uploaded as is kept at [%s]. Copy it over the file its row '
                .'names on the private disk, then run kitsune:media-reconcile --entry=%d (ADR-042 decision 32).',
                $id,
                $kept,
                $id,
            ));

            return;
        }

        Log::error(sprintf(
            'Media visibility, entry %d: the original it was uploaded as could not be kept, and is at [%s] until this '
            .'process ends. Copy it over the file its row names on the private disk now (ADR-042 decision 32).',
            $id,
            $original,
        ));
    }

    /** The refusal, with the disk and path the failure named written to the log, where the path belongs. */
    private static function refused(int $id, string $reason, string $to, MediaCustodyFailure $failure): MediaVisibilityRefused
    {
        Log::warning(sprintf(
            'Media visibility, entry %d: refusing to make it %s — %s (ADR-042 decision 32).',
            $id,
            $to,
            $failure->getMessage(),
        ));

        return new MediaVisibilityRefused($id, $reason, $to, $failure->disk, previous: $failure);
    }

    private static function config(): Repository
    {
        return app('config');
    }
}
