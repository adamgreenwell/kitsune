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
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
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
        $en = Site::create(['org_id' => $orgA->id, 'site_group_id' => $group->id, 'handle' => 'golfdom', 'slug' => 'golfdom', 'name' => 'Golfdom', 'locale' => 'en', 'is_primary' => true]);
        $fr = Site::create(['org_id' => $orgA->id, 'site_group_id' => $group->id, 'handle' => 'golfdom-fr', 'slug' => 'golfdom-fr', 'name' => 'Golfdom FR', 'locale' => 'fr']);

        $context->setOrg($orgB);
        // Deliberately the SAME handle as Golfdom's site. UNIQUE is
        // (org_id, handle), so this is legal — and it is the case that broke
        // route binding: an unscoped lookup returned whichever row came
        // first, so one customer's admin became unreachable depending on row
        // order. Every admin spec navigates to /admin/golfdom, which means
        // the whole browser suite is the regression test for it.
        $rival = Site::create(['org_id' => $orgB->id, 'handle' => 'golfdom', 'slug' => 'rival-golfdom', 'name' => 'Rival Golfdom', 'locale' => 'en']);

        $user = User::create(['name' => 'Alpha User', 'email' => 'alpha@kitsune.test', 'password' => Hash::make('password')]);
        $user->sites()->attach([$en->id, $fr->id]);
        $user->orgs()->attach($orgA->id);

        /*
         * ⚠️ A SECOND admin of the SAME org and sites, differing only in UI locale.
         *
         * ADR-018 rule 2: the UI locale is a viewer preference, not a site setting,
         * because one org has editors working in different languages. This row is that
         * claim made testable — the browser suite signs in as both and asserts the same
         * pages render in opposite directions, on one server, from one database.
         *
         * It replaces a second web server started with `APP_LOCALE=ar`. That fixture was
         * honest when the locale was fixed for the life of a process; issue #38 made the
         * locale per-request, so proving RTL through an environment variable would now be
         * proving something Kitsune no longer does.
         */
        $rtlUser = User::create([
            'name' => 'مستخدم ألفا', 'email' => 'alpha-rtl@kitsune.test',
            'password' => Hash::make('password'), 'locale' => 'ar',
        ]);
        $rtlUser->sites()->attach([$en->id, $fr->id]);
        $rtlUser->orgs()->attach($orgA->id);

        // A user of the OTHER org, which the admin must never be able to
        // enumerate. Nothing lists users yet; this row is here so the
        // boundary has something to fail against the moment something does
        // (issue #21).
        $rivalUser = User::create(['name' => 'Rival User', 'email' => 'rival@kitsune.test', 'password' => Hash::make('password')]);
        $rivalUser->sites()->attach($rival->id);
        $rivalUser->orgs()->attach($orgB->id);

        // A global system type, available to every org (org_id NULL).
        EntryType::create(['org_id' => null, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_system' => true, 'icon' => 'heroicon-o-photo']);

        $article = EntryType::create(['org_id' => $orgA->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles', 'icon' => 'heroicon-o-document-text']);
        $product = EntryType::create(['org_id' => $orgA->id, 'handle' => 'product', 'name' => 'Product', 'plural_name' => 'Products', 'icon' => 'heroicon-o-shopping-bag']);

        /*
         * ⚠️ REAL FIELDS ON THE ARTICLE TYPE, and until now there were none at all — the
         * seeder created zero `field_storage` and zero `field` rows, so every entry type
         * had only the platform columns.
         *
         * That is why issue #39's direction tests could only ever exercise `title` and
         * `slug`: a dynamic form had nothing else to render, so a textarea, a select or a
         * JSON editor laying out backwards was not a failing test, it was an unobservable
         * one. Fixtures are what make the difference.
         *
         * ⚠️ One field per CONTROL KIND that a browser can measure, not one per field
         * type. `Auto` and `Neutral` and `PerBlock` all need to be present, because a
         * suite where everything is `Auto` passes against a renderer that sets `dir` on
         * everything — which is wrong for rich text specifically.
         */
        $articleFields = [
            // Auto, and multi-line: the control #39 named first and could not test.
            ['summary', 'textarea', 'Summary', 1],
            // Auto, PerBlock inside: no wrapper dir, per-block dir survives sanitising.
            ['body', 'rich_text', 'Body', 1],
            // Auto through option LABELS — the case that looks like chrome and is not.
            ['section', 'select', 'Section', 1],
            // A second select whose labels are ALL Arabic. Needed because `dir="auto"` on
            // a <select> resolves from the whole option list rather than the selection —
            // measured — so a mixed list starting with Latin text is always LTR and cannot
            // demonstrate that the attribute does anything.
            ['origin', 'select', 'Origin', 1],
            // Neutral: an app-formatted number must NOT carry dir.
            ['reading_minutes', 'number', 'Reading minutes', 1],
            // Auto, and MULTI-VALUE: direction belongs on the inner control, not the
            // repeater wrapper.
            ['keywords', 'text', 'Keywords', 4],
        ];

        foreach ($articleFields as $index => [$handle, $type, $label, $cardinality]) {
            $storage = FieldStorage::create([
                'org_id' => $orgA->id,
                'handle' => $handle,
                'type' => $type,
                // ADR-020 fails closed, so every storage row must classify itself.
                'pii_class' => 'none',
                'cardinality' => $cardinality,
                'settings' => match ($handle) {
                    // ⚠️ Deliberately MIXED-script labels, Latin first. An English-only set
                    // would let a select pass while rendering Arabic labels backwards.
                    'section' => ['options' => ['greens' => 'Greens', 'bunkers' => 'الحواجز الرملية']],
                    // ⚠️ ALL Arabic, which is what actually demonstrates the attribute
                    // working: a <select> with `dir="auto"` resolves from its option list as
                    // a whole, so this one computes RTL while `section` computes LTR.
                    'origin' => ['options' => ['sand' => 'رمل', 'clay' => 'طين']],
                    default => null,
                },
            ]);

            Field::create([
                'entry_type_id' => $article->id,
                'field_storage_id' => $storage->id,
                'label' => $label,
                'ordering' => $index + 1,
            ]);
        }

        // Belongs to the other org — must be unreachable from Golfdom's admin.
        EntryType::create(['org_id' => $orgB->id, 'handle' => 'confidential', 'name' => 'Confidential', 'plural_name' => 'Confidential']);

        // Owned by this org but DISABLED for this site (ADR-022): a section
        // the French edition drops. It must 404 at the route, and it must not
        // appear in navigation — the availability half of the middleware had
        // no route-level test until this fixture existed.
        $podcast = EntryType::create(['org_id' => $orgA->id, 'handle' => 'podcast', 'name' => 'Podcast', 'plural_name' => 'Podcasts']);
        EntryTypeAvailability::create([
            'entry_type_id' => $podcast->id,
            'scope_type' => 'site',
            'scope_id' => $en->id,
            'is_enabled' => false,
        ]);

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

        // ⚠️ An RTL title in an otherwise LTR org, because issue #39's failure only
        // appears with bidirectional content in ONE admin — which ADR-018 rule 2 says is
        // the designed case. Without a row like this the direction tests would have
        // nothing to measure and would pass by asserting about LTR text in an LTR panel.
        Entry::create([
            'entry_type_id' => $article->id,
            'title' => 'صيانة الملاعب في الأسبوع السابع',
            'slug' => 'course-maintenance-week-7',
            'status' => 'published',
            'values' => ['summary' => 'ملاحظات الأسبوع السابع.'],
            'published_at' => now()->subDays(7),
        ]);

        foreach (['Fairway mower', 'Bunker rake'] as $i => $name) {
            Entry::create([
                'entry_type_id' => $product->id,
                'title' => $name,
                'slug' => str($name)->slug()->value(),
                'status' => 'published',
                'values' => ['price' => 1200 + ($i * 350)],
            ]);
        }

        // Relations through the real table (ADR-015), so the page-based
        // relation manager has something to show.
        $first = Entry::where('slug', 'course-maintenance-week-1')->first();
        // ⚠️ The Arabic-titled entry is among them ON PURPOSE. The related-records table
        // defines its OWN title column, so `dir="auto"` on the entry list did nothing for
        // it — and without an RTL title attached here there is nothing on that page for a
        // direction test to measure, so the gap stayed invisible (issue #39).
        $others = Entry::whereIn('slug', [
            'course-maintenance-week-2',
            'course-maintenance-week-3',
            'course-maintenance-week-7',
        ])->pluck('id');
        $first?->related()->attach($others->all(), ['org_id' => $orgA->id]);

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
