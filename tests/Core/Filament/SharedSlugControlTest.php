<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Filament\Schemas\FieldValueRenderer;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/*
 * An org-shared entry is offered no control that writes `slug` — ADR-042 decision 2.
 *
 * The built-in slug input is asserted where the edit page renders, in the browser suite. An org-defined slug-typed
 * field's control is built here, by `FieldValueRenderer`, and it writes the same column — so it is withheld by the same
 * rule, and it is asserted here.
 */

beforeEach(function (): void {
    $this->org = Org::create(['slug' => 'controls', 'name' => 'Controls']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);

    $this->type = EntryType::create([
        'org_id' => $this->org->getKey(), 'handle' => 'photo', 'name' => 'Photo', 'plural_name' => 'Photos', 'is_media' => true,
    ]);

    $this->permalink = FieldStorage::create([
        'org_id' => $this->org->getKey(), 'handle' => 'permalink', 'type' => 'slug', 'pii_class' => 'none', 'cardinality' => 1,
    ]);
    $this->summary = FieldStorage::create([
        'org_id' => $this->org->getKey(), 'handle' => 'summary', 'type' => 'text', 'pii_class' => 'none', 'cardinality' => 1,
    ]);

    $this->shared = Entry::create(['entry_type_id' => $this->type->getKey(), 'site_id' => null, 'title' => 'Shared']);
    $this->kept = Entry::create(['entry_type_id' => $this->type->getKey(), 'title' => 'Kept', 'slug' => 'kept']);
});

afterEach(fn () => app(Context::class)->forget());

function slugControlHiddenFor(FieldStorage $storage, Entry $record): bool
{
    return FieldValueRenderer::formComponent(new FieldConfig($storage))->model($record)->isHidden();
}

it('withholds a slug-typed field from a shared entry, and offers it on a site entry and a new one', function (): void {
    expect(slugControlHiddenFor($this->permalink, $this->shared))->toBeTrue()
        ->and(slugControlHiddenFor($this->permalink, $this->kept))->toBeFalse()
        /* The create page's record is one that does not exist yet. */
        ->and(slugControlHiddenFor($this->permalink, new Entry))->toBeFalse();
});

/** The control: only the column that is refused is withheld. */
it('offers every other control on a shared entry', function (): void {
    expect(slugControlHiddenFor($this->summary, $this->shared))->toBeFalse();
});

/** ⚠️ THE STORED ROW DECIDES, NOT THE INSTANCE. An attribute anybody can set is not a way to be offered the control. */
it('asks the stored row, not the instance', function (): void {
    $forgedShared = Entry::query()->findOrFail($this->kept->getKey());
    $forgedShared->site_id = null;

    $forgedKept = Entry::query()->findOrFail($this->shared->getKey());
    $forgedKept->site_id = $this->site->getKey();

    expect(slugControlHiddenFor($this->permalink, $forgedShared))->toBeFalse()
        ->and(slugControlHiddenFor($this->permalink, $forgedKept))->toBeTrue()
        ->and(DB::table('entries')->whereNull('site_id')->count())->toBe(1);
});
