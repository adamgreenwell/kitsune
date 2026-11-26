<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Kitsune\Core\Http\Api\SiteTokenSchema;

return new class extends Migration
{
    public function up(): void
    {
        SiteTokenSchema::create(User::class);
    }

    public function down(): void
    {
        SiteTokenSchema::drop(User::class);
    }
};
