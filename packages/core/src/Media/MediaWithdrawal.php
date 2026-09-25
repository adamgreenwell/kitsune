<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Media;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;

/**
 * Take a trashed or erased entry's file off the web inside the write that trashes or erases it — ADR-042 decision 5.
 *
 * @internal
 *
 * One per audited write, on that write's connection, and only inside its transaction: the rows it reads are the rows
 * the write has just changed, locked until it commits.
 *
 * ⚠️ WITHDRAWAL PRECEDES THE COMMIT. A trashed file is copied to the private disk, the copy read back, and every copy
 * on a disk the web serves deleted — all before the trash commits, so no committed trash ever leaves its file public.
 * A copy that cannot be made or a public copy that cannot be removed refuses the write instead
 * (`MediaWithdrawalRefused`), and the entry stays as it was.
 *
 * ⚠️ AND WHAT IT MOVED IS PUT BACK IF THE WRITE DOES NOT COMMIT. A rollback — of the write, or of a transaction
 * enclosing it — queues every entry whose bytes moved, and custody settles each once nothing on the connection is
 * left to commit (`MediaCustody::drain()`). A restore goes the other way: publication follows the outermost commit,
 * because a file made public inside a transaction that then rolled back would be a trashed file on the web.
 *
 * ⚠️ BY PATH, ON EVERY DISK THE WEB SERVES — not by the disk the row names. A row can name one disk while a copy
 * from a former configuration sits on another that still serves it; withdrawal empties each one.
 */
final class MediaWithdrawal
{
    /** @var list<int> entries whose bytes moved */
    private array $moved = [];

    private bool $queued = false;

    /** @var list<int> entries a restore made public, to be published after the commit */
    private array $publishing = [];

    private bool $publishRetried = false;

    /** @var list<stdClass> the file rows a force-delete locked before erasing them */
    private array $locked = [];

    /** @param  string  $operation  what the write does, as its refusal says it: "trash" or "erase" */
    public function __construct(private readonly Connection $connection, private readonly string $operation = 'trash') {}

    /**
     * Withdraw what this soft write trashed, and register the publication of what it restored.
     *
     * ⚠️ ONLY A FILE THE WEB COULD REACH IS A CANDIDATE: a trashed entry's file that is public, or that sits on a disk
     * the web serves. A private file on an unserved disk was never public, so its trash touches no disk at all.
     *
     * @param  list<int|string>  $keys  the rows the write changed
     *
     * @throws MediaWithdrawalRefused
     */
    public function afterSoftWrite(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $ids = array_map(static fn (int|string $key): int => (int) $key, $keys);
        $trashedAt = $this->connection->table('entries')->whereIntegerInRaw('id', $ids)->lockForUpdate()->pluck('deleted_at', 'id')->all();
        // In key order, so a bulk write withdraws — and locks — its files in the order every other write takes them.
        $files = $this->connection->table('media_files')->whereIntegerInRaw('entry_id', $ids)->orderBy('entry_id')->lockForUpdate()->get()->all();

        $config = self::config();
        $public = MediaDisks::configured($config, 'public');
        $private = MediaDisks::configured($config, 'private');
        $served = MediaDisks::servedDisks($config);
        $publishing = [];
        $candidates = [];

        foreach ($files as $file) {
            $trashed = ($trashedAt[(int) $file->entry_id] ?? null) !== null;

            if (! $trashed && $file->visibility === 'public' && $file->disk !== $public) {
                $publishing[] = (int) $file->entry_id;
            }

            if ($trashed && ($file->visibility === 'public' || in_array($file->disk, $served, true))) {
                $candidates[] = $file;
            }
        }

        if ($publishing !== []) {
            $this->publishing = $publishing;
            MediaCustody::whenOutermost($this->connection, fn () => MediaCustody::publish($this->connection, $publishing));
        }

        if ($candidates === []) {
            return;
        }

        $this->refuseUnsafeDisks($config, (int) $candidates[0]->entry_id);

        // ⚠️ THE ROW FIRST: it names the private disk before any byte moves, inside the same transaction.
        $moving = array_values(array_map(
            static fn (stdClass $file): int => (int) $file->entry_id,
            array_filter($candidates, static fn (stdClass $file): bool => $file->disk !== $private),
        ));

        if ($moving !== []) {
            $this->connection->table('media_files')->whereIntegerInRaw('entry_id', $moving)->update(['disk' => $private]);
        }

        foreach ($candidates as $file) {
            $this->withdraw($file, $served, $config);
        }
    }

    /**
     * Lock the file rows a force-delete is about to erase, before the rows go.
     *
     * @param  list<int|string>  $keys
     */
    public function lockFiles(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $this->locked = $this->connection->table('media_files')
            ->whereIntegerInRaw('entry_id', array_map(static fn (int|string $key): int => (int) $key, $keys))
            ->orderBy('entry_id')
            ->lockForUpdate()
            ->get()
            ->all();
    }

    /**
     * Withdraw every locked file, after the rows are erased and before the erasure commits.
     *
     * @throws MediaWithdrawalRefused
     */
    public function withdrawAll(): void
    {
        if ($this->locked === []) {
            return;
        }

        $config = self::config();
        $this->refuseUnsafeDisks($config, (int) $this->locked[0]->entry_id);
        $served = MediaDisks::servedDisks($config);

        foreach ($this->locked as $file) {
            $this->withdraw($file, $served, $config);
        }
    }

    /** @return list<array{entry_id: int, disk: string, path: string}> the files a force-delete locked */
    public function files(): array
    {
        return array_map(static fn (stdClass $file): array => [
            'entry_id' => (int) $file->entry_id,
            'disk' => (string) $file->disk,
            'path' => (string) $file->path,
        ], $this->locked);
    }

    /**
     * After the write failed: put back what it moved, once nothing is left to commit, and register again the
     * publication its rollback discarded — the row decides whether there is anything to publish.
     */
    public function compensate(): void
    {
        if ($this->moved !== []) {
            $this->queue();
        }

        MediaCustody::drainSoon($this->connection);

        if ($this->publishing !== [] && ! $this->publishRetried) {
            $this->publishRetried = true;
            $publishing = $this->publishing;
            MediaCustody::whenOutermost($this->connection, fn () => MediaCustody::publish($this->connection, $publishing));
        }
    }

    /**
     * Take one file off every disk the web serves, keeping a verified copy on the private disk.
     *
     * ⚠️ A PRIVATE FILE ON AN UNSERVED DISK IS LOOKED FOR ON THE CONFIGURED PUBLIC DISK ALONE. It was never public, so
     * only a stray copy there is a risk, and a served disk that cannot be reached must not block its erasure.
     *
     * @param  list<string>  $served
     *
     * @throws MediaWithdrawalRefused
     */
    private function withdraw(stdClass $file, array $served, Repository $config): void
    {
        $id = (int) $file->entry_id;
        $path = (string) $file->path;
        $named = (string) $file->disk;
        $public = MediaDisks::configured($config, 'public');
        $target = MediaDisks::configured($config, 'private');
        $reachable = $file->visibility === 'public' || in_array($named, $served, true);

        // A served local disk nothing configures or names, whose root does not exist, holds nothing, and is not built.
        $surfaces = array_values(array_filter(
            array_diff(array_unique($reachable ? [$public, ...$served, $named] : [$public]), [$target]),
            static function (string $disk) use ($config, $public, $named): bool {
                if ($disk === $public || $disk === $named) {
                    return true;
                }

                $root = MediaDisks::resolved($config, $disk)['root'];

                return $root === null || is_dir($root);
            },
        ));

        try {
            $held = array_values(array_filter($surfaces, static fn (string $disk): bool => MediaBytes::present($disk, $path)));
            $partials = array_values(array_filter($surfaces, static fn (string $disk): bool => MediaBytes::present($disk, MediaBytes::partial($path))));
        } catch (MediaCustodyFailure $failure) {
            throw $this->refused($id, MediaWithdrawalRefused::UNREADABLE, $failure);
        }

        if ($held !== []) {
            try {
                MediaDisks::refuseCoincidingMediaDisks($config, $target, ...$held);
            } catch (RuntimeException $coinciding) {
                throw new MediaWithdrawalRefused($id, MediaWithdrawalRefused::COINCIDING, $target, $this->operation, $coinciding);
            }

            /*
             * ⚠️ NOTHING IS SET ASIDE HERE. A trash or an erasure refuses on any copy it cannot read, which leaves the
             * entry as it was; only settle, taking an already-trashed file off the web, sets such a copy aside (Adam,
             * decision 6, 2026-09-25) — so no spare disks are passed.
             */
            try {
                $keeper = MediaCustody::keeper($file, $target, $named, array_values(array_unique([$target, $named, $public, ...$served])));
            } catch (MediaCustodyFailure $failure) {
                throw $this->refused($id, MediaWithdrawalRefused::UNREADABLE, $failure);
            }

            if ($keeper->disk === null || $keeper->expected === null) {
                // Every copy seen a moment ago has gone since: there is nothing left to take off the web.
                return;
            }

            if (! $keeper->targetHolds) {
                try {
                    MediaCustody::noteDiffering($target, $path, $keeper->hashes[$target] ?? null, $keeper, 'overwriting');
                    MediaBytes::copyVerified($keeper->disk, $target, $path, $keeper->expected);
                } catch (MediaCustodyFailure $failure) {
                    throw $this->refused($id, $failure->reason === 'coinciding' ? MediaWithdrawalRefused::COINCIDING : MediaWithdrawalRefused::COPY_FAILED, $failure);
                }
            }

            $this->recordMove($id);

            foreach ($held as $disk) {
                if (MediaBytes::sameObject($target, $disk, $path)) {
                    throw new MediaWithdrawalRefused($id, MediaWithdrawalRefused::COINCIDING, $disk, $this->operation);
                }

                try {
                    $hash = array_key_exists($disk, $keeper->hashes) ? $keeper->hashes[$disk] : MediaBytes::hash($disk, $path);
                } catch (MediaCustodyFailure $failure) {
                    throw $this->refused($id, MediaWithdrawalRefused::UNREADABLE, $failure);
                }

                MediaCustody::noteDiffering($disk, $path, $hash, $keeper, 'removing', $target);

                try {
                    MediaBytes::delete($disk, $path);
                } catch (MediaCustodyFailure $failure) {
                    throw $this->refused($id, MediaWithdrawalRefused::DELETE_FAILED, $failure);
                }
            }
        } elseif ($partials === [] && self::nowhere($path, $target, $named)) {
            Log::warning(sprintf(
                'Media custody, entry %d: no copy of [%s] was found on [%s] or on the private disk [%s] — the file was '
                .'removed outside Kitsune (ADR-042 decision 5).',
                $id,
                $path,
                implode(', ', $surfaces),
                $target,
            ));
        }

        foreach ($partials as $disk) {
            try {
                MediaBytes::delete($disk, MediaBytes::partial($path));
            } catch (MediaCustodyFailure $failure) {
                throw $this->refused($id, MediaWithdrawalRefused::DELETE_FAILED, $failure);
            }
        }
    }

    /** Whether neither the private disk nor the named one holds the file — false when that cannot be told. */
    private static function nowhere(string $path, string $target, string $named): bool
    {
        return (bool) rescue(
            static fn (): bool => ! MediaBytes::present($target, $path) && ($named === $target || ! MediaBytes::present($named, $path)),
            false,
            false,
        );
    }

    /**
     * The configuration's refusal, as this write's: a trash or an erasure refused like any other, so the admin shows it
     * as a notification naming the entry rather than failing with an error (decision 7).
     */
    private function refuseUnsafeDisks(Repository $config, int $entryId): void
    {
        try {
            MediaDisks::refuseUnsafeMediaDisks($config);
        } catch (RuntimeException $unsafe) {
            throw new MediaWithdrawalRefused($entryId, MediaWithdrawalRefused::UNSAFE_DISKS, MediaDisks::configured($config, 'private'), $this->operation, $unsafe);
        }
    }

    /**
     * Remember that this entry's bytes moved, and on the first move, have a rollback queue every one of them.
     */
    private function recordMove(int $entryId): void
    {
        if ($this->moved === []) {
            MediaCustody::onRollback($this->connection, fn () => $this->queue());
        }

        $this->moved[] = $entryId;
    }

    /** Queue what moved for custody to put back — once, however many routes ask. */
    private function queue(): void
    {
        if ($this->queued) {
            return;
        }

        $this->queued = true;
        MediaCustody::queue($this->connection->getName(), $this->moved);
    }

    /** The refusal, with the disk and path the failure named written to the log, where the path belongs. */
    private function refused(int $entryId, string $reason, MediaCustodyFailure $failure): MediaWithdrawalRefused
    {
        Log::warning(sprintf(
            'Media custody, entry %d: refusing to %s it — %s (ADR-042 decision 5).',
            $entryId,
            $this->operation,
            $failure->getMessage(),
        ));

        return new MediaWithdrawalRefused($entryId, $reason, $failure->disk, $this->operation, $failure);
    }

    private static function config(): Repository
    {
        return app('config');
    }
}
