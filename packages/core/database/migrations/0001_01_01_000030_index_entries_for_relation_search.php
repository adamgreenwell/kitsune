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
 * Relation searches serve both Site entries and rows shared through a null Site.
 * The picker supplies the current Org explicitly and retains SiteScope and its
 * existing target and viewer checks. The Site-leading indexes remain for the
 * existing Site-only reads. See ADR-042, decision 2.
 *
 * The largest InnoDB key uses two VARCHAR(255) fields, a CHAR(36) UUID,
 * three bigints and a timestamp. At four bytes per character this is
 * about 2.2 KB, within the 3,072-byte limit on the tested default 16 KiB
 * InnoDB pages with the DYNAMIC row format.
 */
return new class extends Migration
{
    public function up(): void
    {
        $name = $this->indexName();

        Schema::table('entries', function (Blueprint $table) use ($name): void {
            $table->index(['org_id', 'title', 'id', 'deleted_at', 'site_id', 'type_handle', 'translation_group'], $name);
        });
    }

    public function down(): void
    {
        $name = $this->indexName();

        Schema::table('entries', function (Blueprint $table) use ($name): void {
            $table->dropIndex($name);
        });
    }

    private function indexName(): string
    {
        $name = 'entries_relation_search_order_index';
        $prefix = Schema::getConnection()->getTablePrefix();

        return $prefix === '' ? $name : $name.'_'.substr(hash('sha256', $prefix), 0, 24);
    }
};
