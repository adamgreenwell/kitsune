<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Forms\Components\Select;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Filament\Schemas\FieldValueRenderer;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/**
 * What the relation picker offers, asked of the picker itself.
 *
 * ⚠️ A PHP TEST RATHER THAN A BROWSER ONE, deliberately, because the interesting variable is
 * the DATABASE DRIVER. `e2e/relation-picker.spec.js` drives the real control but runs against
 * SQLite only, so it cannot see a divergence between engines — and the defect this file was
 * written for was exactly that: `LIKE` is case-sensitive on PostgreSQL and case-insensitive
 * on SQLite and a default MySQL collation, so an author on Postgres could not find
 * "Course maintenance" by typing `course` while the same interaction worked elsewhere.
 * The four-engine matrix runs this; the browser suite could not have caught it.
 */
beforeEach(function (): void {
    $this->org = Org::create(['name' => 'Golfdom', 'slug' => 'golfdom']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['org_id' => $this->org->id, 'handle' => 's', 'slug' => 's', 'name' => 'S']);
    app(Context::class)->setSite($this->site);

    $this->article = EntryType::create([
        'org_id' => $this->org->id, 'handle' => 'article',
        'name' => 'Article', 'plural_name' => 'Articles',
    ]);
    $this->note = EntryType::create([
        'org_id' => $this->org->id, 'handle' => 'note',
        'name' => 'Note', 'plural_name' => 'Notes',
    ]);

    Entry::create(['entry_type_id' => $this->article->id, 'title' => 'Course maintenance in week 3']);
    Entry::create(['entry_type_id' => $this->note->id, 'title' => 'Course notes, private']);
});

afterEach(fn () => app(Context::class)->forget());

/** The picker for a relation field, built the way the admin builds it. */
function picker(Org $org, array $settings = [], int $cardinality = -1): Select
{
    $storage = FieldStorage::create([
        'org_id' => $org->id,
        'handle' => 'related_'.bin2hex(random_bytes(4)),
        'type' => 'relation',
        'pii_class' => 'none',
        'cardinality' => $cardinality,
        'settings' => $settings,
    ]);

    $component = FieldValueRenderer::formComponent(new FieldConfig($storage));

    expect($component)->toBeInstanceOf(Select::class);

    return $component;
}

it('finds a target whatever case the author types', function (): void {
    /*
     * ⚠️ THE ENGINE-DIVERGENCE CASE, and the reason the assertion uses a case that does NOT
     * match the stored title. The browser tests only ever typed substrings whose case already
     * matched, so they passed on every engine while Postgres was broken. Found by review.
     */
    $found = picker($this->org)->getSearchResults('course');

    expect(array_values($found))->toContain('Course maintenance in week 3');
});

it('finds a target by an exactly-matching substring too', function (): void {
    // The case that already worked, kept so a "fix" that only handles folded case fails.
    $found = picker($this->org)->getSearchResults('Course maintenance');

    expect(array_values($found))->toContain('Course maintenance in week 3');
});

it('offers only the entry types the field targets', function (): void {
    /*
     * The picker mirrors `RelationType::elementValidationRules()` rather than restating it: a
     * picker offering a wider set would let an author choose something the save then refuses.
     */
    $constrained = array_values(picker($this->org, ['targetTypes' => ['article']])->getSearchResults('course'));

    expect($constrained)->toContain('Course maintenance in week 3')
        ->and($constrained)->not->toContain('Course notes, private');

    // An empty target list means "any type", which is what the validation rule does.
    $unconstrained = array_values(picker($this->org)->getSearchResults('course'));

    expect($unconstrained)->toContain('Course maintenance in week 3')
        ->and($unconstrained)->toContain('Course notes, private');
});

it('does not offer another org\'s entries', function (): void {
    /*
     * `Entry` is `#[SiteScoped]`, so the search inherits the scope rather than re-deriving it.
     * Asserted anyway: ADR-021 says cross-org leakage has no framework safety net, and a
     * picker is a search box pointed at a table.
     */
    $rival = Org::create(['name' => 'Rival', 'slug' => 'rival']);
    app(Context::class)->setOrg($rival);
    $rivalSite = Site::create(['org_id' => $rival->id, 'handle' => 'r', 'slug' => 'r', 'name' => 'R']);
    app(Context::class)->setSite($rivalSite);
    $rivalType = EntryType::create([
        'org_id' => $rival->id, 'handle' => 'article',
        'name' => 'Article', 'plural_name' => 'Articles',
    ]);
    Entry::create(['entry_type_id' => $rivalType->id, 'title' => 'Course secrets of a rival']);

    app(Context::class)->setOrg($this->org);
    app(Context::class)->setSite($this->site);

    $found = array_values(picker($this->org)->getSearchResults('course'));

    expect($found)->toContain('Course maintenance in week 3')
        ->and($found)->not->toContain('Course secrets of a rival');
});

it('states a finite cardinality as a bound on the control', function (): void {
    /*
     * ⚠️ WITHOUT THIS THE BOUND WAS ENFORCED ONLY AFTER THE AUTHOR HAD DONE THE WORK.
     * `multiple()` is unbounded, so a relation declared with cardinality 2 let an author pick
     * a third target; the save then reached `EntryRelation::guardCardinality()`, which THROWS.
     * They saw a 500 instead of "you may select at most 2".
     *
     * The repeater path already applied `maxItems()`, so one control kind honoured the
     * declared bound and the other did not. Found by review — and missed here because the
     * seeded relation field is unlimited, so no fixture exercised a finite one.
     */
    expect(picker($this->org, [], 2)->getMaxItems())->toBe(2);

    // -1 is the explicit unlimited, and must not become a bound of any kind.
    expect(picker($this->org, [], -1)->getMaxItems())->toBeNull();
});

it('is not a multi-select at all when cardinality is 1', function (): void {
    // The other end of the same question: one target is a scalar control, not a bounded list.
    $single = picker($this->org, [], 1);

    expect($single->isMultiple())->toBeFalse()
        ->and($single->getMaxItems())->toBeNull();
});
