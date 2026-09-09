<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            // ⚠️ The UI locale is a USER preference, not a site setting (ADR-018 rule 2):
            // a Swiss agency has German, French and Italian editors on one org, so the
            // language of the chrome belongs to whoever is looking rather than to what
            // they are looking at.
            //
            // ⚠️ It lives HERE, in the application, and not in kitsune/core. Core stays
            // headless-capable (ADR-002) and must not require a particular auth schema,
            // so it reads Laravel's own `HasLocalePreference` contract and never learns
            // which column the answer came from.
            //
            // Nullable, because "no preference" is a real answer and a different one from
            // any particular locale: it means fall through to the site's, which is what
            // `LocaleResolver` does.
            $table->string('locale')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
