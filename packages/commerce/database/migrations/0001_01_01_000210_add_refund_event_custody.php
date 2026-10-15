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
        $name = str_replace(['-', '.'], '_', Schema::getConnection()->getTablePrefix()).'cc_confirmation_replay_idx';
        Schema::table('commerce_checkout_attempts', function (Blueprint $table) use ($name): void {
            // Site-scoped historical confirmations share fair maintenance with owed refunds. Selection counts
            // advance even when an old credential witness blocks provider recovery; they are not buyer consent.
            $table->unsignedInteger('confirmation_replay_count')->default(0);
            $table->index(['site_id', 'confirmation_replay_count', 'id'], $name);
        });
        Schema::table('commerce_stripe_events', function (Blueprint $table): void {
            // Refund subjects keep the original Session correlation; a Refund is never stored as a Session ID.
            $table->string('refund_reference', 32)->nullable();
            $table->string('refund_id', 100)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('commerce_stripe_events')->useWritePdo()->whereNotNull('refund_reference')->exists()) {
            throw new RuntimeException('Retained refund events must be resolved before rollback.');
        }
        Schema::table('commerce_stripe_events', function (Blueprint $table): void {
            $table->dropColumn(['refund_reference', 'refund_id']);
        });
        $name = str_replace(['-', '.'], '_', Schema::getConnection()->getTablePrefix()).'cc_confirmation_replay_idx';
        Schema::table('commerce_checkout_attempts', function (Blueprint $table) use ($name): void {
            $table->dropIndex($name);
            $table->dropColumn('confirmation_replay_count');
        });
    }
};
