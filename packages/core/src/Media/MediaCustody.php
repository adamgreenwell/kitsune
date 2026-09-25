<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Media;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\Log;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Models\MediaFile;
use LogicException;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Custody of a media file's bytes: where they belong, and moving them there under the row's lock — ADR-042 decision 5.
 *
 * @internal
 *
 * ⚠️ THE ROW DECIDES, AND IT IS LOCKED WHILE THE BYTES MOVE. A file belongs on the configured public disk while its
 * entry is live and its visibility public, and on the configured private disk otherwise. Every byte custody moves is
 * moved inside `locked()`, on the connection the write it serves ran on, so no other custody write can change the row
 * between the decision and the move. On SQLite that lock is the database's write lock, taken with a write before any
 * byte moves (Adam, 2026-09-24); elsewhere the entry and its file are read `FOR UPDATE`, entry first.
 *
 * ⚠️ NO COPY IS DELETED UNTIL ANOTHER IS VERIFIED. A copy counts once it has been written and read back with a
 * matching hash — no fsync, as `store()` also has none — and ~~one disk's copy is deleted only while another's is known
 * to hold the same bytes~~ a copy is deleted only once the kept copy is verified by SHA-256 on the disk the row's state
 * says it belongs on — one that differs goes with both hashes logged (Adam, decisions 2 and 2b, 2026-09-24); one alone
 * matching the checksum never, and one that cannot be read never (Adam, decision 6, 2026-09-25). Two names for one
 * place, or two that cannot be told apart, are refused before anything moves: a "copy" there could be the file.
 *
 * ⚠️ BYTES MOVE AFTER THE OUTERMOST COMMIT, NEVER INSIDE A TRANSACTION THAT MIGHT STILL ROLL BACK. Publication
 * follows the commit that made a file public again; the compensation of a withdrawal the database then rolled back
 * follows that rollback. `settle()` refuses to run inside an open transaction, and `whenOutermost()` is how everything
 * else waits for one to end.
 */
final class MediaCustody
{
    /** Bytes moved, or the row now names the disk that holds them. */
    public const SETTLED = 'settled';

    /** Everything was already where it belongs. */
    public const UNCHANGED = 'unchanged';

    /** The entry or its file is gone: nothing to keep. */
    public const GONE = 'gone';

    /** No disk custody asked holds the file. */
    public const MISSING = 'missing';

    /** A row names the path, so an orphan's removal was refused. */
    public const CLAIMED = 'claimed';

    /** The file is gone from the disk, and the disk says so. */
    public const REMOVED = 'removed';

    /** The file was taken off the web, and a copy that cannot be read was left where it is (Adam, decision 6, 2026-09-25). */
    public const SET_ASIDE = 'set aside';

    /**
     * Nothing was removed: the row does not name the disk its state says, that disk does not hold the copy kept, or a
     * copy that alone matches the checksum would have gone.
     */
    public const UNSETTLED = 'unsettled';

    /**
     * Entries whose files a rolled-back withdrawal moved, by connection name, waiting to be put back.
     *
     * @var array<string, array<int, true>>
     */
    private array $pending = [];

    /**
     * Whether `media_files.path` is unique, by connection name, asked once.
     *
     * @var array<string, bool>
     */
    private array $unique = [];

    /**
     * Run the work with an entry and its file locked, on this connection, in a transaction `TransactionRecovery`
     * repairs — the only way custody reads a row it will move bytes for.
     *
     * ⚠️ ON SQLITE, A WRITE FIRST. SQLite has no row locks: a read takes a shared lock that a writer on another
     * connection can still take a reserved lock beside, and the second of two such readers to write fails as busy
     * only after both have moved bytes. An UPDATE that changes nothing takes the database's write lock before anything
     * is read, whether or not it matches a row — which is what an entry id of 0, for a path with no row, relies on.
     * Elsewhere it would take `media_files` before `entries` and invert the lock order every other write uses.
     *
     * @template TReturn
     *
     * @param  Closure(?stdClass, ?stdClass): TReturn  $work  given the entry's id and deleted_at, and its file's row
     * @return TReturn
     */
    public static function locked(Connection $connection, int $entryId, Closure $work): mixed
    {
        return TransactionRecovery::run($connection, static function (Connection $inside) use ($entryId, $work): mixed {
            if ($inside->getDriverName() === 'sqlite') {
                $inside->table('media_files')
                    ->where('entry_id', $entryId)
                    ->update(['disk' => new Expression($inside->getQueryGrammar()->wrap('disk'))]);
            }

            $entry = $inside->table('entries')->where('id', $entryId)->lockForUpdate()->first(['id', 'deleted_at']);
            $file = $inside->table('media_files')->where('entry_id', $entryId)->lockForUpdate()->first();

            return $work($entry, $file);
        });
    }

    /** The disk a file belongs on: public while its entry is live and it is public, private otherwise. */
    public static function target(stdClass $entry, stdClass $file): string
    {
        return MediaDisks::configured(
            self::config(),
            $entry->deleted_at === null && $file->visibility === 'public' ? 'public' : 'private',
        );
    }

    /**
     * The disks custody asks for a row's file: the target, the disk the row names, both configured media disks, core's
     * own private disk and every served disk — those last two wherever they can hold anything (Adam, decision 5,
     * 2026-09-25).
     *
     * ⚠️ CORE'S PRIVATE DISK TOO, WHEREVER THE PRIVATE DISK POINTS. Disposal always asks it, so a copy it could not remove
     * can be there after a host moves the private disk elsewhere, and nothing else would ever find it. A disk whose root
     * does not exist holds nothing, and is not built: building it would create the directory.
     *
     * @return list<string>
     */
    public static function asked(Repository $config, string $target, string $named): array
    {
        $mayHold = array_filter(
            [MediaDisks::PRIVATE, ...MediaDisks::servedDisks($config)],
            static fn (string $disk): bool => MediaDisks::mayHold($config, $disk),
        );

        return array_values(array_unique([
            $target,
            $named,
            MediaDisks::configured($config, 'public'),
            MediaDisks::configured($config, 'private'),
            ...$mayHold,
        ]));
    }

    /**
     * Choose the copy to keep, among the disks asked — `MediaKeeper` says in what order.
     *
     * ⚠️ THE TARGET IS HASHED FIRST, so a file already where it belongs costs one hash. And a copy that cannot be read
     * is a failure, never an absence: reading it as absent could choose a copy that differs and delete the file.
     *
     * ⚠️ EVERY DISK ASKED IS IN THE ORDER. The order once named only the configured and served disks, so a copy held only
     * on another disk asked — core's private disk after a host moved the private disk — fell through to the target,
     * which held nothing, and its hash was read before it was taken (slice 5b).
     *
     * ⚠️ AN UNREADABLE COPY MAY BE SET ASIDE, BUT ONLY TO TAKE A FILE OFF THE WEB (Adam, decision 6, 2026-09-25). With
     * `$spare` — settle passes the disks it asks that are neither served nor the target, and only when the target is
     * private — a copy on one of them that exists and cannot be read does not stop the choice while a disk the web serves
     * holds the file: it is set aside, never chosen, and the choice is made among the copies that can be read. When no
     * copy on a served disk could be read after all, nothing is taken off the web by the choice, so it refuses as it
     * would have. A copy whose presence cannot be told is never set aside, and nothing else ever is.
     *
     * @param  list<string>  $candidates  every disk to ask, the target and the named disk among them
     * @param  list<string>  $spare  the disks whose unreadable copy may be set aside; empty everywhere but settle
     */
    public static function keeper(stdClass $file, string $target, string $named, array $candidates, array $spare = []): MediaKeeper
    {
        $path = (string) $file->path;
        $checksum = is_string($file->checksum) && $file->checksum !== '' ? $file->checksum : null;
        $present = [];

        foreach ($candidates as $disk) {
            if (MediaBytes::present($disk, $path)) {
                $present[] = $disk;
            }
        }

        $served = MediaDisks::servedDisks(self::config());
        $spare = $spare !== [] && array_intersect($present, $served) !== [] ? $spare : [];
        $hashes = [];
        $setAside = [];

        foreach (array_values(array_unique([$target, $named, ...$candidates])) as $disk) {
            if (! in_array($disk, $present, true)) {
                continue;
            }

            try {
                $hashes[$disk] = MediaBytes::hash($disk, $path);
            } catch (MediaCustodyFailure $failure) {
                if ($failure->reason !== 'unreadable' || ! in_array($disk, $spare, true)) {
                    throw $failure;
                }

                $setAside[$disk] = $failure;

                continue;
            }

            if (MediaBytes::same($hashes[$disk], $checksum)) {
                return new MediaKeeper($disk, $checksum, MediaKeeper::MATCH, $disk === $target, $hashes, $spare, $setAside, $checksum);
            }
        }

        // Every present copy has been hashed by now; one gone since it was seen is not a copy.
        $held = array_keys(array_filter($hashes, static fn (?string $hash): bool => $hash !== null));

        // Set aside to take a file off the web, and no copy on a disk the web serves could be read after all.
        if ($setAside !== [] && array_intersect($held, $served) === []) {
            throw reset($setAside);
        }

        if ($held === []) {
            return new MediaKeeper(null, $checksum, MediaKeeper::MISSING, false, $hashes, checksum: $checksum);
        }

        if (in_array($named, $held, true)) {
            $disk = $named;
            $mode = MediaKeeper::NAMED;
        } else {
            // Public, private, core's private disk, served, any other disk asked; the target last (Adam, decision 5).
            $config = self::config();
            $order = array_diff(
                array_values(array_unique([
                    MediaDisks::configured($config, 'public'),
                    MediaDisks::configured($config, 'private'),
                    MediaDisks::PRIVATE,
                    ...MediaDisks::servedDisks($config),
                    ...$candidates,
                ])),
                [$target],
            );
            $disk = array_values(array_intersect($order, $held))[0] ?? $target;
            $mode = MediaKeeper::FIRST;
        }

        $expected = (string) $hashes[$disk];

        Log::warning(sprintf(
            'Media custody, entry %d: no copy of [%s] matches its recorded checksum [%s], so the copy on [%s] is kept '
            .'(%s) and every other is verified against it. Each disk held: %s.%s A copy was changed outside Kitsune '
            .'(ADR-042 decision 5).',
            (int) $file->entry_id,
            $path,
            $checksum ?? 'none',
            $disk,
            $mode === MediaKeeper::NAMED ? 'the disk the row names' : 'the first in Adam\'s order',
            json_encode($hashes, JSON_UNESCAPED_SLASHES),
            $setAside === [] ? '' : sprintf(' Unreadable, and set aside: [%s].', implode(', ', array_keys($setAside))),
        ));

        return new MediaKeeper($disk, $expected, $mode, MediaBytes::same($hashes[$target] ?? null, $expected), $hashes, $spare, $setAside, $checksum);
    }

    /**
     * Put an entry's file where its row says it belongs, and nowhere the web serves when that is the private disk.
     *
     * ⚠️ OUTSIDE ANY TRANSACTION, OR IT REFUSES. Bytes moved inside one would stay moved when it rolls back.
     *
     * With `$publication`, only a file that belongs on the public disk is moved: publication follows a restore, and
     * the entry may have been trashed again since.
     *
     * ⚠️ THE ORDER: THE KEPT COPY ONTO THE TARGET, THEN EVERY SERVED COPY AWAY, THEN THE NAMED DISK'S, THEN THE ROW — slice
     * 5b. The served disks go first, so a failure on the disk the row names cannot keep a file on the web; the row moves
     * last, so it never names a disk whose copy was not settled. A copy that exists and cannot be read on a disk neither
     * served nor the target is set aside while the file is taken off the web, never touched; a row naming that disk keeps
     * naming it (Adam, decision 6, 2026-09-25).
     *
     * @throws LogicException inside an open transaction
     * @throws MediaCustodyFailure when a copy cannot be written, verified or removed; nothing it verified is lost
     * @throws RuntimeException when the configured disks cannot keep the promise, or two disks cannot be told apart
     */
    public static function settle(Connection $connection, int $entryId, bool $publication = false): string
    {
        if (! self::isOutermost($connection)) {
            throw new LogicException(sprintf(
                'Refusing to settle entry %d\'s file inside an open transaction: bytes moved now would stay moved if it '
                .'rolled back (ADR-042 decision 5). Run it through MediaCustody::whenOutermost().',
                $entryId,
            ));
        }

        return self::locked($connection, $entryId, static function (?stdClass $entry, ?stdClass $file) use ($connection, $publication): string {
            if ($entry === null || $file === null) {
                return self::GONE;
            }

            $config = self::config();
            $public = MediaDisks::configured($config, 'public');
            $private = MediaDisks::configured($config, 'private');
            $target = self::target($entry, $file);

            if ($publication && $target !== $public) {
                return self::UNCHANGED;
            }

            MediaDisks::refuseUnsafeMediaDisks($config);

            $path = (string) $file->path;
            $named = (string) $file->disk;
            $asked = self::asked($config, $target, $named);
            $served = MediaDisks::servedDisks($config);

            // The disks whose unreadable copy may be set aside: neither served nor the target, and only to take a file
            // off the web (Adam, decision 6, 2026-09-25).
            $spare = $target !== $public ? array_values(array_diff($asked, [$target], $served)) : [];
            $keeper = self::keeper($file, $target, $named, $asked, $spare);

            if ($keeper->disk === null || $keeper->expected === null) {
                Log::warning(sprintf(
                    'Media custody, entry %d: no disk holds [%s] — not [%s], nor any other disk custody asks — so '
                    .'nothing was moved. kitsune:media-reconcile lists it as missing, and kitsune:media-prune lists a copy '
                    .'at its path on a disk another row names — the one place it looks that custody does not; otherwise '
                    .'restore it from a backup, or erase the entry (ADR-042 decision 5).',
                    (int) $file->entry_id,
                    $path,
                    $named,
                ));

                return self::MISSING;
            }

            $changed = false;

            if (! $keeper->targetHolds) {
                /*
                 * ⚠️ NOT ONTO THE SOURCE UNDER ANOTHER NAME — review of slice 5b. An object store is written at the key
                 * itself, and discarded there when the copy does not verify: were the target the kept copy's own store
                 * through another endpoint, a failed copy would delete the file, and the move-off after it would too.
                 */
                if ($keeper->disk !== $target) {
                    MediaDisks::refuseCoincidingMediaDisks($config, $target, $keeper->disk);
                }

                // Read again when the keeper read it as absent: a copy there now may be the one that matches.
                $there = $keeper->hashes[$target] ?? MediaBytes::hash($target, $path);
                $keeper->refuseToLose($target, $path, $there);

                self::noteDiffering($target, $path, $there, $keeper, 'overwriting');
                MediaBytes::copyVerified($keeper->disk, $target, $path, $keeper->expected);
                $changed = true;
            }

            /*
             * ⚠️ PRIVATE: NO DISK THE WEB SERVES KEEPS A COPY — AND FIRST, so a failure on the disk the row names cannot
             * keep a file on the web (review of slice 5b). Of the disks asked, so none whose root does not exist is built.
             */
            $swept = 0;

            if ($target !== $public) {
                foreach (array_intersect($asked, $served) as $disk) {
                    if ($disk !== $target && self::removeCopy($config, $target, $disk, $path, $keeper)) {
                        $swept++;
                    }
                }
            }

            $changed = $changed || $swept > 0;
            $setAside = $keeper->setAside;

            // Decision 6 answers for taking a file off the web: with no served copy removed here, it refuses as it would.
            if ($setAside !== [] && $swept === 0) {
                throw reset($setAside);
            }

            /*
             * ⚠️ MOVE-OFF: THE DISK THE ROW NAMED LOSES ITS COPY, unless it is a private one. A disk that was once the
             * public one — an object store with no url, say — can still serve what it holds with no configuration
             * saying so, and nothing would ever name that copy again. Unless it cannot be read and was set aside: then
             * the row stays on it (Adam, decision 6, 2026-09-25).
             *
             * ⚠️ NOR WHEN ITS COPY IS THE TARGET'S OWN ENTRY — review of slice 5b. A row naming Laravel's `public` while the
             * public disk is another name for the same directory failed on every run and was never repointed:
             * `removeCopy()` rightly refuses to remove the one file both names reach. Only the row moves then — and only
             * when the two names reach one directory entry: two disks `onePlace()` merely cannot tell apart, or whose
             * directories nest, hold two files at the path; and a hard link, a symlinked file or a bind mount is one file
             * under two entries, the other of which the web may serve. Each of those refuses, as it did (review of 5b,
             * twice).
             */
            if ($named !== $target && ! in_array($named, [$private, MediaDisks::PRIVATE], true) && ! isset($setAside[$named])
                && ! MediaBytes::sameEntry($target, $named, $path)) {
                try {
                    $changed = self::removeCopy($config, $target, $named, $path, $keeper) || $changed;
                } catch (MediaCustodyFailure $failure) {
                    // Its first hash may be here, when the keeper matched before it reached the named disk.
                    if ($failure->reason !== 'unreadable' || ! in_array($named, $keeper->spare, true) || $swept === 0) {
                        throw $failure;
                    }

                    $setAside[$named] = $failure;
                }
            }

            /*
             * The row moves last, and never off a named disk whose copy was set aside and would otherwise be stranded — one
             * the move-off leaves: that copy stays where a row points. Core's private disk is not one: prune sweeps it
             * wherever the private disk points, and lists a copy there at a row's path as an extra copy (review of 5b).
             */
            $stays = isset($setAside[$named]) && ! in_array($named, [$private, MediaDisks::PRIVATE], true);

            if ($named !== $target && ! $stays) {
                $connection->table('media_files')->where('entry_id', (int) $file->entry_id)->update(['disk' => $target]);
                $changed = true;
            }

            foreach (array_keys($setAside) as $disk) {
                Log::warning(sprintf(
                    'Media custody, entry %d: the copy of [%s] on [%s] exists and cannot be read, so it was left where it '
                    .'is — not chosen, overwritten or removed — and the file was taken off every disk the web serves from '
                    .'a readable copy verified on [%s] (Adam, decision 6, 2026-09-25).%s',
                    (int) $file->entry_id,
                    $path,
                    $disk,
                    $target,
                    $disk === $named && $stays
                        ? sprintf(' The row still names [%s]; once it can be read, kitsune:media-reconcile --entry=%d --force '
                          .'settles it.', $disk, (int) $file->entry_id)
                        : ' kitsune:media-prune lists it, and removes it under its row\'s lock once it can be read.',
                ));
            }

            if ($setAside !== []) {
                return self::SET_ASIDE;
            }

            return $changed ? self::SETTLED : self::UNCHANGED;
        });
    }

    /**
     * Publish each entry's file, after the commit that made it public again — and say so when one cannot be.
     *
     * ⚠️ A FAILURE IS LOGGED, NEVER THROWN. The commit it follows has happened; the entry is live, and its file stays
     * on the private disk, unreachable at its public URL, until it is retried.
     *
     * @param  list<int>  $entryIds
     */
    public static function publish(Connection $connection, array $entryIds): void
    {
        $public = MediaDisks::configured(self::config(), 'public');
        $named = static fn (int $entryId): mixed => rescue(
            static fn (): mixed => $connection->table('media_files')->where('entry_id', $entryId)->value('disk'),
            null,
            false,
        );

        foreach ($entryIds as $entryId) {
            $before = $named($entryId);

            try {
                $settled = self::settle($connection, $entryId, publication: true);
            } catch (Throwable $failure) {
                // A COMMIT that failed may still have landed: a row that now names the public disk says it did.
                $landed = $before !== $public && $named($entryId) === $public;

                Log::warning(sprintf(
                    'Media custody, entry %d: publication failed — %s — %s (ADR-042 decision 5).',
                    $entryId,
                    $failure->getMessage(),
                    $landed
                        ? sprintf('though its row now names the public disk, so whether the commit landed is unknown. '
                          .'kitsune:media-reconcile --entry=%d shows where the file is', $entryId)
                        : sprintf('so it is not published, and may not be reachable at its public URL. '
                          .'kitsune:media-prune and kitsune:media-reconcile list it under "awaiting publication", and '
                          .'kitsune:media-reconcile --entry=%d --force publishes it', $entryId),
                ));

                continue;
            }

            if ($settled === self::SETTLED) {
                self::cleanUpReporting($connection, $entryId);
            }
        }
    }

    /**
     * Delete the private copies of a live public file, once the public disk holds the copy kept — the third write, after
     * a publication or a compensation (ADR-042 decision 5).
     *
     * ⚠️ LOCKED, AND ASKED AGAIN: the entry still live, still public, its row still naming the public disk. A delete
     * that got there first has made the private copy the one it claims, and it is left alone. Review found the unlocked
     * version: a delete landing between a publication's commit and its cleanup copied the public bytes back to the
     * private path, had that fresh copy deleted by the cleanup, then deleted the public copy — the file on neither disk.
     *
     * ⚠️ ~~AND ONLY WHILE ANOTHER COPY IS KNOWN TO HOLD THE SAME BYTES (rule 2): the public copy's hash is compared with the
     * private copy's under the lock, and a copy that differs, or cannot be read, is kept.~~ AND ONLY ONCE THE PUBLIC DISK
     * HOLDS THE COPY KEPT (rule 2, as decided): a private copy matching it goes, one that differs goes with both hashes
     * logged (Adam, decisions 2 and 2b — "the rest waits for 5b"), and one that alone matches the checksum, or cannot be
     * read, keeps everything. `removeBeside()` argues it once, for this and for prune.
     *
     * @throws LogicException inside an open transaction
     * @throws RuntimeException when `media_files.path` is not unique on this database
     * @throws MediaCustodyFailure when a copy cannot be read or removed
     */
    public static function cleanUp(Connection $connection, int $entryId): string
    {
        if (! self::isOutermost($connection)) {
            throw new LogicException(sprintf(
                'Refusing to clean up entry %d\'s private copy inside an open transaction (ADR-042 decision 5).',
                $entryId,
            ));
        }

        self::refuseSharedPaths($connection);

        return self::locked($connection, $entryId, static function (?stdClass $entry, ?stdClass $file): string {
            if ($entry === null || $file === null) {
                return self::GONE;
            }

            $config = self::config();
            $public = MediaDisks::configured($config, 'public');

            if ($entry->deleted_at !== null || $file->visibility !== 'public' || $file->disk !== $public) {
                return self::UNCHANGED;
            }

            $private = [MediaDisks::configured($config, 'private')];

            // Core's own, wherever the private disk points, when it can hold anything: building it would create it.
            if (MediaDisks::mayHold($config, MediaDisks::PRIVATE)) {
                $private[] = MediaDisks::PRIVATE;
            }

            return self::removeBeside($config, $file, $public, array_values(array_diff(array_unique($private), [$public])));
        });
    }

    /**
     * Remove a copy at a row's path on a disk the row does not name — prune's removal of an extra copy (ADR-042 decision
     * 5, slice 5b).
     *
     * ⚠️ ONLY WHILE THE ROW NAMES THE DISK ITS STATE SAYS, asked under the lock: a row that does not is reconcile's, and
     * the extra copy may be the one it needs. Then as the third write: the target must hold the copy kept.
     *
     * @throws LogicException inside an open transaction
     * @throws RuntimeException when `media_files.path` is not unique, or the two disks cannot be told apart
     * @throws MediaCustodyFailure when a copy cannot be read or removed
     */
    public static function removeExtra(Connection $connection, int $entryId, string $disk): string
    {
        self::refuseInsideTransaction($connection, sprintf('an extra copy of entry %d\'s file', $entryId));
        self::refuseSharedPaths($connection);

        return self::locked($connection, $entryId, static function (?stdClass $entry, ?stdClass $file) use ($disk): string {
            if ($entry === null || $file === null) {
                return self::GONE;
            }

            $target = self::target($entry, $file);

            if ((string) $file->disk !== $target) {
                return self::UNSETTLED;
            }

            return self::removeBeside(self::config(), $file, $target, [$disk]);
        });
    }

    /**
     * Whether `media_files.path` is unique on this connection — the premise that lets custody remove a copy at a path as
     * one row's (Adam, decision 8, 2026-09-25).
     *
     * ⚠️ ASKED OF THE DATABASE, NOT ASSUMED. Nothing checks for a second row naming a path any more, because the index
     * makes one impossible; a checkout that has not migrated, or the migration's `down()`, would take that away and leave
     * nothing in its place. Asked once per connection for a request or a command.
     */
    public static function pathsAreUnique(Connection $connection): bool
    {
        $custody = app(self::class);

        return $custody->unique[$connection->getName()] ??= collect($connection->getSchemaBuilder()->getIndexes(MediaFile::TABLE))
            ->contains(static fn (array $index): bool => (bool) $index['unique'] && $index['columns'] === ['path']);
    }

    /** @throws RuntimeException when `media_files.path` is not unique on this connection */
    private static function refuseSharedPaths(Connection $connection): void
    {
        if (self::pathsAreUnique($connection)) {
            return;
        }

        throw new RuntimeException(
            'Refusing: media_files.path is not unique on this database, and custody removes a copy only on the '
            .'understanding that its path is one row\'s (ADR-042 decision 5; Adam, decision 8, 2026-09-25). Run the '
            .'migration 0001_01_01_000010_make_media_file_paths_unique (php artisan migrate) and try again. Nothing was '
            .'removed.'
        );
    }

    /**
     * Remove every copy on these disks once the target — the disk the row names — holds the copy kept: the third write
     * and prune's removal of an extra copy, argued once (ADR-042 decision 5, rule 2; Adam, decisions 2 and 2b).
     *
     * Inside the lock. A disk that is the target under another name is left out; one that cannot be told from it is
     * refused before anything is read.
     *
     * ⚠️ EVERY COPY IS HASHED BEFORE THE FIRST DELETE — review of slice 5b. A copy the keeper saw and read as absent a
     * moment later, and that is back now, is hashed again rather than deleted unhashed; and a copy that cannot be read
     * fails the step with nothing removed.
     *
     * ⚠️ A COPY THAT ALONE MATCHES THE CHECKSUM NEVER GOES. When the target's copy was kept for want of any match, and a
     * copy here turns out to match, nothing is removed: *"nothing matching it is touched by a fallback"*.
     *
     * @param  list<string>  $disks
     */
    private static function removeBeside(Repository $config, stdClass $file, string $target, array $disks): string
    {
        $path = (string) $file->path;
        $checksum = is_string($file->checksum) && $file->checksum !== '' ? $file->checksum : null;
        $others = [];

        foreach ($disks as $disk) {
            $place = MediaDisks::onePlace($config, $target, $disk);

            if ($place === true) {
                continue;
            }

            if ($place === null) {
                MediaDisks::refuseCoincidingMediaDisks($config, $target, $disk);
            }

            $others[] = $disk;
        }

        $held = array_values(array_filter($others, static fn (string $disk): bool => MediaBytes::present($disk, $path)));

        if ($held === []) {
            return self::UNCHANGED;
        }

        $keeper = self::keeper($file, $target, $target, [$target, ...$held]);

        if (! $keeper->targetHolds) {
            Log::warning(sprintf(
                'Media custody, entry %d: nothing was removed beside [%s] — [%s], the disk the row names, does not hold '
                .'the copy kept, %s (ADR-042 decision 5).',
                (int) $file->entry_id,
                $path,
                $target,
                match (true) {
                    $keeper->disk === null => 'because no disk holds one now',
                    // Reconcile asks only the disks custody asks: a copy elsewhere is restored by hand (Adam, decision 5).
                    in_array($keeper->disk, self::asked($config, $target, $target), true) => sprintf(
                        'which is on [%s]; kitsune:media-reconcile --entry=%d --force rewrites [%s] from it',
                        $keeper->disk,
                        (int) $file->entry_id,
                        $target,
                    ),
                    default => sprintf(
                        'which is on [%s], a disk kitsune:media-reconcile does not ask: copy it over [%s] by hand; '
                        .'kitsune:media-reconcile --entry=%d then verifies it',
                        $keeper->disk,
                        $target,
                        (int) $file->entry_id,
                    ),
                },
            ));

            return self::UNSETTLED;
        }

        $hashes = [];

        foreach ($held as $disk) {
            $hash = $keeper->hashes[$disk] ?? MediaBytes::hash($disk, $path);

            if ($hash === null) {
                continue;
            }

            if ($keeper->mode !== MediaKeeper::MATCH && MediaBytes::same($hash, $checksum)) {
                Log::warning(sprintf(
                    'Media custody, entry %d: nothing was removed beside [%s] — the copy on [%s] alone matches the '
                    .'recorded checksum, and [%s], the disk the row names, holds one that does not. %s (ADR-042 decision 5).',
                    (int) $file->entry_id,
                    $path,
                    $disk,
                    $target,
                    in_array($disk, self::asked($config, $target, $target), true)
                        ? sprintf('kitsune:media-reconcile --entry=%d --force rewrites [%s] from it', (int) $file->entry_id, $target)
                        : sprintf('Copy it over [%s] by hand; kitsune:media-reconcile --entry=%d then verifies it', $target, (int) $file->entry_id),
                ));

                return self::UNSETTLED;
            }

            $hashes[$disk] = $hash;
        }

        $keeper = new MediaKeeper($keeper->disk, $keeper->expected, $keeper->mode, $keeper->targetHolds, [...$keeper->hashes, ...$hashes], checksum: $keeper->checksum);

        foreach (array_keys($hashes) as $disk) {
            self::removeCopy($config, $target, $disk, $path, $keeper);
        }

        return $hashes === [] ? self::UNCHANGED : self::SETTLED;
    }

    /**
     * Whether a write on this connection would commit now: no transaction, or none a callback would wait for — the
     * test suite's wrapper is not one.
     */
    public static function isOutermost(Connection $connection): bool
    {
        return $connection->transactionLevel() === 0 || self::innermost($connection) === null;
    }

    /**
     * Run the job now if nothing on this connection is still to commit, or after the outermost commit that is.
     *
     * ⚠️ ON THIS CONNECTION'S OWN TRANSACTION. `afterCommit()` attaches a callback to the last pending transaction
     * whatever its connection: one opened on another connection inside this one would take the job, and lose it if it
     * rolled back. So the job goes on this connection's innermost pending record, which carries it to the outermost
     * commit and drops it with a rollback. It is asked again when it fires, which costs a comparison.
     *
     * A job that throws is reported, never rethrown: what it follows has already committed.
     */
    public static function whenOutermost(Connection $connection, Closure $job): void
    {
        $record = $connection->transactionLevel() === 0 ? null : self::innermost($connection);

        if ($record !== null) {
            $record->addCallback(static fn () => self::whenOutermost($connection, $job));

            return;
        }

        try {
            $job();
        } catch (Throwable $failure) {
            report($failure);
        }
    }

    /**
     * Run the callback if this connection's innermost transaction rolls back — now or with any transaction enclosing
     * it, which Laravel runs a committed child's rollback callbacks for.
     *
     * ⚠️ ON THIS CONNECTION'S OWN TRANSACTION, for the reason `whenOutermost()` gives: `afterRollBack()` would attach it
     * to the last pending transaction on any connection. With none open, nothing can roll back, and nothing is kept.
     */
    public static function onRollback(Connection $connection, Closure $callback): void
    {
        $record = $connection->transactionLevel() === 0 ? null : self::innermost($connection);

        $record?->addCallbackForRollback($callback);
    }

    /**
     * Remember entries whose files a rolled-back withdrawal moved, to put them back once nothing is left to commit.
     *
     * @param  list<int>  $entryIds
     */
    public static function queue(string $connection, array $entryIds): void
    {
        $custody = app(self::class);

        foreach ($entryIds as $entryId) {
            $custody->pending[$connection][$entryId] = true;
        }
    }

    /** Drain this connection's queue once nothing on it is left to commit. A no-op when nothing is queued. */
    public static function drainSoon(Connection $connection): void
    {
        if ((app(self::class)->pending[$connection->getName()] ?? []) === []) {
            return;
        }

        self::whenOutermost($connection, static fn () => self::drain($connection));
    }

    /**
     * Settle every queued entry on this connection, each on its own.
     *
     * ⚠️ THE QUEUE IS TAKEN BEFORE ANYTHING IS SETTLED. A settle that fails rolls back its own transaction, which
     * announces a rollback, which drains again: with the ids still queued, that drain would settle them again, and fail
     * again, without end.
     */
    public static function drain(Connection $connection): void
    {
        $custody = app(self::class);
        $entryIds = array_keys($custody->pending[$connection->getName()] ?? []);
        unset($custody->pending[$connection->getName()]);

        foreach ($entryIds as $entryId) {
            try {
                self::settle($connection, $entryId);
            } catch (Throwable $failure) {
                Log::warning(sprintf(
                    'Media custody, entry %d: its file could not be put back where its row says it belongs after the '
                    .'write that moved it rolled back — %s. kitsune:media-prune lists it, and kitsune:media-reconcile '
                    .'--entry=%d --force puts it back (ADR-042 decision 5).',
                    $entryId,
                    $failure->getMessage(),
                    $entryId,
                ));

                continue;
            }

            self::cleanUpReporting($connection, $entryId);
        }
    }

    /** Clean up, logging rather than throwing: what it follows has already happened, and the copy it keeps is safe. */
    private static function cleanUpReporting(Connection $connection, int $entryId): void
    {
        try {
            self::cleanUp($connection, $entryId);
        } catch (Throwable $failure) {
            Log::warning(sprintf(
                'Media custody, entry %d: its private copy could not be removed — %s. The file is published; '
                .'kitsune:media-prune --force removes the copy once it can be (ADR-042 decision 5).',
                $entryId,
                $failure->getMessage(),
            ));
        }
    }

    /**
     * Remove a file no row claims, rechecked under the lock: a row naming the path, on any disk, refuses it.
     *
     * A partial copy is rechecked by the path it was written for.
     *
     * ⚠️ A CLAIM CHECK, NOT A SHARING CHECK. Paths are unique (Adam, decision 8, 2026-09-25), but the listing that found
     * the file is not locked, and `store()` writes an upload's bytes before its row: a row committed since the listing
     * claims a path no row named then, which the index cannot prevent. The recheck is a lookup under the index.
     *
     * ⚠️ NEVER INSIDE AN OPEN TRANSACTION — review of slice 5b. The recheck would read that transaction's own work: a
     * file an erasure not yet committed has freed reads as an orphan, and stays deleted when the erasure rolls back.
     *
     * @throws LogicException inside an open transaction
     */
    public static function removeOrphan(Connection $connection, string $disk, string $path): string
    {
        self::refuseInsideTransaction($connection, sprintf('an orphan, [%s:%s],', $disk, $path));
        $claimed = str_ends_with($path, MediaBytes::PARTIAL) ? substr($path, 0, -strlen(MediaBytes::PARTIAL)) : $path;

        return self::locked($connection, 0, static function () use ($connection, $disk, $path, $claimed): string {
            if ($connection->table('media_files')->where('path', $claimed)->exists()) {
                return self::CLAIMED;
            }

            MediaBytes::delete($disk, $path);

            return self::REMOVED;
        });
    }

    /**
     * Remove a partial copy custody left beside an entry's file, with the entry locked — outside any transaction, as an
     * orphan is.
     *
     * @throws LogicException inside an open transaction
     */
    public static function removeTemp(Connection $connection, int $entryId, string $disk, string $partial): string
    {
        self::refuseInsideTransaction($connection, sprintf('entry %d\'s partial copy, [%s:%s],', $entryId, $disk, $partial));

        return self::locked($connection, $entryId, static function () use ($disk, $partial): string {
            MediaBytes::delete($disk, $partial);

            return self::REMOVED;
        });
    }

    /** Refuse to remove anything inside an open transaction: what it read could still roll back (ADR-042 decision 5). */
    private static function refuseInsideTransaction(Connection $connection, string $what): void
    {
        if (! self::isOutermost($connection)) {
            throw new LogicException(sprintf('Refusing to remove %s inside an open transaction (ADR-042 decision 5).', $what));
        }
    }

    /**
     * Delete one disk's copy, and any partial beside it, once the target is known to be another place.
     *
     * A copy that differs from the kept one is logged before it goes: it is the only trace of a change made outside
     * Kitsune. A hash the keeper took as absent, of a copy that is here now, is taken again (slice 5b).
     *
     * @return bool whether the disk held a copy
     */
    private static function removeCopy(Repository $config, string $target, string $disk, string $path, MediaKeeper $keeper): bool
    {
        MediaDisks::refuseCoincidingMediaDisks($config, $target, $disk);

        if (MediaBytes::sameObject($target, $disk, $path)) {
            throw new MediaCustodyFailure('coinciding', $disk, $path);
        }

        $held = MediaBytes::present($disk, $path);

        if ($held) {
            $hash = $keeper->hashes[$disk] ?? MediaBytes::hash($disk, $path);
            $keeper->refuseToLose($disk, $path, $hash);

            self::noteDiffering($disk, $path, $hash, $keeper, 'removing', $target);

            MediaBytes::delete($disk, $path);
        }

        // Only a local disk is ever written a partial copy: an object store's PUT is whole or nothing.
        if (MediaBytes::local($disk)) {
            MediaBytes::delete($disk, MediaBytes::partial($path));
        }

        return $held;
    }

    /**
     * Log a copy about to be overwritten or removed whose bytes differ from the kept copy's: it is the only trace of a
     * change made outside Kitsune.
     */
    public static function noteDiffering(string $disk, string $path, ?string $hash, MediaKeeper $keeper, string $doing, ?string $holder = null): void
    {
        if ($hash === null || MediaBytes::same($hash, $keeper->expected)) {
            return;
        }

        Log::warning(sprintf(
            'Media custody: %s the copy of [%s] on [%s], whose hash [%s] differs from the kept copy\'s [%s], which [%s] '
            .'holds (ADR-042 decision 5).',
            $doing,
            $path,
            $disk,
            $hash,
            (string) $keeper->expected,
            $holder ?? (string) $keeper->disk,
        ));
    }

    /** This connection's innermost transaction that after-commit callbacks wait for, if one is open. */
    private static function innermost(Connection $connection): ?DatabaseTransactionRecord
    {
        /** @var DatabaseTransactionsManager $manager */
        $manager = app('db.transactions');

        return $manager->callbackApplicableTransactions()->last(
            static fn (DatabaseTransactionRecord $record): bool => $record->connection === $connection->getName(),
        );
    }

    private static function config(): Repository
    {
        return app('config');
    }
}
