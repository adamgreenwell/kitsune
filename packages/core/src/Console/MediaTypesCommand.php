<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Find entry types whose `is_media` disagrees with their entries, and repair one — ADR-042 decision 1.
 *
 * ⚠️ THE ONE DOOR PAST THE LOCK, BESIDE THE MIGRATION THAT SET THE FLAG. `is_media` is fixed at creation, and the
 * migration that added it classified every existing type from what `media_files` held at that moment. Codex found
 * the moment is not an instant on #150: `deploy/release.sh` migrates while the previous release still serves, so an
 * editor can create a file-less entry on a type the migration has just marked, or an import can store a file on
 * one it left unmarked, before the new release goes live. With the flag locked, that state had no way back.
 *
 * ⚠️ READ-ONLY WITHOUT `--force`, AND IT FAILS WHEN IT FINDS SOMETHING, so a deploy can run it after activation as a
 * check and stop on the answer. Repair takes one type by handle or id, never all of them: each is a decision about
 * that type's entries, and the command reports rather than guesses when the answer is not clear.
 *
 * ⚠️ THE MIGRATION'S RULE, NOT A LOOSER ONE. A type whose every entry carries a file becomes a media type; one whose
 * entries carry none stops being one; one holding both is refused, because either value strands the other half —
 * which is the reason the flag is locked at all. Trashed entries count, since a restore brings them back. The rule is
 * written here again rather than shared with the migration, because a migration is frozen at the release that
 * shipped it and this command is not.
 *
 * ⚠️ UNDER THE LOCKS THE OTHER WRITERS TAKE. The type's row is locked for update and its entries with it, then
 * counted, then written, in one transaction. `MediaLibrary::store()` reads the flag under a shared lock inside its
 * own transaction and the retype boundary reads the destination's the same way, so neither can act on the value
 * this is about to change. What no lock covers is the create page, which still makes a file-less entry of a media
 * type until ADR-042 decision 3 closes it; this command is how that state is found.
 */
final class MediaTypesCommand extends Command
{
    protected $signature = 'kitsune:media-types
        {type? : the handle or id of the one type to repair — required with --force}
        {--force : set that type\'s flag to agree with its entries, rather than reporting}';

    protected $description = 'Find entry types whose is_media flag disagrees with their entries, and repair one (ADR-042)';

    public function handle(): int
    {
        if ($this->option('force')) {
            return $this->repair();
        }

        $disagreements = $this->disagreements();

        if ($disagreements === []) {
            $this->info('Every entry type agrees with its entries: media types hold only entries with files, and no other type holds a file.');

            return self::SUCCESS;
        }

        $this->table(
            ['Id', 'Handle', 'Org', 'is_media', 'With a file', 'Without', 'Problem'],
            array_map(static fn (array $row): array => [
                $row['id'], $row['handle'], $row['org'] ?? 'global', $row['is_media'] ? 'yes' : 'no',
                $row['with'], $row['without'], $row['problem'],
            ], $disagreements),
        );

        $this->warn(sprintf(
            '%d entry type%s disagree%s with %s entries. Nothing was changed. Repair one with '
            .'`php artisan kitsune:media-types <handle-or-id> --force`; a type holding entries with files and '
            .'entries without cannot be repaired until the entries that do not belong are force-deleted.',
            count($disagreements),
            count($disagreements) === 1 ? '' : 's',
            count($disagreements) === 1 ? 's' : '',
            count($disagreements) === 1 ? 'its' : 'their',
        ));

        return self::FAILURE;
    }

    /**
     * Every type whose flag disagrees with its entries, trashed entries included.
     *
     * @return list<array{id: int, handle: string, org: int|null, is_media: bool, with: int, without: int, problem: string}>
     */
    private function disagreements(): array
    {
        $found = [];

        foreach (DB::table('entry_types')->orderBy('id')->get(['id', 'handle', 'org_id', 'is_media']) as $type) {
            [$with, $without] = self::counts((int) $type->id);
            $isMedia = (bool) $type->is_media;

            $problem = match (true) {
                $isMedia && $without > 0 => 'a media type with entries that carry no file',
                ! $isMedia && $with > 0 => 'not a media type, and entries carry files',
                default => null,
            };

            if ($problem !== null) {
                $found[] = [
                    'id' => (int) $type->id,
                    'handle' => (string) $type->handle,
                    'org' => $type->org_id === null ? null : (int) $type->org_id,
                    'is_media' => $isMedia,
                    'with' => $with,
                    'without' => $without,
                    'problem' => $problem,
                ];
            }
        }

        return $found;
    }

    private function repair(): int
    {
        $given = $this->argument('type');

        if (! is_string($given) || $given === '') {
            $this->error('--force repairs one type: name it by handle or id. Run without --force to see which disagree.');

            return self::FAILURE;
        }

        $matches = DB::table('entry_types')
            ->where(fn (Builder $query) => ctype_digit($given)
                ? $query->where('id', (int) $given)->orWhere('handle', $given)
                : $query->where('handle', $given))
            ->pluck('id');

        if ($matches->count() !== 1) {
            $this->error($matches->isEmpty()
                ? "No entry type is [{$given}]."
                : "[{$given}] names {$matches->count()} entry types — orgs may share a handle. Repair it by id.");

            return self::FAILURE;
        }

        $id = (int) $matches->first();

        try {
            $outcome = DB::transaction(static function () use ($id): string {
                $type = DB::table('entry_types')->where('id', $id)->lockForUpdate()->first(['handle', 'is_media']);

                DB::table('entries')->where('entry_type_id', $id)->lockForUpdate()->pluck('id');

                [$with, $without] = self::counts($id);

                if ($with > 0 && $without > 0) {
                    throw new RuntimeException(sprintf(
                        'Refusing to repair [%s]: %d of its entries carry a file and %d do not, so it is neither a '
                        .'media type nor an ordinary one (ADR-042). Soft-deleted entries count. Force-delete the '
                        .'entries that do not belong and run this again — moving them is refused, because no entry '
                        .'crosses the media boundary. Nothing was changed.',
                        $type->handle, $with, $without,
                    ));
                }

                /* An empty type agrees with either value, and a fresh media type is empty: nothing to decide. */
                if ($with === 0 && $without === 0) {
                    return sprintf('[%s] has no entries, so its flag agrees with them either way; nothing was changed.', $type->handle);
                }

                $agrees = $with > 0;

                if ((bool) $type->is_media === $agrees) {
                    return sprintf('[%s] already agrees with its entries; nothing was changed.', $type->handle);
                }

                /*
                 * `DB::table()`, below the model, deliberately: `is_media` is fixed at creation, so no model write
                 * may change it, and this is the sanctioned exception the migration also is.
                 */
                DB::table('entry_types')->where('id', $id)->update(['is_media' => $agrees]);

                return sprintf(
                    '[%s] %s a media type now: %s.',
                    $type->handle,
                    $agrees ? 'is' : 'is no longer',
                    $agrees ? "every one of its {$with} entries carries a file" : 'none of its entries carries a file',
                );
            });
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($outcome);

        return self::SUCCESS;
    }

    /**
     * Entries of a type with a stored file and without one, trashed included.
     *
     * @return array{0: int, 1: int}
     */
    private static function counts(int $typeId): array
    {
        $total = DB::table('entries')->where('entry_type_id', $typeId)->count();

        $with = DB::table('entries')
            ->where('entry_type_id', $typeId)
            ->whereExists(static fn (Builder $query) => $query->selectRaw('1')
                ->from('media_files')
                ->whereColumn('media_files.entry_id', 'entries.id'))
            ->count();

        return [$with, $total - $with];
    }
}
