<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The global `image` media type accepts images alone — ADR-042 decision 33.
 *
 * ⚠️ A ROW OF DATA, BECAUSE NO ADMIN CAN REACH IT. A global type belongs to every org, so no org's admin may edit it
 * (`EntryTypeResource::ownsRecord()`), and `deploy/release.sh` never seeds: without this, an installation's `image` type
 * would accept every format until someone wrote its settings by hand. Only the global `image` media type, and only one
 * that names nothing yet — an org's own type, and a list already chosen, are left as they are.
 *
 * ⚠️ THE LIST WRITTEN HERE, NOT READ FROM `MediaFormats`, so the migration means tomorrow what it means today. Written
 * below the model, as `…000008` writes its flag, and decoded in PHP rather than by each engine's own JSON functions.
 */
return new class extends Migration
{
    private const ACCEPTS = 'accepts';

    private const IMAGES = ['jpeg', 'png', 'gif', 'webp', 'avif', 'svg'];

    public function up(): void
    {
        foreach ($this->imageTypes() as $id => $settings) {
            if (array_key_exists(self::ACCEPTS, $settings)) {
                continue;
            }

            $settings[self::ACCEPTS] = self::IMAGES;
            DB::table('entry_types')->where('id', $id)->update(['settings' => json_encode($settings)]);
        }
    }

    /**
     * ⚠️ NOTHING — review of #162. A list this wrote and one someone had already chosen, the images alone, read the same,
     * so taking it back would take an operator's choice with it and widen their type to every format. Left, it narrows
     * the type as it did, and code rolled back past decision 33 reads no list at all.
     */
    public function down(): void {}

    /** @return array<int, array<string, mixed>> each global `image` media type's settings, by id */
    private function imageTypes(): array
    {
        $types = [];

        foreach (DB::table('entry_types')->whereNull('org_id')->where('handle', 'image')->where('is_media', true)->get(['id', 'settings']) as $type) {
            $settings = is_string($type->settings) ? json_decode($type->settings, true) : null;
            $types[(int) $type->id] = is_array($settings) ? $settings : [];
        }

        return $types;
    }
};
