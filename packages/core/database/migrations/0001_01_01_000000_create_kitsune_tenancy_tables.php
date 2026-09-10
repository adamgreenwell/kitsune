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
        Schema::create('orgs', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('site_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->cascadeOnDelete();
            $table->string('handle');
            $table->string('name');
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['org_id', 'handle']);
            // ADR-021: composite indexes lead with the scope key.
            $table->index(['org_id', 'handle']);
        });

        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_group_id')->nullable()->constrained()->nullOnDelete();
            // handle stays org-unique (ADR-021): it is how an operator names
            // a site within their own organisation.
            $table->string('handle');
            // slug is the ROUTE key and is globally unique, because the admin
            // URL /admin/{site} has no org segment to disambiguate it. Two
            // orgs may legitimately both call a site "golfdom"; only one can
            // own that URL.
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('locale')->default('en');
            $table->string('url_strategy')->default('path');
            $table->string('base_url')->nullable();

            /*
             * ⚠️ DERIVED FROM `base_url`, STORED, AND UNIQUE TOGETHER — three problems in
             * one pair of columns, and none is solvable by parsing `base_url` per request.
             *
             * 1. RESOLUTION MUST BE INDEXED. A public request finds its site before
             *    anything else happens, and scanning every row in PHP makes every page view
             *    O(total sites) in time and memory — which the 1 vCPU / 1 GB floor of
             *    ADR-027 does not have to give away.
             *
             * 2. TWO ORGS MUST NOT CLAIM ONE URL. `base_url` accepts equivalent spellings —
             *    `https://example.test`, `http://example.test/`, `EXAMPLE.TEST` — so a
             *    uniqueness constraint on it directly would let two orgs both own a
             *    hostname and let row order decide which one a request reached.
             *    Canonicalising first makes the constraint mean something, and cross-org
             *    URL theft is exactly the class ADR-021 says has no framework safety net.
             *
             * 3. THE WINNER MUST BE DETERMINISTIC. Ranked by specificity — host+prefix,
             *    then host, then prefix, then root — not by whichever row the database
             *    happened to return first.
             *
             * NULL in both means the site has no public URL and is reachable only through
             * the admin. NULLs compare distinct in a unique index on every engine, so any
             * number of admin-only sites coexist while a populated pair is claimed once.
             * An EMPTY STRING is a real value: `canonical_host = ''` is any host,
             * `path_prefix = ''` is the site root.
             */
            $table->string('canonical_host')->nullable();
            $table->string('path_prefix')->nullable();
            $table->string('theme')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['org_id', 'handle']);
            /*
             * The public URL is claimed once, across every org. See the columns above.
             *
             * ⚠️ DELIBERATELY DOES NOT LEAD WITH `org_id`, which is the carve-out added to
             * AGENTS.md invariant 4 rather than an oversight. Both conditions it requires
             * hold here, and are stated because the invariant says to state them:
             *
             * 1. What this claims is a GLOBALLY SCARCE NAME, not a row an org owns. Leading
             *    with `org_id` would permit exactly what the constraint forbids — two orgs
             *    each holding `golfdom.test`, with row order deciding which answers.
             * 2. Its consumer is a BOOTSTRAP. `ResolveSiteFromRequest` runs before any org
             *    context exists, because the org is derived FROM the site it returns, and
             *    says so through `withoutScopeBecause()`. There is no scope key to lead
             *    with at the moment this index is used.
             */
            $table->unique(['canonical_host', 'path_prefix']);
            $table->index(['org_id', 'site_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
        Schema::dropIfExists('site_groups');
        Schema::dropIfExists('orgs');
    }
};
