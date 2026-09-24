<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Console;

use Illuminate\Console\Command;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaStaging;
use RuntimeException;

/**
 * Remove staged uploads nobody submitted — ADR-042 decision 4.
 *
 * ⚠️ BY AGE, WHICH IS WHY THIS IS NOT `kitsune:media-prune`. A staged file never has a row, so that command's rule —
 * it asks the database, never the filename — cannot apply to it, and an age heuristic folded into it would break the
 * rule it states. This one has no database to ask: everything on the intake disk is a staged upload by definition.
 *
 * ⚠️ NO SCHEDULER IS NEEDED TO BOUND THE DIRECTORY. The same sweep runs after every accepted upload
 * (`GuardUploadStaging`) and, by lottery, after any request's response (`HoldMediaStaging`), so this exists for an
 * operator — and core registers it with Laravel's scheduler for an installation that runs one.
 *
 * ⚠️ IT REFUSES A DISK REDEFINED OR OVERLAPPED AFTER BOOT (Codex, #152), which `MediaStaging::sweep()` checks before
 * it lists anything: a command never passes the request middleware that checks again.
 *
 * ⚠️ READ-ONLY WITHOUT `--force`, as `kitsune:media-prune` is: it deletes files.
 */
final class MediaIntakeSweepCommand extends Command
{
    protected $signature = 'kitsune:media-intake-sweep {--force : actually delete the stale staged files rather than listing them}';

    protected $description = 'Remove staged uploads older than 24 hours from the intake disk (ADR-042)';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        try {
            $swept = MediaStaging::sweep($force);
        } catch (RuntimeException $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        }
        $stale = count($swept['stale']);

        if ($stale === 0) {
            $this->info('No stale staged uploads. Everything on the intake disk is younger than 24 hours.');

            return self::SUCCESS;
        }

        $this->table(['Disk', 'Path'], array_map(static fn (string $path): array => [MediaDisks::INTAKE, $path], $swept['stale']));

        if (! $force) {
            $this->warn(sprintf(
                '%d stale staged file%s listed and nothing removed. Re-run with --force to delete them.',
                $stale,
                $stale === 1 ? '' : 's',
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf('Removed %d of %d stale staged file%s.', $swept['removed'], $stale, $stale === 1 ? '' : 's'));

        /* A disk that refused is reported by the count disagreeing, rather than by silence. */
        return $swept['removed'] === $stale ? self::SUCCESS : self::FAILURE;
    }
}
