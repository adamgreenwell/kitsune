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
use Illuminate\Filesystem\ReadThroughFilesystem;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaBytes;
use Kitsune\Core\Media\MediaCustody;
use Kitsune\Core\Media\MediaCustodyFailure;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Models\MediaFile;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
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
 * are, not whether they are the right ones; `--force` finds that out under the lock. Of an `extra` row — its file where it
 * belongs, held on more than one disk, nothing else wrong — each copy is opened and its first byte read (on an FTP or
 * SFTP disk, or an S3 disk without `stream_reads`, a download of the whole copy), except on a disk
 * whose media directory nests with the target's, or a `read-through` disk; so is the copy an `elsewhere` row names while
 * the disk it belongs on holds the file too, whatever left it there — decision 6's set-aside copy, a move-off that
 * failed, a row naming core's private disk — and the copy an `awaiting publication` row names on a disk that is neither
 * private disk, while the configured private disk holds the file too — decision 6's row once its entry is restored
 * among them. Every --force fails on each of these while it cannot be read; a row naming core's private disk is
 * repointed first, then kept. No other copy is opened. A copy that cannot be read fails every `--force` that would
 * remove it (Adam, decision 12, 2026-09-26).
 *
 * ⚠️ `--force` DECIDES NOTHING FROM THE LISTING. For each listed row it runs exactly what a compensation runs —
 * `MediaCustody::settle()` then, unless it refused to publish a JPEG (decision 37), `cleanUp()` — each its own outermost transaction under the row's lock, taking every
 * decision from the row as read there. The listing only chooses which rows to lock: a row a trash or a restore changed
 * since is settled as it now is, and one listed as missing is asked again (a publication may have moved it). No copy is
 * deleted until the copy kept is verified on the disk the row's state says it belongs on — or, for a JPEG custody refuses
 * to publish, on the private disk (decision 37); one that cannot be read is
 * never touched (ADR-042 decision 5, rule 2; Adam, decisions 2, 2b and 6).
 *
 * ⚠️ IT FAILS ON FINDINGS (Adam, decision 7, 2026-09-25), so a deploy or a cron can run it as a check: read-only, while
 * any finding is still there when the rows are asked again at the end; forced, while any row failed, is missing or was
 * kept — a JPEG custody refused to publish among them (decision 37). A file with no bytes anywhere keeps it failing until its entry is erased or the file restored from a backup; a
 * copy the listing opens, or a forced row's second look, and cannot read keeps both failing until it can be read (Adam,
 * decision 12, 2026-09-26) — not one on a disk nesting with the target, which prune keeps for a hand, nor the lone copy
 * of a file where its row belongs, which only a forced prune reads, and only when it removes a copy at the row's path on
 * a disk only other rows name (T133). A lone copy anywhere else on a disk custody asks — an `absent`, `awaiting
 * publication`, `elsewhere` or `exposed` row's — is a finding already, and fails every --force, whose keeper hashes it;
 * one on a disk custody does not ask leaves its row `missing`, which every --force fails without reading it; a forced
 * prune keeps either or does not list it (ADR-042 decision 5, decision 12's consequences and *What it leaves*).
 *
 * ⚠️ ON SQLITE A FORCED RUN HOLDS THE DATABASE'S WRITE LOCK, row by row, while bytes move, and a save or an upload that
 * reads before it writes fails during each hold. The lever is Adam's (ADR-042, *Measured — decision 5, slice 5b*): the
 * command says so, and presumes nothing.
 */
final class MediaReconcileCommand extends Command
{
    protected $signature = 'kitsune:media-reconcile
        {--force : put each file where its row\'s state says and point the row at it, or keep off the web a JPEG no copy of which matches its checksum, rather than listing}
        {--entry=* : only these entries}';

    protected $description = 'Find media files that are not where their row\'s state says they belong, and put them there, or keep off the web a JPEG no copy of which matches its checksum (ADR-042)';

    /**
     * The labels that are findings; `extra` is listed for `kitsune:media-prune`, and is not one — unless a copy cannot be
     * read, which is `unreadable` (Adam, decision 12, 2026-09-26). A row whose path the disks read as another, or refuse,
     * is `misnamed`, wherever its file is: custody refuses it (review of slice 5c).
     */
    private const FINDINGS = ['misnamed', 'unknown', 'missing', 'exposed', 'awaiting publication', 'elsewhere', 'absent', 'private copy', 'unreadable'];

    /** @var list<string> custody's warnings, collected while a row is forced and printed after it */
    private array $warnings = [];

    /** @var array<string, bool> whether each disk nests with a target, asked once a pair and run, as prune remembers it */
    private array $nests = [];

    /** @var array<string, bool> whether core's private disk is the private disk under another name, asked once a run */
    private array $corePrivateAlias = [];

    /**
     * Whether a forced run is collecting them now, and whether this instance has registered its listener.
     *
     * ⚠️ ONE LISTENER PER INSTANCE. Artisan resolves a command once per process and reuses it, so a listener added on
     * each run piled up — every warning printed once per earlier run — and nothing ever removed one (review of 5b).
     */
    private bool $collecting = false;

    private bool $listening = false;

    /** The outcomes that fail a forced run (Adam, decision 7, 2026-09-25). */
    private const FAILING = ['kept', 'missing', 'failed'];

    public function handle(): int
    {
        // Artisan reuses a command in a process: nothing is remembered from a run before.
        $this->nests = [];
        $this->corePrivateAlias = [];
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
                $this->error(OutputFormatter::escape($refusal));

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
            '%s %s, trashed included: a file belongs on [%s] while its entry is live and it is public, and on [%s] '
            .'otherwise. Asking %s.',
            $force ? 'Reconciling' : 'Listing, read-only,',
            $entries === null ? 'every media row' : 'these entries\' media rows',
            OutputFormatter::escape($public),
            OutputFormatter::escape($private),
            OutputFormatter::escape($this->asking($config)),
        ));

        if ($force && $connection->getDriverName() === 'sqlite') {
            $this->warn(
                'On SQLite each row this run repairs holds the database\'s write lock while its bytes move, and a save or '
                .'upload that reads before it writes fails during each hold: run it when nobody is editing (ADR-042, '
                .'*Measured — decision 5, slice 5b*).'
            );
        }

        if (! $force && $unsafe !== null) {
            $this->warn(OutputFormatter::escape($unsafe).' kitsune:media-reconcile --force refuses until this is fixed.');
        }

        if (! $force && ! $unique) {
            $this->warn('media_files.path is not unique on this database: --force refuses until the migration '
                .'0001_01_01_000010_make_media_file_paths_unique has run.');
        }

        $this->legacy($connection, $config, $force, $entries);

        if ($force && ! $this->listening) {
            $this->listening = true;

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

        $this->collecting = $force;
        $this->warnings = [];

        try {
            $this->reconcile($connection, $config, $entries, $force, $counts, $findings, $total, $bad);
        } finally {
            $this->collecting = false;
            $this->warnings = [];
        }

        $this->summary($counts, $force);

        if ($force) {
            return $bad === 0 ? self::SUCCESS : self::FAILURE;
        }

        $still = $this->recheck($connection, $config, $findings);
        $remaining = array_sum($still);

        if ($findings === []) {
            $this->info('Every media row names the disk its state says, and that disk holds its file.');

            return self::SUCCESS;
        }

        // Not every finding is a disagreement: one may have become readable, or its disk answerable (review of slice 5c).
        if ($remaining === 0) {
            $this->info(sprintf(
                '%d of %d media row%s %s a finding when listed, and none still is: %s settled, or became readable or '
                .'answerable, while this ran. Nothing was changed by this run.',
                count($findings),
                $total,
                $total === 1 ? '' : 's',
                count($findings) === 1 ? 'was' : 'were',
                count($findings) === 1 ? 'it' : 'each',
            ));

            return self::SUCCESS;
        }

        /*
         * Each kind told what settles it (review of slice 5c): a copy the listing found it cannot read, a disk that cannot
         * say whether it holds the file (Adam, decision 9), a file custody would have to read or remove through a
         * read-through disk, a row naming a disk that reaches the one its file belongs on, a file held only on a disk that
         * is, cannot be told apart from, or nests with the one it belongs on, and a row whose path the disks read as
         * another, are not ones --force can put right, so none is sent there. A copy the listing did not open and cannot be read, --force names, and fails on.
         */
        $unreadable = $still['unreadable'] ?? 0;
        $unknown = $still['unknown'] ?? 0;
        $readThrough = $still['read-through'] ?? 0;
        $misnamed = $still['misnamed'] ?? 0;
        $overlapping = $still['overlapping'] ?? 0;
        $coinciding = $still['coinciding'] ?? 0;
        $astray = $remaining - $unreadable - $unknown - $readThrough - $misnamed - $overlapping - $coinciding;

        $this->warn(implode(' ', array_filter([
            $astray === 0 ? null : sprintf(
                '%d of %d media row%s disagree%s with where %s bytes are, and nothing was changed. Re-run with --force: '
                .'each file is copied where its row\'s state says, verified by SHA-256, the row repointed, and the copies '
                .'custody\'s steps remove removed — one that differs named in the log with both hashes, and one that cannot '
                .'be read named, and the run failed on it, its row kept or failed; and a JPEG it would publish, no copy of '
                .'which matches its recorded checksum, kept off the web rather than published, and the run failed on it, as '
                .'the log then says — one whose row names the public disk, which holds the copy kept, left where it is '
                .'(ADR-042 decision 37). Media has no revision history and no undo.',
                $astray,
                $total,
                $total === 1 ? '' : 's',
                $astray === 1 ? 's' : '',
                $astray === 1 ? 'its' : 'their',
            ),
            $unreadable === 0 ? null : sprintf(
                '%d of %d media row%s hold%s a copy that cannot be read, on the disk %s line above names, and nothing was '
                .'changed: make it readable. Until it can be, every --force run leaves it and fails on it (Adam, decision '
                .'12, 2026-09-26).',
                $unreadable,
                $total,
                $total === 1 ? '' : 's',
                $unreadable === 1 ? 's' : '',
                $unreadable === 1 ? 'its' : 'each',
            ),
            $readThrough === 0 ? null : sprintf(
                '%d of %d media row%s file%s held on a read-through disk, which custody neither reads, copies from nor removes '
                .'a copy through, and nothing was changed: copy the file where its row belongs by hand if it is not there, '
                .'checking it against the recorded checksum; then compare the read-through disk\'s copy with it and, where '
                .'custody would remove it — a served copy of a file kept off the web, or one on the disk the row names — take '
                .'it off through the disk each half is. Until then every --force run fails on it (ADR-042 decision 5, open '
                .'for Adam).',
                $readThrough,
                $total,
                $total === 1 ? '\'s' : 's\'',
                $readThrough === 1 ? ' is' : 's are',
            ),
            $coinciding === 0 ? null : sprintf(
                '%d of %d media row%s file%s held only on a disk that cannot be told apart from the one %s belongs on, or whose '
                .'media directory nests with it, and nothing was changed: custody copies no file onto a disk from one that may be '
                .'it or nests with it. Copy the file where its row belongs by hand, checking it against the recorded checksum. '
                .'Until then every --force run refuses %s.',
                $coinciding,
                $total,
                $total === 1 ? '\'s' : 's\'',
                $coinciding === 1 ? ' is' : 's are',
                $coinciding === 1 ? 'it' : 'each',
                $coinciding === 1 ? 'it' : 'them',
            ),
            $overlapping === 0 ? null : sprintf(
                '%d of %d media row%s disk%s reach%s the disk %s file belongs on, or cannot be told apart from it, and nothing '
                .'was changed: custody moves no row off a disk that may hold the very file it would keep, and removes no copy '
                .'there. Copy the file where its row belongs by hand if it is not there, checking it against the recorded '
                .'checksum, then correct media_files.disk to that disk — or point the disk the row names at a place that does '
                .'not overlap it. Until then every --force run refuses %s.',
                $overlapping,
                $total,
                $total === 1 ? '\'s' : 's\'',
                $overlapping === 1 ? '' : 's',
                $overlapping === 1 ? 'es' : '',
                $overlapping === 1 ? 'its' : 'each',
                $overlapping === 1 ? 'it' : 'them',
            ),
            $misnamed === 0 ? null : sprintf(
                '%d of %d media row%s path%s not written as the disks read it, or refused by them, as %s line above says, and '
                .'nothing was changed: correct media_files.path to the path its file is under, as the disks read it, unless '
                .'another row names that path — a file under the row\'s literal name, one no disk reads or removes, moved by hand '
                .'to such a path first, checked against the recorded checksum. Until then every --force run, trash and erasure '
                .'refuses %s.',
                $misnamed,
                $total,
                $total === 1 ? '\'s' : 's\'',
                $misnamed === 1 ? ' is' : 's are',
                $misnamed === 1 ? 'its' : 'each',
                $misnamed === 1 ? 'it' : 'them',
            ),
            $unknown === 0 ? null : sprintf(
                '%d of %d media row%s could not be asked about — a disk could not say whether it holds %s file, as %s line '
                .'above says — and nothing was changed: make the disk reachable, or its configuration whole. Until then every '
                .'--force run refuses %s (Adam, decision 9, 2026-09-26).',
                $unknown,
                $total,
                $total === 1 ? '' : 's',
                $unknown === 1 ? 'its' : 'their',
                $unknown === 1 ? 'its' : 'each',
                $unknown === 1 ? 'it' : 'them',
            ),
            $remaining === count($findings) ? null : sprintf(
                '%d more %s when listed and no longer %s.',
                count($findings) - $remaining,
                count($findings) - $remaining === 1 ? 'was a finding' : 'were findings',
                count($findings) - $remaining === 1 ? 'is' : 'are',
            ),
        ])));

        return self::FAILURE;
    }

    /**
     * List every row, and under `--force` settle each one listed, in chunks.
     *
     * @param  list<int>|null  $entries
     * @param  array<string, array<string, int>>  $counts
     * @param  list<int>  $findings
     */
    private function reconcile(Connection $connection, Repository $config, ?array $entries, bool $force, array &$counts, array &$findings, int &$total, int &$bad): void
    {
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
                    $this->raw($line);

                    continue;
                }

                [$outcome, $bucket] = $this->askAgain($connection, $config, (int) $row->entry_id, ...$this->settle($connection, (int) $row->entry_id));
                $counts[$survey['label']]['listed'] = ($counts[$survey['label']]['listed'] ?? 0) + 1;
                $counts[$survey['label']][$bucket] = ($counts[$survey['label']][$bucket] ?? 0) + 1;
                $bad += in_array($bucket, self::FAILING, true) ? 1 : 0;

                $this->raw($line.'  → '.$outcome);

                foreach ($this->warnings as $warning) {
                    $this->raw('    '.$warning);
                }

                $this->warnings = [];
            }
        }, 'media_files.entry_id', 'entry_id');
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
            /*
             * A whole number, written as PHP reads it back — zero and below among them, which SQLite's and PostgreSQL's
             * signed ids hold (MySQL's and MariaDB's are unsigned: zero at most, never below), and which prune and custody
             * name in the `--entry` they advise (review of slice 5c): one past either end of PHP's integer would become
             * another id.
             */
            $text = (string) $value;
            $written = preg_replace('/\A(-?)0+(?=\d)/', '$1', $text);

            if (preg_match('/\A-?\d+\z/', $text) !== 1 || (string) (int) $text !== $written) {
                $this->error(sprintf('--entry takes an entry id, a whole number: [%s] is not one. Nothing was listed.', OutputFormatter::escape((string) $value)));

                return false;
            }

            $ids[] = (int) $text;
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
     * The disks every row is asked about beside its target and the disk it names: both configured disks, core's private
     * disk and the served disks, the last two wherever they can hold anything — or why that cannot be said.
     */
    private function asking(Repository $config): string
    {
        try {
            $disks = MediaCustody::asked($config, MediaDisks::configured($config, 'public'), MediaDisks::configured($config, 'private'));
        } catch (Throwable $failure) {
            return 'no disk can be listed — '.$failure->getMessage();
        }

        return implode(', ', array_map(static fn (string $disk): string => "[{$disk}]", $disks)).', and the disk each row names';
    }

    /**
     * Say so when rows still name a disk that is neither configured nor core's — ADR-041's `local`, a former public
     * disk — because prune lists orphans on such a disk only while a row names it, and a forced run moves the rows off it.
     *
     * ⚠️ SERVED DISKS TOO — review of slice 5b. Prune scans a served disk whatever names it, but only for extra copies: a
     * file there no row's path names is taken for the host's. So once the last row leaves a former public disk, an
     * orphan an erasure could not remove there stays on the web and is never listed again.
     *
     * Under `--entry`, only when these entries' rows are every row naming the disk: a run over one entry can move the last.
     * A disk no configuration names is left out, because prune cannot sweep it either. A configuration that cannot be
     * read says nothing here: each row reports it as unknown.
     *
     * @param  list<int>|null  $entries
     */
    private function legacy(Connection $connection, Repository $config, bool $force, ?array $entries): void
    {
        try {
            $known = [MediaDisks::configured($config, 'public'), MediaDisks::configured($config, 'private'), MediaDisks::PRIVATE];
        } catch (Throwable) {
            return;
        }

        /*
         * ⚠️ COUNTED IN PHP, BY THE NAME AS WRITTEN — review of slice 5c. MySQL and MariaDB group `disk` under the column's
         * collation, which folds `LEGACY` into `legacy`: the warning went to the spelling no configuration names, or
         * counted another disk's rows, while prune, which compares the names in PHP, stopped sweeping the disk.
         */
        $naming = static function (Builder $rows): array {
            $counts = [];

            $rows->select(['id', 'disk'])->chunkById(500, function (Collection $batch) use (&$counts): void {
                foreach ($batch as $row) {
                    $counts[(string) $row->disk] = ($counts[(string) $row->disk] ?? 0) + 1;
                }
            }, 'id');

            ksort($counts, SORT_STRING);

            return $counts;
        };

        $named = $naming($connection->table('media_files'));
        $moving = $entries === null ? null : $naming($connection->table('media_files')->whereIntegerInRaw('entry_id', $entries));

        foreach ($named as $disk => $rows) {
            // A disk named with digits alone is an integer key.
            $disk = (string) $disk;

            if (in_array($disk, $known, true) || ! is_array($config->get("filesystems.disks.{$disk}"))) {
                continue;
            }

            if ($moving !== null && ($moving[$disk] ?? 0) !== $rows) {
                continue;
            }

            // Another name for a disk prune always sweeps — Laravel's `public`, say, at the public disk's directory — is
            // swept under that name whatever names it (review of slice 5b).
            $swept = $this->sweptAs($config, $disk, $known, array_map('strval', array_keys($named)));

            if ($swept === true) {
                continue;
            }

            // Named as it is, whatever the console would read in it as a style (review of slice 5c).
            $name = OutputFormatter::escape($disk);
            $subject = $moving === null
                ? sprintf('%d row%s [%s]', $rows, $rows === 1 ? ' names' : 's name', $name)
                : sprintf('%s [%s] %s among these entries', $rows === 1 ? 'The one row naming' : "All {$rows} rows naming", $name, $rows === 1 ? 'is' : 'are');
            $them = $rows === 1 ? 'the row' : 'them';

            $this->warn(match (true) {
                is_string($swept) => sprintf(
                    '[%s]\'s media directory nests with [%s]\'s: kitsune:media-prune never lists its orphans while the two '
                    .'nest — it refuses to list, or scans it for extra copies only — so move one of them, or remove its '
                    .'leftovers by hand.',
                    $name,
                    OutputFormatter::escape($swept),
                ),
                $force => sprintf(
                    '%s: this run moves %s off it, after which kitsune:media-prune no longer sweeps it for orphans — stop '
                    .'now and run kitsune:media-prune --force first if it may hold any.',
                    $subject,
                    $them,
                ),
                $moving === null => sprintf(
                    '%s, which kitsune:media-prune sweeps for orphans only while a row names it: run kitsune:media-prune '
                    .'--force before kitsune:media-reconcile --force moves %s off it.',
                    $subject,
                    $them,
                ),
                default => sprintf(
                    '%s: kitsune:media-prune sweeps [%s] for orphans only while a row names it, so run kitsune:media-prune '
                    .'--force before kitsune:media-reconcile --force moves %s off it.',
                    $subject,
                    $name,
                    $them,
                ),
            });
        }
    }

    /**
     * Whether prune sweeps a disk through one of these: true when it is one of them under another name, or cannot be told
     * apart from one; the other's name when their media directories nest, and prune never lists its orphans; false when
     * it sweeps it only while a row names it. Asked only of disks that can hold anything, since asking builds a local
     * disk; a disk that cannot be asked is none of them.
     *
     * @param  list<string>  $disks
     * @param  list<string>  $rowNamed  the disks rows name, which prune lists orphans on too
     */
    private function sweptAs(Repository $config, string $disk, array $disks, array $rowNamed): bool|string
    {
        try {
            if (! MediaDisks::mayHold($config, $disk)) {
                return false;
            }

            foreach ($disks as $other) {
                // One pair that cannot be compared — a disk that cannot be built — leaves out only that pair, and the rest
                // still decide (review of slice 5c).
                try {
                    if (! is_array($config->get("filesystems.disks.{$other}")) || ! MediaDisks::mayHold($config, $other)) {
                        continue;
                    }

                    // Nested: another directory, whose orphans prune never lists while the two nest (review of 5b).
                    if (MediaDisks::nested($config, $disk, $other)) {
                        return $other;
                    }

                    // One directory, or one prune cannot tell apart, and so never scans.
                    if (MediaDisks::onePlace($config, $disk, $other) !== false) {
                        return true;
                    }
                } catch (Throwable) {
                    continue;
                }
            }

            /*
             * And any configured disk inside it, or another disk a row names that it lies inside: prune refuses to list
             * while either nests (review of 5b). As prune asks: a disk it scans — served, or named by a row — is built,
             * so a read-through disk a driver of the host's own builds is compared by its halves, and read from its
             * configuration alone where building it throws; any other, a host's nothing uses among them, is read from its
             * configuration alone (review of slice 5c). One disk that cannot be asked leaves out only itself.
             */
            $scanned = [...MediaDisks::servedDisks($config), ...$rowNamed];

            foreach (array_keys((array) $config->get('filesystems.disks', [])) as $other) {
                $other = (string) $other;

                if ($other === $disk || ! is_array($config->get("filesystems.disks.{$other}"))) {
                    continue;
                }

                foreach (in_array($other, $scanned, true) ? [true, false] : [false] as $build) {
                    try {
                        if (MediaDisks::mayHold($config, $other) && MediaDisks::within($config, $other, $disk, build: $build)) {
                            return $other;
                        }

                        break;
                    } catch (Throwable) {
                        continue;
                    }
                }

                // A disk a row names that it lies inside — it too is built, as prune scans it, and read from its
                // configuration alone where building it throws (review of slice 5c).
                if (in_array($other, $rowNamed, true)) {
                    foreach ([true, false] as $build) {
                        try {
                            if (MediaDisks::mayHold($config, $other) && MediaDisks::within($config, $disk, $other, build: $build)) {
                                return $other;
                            }

                            break;
                        } catch (Throwable) {
                            continue;
                        }
                    }
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    /**
     * Where a row's file belongs and which disks hold its path, from the unlocked row and presence alone — and, for a file
     * where it belongs and held on more than one disk, whether each copy can be read.
     *
     * ⚠️ A DISK WHOSE ROOT DOES NOT EXIST IS NOT ASKED: it holds nothing, and building it would create it — but for a
     * `read-through` disk's half, which asking the read-through disk builds: a local half whose root does not exist is
     * created — but one whose root cannot be created is not built, and makes the row `unknown` (`refuseBlockedHalf()`;
     * review of slice 5c, twice). A local disk custody asks whatever it holds — the target, the disk the row names, a
     * configured one — with no root, or a root that cannot be created, makes the row `unknown`: every --force fails to
     * build it. A failure to tell whether a disk holds the path is `unknown`, never absent — every disk the keeper asks
     * is asked for a row --force would settle, but, for a row held on another disk, a read-through disk skipped as the
     * public disk whose building would create a local half's root (review of 5c).
     *
     * ⚠️ A COPY THAT CANNOT BE READ FAILS THE CHECK (Adam, decision 12, 2026-09-26). A file held twice is `extra`,
     * which prune removes under the lock after reading every copy, where a disk's listing shows it; one of them that
     * cannot be read fails that removal every time, so the check that says `extra` must not pass. Each copy is opened
     * and its first byte read — nothing is hashed, though on an FTP or SFTP disk, or an S3 disk without `stream_reads`,
     * opening downloads the whole copy — the target's and whatever else the row holds, since every forced run's keeper
     * reads it first; but not one on a disk whose media directory nests with the target's, nor one on a `read-through`
     * disk, where no step removes a copy and so none reads it to remove it (review of slice 5c). Beside a copy prune
     * keeps for a hand, only a forced prune passes on an unreadable target's copy. Every other label is a finding
     * already; of those, only two are opened, both states every --force fails on while the copy cannot be read: an
     * `elsewhere` row whose file is where it belongs and on the disk the row names (decision 6's among them, and any
     * other row in that state; one naming core's private disk is repointed by --force, then kept), and an `awaiting
     * publication` row whose named disk, neither private one, holds the file while the configured private disk holds it
     * too (decision 6's row once its entry is restored among them) — so the closing line does not send a copy --force
     * cannot read back to --force.
     *
     * @return array{label: ?string, target: string, held: list<string>, failure: ?string, unreadable: list<string>}
     */
    private function survey(Repository $config, stdClass $row): array
    {
        $public = MediaDisks::configured($config, 'public');
        $private = MediaDisks::configured($config, 'private');
        $target = MediaCustody::target($row, $row);
        $named = (string) $row->disk;
        $path = (string) $row->path;
        $held = [];

        /*
         * ⚠️ BEFORE ANY DISK IS ASKED — review of slice 5c. A row path written past `MediaFile` in a form the disks read as
         * another, or refuse, is asked about as the path they read: the check read such a row as settled, or as a finding
         * --force would put right, while every --force, trash and erasure refused it. It is `misnamed`, a finding, wherever
         * its file is, and its row is what a person corrects.
         */
        try {
            MediaBytes::refuseMisnamed($target, $path);
        } catch (MediaCustodyFailure $misnamed) {
            return ['label' => 'misnamed', 'target' => $target, 'held' => [], 'failure' => $misnamed->getMessage(), 'unreadable' => []];
        }

        $skipped = [];

        try {
            $served = MediaDisks::servedDisks($config);

            foreach (MediaCustody::asked($config, $target, $named) as $disk) {
                if (! MediaDisks::mayHold($config, $disk)) {
                    // Custody asks the target, the disk the row names and both configured disks whatever they hold, and
                    // builds each: a local one with no root, or one whose root cannot be created — a file where it
                    // should be, a dangling link, a missing root whose nearest existing directory it may not write into
                    // or search — cannot be built, so every --force refuses the row as unknown (Adam, decision 9), and
                    // so does this, rather than send it there. One whose root exists, and whose prefix below it does
                    // not, is built, and holds nothing (review of slice 5c, three times).
                    $resolved = MediaDisks::resolved($config, $disk);

                    if ($resolved['driver'] === 'local' && ($resolved['root'] === null || self::rootCannotBeMade($config, $disk))) {
                        throw new RuntimeException(sprintf('the [%s] disk is local and its root is missing or cannot be created, so it cannot be built, and whether it holds the file cannot be told', $disk));
                    }

                    // The target or the disk the row names, which custody asks whatever it holds (review of slice 5c).
                    if (in_array($disk, [$target, $named], true) && MediaDisks::cycles($config, $disk)) {
                        throw new RuntimeException(sprintf('the [%s] disk is a read-through one whose halves name each other, so it cannot be built, and whether it holds the file cannot be told', $disk));
                    }

                    continue;
                }

                // Under a public target, P under another name, or a disk that cannot be told from it, is P itself; one
                // nested in it or around it is another directory, holding another file at the path (review of 5b).
                if ($target === $public && $disk !== $public && MediaDisks::onePlace($config, $public, $disk) !== false
                    && ! MediaDisks::nested($config, $public, $disk)) {
                    $skipped[] = $disk;

                    continue;
                }

                // Under a private target, core's private disk where it is that disk under another name is the private disk
                // itself, as custody's settle takes it: counted again, every private file read as `extra`. Not where the row
                // names it: it is `elsewhere`, its copy opened, and repointed by --force (review of slice 5c). Asked once a
                // run: the pair is fixed, and asking resolves both directories, some 40-120 µs a row on the laptop.
                if ($target === $private && $disk === MediaDisks::PRIVATE && $disk !== $named && $private !== MediaDisks::PRIVATE
                    && ($this->corePrivateAlias[$private] ??= MediaDisks::onePlace($config, $private, $disk) === true
                        && ! MediaDisks::nested($config, $private, $disk))) {
                    continue;
                }

                // A read-through disk a half of which cannot be built is not built either (review of slice 5c).
                $this->refuseBlockedHalf($config, $disk);

                if (MediaBytes::present($disk, $path)) {
                    $held[] = $disk;
                }
            }

            /*
             * Held nowhere else, each disk skipped above as the public disk under another name is asked, as every --force's
             * keeper asks it: one that holds the file holds it — never `missing` — and one that cannot say whether it does
             * makes the row `unknown` (Adam, decision 9), never sent to --force (review of slice 5c, twice).
             */
            $askedSkipped = $held === [];

            if ($askedSkipped) {
                foreach ($skipped as $disk) {
                    $this->refuseBlockedHalf($config, $disk);

                    if (MediaBytes::present($disk, $path)) {
                        $held[] = $disk;
                    }
                }
            }
        } catch (Throwable $failure) {
            return ['label' => 'unknown', 'target' => $target, 'held' => $held, 'failure' => $failure->getMessage(), 'unreadable' => []];
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

        /*
         * ...and for every row --force would settle, whatever else holds the file: its keeper asks each skipped disk
         * whether it holds the path before it hashes anything, so one that cannot say makes the row `unknown` here too —
         * the check passed it, or sent it to a --force that failed on that disk every run (Adam, decision 9; review of slice
         * 5c, three times). A read-through disk whose building would create a local half's root, at any depth, is not asked
         * here: that half holds nothing, and asking only to hear whether the disk can answer would create it (T87) — held
         * nowhere else, it is asked above, and building it creates that root, as the listing records. One a half of which
         * cannot be created is not built, and makes the row `unknown`: building it fails, as every --force's does (review
         * of slice 5c). A settled row, which --force leaves alone, asks nothing more.
         */
        if ($label !== null && ! $askedSkipped) {
            try {
                foreach ($skipped as $disk) {
                    $this->refuseBlockedHalf($config, $disk);

                    if (! $this->makesAHalfRoot($config, $disk)) {
                        MediaBytes::present($disk, $path);
                    }
                }
            } catch (Throwable $failure) {
                return ['label' => 'unknown', 'target' => $target, 'held' => $held, 'failure' => $failure->getMessage(), 'unreadable' => []];
            }
        }

        $opened = match (true) {
            $label === 'extra' => $held,
            // Decision 6 leaves a copy it set aside where a row points: the file where it belongs, and the row still naming
            // the disk that cannot read it — which every --force refuses until it can (review of slice 5c).
            $label === 'elsewhere' && in_array($target, $held, true) && in_array($named, $held, true) => [$named],
            // ...and so is the row it becomes once its entry is restored, the configured private disk holding the file:
            // for a public target no copy is set aside, so every --force refuses it too (review of slice 5c).
            $label === 'awaiting publication' && in_array($named, $held, true) && ! in_array($named, [$private, MediaDisks::PRIVATE], true)
                && in_array($private, $held, true) => [$named],
            default => [],
        };

        $unreadable = array_values(array_filter($opened, fn (string $disk): bool => ! $this->nestsWithTarget($config, $disk, $target)
            && ! $this->readsThrough($disk) && ! MediaBytes::readable($disk, $path)));

        return ['label' => $label === 'extra' && $unreadable !== [] ? 'unreadable' : $label, 'target' => $target, 'held' => $held, 'failure' => null, 'unreadable' => $unreadable];
    }

    /**
     * Whether a disk is a `read-through` one — custody removes no copy through it (`MediaBytes::delete()`), so prune keeps
     * a copy there for a hand and no step reads one to remove it; opened, a copy only its fallback holds would be copied
     * into its primary (review of slice 5c). Asked of a disk that holds the path, and so is built already.
     */
    private function readsThrough(string $disk): bool
    {
        try {
            return Storage::disk($disk) instanceof ReadThroughFilesystem;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether a local disk's own root — not its root plus a prefix, which Laravel does not create — cannot be created, as
     * Flysystem creates it when the disk is built (`cannotMake()`). Asked of the configuration and the file system;
     * nothing is created.
     */
    private static function rootCannotBeMade(Repository $config, string $disk): bool
    {
        [$entry] = MediaDisks::unscoped($config, $config->get("filesystems.disks.{$disk}"), $disk);

        return self::cannotMake(is_array($entry) && is_string($entry['root'] ?? null) ? $entry['root'] : '');
    }

    /**
     * Whether building a local disk rooted here fails to create its root: none configured; a file or a link where a
     * directory must be; or, for a root that does not exist, a nearest existing directory it may not write into or
     * search. A root that exists is not created — Flysystem's `ensureDirectoryExists()` returns at `is_dir()` — so the build
     * cannot fail on it, whatever its mode, and whatever is missing below it, under a prefix Laravel does not create
     * (review of slice 5c, twice).
     */
    private static function cannotMake(string $root): bool
    {
        if ($root === '') {
            return true;
        }

        $path = rtrim($root, '/') === '' ? '/' : rtrim($root, '/');

        if (is_dir($path)) {
            return false;
        }

        while (! is_dir($path)) {
            // A regular file, a link to one, or a dangling link — which file_exists() does not see — where a directory must be.
            if (file_exists($path) || is_link($path)) {
                return true;
            }

            $parent = dirname($path);

            if ($parent === $path) {
                return true;
            }

            $path = $parent;
        }

        return ! is_writable($path) || ! is_executable($path);
    }

    /**
     * Whether building a `read-through` disk configured as one would create a local half's root that does not exist, at
     * any depth — not one whose root cannot be created, which the build fails on (`refuseBlockedHalf()`). A walk that
     * cannot finish answers no, and the disk is asked, as before.
     */
    private function makesAHalfRoot(Repository $config, string $disk): bool
    {
        return $this->halfRoot($config, $disk) === 'missing';
    }

    /**
     * Refuse to ask a `read-through` disk with a local half whose root cannot be created: building it fails — as every
     * --force's does — after creating any other half's missing root, so it is never built, and the row is `unknown`
     * (review of slice 5c).
     */
    private function refuseBlockedHalf(Repository $config, string $disk): void
    {
        if ($this->halfRoot($config, $disk) === 'blocked') {
            throw new RuntimeException(sprintf('the [%s] disk reads through to a local disk whose root cannot be created, so it cannot be built, and whether it holds the file cannot be told', $disk));
        }
    }

    /**
     * What building a `read-through` disk would meet in a local half's root, at any depth: 'blocked' where one cannot be
     * created, 'missing' where one does not exist and would be created, and null where there is none. Laravel builds each
     * half as the disk is built — a named one by its name, one written inline from its entry, a scoped one's base through
     * its layers — and a local one creates its own root, never its root plus a prefix (review of slice 5c, three times).
     * A blocked half wins over a missing one, which building would create before it failed. A walk that cannot finish
     * answers null.
     *
     * @return 'blocked'|'missing'|null
     */
    private function halfRoot(Repository $config, string $disk): ?string
    {
        try {
            return MediaDisks::readsThrough($config, $disk) ? $this->missingRoot($config, $config->get("filesystems.disks.{$disk}"), $disk, 0) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return 'blocked'|'missing'|null */
    private function missingRoot(Repository $config, mixed $entry, string $disk, int $depth): ?string
    {
        [$base] = MediaDisks::unscoped($config, $entry, $disk);

        if (! is_array($base) || $depth >= 8) {
            return null;
        }

        if (($base['driver'] ?? null) === 'read-through') {
            $found = null;

            foreach ([$base['primary'] ?? null, $base['fallback'] ?? null] as $half) {
                $root = $this->missingRoot($config, is_string($half) ? $config->get("filesystems.disks.{$half}") : $half, $disk, $depth + 1);

                if ($root === 'blocked') {
                    return 'blocked';
                }

                $found ??= $root;
            }

            return $found;
        }

        if ($depth === 0 || ($base['driver'] ?? null) !== 'local' || ! is_string($base['root'] ?? null) || $base['root'] === '' || is_dir($base['root'])) {
            return null;
        }

        return self::cannotMake($base['root']) ? 'blocked' : 'missing';
    }

    /**
     * Whether a disk is, cannot be told apart from, or nests with the public disk — settle copies onto it from none of
     * them (`refuseCoincidingMediaDisks()`, where a nest counts; review of slice 5c, twice).
     */
    private function coincidesWithPublic(Repository $config, string $public, string $disk): bool
    {
        try {
            return $disk !== $public && MediaDisks::onePlace($config, $public, $disk) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether a disk's media directory nests with the target's — prune keeps a copy there for a hand, and custody takes
     * the two for one place. A disk that cannot be asked does not nest, and is read.
     *
     * Remembered for each pair: asked afresh before every open, it cost a row held twice about as much as its two opens
     * (review of slice 5c).
     */
    private function nestsWithTarget(Repository $config, string $disk, string $target): bool
    {
        if ($disk === $target) {
            return false;
        }

        return $this->nests[$disk."\0".$target] ??= (static function () use ($config, $disk, $target): bool {
            try {
                return MediaDisks::mayHold($config, $disk) && MediaDisks::mayHold($config, $target) && MediaDisks::nested($config, $disk, $target);
            } catch (Throwable) {
                return false;
            }
        })();
    }

    /**
     * A line naming a path or what custody said, written as it is: a name may hold what the console reads as a style,
     * which it would strip, and the line would name another file (review of slice 5c).
     */
    private function raw(string $line): void
    {
        $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
    }

    /** @param array{label: ?string, target: string, held: list<string>, failure: ?string, unreadable: list<string>} $survey */
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
                $survey['label'] === 'misnamed' => 'not asked — '.$survey['failure'],
                $survey['failure'] !== null => 'held by: cannot be told — '.$survey['failure'],
                $survey['held'] === [] => 'held by no disk custody asks',
                $survey['unreadable'] !== [] => sprintf('held by %s — [%s] cannot be read', implode(', ', $survey['held']), implode('], [', $survey['unreadable'])),
                default => 'held by '.implode(', ', $survey['held']),
            },
        );
    }

    /**
     * Settle one row, then clean up after it — a compensation's steps, each under the lock and each deciding from the row
     * as it is there.
     *
     * @return array{string, string} what happened, and its outcome: settled, unchanged, gone, kept, missing or failed
     */
    private function settle(Connection $connection, int $entryId): array
    {
        try {
            $settled = MediaCustody::settle($connection, $entryId);

            if ($settled === MediaCustody::GONE) {
                return ['gone since the listing', 'gone'];
            }

            if ($settled === MediaCustody::MISSING) {
                return [
                    'missing: no disk custody asks holds its file — kitsune:media-prune lists a copy at its path on a disk '
                    .'another row names; otherwise restore it from a backup, or erase the entry',
                    'missing',
                ];
            }

            // A JPEG custody refused to publish (decision 37): its row names the private disk now, with nothing to clean up.
            if ($settled === MediaCustody::REFUSED) {
                return [
                    'kept off the web: a JPEG no copy of which matches its recorded checksum is not published, as the log '
                    .'below says (ADR-042 decision 37)',
                    'kept',
                ];
            }

            $cleaned = MediaCustody::cleanUp($connection, $entryId);

            if ($settled === MediaCustody::SET_ASIDE) {
                return [$this->setAside($connection, $entryId), 'kept'];
            }
        } catch (Throwable $failure) {
            return ['failed: '.$failure->getMessage(), 'failed'];
        }

        if ($cleaned === MediaCustody::UNSETTLED) {
            return ['kept: a copy was left where it is, as the log below says', 'kept'];
        }

        if ($settled === MediaCustody::SETTLED || $cleaned === MediaCustody::SETTLED) {
            return ['settled', 'settled'];
        }

        return ['nothing to do under the lock', 'unchanged'];
    }

    /**
     * A forced row that settled, or needed nothing, asked again as it now is: while a copy of its file cannot be read it
     * was kept, and fails the run (Adam, decisions 7 and 12, 2026-09-26).
     *
     * ⚠️ WHATEVER IT WAS LISTED AS — review of slice 5c. Settle stops reading once the target's copy matches, and the
     * cleanup reads only the private disks, so a row listed `private copy` or `exposed` can settle past a copy on another
     * disk that nothing read; read-only would then fail on it while `--force` passed. Asked of the row read again, since
     * settle may have repointed it; a second look a disk cannot answer fails the row, as one the database cannot does. It
     * costs a read of the row, a presence check on each disk custody asks, and the listing's opens where they apply.
     *
     * @return array{string, string}
     */
    private function askAgain(Connection $connection, Repository $config, int $entryId, string $outcome, string $bucket): array
    {
        if (! in_array($bucket, ['settled', 'unchanged'], true)) {
            return [$outcome, $bucket];
        }

        $told = null;
        $survey = null;

        try {
            $row = $this->rows($connection, [$entryId])->first();
            $survey = $row === null ? null : $this->survey($config, $row);
        } catch (Throwable $failure) {
            $told = $failure->getMessage();
        }

        $told ??= $survey !== null && $survey['failure'] !== null ? $survey['failure'] : null;

        if ($told !== null) {
            return [($bucket === 'settled' ? 'settled, then ' : '').'failed: asked again, it could not be told — '.$told, 'failed'];
        }

        if ($survey === null || $survey['label'] !== 'unreadable') {
            return [$outcome, $bucket];
        }

        return [
            sprintf(
                '%skept: [%s] cannot be read, and every --force run leaves it and fails until it can be read — make it readable',
                $bucket === 'settled' ? 'settled, then ' : '',
                implode('], [', $survey['unreadable']),
            ),
            'kept',
        ];
    }

    /**
     * Which disks' copies were set aside, from custody's own warnings for this row, and whether the row still names one
     * (Adam, decision 6, 2026-09-25).
     */
    private function setAside(Connection $connection, int $entryId): string
    {
        $disks = [];

        // The disk from custody's own words, whatever the path holds: a greedy path runs to the last `] on [` the fixed
        // phrase follows, so a `]` in it — which ended the name a narrower pattern took — does not stop it, and neither does
        // the row's being gone since (review of slice 5c).
        foreach ($this->warnings as $warning) {
            if (preg_match('/^Media custody, entry '.$entryId.': the copy of \[.*\] on \[([^\]]+)\] exists and cannot be read, so it was left/s', $warning, $match) === 1) {
                $disks[] = $match[1];
            }
        }

        $named = $connection->table('media_files')->where('entry_id', $entryId)->value('disk');
        $stayed = is_string($named) && in_array($named, $disks, true);

        return sprintf(
            'set aside: [%s] cannot be read, left untouched, and the file taken off the web (Adam, decision 6, 2026-09-25)%s',
            implode('], [', $disks),
            $stayed ? sprintf('; the row still names [%s]', $named) : '',
        );
    }

    /** @param array<string, array<string, int>> $counts */
    private function summary(array $counts, bool $force): void
    {
        if ($counts === []) {
            return;
        }

        $order = [...self::FINDINGS, 'extra'];
        uksort($counts, static fn (string $a, string $b): int => array_search($a, $order, true) <=> array_search($b, $order, true));

        $outcomes = ['settled', 'unchanged', 'gone', 'kept', 'missing', 'failed'];

        $this->table(
            $force ? ['Label', 'Rows', 'Settled', 'Nothing to do', 'Gone', 'Kept', 'Missing', 'Failed'] : ['Label', 'Rows'],
            array_map(static fn (string $label, array $count): array => $force
                ? [$label, $count['listed'] ?? 0, ...array_map(static fn (string $outcome): int => $count[$outcome] ?? 0, $outcomes)]
                : [$label, $count['listed'] ?? 0], array_keys($counts), $counts),
        );
    }

    /**
     * How many of the findings are still there, by label, asked again of each row: a restore's publication in flight while
     * the listing ran is not a problem, and a check run by a deploy should not fail on one.
     *
     * ⚠️ IN SLICES, THE IDS WRITTEN INTO THE STATEMENT: `whereIntegerInRaw` binds nothing, so no engine's limit on bound
     * parameters is reached however many findings there are.
     *
     * @param  list<int>  $findings
     * @return array<string, int>
     */
    private function recheck(Connection $connection, Repository $config, array $findings): array
    {
        $remaining = [];

        try {
            $public = MediaDisks::configured($config, 'public');
            $private = MediaDisks::configured($config, 'private');
            $served = MediaDisks::servedDisks($config);
        } catch (Throwable) {
            [$public, $private, $served] = [null, null, []];
        }

        foreach (array_chunk($findings, 500) as $slice) {
            foreach ($this->rows($connection, $slice)->get() as $row) {
                $survey = $this->survey($config, $row);

                if (in_array($survey['label'], self::FINDINGS, true)) {
                    /*
                     * What settles it, rather than what it is: a copy that cannot be read, whatever the label; and a row
                     * whose settling needs a read-through disk custody neither reads, copies from nor removes a copy
                     * through (review of slice 5c) — its file held only on such disks; off the web, still on a served one,
                     * which custody's sweep removes; or on the disk its row names, which the move-off removes. A row that
                     * could not be asked is `unknown`, whatever a disk asked before the one that failed answered.
                     */
                    $target = $survey['target'];
                    $named = (string) $row->disk;
                    $readThrough = array_values(array_filter($survey['held'], fn (string $disk): bool => $this->readsThrough($disk)));
                    $kind = match (true) {
                        $survey['label'] === 'misnamed' => 'misnamed',
                        $survey['failure'] !== null => 'unknown',
                        // Only while a disk custody asks holds the file does settle reach the move-off: one held nowhere is
                        // missing, and --force says so. The disk the row names is asked too, which the survey skips where
                        // it cannot be told from the public disk (review of slice 5c).
                        MediaCustody::overlapsTarget($config, $target, $named, (string) $row->path) && $survey['held'] !== [] => 'overlapping',
                        $survey['unreadable'] !== [] => 'unreadable',
                        $survey['held'] !== [] && ! in_array($target, $survey['held'], true) && $readThrough === $survey['held'] => 'read-through',
                        $target !== $public && array_diff(array_intersect($readThrough, $served), [$target]) !== [] => 'read-through',
                        $named !== $target && in_array($named, $readThrough, true) && ! in_array($named, [$private, MediaDisks::PRIVATE], true) => 'read-through',
                        // Held only on disks that are, cannot be told apart from, or nest with the public disk: settle
                        // copies from none of them onto it, since the copy could be the file itself (review of slice 5c).
                        $target === $public && $survey['held'] !== [] && ! in_array($target, $survey['held'], true)
                            && array_filter($survey['held'], fn (string $disk): bool => ! $this->coincidesWithPublic($config, $public, $disk)) === [] => 'coinciding',
                        default => (string) $survey['label'],
                    };
                    $remaining[$kind] = ($remaining[$kind] ?? 0) + 1;
                }
            }
        }

        return $remaining;
    }
}
