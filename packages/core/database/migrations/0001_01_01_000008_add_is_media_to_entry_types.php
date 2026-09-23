<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which entry types hold media — ADR-042 decision 1.
 *
 * ⚠️ A MIGRATION OF ITS OWN, NOT AN EDIT TO THE ONE THAT CREATES `entry_types`, and the reason is the deploy.
 * `deploy/release.sh` runs `migrate --force` against a live database, and Laravel skips a migration it has already
 * recorded — so a column added by editing `0001_01_01_000001` never reaches a database migrated before the edit.
 * That file has been edited in place six times since #19 created it (#30, #35, #43, #45, #82, #102); ADR-042
 * records the consequence as an open question, and this change does not add a seventh.
 *
 * ⚠️ IT BACKFILLS, BECAUSE THE FLAG IS LOCKED THE MOMENT IT EXISTS. #145–#148 let `MediaLibrary::store()` attach
 * bytes to any type, so an installation migrated before this may already have types whose entries carry
 * `media_files` rows. Left at the default, those types would lose their upload control and could never be
 * corrected through the model, which refuses to change `is_media` after creation.
 *
 * ⚠️ AND IT REFUSES A TYPE IT CANNOT CLASSIFY, BEFORE IT CHANGES ANYTHING. A type holding entries with bytes and
 * entries without is neither a media type nor an ordinary one, and marking it either way would lock one half of
 * its entries into a state ADR-042 calls broken. The check runs before the column is added because on MySQL and
 * MariaDB a DDL statement commits on its own: a refusal thrown after it would leave the column in place and make
 * every retry fail on "duplicate column" rather than on the problem it is reporting. Trashed entries count — a
 * soft-deleted media entry still has its bytes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $types = $this->typesHoldingMedia();

        Schema::table('entry_types', function (Blueprint $table): void {
            $table->boolean('is_media')->default(false);
        });

        $this->markAsMedia($types);
    }

    /**
     * @param  list<int>  $types
     */
    public function markAsMedia(array $types): void
    {
        if ($types !== []) {
            DB::table('entry_types')->whereIn('id', $types)->update(['is_media' => true]);
        }
    }

    /**
     * The ids of every type whose entries all carry stored files — refusing, before anything changes, a type
     * where only some do.
     *
     * Public, with `markAsMedia()`, so a test can run both directly. The one line of `up()` between them is
     * schema, and on MySQL and MariaDB a schema statement inside a test commits the transaction that test runs in.
     *
     * @return list<int>
     */
    public function typesHoldingMedia(): array
    {
        $withBytes = DB::table('entries')
            ->join('media_files', 'media_files.entry_id', '=', 'entries.id')
            ->distinct()
            ->pluck('entries.entry_type_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        if ($withBytes === []) {
            return [];
        }

        /* Counted per type, trashed rows included: a soft-deleted entry is still a row the lock would strand. */
        $mixed = DB::table('entries')
            ->whereIn('entry_type_id', $withBytes)
            ->whereNotExists(static function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('media_files')
                    ->whereColumn('media_files.entry_id', 'entries.id');
            })
            ->groupBy('entry_type_id')
            ->selectRaw('entry_type_id, count(*) as without_files')
            ->pluck('without_files', 'entry_type_id')
            ->all();

        if ($mixed === []) {
            return $withBytes;
        }

        /* By id as well as handle: two orgs may each own a type with the same handle. */
        $types = DB::table('entry_types')
            ->whereIn('id', array_keys($mixed))
            ->orderBy('handle')
            ->orderBy('id')
            ->get(['id', 'handle'])
            ->map(static fn (object $type): string => sprintf(
                '%s (id %d): %d without a file',
                $type->handle,
                $type->id,
                $mixed[$type->id] ?? $mixed[(string) $type->id] ?? 0,
            ))
            ->implode('; ');

        throw new RuntimeException(sprintf(
            'Cannot mark media types: [%s]. %s entries with stored files and entries without, so %s neither a '
            .'media type nor an ordinary one (ADR-042). Nothing was changed. Soft-deleted entries count, because '
            .'the flag is locked and a restore would bring them back — so deleting them in the admin, which only '
            .'trashes them, does not clear this. Force-delete the entries without files, or move them to another '
            .'type, and run the migration again.',
            $types,
            count($mixed) === 1 ? 'That type holds' : 'Each of those types holds',
            count($mixed) === 1 ? 'it is' : 'each is',
        ));
    }

    public function down(): void
    {
        Schema::table('entry_types', function (Blueprint $table): void {
            $table->dropColumn('is_media');
        });
    }
};
