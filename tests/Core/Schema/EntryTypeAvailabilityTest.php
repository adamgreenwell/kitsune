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
use Kitsune\Core\Models\SiteGroup;
use Kitsune\Core\Tenancy\Context;

/*
 * ADR-022: availability is sparse and inherited, exactly like settings —
 * a row exists only where someone made a decision, and absent at every
 * level means enabled.
 *
 * Written after a Codex review caught the routing middleware ignoring this
 * entirely, while its own docblock claimed it did not.
 */

beforeEach(function (): void {
    $this->org = Org::create(['name' => 'Golfdom', 'slug' => 'golfdom']);
    app(Context::class)->setOrg($this->org);

    $this->group = SiteGroup::create(['org_id' => $this->org->id, 'handle' => 'g', 'name' => 'Group']);
    $this->site = Site::create(['org_id' => $this->org->id, 'site_group_id' => $this->group->id, 'handle' => 'fr', 'slug' => 'fr', 'name' => 'FR']);

    $this->type = EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
});

afterEach(fn () => app(Context::class)->forget());

it('is enabled when nothing has been decided anywhere', function (): void {
    expect(EntryTypeAvailability::isEnabledFor($this->type, $this->site))->toBeTrue();
});

it('is disabled when the site says so', function (): void {
    EntryTypeAvailability::create([
        'entry_type_id' => $this->type->id, 'scope_type' => 'site',
        'scope_id' => $this->site->id, 'is_enabled' => false,
    ]);

    expect(EntryTypeAvailability::isEnabledFor($this->type, $this->site))->toBeFalse();
});

it('inherits a decision made at the org level', function (): void {
    EntryTypeAvailability::create([
        'entry_type_id' => $this->type->id, 'scope_type' => 'org',
        'scope_id' => $this->org->id, 'is_enabled' => false,
    ]);

    expect(EntryTypeAvailability::isEnabledFor($this->type, $this->site))->toBeFalse();
});

it('lets the most specific level win', function (): void {
    // A French edition re-enabling what the brand switched off.
    EntryTypeAvailability::create(['entry_type_id' => $this->type->id, 'scope_type' => 'org', 'scope_id' => $this->org->id, 'is_enabled' => false]);
    EntryTypeAvailability::create(['entry_type_id' => $this->type->id, 'scope_type' => 'site_group', 'scope_id' => $this->group->id, 'is_enabled' => false]);
    EntryTypeAvailability::create(['entry_type_id' => $this->type->id, 'scope_type' => 'site', 'scope_id' => $this->site->id, 'is_enabled' => true]);

    expect(EntryTypeAvailability::isEnabledFor($this->type, $this->site))->toBeTrue();
});

it('is available with no site, which is the console and API path', function (): void {
    expect(EntryTypeAvailability::isEnabledFor($this->type, null))->toBeTrue();
});
