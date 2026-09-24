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
use LogicException;
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
 * matching hash — no fsync, as `store()` also has none — and one disk's copy is deleted only while another's is known
 * to hold the same bytes. Two names for one place are refused before anything moves: a "copy" there is the file.
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

    /**
     * Entries whose files a rolled-back withdrawal moved, by connection name, waiting to be put back.
     *
     * @var array<string, array<int, true>>
     */
    private array $pending = [];

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
     * Choose the copy to keep, among the disks asked — `MediaKeeper` says in what order.
     *
     * ⚠️ THE TARGET IS HASHED FIRST, so a file already where it belongs costs one hash. And a copy that cannot be read
     * is a failure, never an absence: reading it as absent could choose a copy that differs and delete the file.
     *
     * @param  list<string>  $candidates  every disk to ask, the target and the named disk among them
     */
    public static function keeper(stdClass $file, string $target, string $named, array $candidates): MediaKeeper
    {
        $path = (string) $file->path;
        $checksum = is_string($file->checksum) && $file->checksum !== '' ? $file->checksum : null;
        $present = array_values(array_filter($candidates, static fn (string $disk): bool => MediaBytes::present($disk, $path)));
        $hashes = [];

        foreach (array_values(array_unique([$target, $named, ...$candidates])) as $disk) {
            if (! in_array($disk, $present, true)) {
                continue;
            }

            $hashes[$disk] = MediaBytes::hash($disk, $path);

            if (MediaBytes::same($hashes[$disk], $checksum)) {
                return new MediaKeeper($disk, $checksum, MediaKeeper::MATCH, $disk === $target, $hashes);
            }
        }

        // Every present copy has been hashed by now; one gone since it was seen is not a copy.
        $held = array_keys(array_filter($hashes, static fn (?string $hash): bool => $hash !== null));

        if ($held === []) {
            return new MediaKeeper(null, $checksum, MediaKeeper::MISSING, false, $hashes);
        }

        if (in_array($named, $held, true)) {
            $disk = $named;
            $mode = MediaKeeper::NAMED;
        } else {
            $config = self::config();
            $order = array_diff(
                array_values(array_unique([MediaDisks::configured($config, 'public'), MediaDisks::configured($config, 'private'), ...MediaDisks::servedDisks($config)])),
                [$target],
            );
            $disk = array_values(array_intersect($order, $held))[0] ?? $target;
            $mode = MediaKeeper::FIRST;
        }

        $expected = (string) $hashes[$disk];

        Log::warning(sprintf(
            'Media custody, entry %d: no copy of [%s] matches its recorded checksum [%s], so the copy on [%s] is kept '
            .'(%s) and every other is verified against it. Each disk held: %s. A copy was changed outside Kitsune '
            .'(ADR-042 decision 5).',
            (int) $file->entry_id,
            $path,
            $checksum ?? 'none',
            $disk,
            $mode === MediaKeeper::NAMED ? 'the disk the row names' : 'the first in the configured order',
            json_encode($hashes, JSON_UNESCAPED_SLASHES),
        ));

        return new MediaKeeper($disk, $expected, $mode, MediaBytes::same($hashes[$target] ?? null, $expected), $hashes);
    }

    /**
     * Put an entry's file where its row says it belongs, and nowhere the web serves when that is the private disk.
     *
     * ⚠️ OUTSIDE ANY TRANSACTION, OR IT REFUSES. Bytes moved inside one would stay moved when it rolls back.
     *
     * With `$publication`, only a file that belongs on the public disk is moved: publication follows a restore, and
     * the entry may have been trashed again since.
     *
     * @throws LogicException inside an open transaction
     * @throws MediaCustodyFailure when a copy cannot be written, verified or removed; nothing it verified is lost
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
            $served = MediaDisks::servedDisks($config);
            $keeper = self::keeper($file, $target, $named, array_values(array_unique([$target, $named, $public, $private, ...$served])));

            if ($keeper->disk === null || $keeper->expected === null) {
                Log::warning(sprintf(
                    'Media custody, entry %d: no disk holds [%s] — not [%s], nor the configured or served disks — so '
                    .'nothing was moved. kitsune:media-prune lists it (ADR-042 decision 5).',
                    (int) $file->entry_id,
                    $path,
                    $named,
                ));

                return self::MISSING;
            }

            $changed = false;

            if (! $keeper->targetHolds) {
                self::noteDiffering($target, $path, $keeper->hashes[$target] ?? null, $keeper, 'overwriting');
                MediaBytes::copyVerified($keeper->disk, $target, $path, $keeper->expected);
                $changed = true;
            }

            if ($named !== $target) {
                $connection->table('media_files')->where('entry_id', (int) $file->entry_id)->update(['disk' => $target]);
                $changed = true;
            }

            /*
             * ⚠️ MOVE-OFF: THE DISK THE ROW NAMED LOSES ITS COPY, unless it is a private one. A disk that was once the
             * public one — an object store with no url, say — can still serve what it holds with no configuration
             * saying so, and nothing would ever name that copy again.
             */
            if ($named !== $target && ! in_array($named, [$private, MediaDisks::PRIVATE], true)) {
                $changed = self::removeCopy($config, $target, $named, $path, $keeper) || $changed;
            }

            // Private: no disk the web serves keeps a copy.
            if ($target !== $public) {
                foreach ($served as $disk) {
                    if ($disk !== $target) {
                        $changed = self::removeCopy($config, $target, $disk, $path, $keeper) || $changed;
                    }
                }
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
                        ? 'though its row now names the public disk, so whether the commit landed is unknown. '
                          .'kitsune:media-prune shows whether the file is there'
                        : 'so it is not published, and may not be reachable at its public URL. kitsune:media-prune '
                          .'lists it under "awaiting publication"; delete and restore the entry to retry',
                ));

                continue;
            }

            if ($settled === self::SETTLED) {
                self::cleanUpReporting($connection, $entryId);
            }
        }
    }

    /**
     * Delete the private copy of a live public file, once the public disk verifiably holds the same bytes — the third
     * write, after a publication or a compensation (ADR-042 decision 5).
     *
     * ⚠️ LOCKED, AND ASKED AGAIN: the entry still live, still public, its row still naming the public disk. A delete
     * that got there first has made the private copy the one it claims, and it is left alone. Review found the unlocked
     * version: a delete landing between a publication's commit and its cleanup copied the public bytes back to the
     * private path, had that fresh copy deleted by the cleanup, then deleted the public copy — the file on neither disk.
     *
     * ⚠️ AND ONLY WHILE ANOTHER COPY IS KNOWN TO HOLD THE SAME BYTES (rule 2): the public copy's hash is compared with the
     * private copy's under the lock, and a copy that differs, or cannot be read, is kept.
     *
     * @throws LogicException inside an open transaction
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

        return self::locked($connection, $entryId, static function (?stdClass $entry, ?stdClass $file): string {
            if ($entry === null || $file === null) {
                return self::GONE;
            }

            $config = self::config();
            $public = MediaDisks::configured($config, 'public');

            if ($entry->deleted_at !== null || $file->visibility !== 'public' || $file->disk !== $public) {
                return self::UNCHANGED;
            }

            $path = (string) $file->path;
            $changed = false;

            foreach (array_diff(array_unique([MediaDisks::configured($config, 'private'), MediaDisks::PRIVATE]), [$public]) as $disk) {
                if (! MediaBytes::present($disk, $path)) {
                    continue;
                }

                MediaDisks::refuseCoincidingMediaDisks($config, $public, $disk);

                if (MediaBytes::sameObject($public, $disk, $path)) {
                    throw new MediaCustodyFailure('coinciding', $disk, $path);
                }

                $published = MediaBytes::hash($public, $path);
                $copy = MediaBytes::hash($disk, $path);

                if (! MediaBytes::same($published, $copy)) {
                    Log::warning(sprintf(
                        'Media custody, entry %d: the private copy of [%s] on [%s] was kept — the public copy %s '
                        .'(ADR-042 decision 5). kitsune:media-prune lists it as kept.',
                        (int) $file->entry_id,
                        $path,
                        $disk,
                        $published === null ? 'is missing' : "differs from it ([{$published}] against [{$copy}])",
                    ));

                    continue;
                }

                MediaBytes::delete($disk, $path);
                $changed = true;
            }

            return $changed ? self::SETTLED : self::UNCHANGED;
        });
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
                    .'write that moved it rolled back — %s. kitsune:media-prune lists it (ADR-042 decision 5).',
                    $entryId,
                    $failure->getMessage(),
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
                .'kitsune:media-prune lists the copy as kept (ADR-042 decision 5).',
                $entryId,
                $failure->getMessage(),
            ));
        }
    }

    /**
     * Remove a file no row claims, rechecked under the lock: a row naming the path, on any disk, refuses it.
     *
     * A partial copy is rechecked by the path it was written for.
     */
    public static function removeOrphan(Connection $connection, string $disk, string $path): string
    {
        $claimed = str_ends_with($path, MediaBytes::PARTIAL) ? substr($path, 0, -strlen(MediaBytes::PARTIAL)) : $path;

        return self::locked($connection, 0, static function () use ($connection, $disk, $path, $claimed): string {
            if ($connection->table('media_files')->where('path', $claimed)->exists()) {
                return self::CLAIMED;
            }

            MediaBytes::delete($disk, $path);

            return self::REMOVED;
        });
    }

    /** Remove a partial copy custody left beside an entry's file, with the entry locked. */
    public static function removeTemp(Connection $connection, int $entryId, string $disk, string $partial): string
    {
        return self::locked($connection, $entryId, static function () use ($disk, $partial): string {
            MediaBytes::delete($disk, $partial);

            return self::REMOVED;
        });
    }

    /**
     * Delete one disk's copy, and any partial beside it, once the target is known to be another place.
     *
     * A copy that differs from the kept one is logged before it goes: it is the only trace of a change made outside
     * Kitsune.
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
            $hash = array_key_exists($disk, $keeper->hashes) ? $keeper->hashes[$disk] : MediaBytes::hash($disk, $path);

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
