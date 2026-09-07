<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/*
 * The list behind both the admin sidebar and the headless API (ADR-002).
 *
 * It is memoised because Filament asks for it five times per request —
 * measured, not estimated — and a memo whose key cannot tell two sites apart
 * is worse than no memo at all.
 */

beforeEach(function (): void {
    $this->org = Org::create(['name' => 'Publisher', 'slug' => 'publisher']);
    app(Context::class)->setOrg($this->org);

    $this->siteA = Site::create(['org_id' => $this->org->id, 'handle' => 'a', 'slug' => 'pub-a', 'name' => 'A']);
    $this->siteB = Site::create(['org_id' => $this->org->id, 'handle' => 'b', 'slug' => 'pub-b', 'name' => 'B']);

    $this->article = EntryType::create([
        'org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles',
    ]);
    $this->product = EntryType::create([
        'org_id' => $this->org->id, 'handle' => 'product', 'name' => 'Product', 'plural_name' => 'Products',
    ]);

    // Products exist for the org but are turned off on site B (ADR-022).
    EntryTypeAvailability::create([
        'entry_type_id' => $this->product->id,
        'scope_type' => 'site',
        'scope_id' => $this->siteB->id,
        'is_enabled' => false,
    ]);
});

afterEach(fn () => app(Context::class)->forget());

it('lists what a site may use', function (): void {
    expect(EntryType::visibleFor($this->siteA)->pluck('handle')->all())
        ->toBe(['article', 'product']);
});

it('leaves out a type the site has disabled', function (): void {
    expect(EntryType::visibleFor($this->siteB)->pluck('handle')->all())
        ->toBe(['article']);
});

it('cannot be confused by two sites arriving under one object identity', function (): void {
    /*
     * ⚠️ Regression test with a specific mechanism.
     *
     * once() hashes the closure's captured variables, and hashes an object
     * by spl_object_id. PHP reuses those handles as soon as an object is
     * collected — so a memo keyed on the Site OBJECT cannot reliably tell
     * two sites apart within one process. Under PHP-FPM the process dies
     * between requests and this never bites; under Octane or a queue worker
     * it does, and site B is shown site A's navigation.
     *
     * Rather than wait for the garbage collector to hand out the same handle
     * — which it does, but not on demand — this reuses one PHP object for
     * both sites. That is exactly what a recycled id looks like to once():
     * one identity, two sites. Deterministic, and it fails without the
     * scalar site key in the memo key.
     */
    $site = Site::find($this->siteA->id);

    expect(EntryType::visibleFor($site)->pluck('handle')->all())->toBe(['article', 'product']);

    $site->setRawAttributes(Site::find($this->siteB->id)->getAttributes(), true);

    expect(EntryType::visibleFor($site)->pluck('handle')->all())->toBe(['article']);
});

it('memoises, because Filament asks five times per request', function (): void {
    DB::enableQueryLog();

    EntryType::visibleFor($this->siteA);
    $first = count(DB::getQueryLog());

    for ($i = 0; $i < 4; $i++) {
        EntryType::visibleFor($this->siteA);
    }

    expect(count(DB::getQueryLog()))->toBe($first);
});
