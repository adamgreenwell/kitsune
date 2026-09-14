<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Models\EntryType;

/*
 * The entry list is headed by its type, not by the model behind every type — ADR-012.
 *
 * One Resource serves every entry type, so Filament's default label is the same word for all of them: a list of
 * articles was headed "Entries" beneath a sidebar item reading "Articles". The title, the breadcrumb and "Create …"
 * all read these two methods, which is why the test is on the methods rather than on one page that uses them.
 * `e2e/admin.spec.js` asserts the rendered heading.
 */

it('names the list after the type the request is about', function (): void {
    app()->instance(EntryType::class, new EntryType([
        'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles',
    ]));

    expect(EntryResource::getModelLabel())->toBe('Article')
        ->and(EntryResource::getPluralModelLabel())->toBe('Articles')
        // What the list title and the breadcrumb are built from.
        ->and(EntryResource::getTitleCasePluralModelLabel())->toBe('Articles')
        ->and(EntryResource::getBreadcrumb())->toBe('Articles');
});

it('keeps the operator\'s capitalisation, because title case only ever capitalises', function (): void {
    app()->instance(EntryType::class, new EntryType([
        'handle' => 'faq', 'name' => 'FAQ', 'plural_name' => 'FAQs',
    ]));

    expect(EntryResource::getTitleCaseModelLabel())->toBe('FAQ')
        ->and(EntryResource::getTitleCasePluralModelLabel())->toBe('FAQs');
});

it('falls back to the generic label where no type is bound, rather than failing', function (): void {
    /*
     * ⚠️ The Resource is constructed for pages outside `/c/{type}` too — the dashboard among them — and
     * `IdentifyEntryType` binds nothing there. A label that reached for the type unguarded would 500 them.
     */
    app()->forgetInstance(EntryType::class);

    expect(app()->bound(EntryType::class))->toBeFalse()
        ->and(EntryResource::getModelLabel())->toBe('entry')
        ->and(EntryResource::getPluralModelLabel())->toBe('entries');
});
