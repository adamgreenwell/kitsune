<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Kitsune\Core\Media\MediaBytes;
use Kitsune\Core\Media\MediaCustody;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Models\MediaFile;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Find every media file that is not where its row says it belongs, and put it there — ADR-042 decision 5, slice 5b.
 *
 * ⚠️ EVERY ROW, TRASHED INCLUDED (Adam, decision 4, 2026-09-24). A file belongs on the configured public disk while its
 * entry is live and its visibility public, and on the configured private disk otherwise. A row whose disk, or whose bytes,
 * disagree with that is listed: a trashed file still on a disk the web serves, a restored one whose publication did not
 * finish, a live one whose compensation failed, a row still naming ADR-041's `local`, a copy the third write left.
 *
 * ⚠️ READ-ONLY WITHOUT `--force`, for `kitsune:media-prune`'s reason: it moves files, and media has no revision history
 * and no undo. The listing asks each disk whether it holds the path and hashes nothing, so it can say where the bytes
 * are, not whether they are the right ones; `--force` finds that out under the lock.
 *
 * ⚠️ `--force` DECIDES NOTHING FROM THE LISTING. For each listed row it runs exactly what a compensation runs —
 * `MediaCustody::settle()` then `cleanUp()` — each its own outermost transaction under the row's lock, taking every
 * decision from the row as read there. The listing only chooses which rows to lock: a row a trash or a restore changed
 * since is settled as it now is, and one listed as missing is asked again (a publication may have moved it). No copy is
 * deleted until the copy kept is verified on the disk the row names or is moving to; one that cannot be read is never
 * touched (ADR-042 decision 5, rule 2; Adam, decisions 2, 2b and 6).
 *
 * ⚠️ IT FAILS ON FINDINGS (Adam, decision 7, 2026-09-25), so a deploy or a cron can run it as a check: read-only, while
 * any finding is still there when the rows are asked again at the end; forced, while any row failed, is missing or was
 * kept. A file with no bytes anywhere keeps it failing until its entry is erased or the file restored from a backup.
 *
 * ⚠️ ON SQLITE A FORCED RUN HOLDS THE DATABASE'S WRITE LOCK, row by row, while bytes move, and a save or an upload that
 * reads before it writes fails during each hold. The lever is Adam's (ADR-042, *Measured — decision 5, slice 5b*): the
 * command says so, and presumes nothing.
 */
final class MediaReconcileCommand extends Command
{
    protected $signature = 'kitsune:media-reconcile
        {--force : put each file where its row\'s state says and point the row at it, rather than listing}
        {--entry=* : only these entries}';

    protected $description = 'Find media files that are not where their row\'s state says they belong, and put them there (ADR-042)';

    /** The labels that are findings; `extra` is listed for `kitsune:media-prune`, and is not one. */
    private const FINDINGS = ['unknown', 'missing', 'exposed', 'awaiting publication', 'elsewhere', 'absent', 'private copy'];

    /** @var list<string> custody's warnings, collected while a row is forced and printed after it */
    private array $warnings = [];

    /** Whether this run is collecting them: a listener outlives the command in a process that runs another. */
    private bool $collecting = false;

    public function handle(): int
    {
        $config = app('config');
        $connection = (new MediaFile)->getConnection();
        $force = (bool) $this->option('force');
        $public = MediaDisks::configured($config, 'public');
        $private = MediaDisks::configured($config, 'private');

        $entries = $this->entries();

        if ($entries === false) {
            return self::FAILURE;
        }

        $unsafe = $this->unsafe($config);
        $unique = MediaCustody::pathsAreUnique($connection);

        if ($force) {
            $refusal = match (true) {
                ! MediaCustody::isOutermost($connection) => 'Refusing to reconcile inside an open transaction: bytes moved '
                    .'now would stay moved if it rolled back (ADR-042 decision 5). Nothing was moved.',
                $unsafe !== null => $unsafe,
                ! $unique => 'Refusing: media_files.path is not unique on this database, and custody removes a copy only on '
                    .'the understanding that its path is one row\'s (ADR-042 decision 5; Adam, decision 8, 2026-09-25). Run '
                    .'the migration 0001_01_01_000010_make_media_file_paths_unique (php artisan migrate) and try again. '
                    .'Nothing was moved.',
                default => null,
            };

            if ($refusal !== null) {
                $this->error($refusal);

                return self::FAILURE;
            }
        }

        $unknown = $entries === null ? [] : array_values(array_diff($entries, $this->rows($connection)
            ->whereIntegerInRaw('media_files.entry_id', $entries)
            ->pluck('media_files.entry_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all()));

        if ($unknown !== []) {
            $this->error(sprintf('No media file for entr%s %s: nothing was listed.', count($unknown) === 1 ? 'y' : 'ies', implode(', ', $unknown)));

            return self::FAILURE;
        }

        $this->line(sprintf(
            '%s every media row, trashed included: a file belongs on [%s] while its entry is live and it is public, and on [%s] otherwise.',
            $force ? 'Reconciling' : 'Listing, read-only,',
            $public,
            $private,
        ));

        if ($force && $connection->getDriverName() === 'sqlite') {
            $this->warn(
                'On SQLite each row this run repairs holds the database\'s write lock while its bytes move, and a save or '
                .'upload that reads before it writes fails during each hold: run it when nobody is editing (ADR-042, '
                .'*Measured — decision 5, slice 5b*).'
            );
        }

        if (! $force && $unsafe !== null) {
            $this->warn($unsafe.' kitsune:media-reconcile --force refuses until this is fixed.');
        }

        if (! $force && ! $unique) {
            $this->warn('media_files.path is not unique on this database: --force refuses until the migration '
                .'0001_01_01_000010_make_media_file_paths_unique has run.');
        }

        $this->legacy($connection, $config, $entries);

        if ($force) {
            $this->collecting = true;

            Event::listen(MessageLogged::class, function (MessageLogged $logged): void {
                if ($this->collecting && $logged->level === 'warning' && str_starts_with($logged->message, 'Media custody')) {
                    $this->warnings[] = $logged->message;
                }
            });
        }

        $counts = [];
        $findings = [];
        $total = 0;
        $bad = 0;

        $this->rows($connection, $entries)->chunkById(500, function (Collection $rows) use ($config, $connection, $force, &$counts, &$findings, &$total, &$bad): void {
            foreach ($rows as $row) {
                $total++;
                $survey = $this->survey($config, $row);

                if ($survey['label'] === null) {
                    continue;
                }

                $line = $this->describe($row, $survey);

                if (in_array($survey['label'], self::FINDINGS, true)) {
                    $findings[] = (int) $row->entry_id;
                }

                if (! $force) {
                    $counts[$survey['label']]['listed'] = ($counts[$survey['label']]['listed'] ?? 0) + 1;
                    $this->line($line);

                    continue;
                }

                [$outcome, $kept] = $this->settle($connection, (int) $row->entry_id);
                $counts[$survey['label']]['listed'] = ($counts[$survey['label']]['listed'] ?? 0) + 1;
                $counts[$survey['label']][$kept === null ? 'settled' : $kept] = ($counts[$survey['label']][$kept === null ? 'settled' : $kept] ?? 0) + 1;
                $bad += $kept === null ? 0 : 1;

                $this->line($line.'  → '.$outcome);

                foreach ($this->warnings as $warning) {
                    $this->line('    '.$warning);
                }

                $this->warnings = [];
            }
        }, 'media_files.entry_id', 'entry_id');

        $this->collecting = false;
        $this->summary($counts, $force);

        if ($force) {
            return $bad === 0 ? self::SUCCESS : self::FAILURE;
        }

        $remaining = $this->recheck($connection, $config, $findings);

        if ($findings === []) {
            $this->info('Every media row names the disk its state says, and that disk holds its file.');

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            '%d of %d media row%s disagree%s with where %s bytes are, and nothing was changed.%s Re-run with --force: each '
            .'file is copied where its row\'s state says, verified by SHA-256, the row repointed, and the copies custody\'s '
            .'steps remove removed — one that differs named in the log with both hashes. Media has no revision history '
            .'and no undo.',
            count($findings),
            $total,
            $total === 1 ? '' : 's',
            count($findings) === 1 ? 's' : '',
            count($findings) === 1 ? 'its' : 'their',
            $remaining === count($findings) ? '' : sprintf(' %d of those settled while this ran.', count($findings) - $remaining),
        ));

        return $remaining === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<int>|null|false the entries asked for, null for every entry, false when an option is not an id */
    private function entries(): array|null|false
    {
        /** @var list<string> $option */
        $option = (array) $this->option('entry');

        if ($option === []) {
            return null;
        }

        $ids = [];

        foreach ($option as $value) {
            if (! ctype_digit((string) $value) || (int) $value < 1) {
                $this->error(sprintf('--entry takes an entry id, a whole number: [%s] is not one. Nothing was listed.', $value));

                return false;
            }

            $ids[] = (int) $value;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Every media row with its entry's state, past every scope: `media_files` is `#[Unscoped]`, and a console asking on
     * an operator's behalf has no org to narrow by.
     *
     * @param  list<int>|null  $entries
     */
    private function rows(Connection $connection, ?array $entries = null): Builder
    {
        $query = $connection->table('media_files')
            ->leftJoin('entries', 'entries.id', '=', 'media_files.entry_id')
            ->select(['media_files.entry_id', 'media_files.disk', 'media_files.path', 'media_files.visibility', 'entries.id as entry', 'entries.deleted_at']);

        return $entries === null ? $query : $query->whereIntegerInRaw('media_files.entry_id', $entries);
    }

    /** The configuration refusal `--force` would make, as its message; null when there is none. */
    private function unsafe(Repository $config): ?string
    {
        try {
            MediaDisks::refuseUnsafeMediaDisks($config);
        } catch (RuntimeException $refused) {
            return $refused->getMessage();
        }

        return null;
    }

    /**
     * Say so when rows still name a disk that is neither configured, core's nor served — ADR-041's `local` — because prune
     * sweeps such a disk for orphans only while a row names it, and reconcile moves the rows off it.
     *
     * @param  list<int>|null  $entries
     */
    private function legacy(Connection $connection, Repository $config, ?array $entries): void
    {
        $known = [
            MediaDisks::configured($config, 'public'),
            MediaDisks::configured($config, 'private'),
            MediaDisks::PRIVATE,
            ...MediaDisks::servedDisks($config),
        ];

        $named = $connection->table('media_files')
            ->when($entries !== null, static fn (Builder $query) => $query->whereIntegerInRaw('entry_id', (array) $entries))
            ->select('disk')
            ->selectRaw('count(*) as rows_naming')
            ->groupBy('disk')
            ->orderBy('disk')
            ->pluck('rows_naming', 'disk');

        foreach ($named as $disk => $rows) {
            if (in_array((string) $disk, $known, true)) {
                continue;
            }

            $this->warn(sprintf(
                '%d row%s name [%s], which kitsune:media-prune sweeps only while a row names it: run kitsune:media-prune '
                .'--force before reconcile moves the last.',
                (int) $rows,
                (int) $rows === 1 ? '' : 's',
                $disk,
            ));
        }
    }

    /**
     * Where a row's file belongs and which disks hold its path, from the unlocked row and presence alone.
     *
     * ⚠️ A DISK WHOSE ROOT DOES NOT EXIST IS NOT ASKED: it holds nothing, and building it would create it. A failure to
     * tell whether a disk holds the path is `unknown`, never absent.
     *
     * @return array{label: ?string, target: string, held: list<string>, failure: ?string}
     */
    private function survey(Repository $config, stdClass $row): array
    {
        $public = MediaDisks::configured($config, 'public');
        $private = MediaDisks::configured($config, 'private');
        $target = MediaCustody::target($row, $row);
        $named = (string) $row->disk;
        $path = (string) $row->path;
        $held = [];

        try {
            $served = MediaDisks::servedDisks($config);

            foreach (MediaCustody::asked($config, $target, $named) as $disk) {
                if (! MediaDisks::mayHold($config, $disk)) {
                    continue;
                }

                // Under a public target, P under another name, or a disk that cannot be told from it, is P itself.
                if ($target === $public && $disk !== $public && MediaDisks::onePlace($config, $public, $disk) !== false) {
                    continue;
                }

                if (MediaBytes::present($disk, $path)) {
                    $held[] = $disk;
                }
            }
        } catch (Throwable $failure) {
            return ['label' => 'unknown', 'target' => $target, 'held' => $held, 'failure' => $failure->getMessage()];
        }

        $label = match (true) {
            $held === [] => 'missing',
            $target === $private && array_intersect($held, $served) !== [] => 'exposed',
            $target === $public && $named !== $public => 'awaiting publication',
            $target === $private && $named !== $private => 'elsewhere',
            ! in_array($target, $held, true) => 'absent',
            $target === $public && array_intersect($held, [$private, MediaDisks::PRIVATE]) !== [] => 'private copy',
            count($held) > 1 => 'extra',
            default => null,
        };

        return ['label' => $label, 'target' => $target, 'held' => $held, 'failure' => null];
    }

    /** @param array{label: ?string, target: string, held: list<string>, failure: ?string} $survey */
    private function describe(stdClass $row, array $survey): string
    {
        return sprintf(
            '%-20s entry %d  %s  [%s]  names %s, belongs on %s, %s',
            (string) $survey['label'],
            (int) $row->entry_id,
            $row->deleted_at === null ? 'live' : 'trashed',
            (string) $row->path,
            (string) $row->disk,
            $survey['target'],
            match (true) {
                $survey['failure'] !== null => 'held by: cannot be told — '.$survey['failure'],
                $survey['held'] === [] => 'held by no disk',
                default => 'held by '.implode(', ', $survey['held']),
            },
        );
    }

    /**
     * Settle one row, then clean up after it — a compensation's steps, each under the lock and each deciding from the row
     * as it is there.
     *
     * @return array{string, ?string} what happened, and why the row counts against the run: failed, missing or kept
     */
    private function settle(Connection $connection, int $entryId): array
    {
        try {
            $settled = MediaCustody::settle($connection, $entryId);
        } catch (Throwable $failure) {
            return ['failed: '.$failure->getMessage(), 'failed'];
        }

        if ($settled === MediaCustody::GONE) {
            return ['gone since the listing', null];
        }

        if ($settled === MediaCustody::MISSING) {
            return ['missing: no disk holds its file — restore it from a backup, or erase the entry', 'missing'];
        }

        try {
            $cleaned = MediaCustody::cleanUp($connection, $entryId);
        } catch (Throwable $failure) {
            return ['failed: '.$failure->getMessage(), 'failed'];
        }

        // Only a withdrawal to the private disk sets a copy aside, so a row naming another disk kept its unreadable one.
        if ($settled === MediaCustody::SET_ASIDE) {
            $named = $connection->table('media_files')->where('entry_id', $entryId)->value('disk');
            $stayed = is_string($named) && $named !== MediaDisks::configured(app('config'), 'private');

            return [
                'set aside: a copy that cannot be read was left untouched, and the file taken off the web (Adam, decision 6, '
                .'2026-09-25)'.($stayed ? sprintf('; the row still names [%s]', $named) : ''),
                'kept',
            ];
        }

        if ($cleaned === MediaCustody::UNSETTLED) {
            return ['kept: a copy was left where it is, as the log below says', 'kept'];
        }

        return [$settled === MediaCustody::SETTLED || $cleaned === MediaCustody::SETTLED ? 'settled' : 'nothing to do under the lock', null];
    }

    /** @param array<string, array<string, int>> $counts */
    private function summary(array $counts, bool $force): void
    {
        if ($counts === []) {
            return;
        }

        $order = [...self::FINDINGS, 'extra'];
        uksort($counts, static fn (string $a, string $b): int => array_search($a, $order, true) <=> array_search($b, $order, true));

        $this->table(
            $force ? ['Label', 'Rows', 'Settled', 'Kept', 'Missing', 'Failed'] : ['Label', 'Rows'],
            array_map(static fn (string $label, array $count): array => $force
                ? [$label, $count['listed'] ?? 0, $count['settled'] ?? 0, $count['kept'] ?? 0, $count['missing'] ?? 0, $count['failed'] ?? 0]
                : [$label, $count['listed'] ?? 0], array_keys($counts), $counts),
        );
    }

    /**
     * How many of the findings are still there, asked again of each row: a restore's publication in flight while the
     * listing ran is not a problem, and a check run by a deploy should not fail on one.
     *
     * ⚠️ IN SLICES, THE IDS WRITTEN INTO THE STATEMENT: `whereIntegerInRaw` binds nothing, so no engine's limit on bound
     * parameters is reached however many findings there are.
     *
     * @param  list<int>  $findings
     */
    private function recheck(Connection $connection, Repository $config, array $findings): int
    {
        $remaining = 0;

        foreach (array_chunk($findings, 500) as $slice) {
            foreach ($this->rows($connection, $slice)->get() as $row) {
                if (in_array($this->survey($config, $row)['label'], self::FINDINGS, true)) {
                    $remaining++;
                }
            }
        }

        return $remaining;
    }
}
