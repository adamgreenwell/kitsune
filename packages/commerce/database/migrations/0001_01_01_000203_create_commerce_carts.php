<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $bytes = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        Schema::create('commerce_carts', function (Blueprint $table) use ($bytes): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            // The host owns reader keys. Match entitlements' byte comparison, including on MySQL/MariaDB.
            $bytes ? $table->binary('reader_id', 255)->nullable() : $table->string('reader_id', 255)->nullable();
            // Domain-separated SHA-256: neither a raw anonymous secret nor a reader identifier.
            $table->string('owner_key', 64);
            $table->unsignedInteger('version')->default(0);
            // UTC whole seconds; datetime also supports dates beyond MySQL TIMESTAMP's 2038 limit.
            $table->dateTime('expires_at');
            $table->timestamps();

            // Every lookup and cleanup index leads with the exact site's scope key (AGENTS.md §4).
            $table->unique(['site_id', 'owner_key']);
            $table->index(['site_id', 'reader_id']);
            $table->index(['site_id', 'expires_at']);
        });

        Schema::create('commerce_cart_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('cart_id')->constrained('commerce_carts')->cascadeOnDelete();
            // Removing a listing retains a visible, removable orphan line, never an inferred replacement.
            $table->foreignId('listing_id')->nullable()->constrained('commerce_listings')->nullOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->timestamps();

            // NULL listing references intentionally allow several retained orphan lines in one cart.
            $table->unique(['site_id', 'cart_id', 'listing_id']);
        });
    }

    public function down(): void
    {
        // No org/module context is required for a rollback. Inspect the authoritative primary before any DDL;
        // an empty lagging replica or a row-query failure must never authorise destroying saved selections.
        $connection = DB::connection($this->getConnection());

        if ($connection->table('commerce_carts')->useWritePdo()->exists()
            || $connection->table('commerce_cart_lines')->useWritePdo()->exists()) {
            throw new RuntimeException('Remove every retained cart and cart line explicitly before rolling back cart storage.');
        }

        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists('commerce_cart_lines');
        $schema->dropIfExists('commerce_carts');
    }
};
