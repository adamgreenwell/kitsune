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
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
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
        $en = Site::create(['org_id' => $orgA->id, 'site_group_id' => $group->id, 'handle' => 'golfdom', 'slug' => 'golfdom', 'name' => 'Golfdom', 'locale' => 'en', 'is_primary' => true,
            // ⚠️ HOST-LESS, so it resolves wherever the installation is served — APP_URL is
            // http://localhost while the browser suite serves 127.0.0.1:8125, and a
            // fully-qualified base_url could never match both (ADR-021 amendment).
            'base_url' => '/golfdom']);
        $fr = Site::create(['org_id' => $orgA->id, 'site_group_id' => $group->id, 'handle' => 'golfdom-fr', 'slug' => 'golfdom-fr', 'name' => 'Golfdom FR', 'locale' => 'fr', 'base_url' => '/golfdom-fr']);

        $context->setOrg($orgB);
        // Deliberately the SAME handle as Golfdom's site. UNIQUE is
        // (org_id, handle), so this is legal — and it is the case that broke
        // route binding: an unscoped lookup returned whichever row came
        // first, so one customer's admin became unreachable depending on row
        // order. Every admin spec navigates to /admin/golfdom, which means
        // the whole browser suite is the regression test for it.
        $rival = Site::create(['org_id' => $orgB->id, 'handle' => 'golfdom', 'slug' => 'rival-golfdom', 'name' => 'Rival Golfdom', 'locale' => 'en']);

        // ⚠️ An RTL site, so the PUBLIC side has something to serve right-to-left without
        // anyone editing APP_LOCALE (issue #38). `golfdom` is `en` and `golfdom-fr` is
        // French — both LTR — so before this row there was no public URL that could
        // demonstrate a site's locale reaching the document at all.
        $context->setOrg($orgA);
        $ar = Site::create([
            'org_id' => $orgA->id, 'site_group_id' => $group->id, 'handle' => 'golfdom-ar',
            'slug' => 'golfdom-ar', 'name' => 'Golfdom AR', 'locale' => 'ar',
            'base_url' => '/golfdom-ar',
        ]);

        /*
         * ⚠️ A NESTED PREFIX, because `Site::MAX_PREFIX_SEGMENTS` is 4 and nothing exercised more
         * than one. The skeleton's public route matched a single segment for three revisions of this
         * branch: a site at `/news/fr` saved, was resolvable, and could never be REACHED — and the
         * suite could not see it, because no fixture had a prefix deeper than one segment.
         *
         * Hebrew rather than Arabic so the assertion cannot pass by matching the other RTL site.
         */
        $nested = Site::create([
            'org_id' => $orgA->id, 'site_group_id' => $group->id, 'handle' => 'golfdom-nested',
            'slug' => 'golfdom-nested', 'name' => 'Golfdom Nested', 'locale' => 'he',
            'base_url' => '/news/fr',
        ]);
        $context->setOrg($orgB);

        $user = User::create(['name' => 'Alpha User', 'email' => 'alpha@kitsune.test', 'password' => Hash::make('password')]);
        // And `golfdom-nested`, where the global `image` type is switched off — the site shared media must not reach.
        $user->sites()->attach([$en->id, $fr->id, $ar->id, $nested->id]);
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
        $rtlUser->sites()->attach([$en->id, $fr->id, $ar->id]);
        $rtlUser->orgs()->attach($orgA->id);

        // A user of the OTHER org, which the admin must never be able to
        // enumerate. Nothing lists users yet; this row is here so the
        // boundary has something to fail against the moment something does
        // (issue #21).
        $rivalUser = User::create(['name' => 'Rival User', 'email' => 'rival@kitsune.test', 'password' => Hash::make('password')]);
        $rivalUser->sites()->attach($rival->id);
        $rivalUser->orgs()->attach($orgB->id);

        // A global system type, available to every org (org_id NULL), and a media type: its entries come from
        // uploaded files, and `MediaLibrary::store()` refuses any type not declared as one (ADR-042).
        $image = EntryType::create(['org_id' => null, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_system' => true, 'is_media' => true, 'icon' => 'heroicon-o-photo']);

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
            // ⚠️ A RELATION, which is the one control whose value is not an attribute. It
            // lives in `entry_relations` (ADR-015), so nothing about it can be tested
            // without a real field to drive the save lifecycle through.
            ['related_articles', 'relation', 'Related articles', -1],
            /*
             * ⚠️ A SECOND RELATION, POINTING AT A TYPE THE COPY-EDITOR MAY NOT VIEW, which is the fixture
             * for a defect review found and nothing else here could reach: an owner links an article to a
             * product, and the article's editor holds `entry.article.*` alone. Filament validates a
             * select's options through the label callbacks, so a withheld label made the id an invalid
             * option and the editor could not save a TITLE change on a field they were not editing.
             *
             * `related_articles` cannot demonstrate it, because the copy-editor may view articles — the
             * whole point of this field is that its targets are outside their grants.
             */
            ['related_products', 'relation', 'Related products', -1],
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
                    // Constrained to articles, so the picker offers what the validation
                    // rule would actually accept rather than a wider set.
                    'related_articles' => ['targetTypes' => ['article']],
                    // Products, which the copy-editor holds nothing on — see the field list above.
                    'related_products' => ['targetTypes' => ['product']],
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

        /*
         * ⚠️ PRODUCT HAD NO FIELDS, so it opened onto a form holding a title and nothing else, and the alpha pass read
         * it as a broken type rather than a second example of one. A SKU and a price are what a product is known by.
         * Optional, like every field here: the product spec creates one with a title alone.
         */
        foreach ([['sku', 'text', 'SKU'], ['price', 'number', 'Price']] as $index => [$handle, $fieldType, $label]) {
            $storage = FieldStorage::create([
                'org_id' => $orgA->id, 'handle' => $handle, 'type' => $fieldType, 'pii_class' => 'none', 'cardinality' => 1,
            ]);

            Field::create([
                'entry_type_id' => $product->id, 'field_storage_id' => $storage->id, 'label' => $label, 'ordering' => $index + 1,
            ]);
        }

        /*
         * ⚠️ AND A PHOTO, POINTING AT IMAGES — the relation field `e2e/media-sharing.spec.js` drives, because shared media
         * has to be offered by a picker and accepted by a save at a second site (ADR-042 decision 2). On `product` rather
         * than `article`: every article-form spec would otherwise meet one more control, and the copy-editor, who holds
         * nothing on `image`, a picker whose search returns nothing.
         */
        $photoStorage = FieldStorage::create([
            'org_id' => $orgA->id, 'handle' => 'photo', 'type' => 'relation', 'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['targetTypes' => ['image']],
        ]);

        Field::create(['entry_type_id' => $product->id, 'field_storage_id' => $photoStorage->id, 'label' => 'Photo', 'ordering' => 3]);

        // Belongs to the other org — must be unreachable from Golfdom's admin.
        $confidential = EntryType::create(['org_id' => $orgB->id, 'handle' => 'confidential', 'name' => 'Confidential', 'plural_name' => 'Confidential']);

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

        /*
         * ⚠️ AND THE GLOBAL `image` TYPE, OFF AT `golfdom-nested` — ADR-042 decision 2 applies the widened scope only
         * together with the site's type availability, so a shared file must be neither listed, offered nor served where
         * its type is switched off. This is the site that shows it.
         */
        EntryTypeAvailability::create([
            'entry_type_id' => $image->id,
            'scope_type' => 'site',
            'scope_id' => $nested->id,
            'is_enabled' => false,
        ]);

        // And a podcast is known by its length and where to hear it — the same gap as product, for the same reason.
        foreach ([['duration_minutes', 'number', 'Duration (minutes)'], ['episode_url', 'text', 'Episode URL']] as $index => [$handle, $fieldType, $label]) {
            $storage = FieldStorage::create([
                'org_id' => $orgA->id, 'handle' => $handle, 'type' => $fieldType, 'pii_class' => 'none', 'cardinality' => 1,
            ]);

            Field::create([
                'entry_type_id' => $podcast->id, 'field_storage_id' => $storage->id, 'label' => $label, 'ordering' => $index + 1,
            ]);
        }

        /*
         * Roles, and a user who deliberately has almost none — ADR-033.
         *
         * ⚠️ THE CONTEXT IS SET PER ORG BEFORE EACH ROLE, because `Role` is `#[OrgScoped]` and
         * `EnforcesScope` refuses a write that names a scope key with no context established: "a scope key
         * nobody vouched for is how a row ends up visible to another org". The seeder is exactly the kind of
         * caller that rule exists for.
         *
         * ⚠️ AND THE TWO EXISTING ADMINS BECOME OWNERS, which is not laziness. Every browser spec signs in
         * as one of them, so without a role they would all start failing the moment the policy is wired —
         * and the honest fixture for "the person who set this installation up" is an owner. The interesting
         * fixture is the one below them.
         */
        $context->setOrg($orgA);

        $ownerA = Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true]);
        $ownerA->assignTo($user->id);
        $ownerA->assignTo($rtlUser->id);

        /*
         * ⚠️ A COPY-EDITOR, because a permission system with only owners in it is a permission system
         * nothing tests. `entry.article.view` and `entry.article.update`, and deliberately nothing else:
         * no `create`, no `delete`, no `publish`, and nothing at all on products.
         *
         * ⚠️ THE COMBINATION IS CHOSEN SO EACH REFUSAL IS SEPARATELY OBSERVABLE, which is why it includes
         * `update` rather than being the minimal grant. Without `update` the edit form is unreachable, and
         * the one thing that cannot then be measured is the status control — the enforcement point for
         * `publish`. A fixture that cannot reach the page it is meant to measure is a fixture that proves
         * the refusal before it.
         */
        $reader = Role::create(['handle' => 'copy-editor', 'name' => 'Copy editor']);
        $reader->grant(Permissions::forEntryType('article', 'view'));
        $reader->grant(Permissions::forEntryType('article', 'update'));

        /*
         * ⚠️ A GRANT ON A TYPE THAT IS DISABLED FOR THE PRIMARY SITE, which is the only fixture that can show
         * a site-specific form silently revoking one. `podcast` is turned off for `golfdom` above, so its
         * section is absent from the role form there — and the save used to read an absent section as
         * "unchecked" and revoke the grant, so editing a role's name from one site removed permissions
         * another site needed. `e2e/roles.spec.js` saves from `golfdom` and then reads the grant back from
         * `golfdom-fr`, where the section does appear.
         */
        $reader->grant(Permissions::forEntryType('podcast', 'view'));

        $readerUser = User::create([
            'name' => 'Reader User',
            'email' => 'reader@kitsune.test',
            'password' => Hash::make('password'),
        ]);
        $readerUser->sites()->attach([$en->id, $fr->id, $ar->id]);
        $readerUser->orgs()->attach($orgA->id);
        $reader->assignTo($readerUser->id);

        /*
         * ⚠️ A VIEWER, and the copy-editor cannot stand in for one. Restoring a version is an EDIT, and the view
         * page renders the same History as the edit page — so what a restore has to be refused for is the
         * absence of `update`, which the copy-editor holds on purpose. `entry.article.view` and nothing else.
         */
        $viewer = Role::create(['handle' => 'viewer', 'name' => 'Viewer']);
        $viewer->grant(Permissions::forEntryType('article', 'view'));

        $viewerUser = User::create([
            'name' => 'Viewer User',
            'email' => 'viewer@kitsune.test',
            'password' => Hash::make('password'),
        ]);
        $viewerUser->sites()->attach([$en->id]);
        $viewerUser->orgs()->attach($orgA->id);
        $viewer->assignTo($viewerUser->id);

        // The rival org gets its own owner, so the cross-org specs measure a user who is fully
        // authorised in their OWN org rather than one who is simply unauthorised everywhere.
        $context->setOrg($orgB);
        $ownerB = Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true]);
        $ownerB->assignTo($rivalUser->id);

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

        /*
         * ⚠️ MEDIA SEEDED THROUGH `MediaLibrary::store()`, NOT THROUGH `Entry::create()` PLUS A ROW. The bytes,
         * the entry, the `media_files` row and the refusals are one path (ADR-041), and a fixture that
         * assembled the rows by hand would let `e2e/media-delivery.spec.js` pass against a store path that
         * does not work. There is no upload UI yet — that is the slice after this one — so the seeder is the
         * only way a browser can be pointed at a real file.
         *
         * One of each visibility, because they are delivered by completely different mechanisms: the private
         * one streams through the panel route that authorises first, and the public one is a direct URL off
         * the linked disk with no PHP in the path at all.
         *
         * ⚠️ AND ONE OF EACH SHARING (ADR-042 decision 2). Uploads are shared across the org by default, so the logo
         * and the course photo are; the course map is kept to Golfdom, the uploader's "this site only". The pair is
         * what `e2e/media-sharing.spec.js` compares at a second site: the shared photo is listed, offered and served
         * there, and the map is not. `e2e/global-setup.js` refuses to run the suite unless they are stored that way.
         */
        $this->seedMediaFile($image, 'Course map', 'private', siteOnly: true);
        $this->seedMediaFile($image, 'Golfdom logo', 'public');
        $sharedPhoto = $this->seedMediaFile($image, 'Shared course photo', 'private');

        /*
         * ⚠️ TWO PUBLIC FILES `e2e/media-deletion.spec.js` DELETES, and no other spec touches (ADR-042 decision 5): one
         * whose delete takes it off the web, and one whose delete the spec makes the public disk refuse. The logo stays
         * where `media-delivery.spec.js` fetches it.
         */
        $this->seedMediaFile($image, 'Withdrawn scorecard', 'public');
        $this->seedMediaFile($image, 'Pinned scorecard', 'public');

        /*
         * ⚠️ A LINK FROM THE SHARED PHOTO TO AN ARTICLE ONLY GOLFDOM SEES — ADR-042 decision 2, decided by Adam: a link
         * a site cannot see is shown withheld and kept. The photo is edited from every site of the org; at `golfdom-fr`
         * this article is invisible, and a form hydrated through the scoped join dropped the link and the save that
         * followed detached it. `media-sharing.spec.js` saves the photo there and asks whether the link survived.
         *
         * On the global `image` type, so the field's storage is global too — a global type's fields are no org's.
         */
        $subjects = FieldStorage::create([
            'org_id' => null, 'handle' => 'subjects', 'type' => 'relation', 'pii_class' => 'none', 'cardinality' => -1,
            'settings' => ['targetTypes' => ['article']],
        ]);

        Field::create(['entry_type_id' => $image->id, 'field_storage_id' => $subjects->id, 'label' => 'Subjects', 'ordering' => 1]);

        $sharedPhoto->syncFieldRelations($subjects, [Entry::query()->where('slug', 'course-maintenance-week-4')->value('id')]);

        /*
         * ⚠️ AND AN ARTICLE ON EACH OF TWO OTHER SITES, so the Attach dialog can be opened where the global `image` type is
         * switched off (`golfdom-nested`) and, as the control, where it is on (`golfdom-fr`).
         */
        $context->setSite($fr);
        Entry::create(['entry_type_id' => $article->id, 'title' => 'Note from the French edition', 'slug' => 'note-fr', 'status' => 'published']);
        $context->setSite($nested);
        Entry::create(['entry_type_id' => $article->id, 'title' => 'Nested course note', 'slug' => 'nested-note', 'status' => 'published']);
        $context->setSite($en);

        /*
         * ⚠️ ONE ARTICLE THAT WAS PUBLISHED AND THEN DEMOTED, because a permission test needs a shape the
         * ordinary rows do not have. Restoring a version that was published PUBLISHES the entry, so the
         * copy-editor — who holds `update` and not `publish` — must be offered that restore as unavailable
         * rather than as a server error (ADR-033). Every other seeded article's history contains only the
         * status it was created with, so nothing here could have measured it.
         */
        $demoted = Entry::create([
            'entry_type_id' => $article->id,
            'title' => 'Bunker renovation, pulled back to draft',
            'slug' => 'bunker-renovation-pulled-back',
            'status' => 'published',
            'values' => ['summary' => 'Published once, then pulled back for a rewrite.'],
            'published_at' => now()->subDays(9),
        ]);

        $demoted->status = 'draft';
        $demoted->save();

        /*
         * ⚠️ AND REWRITTEN ONCE AS A DRAFT, so its history holds a version that restoring would actually CHANGE
         * without publishing anything. A restore onto identical state files nothing, so without this the only
         * restorable draft was the current one — and a spec proving a restore is refused could not tell the
         * refusal from a no-op.
         */
        $demoted->values = [...$demoted->values, 'summary' => 'Rewritten as a draft, and not yet republished.'];
        $demoted->save();

        // ⚠️ An RTL title in an otherwise LTR org, because issue #39's failure only
        // appears with bidirectional content in ONE admin — which ADR-018 rule 2 says is
        // the designed case. Without a row like this the direction tests would have
        // nothing to measure and would pass by asserting about LTR text in an LTR panel.
        Entry::create([
            'entry_type_id' => $article->id,
            'title' => 'صيانة الملاعب في الأسبوع السابع',
            'slug' => 'course-maintenance-week-7',
            'status' => 'published',
            'values' => [
                'summary' => 'ملاحظات الأسبوع السابع.',
                /*
                 * ⚠️ A BODY THAT OPENS IN ENGLISH AND CONTINUES IN ARABIC, because that ordering is
                 * the whole point. `dir="auto"` on the FIELD resolves from the first strong
                 * directional character in the entire document, so this exact value renders every
                 * Arabic paragraph left-to-right under a per-field direction — and looks handled.
                 * Reverse the order and a per-field direction would pass by accident.
                 *
                 * ⚠️ The list is here because `ul` must stay undirected while each `li` resolves
                 * separately: a container carrying a direction reproduces the per-field failure one
                 * level down, and only a fixture with both languages inside one list can show it.
                 *
                 * ⚠️ AND THE QUOTE CARRIES A FIXED `rtl` AROUND *TWO* PARAGRAPHS, the second opening
                 * with a Latin word. That is the one shape that can show a generated default overriding
                 * an author's decision, and both halves of its shape are load-bearing. `Entry` leaves
                 * the paragraphs inside a fixed direction undirected so they inherit it; `auto` on the
                 * second would resolve from `ACME` and render the Arabic left-to-right, undoing what the
                 * author wrote one element up.
                 *
                 * ⚠️ TWO, BECAUSE ONE PARAGRAPH PROVES NOTHING. The first block inside a block yields
                 * anyway — so a single-paragraph quote is left undirected by a rule that has nothing to
                 * do with the author's choice, and a test on it passes with the inheritance rule removed.
                 * Measured: it did.
                 */
                'body' => '<p>Maintenance notes for week seven.</p>'
                    .'<p>ملاحظات الصيانة للأسبوع السابع.</p>'
                    .'<ul><li>Mow the fairway</li><li>تنظيف الحواجز الرملية</li></ul>'
                    .'<blockquote dir="rtl"><p>اقتباس من الفريق.</p><p>ACME مرحبا بالعالم</p></blockquote>',
            ],
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

        /*
         * ⚠️ AND ONE RELATION THROUGH A FIELD, which is a different fixture from the attach above: that one
         * carries no `field_storage_id`, so it feeds the relation MANAGER and no picker. This one is what a
         * relation control is hydrated from — `relatedIdsForField()` — and it points at a product, which the
         * copy-editor may not view. See `related_products` in the field list.
         */
        $productRelation = FieldStorage::where('org_id', $orgA->id)->where('handle', 'related_products')->first();
        $mower = Entry::where('slug', 'fairway-mower')->first();

        /*
         * ⚠️ WEEK FIVE, WHICH NO OTHER SPEC NAMES, and that is the whole reason it is not week one. The
         * browser test for this saves the entry, and an entry another spec asserts the title of is a fixture
         * two tests share — the kind that fails for the wrong reason. Weeks 1 to 4 are spoken for.
         */
        $linked = Entry::where('slug', 'course-maintenance-week-5')->first();

        if ($linked !== null && $productRelation !== null && $mower !== null) {
            $linked->syncFieldRelations($productRelation, [$mower->id]);
        }

        /*
         * ⚠️ THE OTHER ORG'S OWN TYPE, and this used to be `$article` — which org A owns.
         *
         * The fixture is about SITE isolation, so the type was incidental and nobody looked. But nothing tied
         * `entries.org_id` to the org owning `entries.entry_type_id`, so the seeder planted an entry in org
         * B's site carrying org A's `article` type and every guard passed — the seeder demonstrating the gap
         * it was not testing for. `Entry::guardEntryTypeOwnership()` now refuses it, so this names
         * `confidential`, which org B actually owns.
         */
        $context->setSite($rival);
        Entry::create([
            'entry_type_id' => $confidential->id,
            'title' => 'Should never be visible from Golfdom',
            'slug' => 'rival-secret',
            'status' => 'published',
        ]);

        /*
         * ⚠️ A RELATION FIELD ON THE RIVAL'S OWN TYPE, POINTING AT IMAGES, so the other org's picker can be asked for
         * Golfdom's shared photo and shown to offer only its own (ADR-042 decision 2: refused, and not offered, on
         * another org's site).
         */
        $coverStorage = FieldStorage::create([
            'org_id' => $orgB->id, 'handle' => 'cover', 'type' => 'relation', 'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['targetTypes' => ['image']],
        ]);

        Field::create(['entry_type_id' => $confidential->id, 'field_storage_id' => $coverStorage->id, 'label' => 'Cover', 'ordering' => 1]);

        /*
         * ⚠️ AND A RIVAL MEDIA FILE WITH REAL BYTES, ON THE GLOBAL TYPE — the only fixture here that actually tests
         * the SCOPE, and it does two jobs.
         *
         * The row above carries the other org's own type, so `/c/article` excludes it on the type predicate alone
         * — `EntryResource::getEloquentQuery()` filters by the resolved type's id, and under the cross-org
         * invariant no org-B row can ever carry org A's id. Delete every scope from `Entry` and that assertion
         * still passes, which makes it a test of the invariant rather than of isolation. A GLOBAL type is the one
         * case where two orgs legitimately share an `entry_type_id`, so the type predicate cannot help and only
         * the scopes keep this row out of Golfdom's `/c/image`. The file is SHARED across the rival org, as every
         * upload is by default (ADR-042 decision 2), so no site stands in the way — it is the ORG fence that refuses
         * it: `SiteScope` admits a shared row only for its own org, and the panel's widened rule says `org_id` too.
         * `admin.spec.js` asserts that, with the rival's own list showing the file as the control.
         *
         * ⚠️ A STORED FILE, NOT `Entry::create()`. This used to be a byte-less `image` entry, and a media entry
         * with no file behind it is the state ADR-042 arranges never to exist. The bytes also let
         * `e2e/media-delivery.spec.js` measure the boundary at a URL rather than infer it: it signs in as
         * Golfdom's OWNER — who holds every grant in their own org — and asks for this file's id, so neither those
         * grants nor the type can refuse the request, and only the org boundary does.
         */
        $this->seedMediaFile($image, 'Rival private asset', 'private');

        $context->forget();
    }

    /**
     * Store one media file through the real intake path, in whatever org and site `Context` currently holds.
     *
     * ⚠️ A TEMPORARY FILE, BECAUSE `MediaLibrary::store()` TAKES A PATH AND READS IT. The 1×1 PNG below is
     * the same one the media test suites use; `getimagesize()` reads its dimensions and `finfo` sniffs it as
     * `image/png`, so the seeded row is one the allowlist genuinely accepted rather than one written around it.
     */
    private function seedMediaFile(EntryType $type, string $title, string $visibility, bool $siteOnly = false): Entry
    {
        $source = tempnam(sys_get_temp_dir(), 'kitsune-seed-');

        file_put_contents($source, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        try {
            return MediaLibrary::store($source, $title.'.png', $type, $visibility, siteOnly: $siteOnly);
        } finally {
            @unlink($source);
        }
    }
}
