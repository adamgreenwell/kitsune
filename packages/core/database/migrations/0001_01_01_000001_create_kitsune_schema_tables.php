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

return new class extends Migration
{
    public function up(): void
    {
        // Schema definitions are org-owned. org_id NULL means a global system
        // type available to every org — the pattern ADR-021 reuses for shared
        // content rather than inventing a second mechanism.
        Schema::create('entry_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('handle');
            $table->string('name');
            $table->string('plural_name');
            $table->string('icon')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('ordering')->default(0);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['org_id', 'handle']);
        });

        // Drupal's FieldStorageConfig: defined once, reusable across types.
        Schema::create('field_storage', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('handle');
            $table->string('type');
            $table->integer('cardinality')->default(1);
            $table->boolean('is_indexed')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->string('translation_scope')->default('per_locale');
            // ADR-020: fail closed. An unclassified field does not save, so
            // this is deliberately nullable in storage and enforced in code —
            // a NOT NULL default would silently classify everything as none.
            $table->string('pii_class')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['org_id', 'handle']);
        });

        // Drupal's FieldConfig: per-type presentation.
        Schema::create('fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entry_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('field_storage_id')->constrained('field_storage')->cascadeOnDelete();
            $table->string('label');
            $table->string('help_text')->nullable();
            $table->boolean('is_required')->default(false);
            $table->json('default_value')->nullable();
            $table->unsignedInteger('ordering')->default(0);
            $table->string('group')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['entry_type_id', 'field_storage_id']);
        });

        // ADR-020 primitive #2: an entry type names the field that identifies
        // the data subject. Added after `fields` exists because the reference
        // runs the other way from `fields.entry_type_id` — the two tables
        // point at each other, and only one order works.
        //
        // Nullable, and deliberately NOT fail-closed the way `pii_class` is:
        // the subject IS one of the type's fields, so demanding the
        // nomination before the first field can be added is circular. The
        // enforcement that works is a report of types holding personal data
        // with nothing nominated — see EntryType::withoutSubjectIdentifier().
        Schema::table('entry_types', function (Blueprint $table): void {
            $table->foreignId('subject_field_id')->nullable()->after('is_system')
                ->constrained('fields')->nullOnDelete();
        });

        Schema::create('entries', function (Blueprint $table): void {
            $table->id();
            // NULL = shared across the org (ADR-021). Shared rows are not
            // publicly addressable, which is why slug is nullable too.
            $table->foreignId('site_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('org_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entry_type_id')->constrained()->cascadeOnDelete();
            $table->string('type_handle')->index();
            $table->uuid('translation_group')->nullable()->index();
            $table->foreignId('origin_id')->nullable();
            $table->string('status')->default('draft');
            $table->string('slug')->nullable();
            $table->string('title')->nullable();
            $table->json('values')->nullable();
            $table->foreignId('author_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // ADR-021: composite indexes lead with the scope key.
            $table->index(['site_id', 'entry_type_id', 'status']);
            $table->unique(['site_id', 'entry_type_id', 'slug']);
            $table->unique(['translation_group', 'site_id']);
        });

        // ADR-015 Relational storage: a real table, never a JSON id array.
        // Org-scoped, because a relation may link a site entry to org-shared
        // media and therefore cannot be site-scoped.
        Schema::create('entry_relations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_entry_id')->constrained('entries')->cascadeOnDelete();
            $table->foreignId('target_entry_id')->constrained('entries')->cascadeOnDelete();
            $table->foreignId('field_storage_id')->nullable()->constrained('field_storage')->nullOnDelete();
            $table->unsignedInteger('ordering')->default(0);

            $table->index(['source_entry_id', 'field_storage_id']);
            // Reverse lookup: "what references this?" is the whole reason
            // this is a table rather than JSON.
            $table->index('target_entry_id');
        });

        // ADR-020: field-level redactable, never an immutable blob. Erasure
        // has to reach revision history, because revision 4 still holds the
        // name just erased.
        //
        // The promoted columns are snapshotted alongside `values` so a
        // restore is faithful — a revision holding only `values` restores an
        // entry with no title, which is a worse outcome than no revisions at
        // all. It also means erasure has to sweep them, and it does.
        Schema::create('entry_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entry_id')->constrained()->cascadeOnDelete();
            // ⚠️ The type DISCRIMINATOR is part of the version.
            //
            // `entries.entry_type_id` is mutable and the model supports changing
            // it — restamping `type_handle` and rechecking inbound relations. A
            // revision that does not record it describes `values` without
            // recording which schema they were authored against, so restoring an
            // older version onto a retyped entry would write those values back to
            // be read by the wrong field set. Nullable because a revision may
            // outlive nothing here, but the FK cascade matches `entry_id`.
            //
            // ⚠️ NOT NULL, deliberately. Nullable first, and that put a
            // restore-time constraint violation one row away: `snapshot()`
            // includes this column, `restoreRevision()` fills the entry from the
            // snapshot, and `entries.entry_type_id` is NOT NULL — so a revision
            // with no discriminator ended the History restore in a database
            // error. Every revision is written by `recordRevision()` from an
            // entry whose own column is NOT NULL, so the value is always there;
            // the schema now says that rather than leaving a hole to handle.
            $table->foreignId('entry_type_id')->constrained()->cascadeOnDelete();
            $table->json('values')->nullable();
            // ⚠️ Relations are the THIRD storage strategy and a revision that
            // omits them is not a version of the entry.
            //
            // A relational field's data is rows in `entry_relations`, not a key
            // in `values` and not a promoted column — so snapshotting only the
            // other two meant restoring a revision left every relation at its
            // CURRENT value while telling the author the entry now matched the
            // version they picked. Shaped as {field_storage_id: [target ids in
            // order]}, which is what a restore needs to rebuild them.
            //
            // ⚠️ NOT called `relations`, and that is not a style preference.
            // `Model::$relations` is a PROTECTED property holding an Eloquent
            // model's loaded relationships. A column of that name is shadowed by
            // it for any code reading the attribute from inside another model:
            // PHP resolves a protected member declared in a common ancestor
            // directly, so `$revision->relations` never reaches `__get()` and
            // returns the loaded-relationships array instead — `[]`. Restoring
            // silently did nothing, and the same read from outside a class
            // returned the column correctly, which is what made it confusing.
            $table->json('relation_state')->nullable();
            $table->string('status')->default('draft');
            $table->string('title')->nullable();
            $table->string('slug')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('author_id')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['entry_id', 'created_at']);
            $table->index(['entry_id', 'id']);
        });

        // ADR-022: per-site entry types on the same sparse inheritance.
        Schema::create('entry_type_availability', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entry_type_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type');
            $table->unsignedBigInteger('scope_id');
            $table->boolean('is_enabled')->default(true);

            $table->unique(['entry_type_id', 'scope_type', 'scope_id'], 'eta_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_type_availability');
        // The self-reference has to go before `fields` does.
        if (Schema::hasColumn('entry_types', 'subject_field_id')) {
            Schema::table('entry_types', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('subject_field_id');
            });
        }

        Schema::dropIfExists('entry_revisions');
        Schema::dropIfExists('entry_relations');
        Schema::dropIfExists('entries');
        Schema::dropIfExists('fields');
        Schema::dropIfExists('field_storage');
        Schema::dropIfExists('entry_types');
    }
};
