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
        $names = str_replace(['-', '.'], '_', Schema::getConnection()->getTablePrefix()).'cf_sig_';
        Schema::create('commerce_financial_signals', function (Blueprint $table) use ($names): void {
            $table->id();
            $table->foreignId('org_id')->constrained(indexName: $names.'org_fk')->restrictOnDelete();
            // A verified org signature may precede paid confirmation. No account, Site, order or raw payload
            // is guessed here; only exact original-account graph proof may resolve this access veto later.
            $table->string('mode', 4);
            $table->string('event_id', 255);
            $table->string('event_type', 64);
            $table->string('charge_id', 100);
            $table->string('subject_id', 100);
            $table->string('payload_hash', 64);
            $table->string('state', 16);
            $table->dateTime('verified_at');
            $table->dateTime('received_at');
            $table->dateTime('resolved_at')->nullable();
            $table->unique(['org_id', 'mode', 'event_id'], $names.'event_uq');
            $table->index(['org_id', 'mode', 'charge_id', 'state', 'id'], $names.'charge_idx');
        });
        Schema::table('commerce_stripe_events', function (Blueprint $table): void {
            $table->string('financial_charge_id', 100)->nullable();
            $table->string('financial_subject_id', 100)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('commerce_financial_signals')->useWritePdo()->exists()
            || DB::table('commerce_stripe_events')->useWritePdo()->whereNotNull('financial_charge_id')->exists()) {
            throw new RuntimeException('Retained financial signals must be resolved before rollback.');
        }
        Schema::table('commerce_stripe_events', fn (Blueprint $table) => $table->dropColumn(['financial_charge_id', 'financial_subject_id']));
        Schema::dropIfExists('commerce_financial_signals');
    }
};
