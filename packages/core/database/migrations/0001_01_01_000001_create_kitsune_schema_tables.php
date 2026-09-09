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
            // ⚠️ `translation_scope` is deliberately NOT here yet, and ADR-017
            // specifies it. See the amendment recorded there: a column with a default
            // asserts a behaviour, every row would have claimed its field is
            // translated per locale, and nothing honours that claim. Content i18n is
            // Phase 6+, and re-adding a column to a pre-alpha schema is free.
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

        Schema::create('entry_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entry_id')->constrained()->cascadeOnDelete();
            $table->json('values')->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('author_id')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['entry_id', 'created_at']);
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
