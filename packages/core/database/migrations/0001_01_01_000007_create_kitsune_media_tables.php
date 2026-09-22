<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bytes behind a media entry — ADR-016, with ADR-041's `visibility`.
 *
 * ADR-016 decided there is no media subsystem: an uploaded file is an **entry** of a system type (`image`,
 * `document`, `video`) carrying its own fields, and this table holds only what the entry cannot — where the
 * bytes are and what they are. Revisions, permissions, audit and tenancy already apply, because the thing an
 * operator manages is an entry.
 *
 * ⚠️ NO `org_id` OR `site_id`, AND THAT IS NOT AN OMISSION. The row is 1:1 with an entry that carries both,
 * and `EntryRevision` sets the precedent in one line: *"Unscoped because it is reached only through its Entry,
 * which is scoped."* A denormalised scope key here would be a second copy of the answer, free to disagree with
 * the first — and `entry_relations` carries one only because it is a JOIN between two entries rather than a
 * dependent of one.
 *
 * ⚠️ ONE PATH PER ENTRY. ADR-041 decides there are no derivatives in v1.0: originals are served and sized in
 * CSS. That is a floor decision rather than an oversight — ADR-027 forbids assuming external services, and the
 * skeleton's queue defaults to `sync`, so there is no worker to defer image work to unless an operator runs
 * one. A renditions table is what changes when the first operator's bandwidth makes it a problem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_files', function (Blueprint $table): void {
            $table->id();

            /*
             * ⚠️ UNIQUE, because the relationship is 1:1 and a second row would be a second answer to "where
             * are the bytes for this entry". `cascadeOnDelete` matches the lifecycle ADR-041 decides: bytes
             * follow the entry, so a force-deleted entry takes its row with it. The FILE is removed by the
             * model's own hook — a foreign key cannot reach a disk.
             */
            $table->foreignId('entry_id')->unique()->constrained()->cascadeOnDelete();

            /* Flysystem disk and the path within it. Both are written by core; neither is ever caller input. */
            $table->string('disk');
            $table->string('path');

            /*
             * Read from the file's own bytes with `finfo`, never taken from the request. A client-supplied
             * content type is a claim by the person uploading, and this column is used to decide what to send
             * back — so trusting it would let an uploader choose the `Content-Type` a browser later executes.
             */
            $table->string('mime');

            $table->unsignedBigInteger('size_bytes');

            /* Dedupe and integrity, as ADR-016 published it. */
            $table->string('checksum');

            /*
             * ⚠️ PRIVATE BY DEFAULT (ADR-041). Kitsune is a fail-closed house and a leaked gated download is
             * worse than a slow product image, so public is an explicit act rather than the state a row lands
             * in when nobody said. The column decides which disk the bytes live on and which delivery path
             * serves them.
             */
            $table->string('visibility')->default('private');

            /* Best effort, from `getimagesize()` — `ext/standard`, always present, and it does not need GD. */
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            /*
             * ⚠️ NULL IN v1.0, DELIBERATELY. Probing a video needs a binary ADR-027 does not let us assume, and
             * a column populated only when something happens to be installed is worse than one that is
             * honestly empty: it makes "no duration" and "duration unknown" the same value.
             */
            $table->unsignedBigInteger('duration_ms')->nullable();

            $table->timestamp('created_at');

            /* Dedupe asks "does this org already hold these bytes?", which is a scan by checksum. */
            $table->index('checksum');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_files');
    }
};
