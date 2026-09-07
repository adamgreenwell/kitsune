<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Models\SiteGroup;
use Kitsune\Core\Tenancy\Context;

/**
 * Demonstration data for a local alpha.
 *
 * Two orgs on purpose: a single-org seed cannot show that isolation works,
 * and the whole point of the tenancy kernel is what the second org cannot see.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $context = app(Context::class);

        $orgA = Org::create(['name' => 'Golfdom Media', 'slug' => 'golfdom-media', 'settings' => ['timezone' => 'UTC']]);
        $orgB = Org::create(['name' => 'Rival Publishing', 'slug' => 'rival']);

        $context->setOrg($orgA);
        $group = SiteGroup::create(['org_id' => $orgA->id, 'handle' => 'golfdom', 'name' => 'Golfdom', 'settings' => ['logo' => 'golfdom.svg']]);
        $en = Site::create(['org_id' => $orgA->id, 'site_group_id' => $group->id, 'handle' => 'golfdom', 'name' => 'Golfdom', 'locale' => 'en', 'is_primary' => true]);
        $fr = Site::create(['org_id' => $orgA->id, 'site_group_id' => $group->id, 'handle' => 'golfdom-fr', 'name' => 'Golfdom FR', 'locale' => 'fr']);

        $context->setOrg($orgB);
        // Deliberately the SAME handle as Golfdom's site. UNIQUE is
        // (org_id, handle), so this is legal — and it is the case that broke
        // route binding: an unscoped lookup returned whichever row came
        // first, so one customer's admin became unreachable depending on row
        // order. Every admin spec navigates to /admin/golfdom, which means
        // the whole browser suite is the regression test for it.
        $rival = Site::create(['org_id' => $orgB->id, 'handle' => 'golfdom', 'name' => 'Rival Golfdom', 'locale' => 'en']);

        $user = User::create(['name' => 'Alpha User', 'email' => 'alpha@kitsune.test', 'password' => Hash::make('password')]);
        $user->sites()->attach([$en->id, $fr->id]);

        // A global system type, available to every org (org_id NULL).
        EntryType::create(['org_id' => null, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_system' => true, 'icon' => 'heroicon-o-photo']);

        $article = EntryType::create(['org_id' => $orgA->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles', 'icon' => 'heroicon-o-document-text']);
        $product = EntryType::create(['org_id' => $orgA->id, 'handle' => 'product', 'name' => 'Product', 'plural_name' => 'Products', 'icon' => 'heroicon-o-shopping-bag']);

        // Belongs to the other org — must be unreachable from Golfdom's admin.
        EntryType::create(['org_id' => $orgB->id, 'handle' => 'confidential', 'name' => 'Confidential', 'plural_name' => 'Confidential']);

        $context->setSite($en);
        foreach (range(1, 6) as $i) {
            Entry::create([
                'entry_type_id' => $article->id,
                'title' => "Course maintenance in week {$i}",
                'slug' => "course-maintenance-week-{$i}",
                'status' => $i % 3 === 0 ? 'draft' : 'published',
                'values' => ['summary' => "Notes for week {$i}."],
                'published_at' => now()->subDays($i),
            ]);
        }

        foreach (['Fairway mower', 'Bunker rake'] as $i => $name) {
            Entry::create([
                'entry_type_id' => $product->id,
                'title' => $name,
                'slug' => str($name)->slug()->value(),
                'status' => 'published',
                'values' => ['price' => 1200 + ($i * 350)],
            ]);
        }

        $context->setSite($rival);
        Entry::create([
            'entry_type_id' => $article->id,
            'title' => 'Should never be visible from Golfdom',
            'slug' => 'rival-secret',
            'status' => 'published',
        ]);

        $context->forget();
    }
}
