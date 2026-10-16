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
        Schema::create('commerce_stripe_admissions', function (Blueprint $table): void {
            // Org-shared configuration: one primary Org key, no Site-specific price/account admission.
            $table->foreignId('org_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('version', 32);
            $table->string('state', 8);
            $table->string('account_id', 100)->nullable();
            $table->string('credential_witness', 64)->nullable();
            $table->dateTime('admitted_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->dateTime('updated_at');
        });
    }

    public function down(): void
    {
        // Removing configuration refuses new live sales; it deletes no order/payment/recovery custody.
        Schema::dropIfExists('commerce_stripe_admissions');
    }
};
