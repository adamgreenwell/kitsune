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
 * ADR-020 primitives 4 and 5.
 *
 * ⚠️ What is ABSENT from `audit_log` is the design. There is no `changes`
 * column, no `before`/`after`, no payload of any kind — and there is a test
 * asserting the column list exactly, so adding one fails the build rather
 * than passing review.
 *
 * "User 47 updated entry 1203" survives an erasure. "User 47 changed name
 * from X to Y" does not: it re-creates the erased value inside the log meant
 * to prove the erasure happened, which puts SOC 2 and GDPR in direct conflict
 * for no gain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->cascadeOnDelete();
            // NULL for org-level actions that belong to no single site.
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            // NULL for the system acting on its own — a scheduled prune, a
            // migration, a replayed erasure. An audit row with no actor is
            // more honest than one attributing it to whoever happened to be
            // logged in.
            $table->foreignId('actor_id')->nullable();
            $table->string('action');
            // Both nullable: plenty of auditable actions have no model behind
            // them — a settings change, a sign-in, an export. The signature
            // advertises an optional target and the column has to mean it.
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->timestamp('created_at');

            // ADR-021: composite indexes lead with the scope key.
            $table->index(['org_id', 'created_at']);
            $table->index(['org_id', 'target_type', 'target_id']);
            $table->index(['org_id', 'actor_id']);
        });

        /*
         * ADR-020 primitive 5: a replayable erasure log.
         *
         * Backups cannot be rewritten, so the workable answer is a retention
         * window plus erasure re-applied on restore — which needs a record of
         * WHAT WAS ERASED that contains none of the erased content.
         *
         * ⚠️ So this stores the target and the replacement, never the
         * original: entry, field handle, and what it was replaced WITH. That
         * is enough to replay and discloses nothing. Storing the old value —
         * or a hash of it, which is still personal data under GDPR when the
         * input space is small — would defeat the entire point.
         */
        Schema::create('erasure_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entry_id')->nullable();
            $table->string('field_handle');
            $table->string('replacement')->nullable();
            $table->unsignedInteger('rows_rewritten');
            $table->timestamp('erased_at');

            $table->index(['org_id', 'erased_at']);
            $table->index(['org_id', 'entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erasure_log');
        Schema::dropIfExists('audit_log');
    }
};
