<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Models\SiteGroup;
use Kitsune\Core\Tenancy\Context;

beforeEach(function (): void {
    $this->org = Org::create(['name' => 'Offers', 'slug' => 'offers']);
    app(Context::class)->setOrg($this->org);
    $this->group = SiteGroup::create(['org_id' => $this->org->id, 'handle' => 'brand', 'name' => 'Brand']);
    $this->site = Site::create(['org_id' => $this->org->id, 'site_group_id' => $this->group->id, 'handle' => 'one', 'slug' => 'one', 'name' => 'One']);
    app(Context::class)->setSite($this->site);
    $this->type = EntryType::create(['org_id' => $this->org->id, 'handle' => 'document', 'name' => 'Document', 'plural_name' => 'Documents', 'is_media' => true]);
    $this->entry = DB::table('entries')->insertGetId(['org_id' => $this->org->id, 'site_id' => $this->site->id,
        'entry_type_id' => $this->type->id, 'type_handle' => 'document', 'title' => 'One']);
});

afterEach(fn () => app(Context::class)->forget());

$precedence = [];
foreach ([null, false, true] as $site) {
    foreach ([null, false, true] as $group) {
        foreach ([null, false, true] as $org) {
            $precedence[] = [$site, $group, $org];
        }
    }
}

it('filters entries with exactly the batched availability precedence on every supported engine', function (?bool $site, ?bool $group, ?bool $org): void {
    foreach ([['site', $this->site->id, $site], ['site_group', $this->group->id, $group], ['org', $this->org->id, $org]] as [$scope, $id, $choice]) {
        if ($choice !== null) {
            EntryTypeAvailability::create(['entry_type_id' => $this->type->id, 'scope_type' => $scope, 'scope_id' => $id, 'is_enabled' => $choice]);
        }
        // A decision elsewhere never overrides the current scope.
        EntryTypeAvailability::create(['entry_type_id' => $this->type->id, 'scope_type' => $scope, 'scope_id' => 900000 + $id, 'is_enabled' => false]);
    }
    $expected = $site ?? $group ?? $org ?? true;
    $map = EntryTypeAvailability::enabledMapFor([$this->type->id], $this->site->id, $this->group->id, $this->org->id);
    $ids = EntryTypeAvailability::constrainEnabledEntries(Entry::query(), $this->site)->pluck('entries.id')->all();

    expect($map[$this->type->id])->toBe($expected)
        ->and($ids)->toBe($expected ? [$this->entry] : []);
})->with($precedence);

it('uses fixed wrapped prefixed identifiers for both correlated availability levels', function (): void {
    $connection = DB::connection();
    $prefix = $connection->getTablePrefix();
    try {
        $connection->setTablePrefix('probe_');
        $sql = EntryTypeAvailability::constrainEnabledEntries(Entry::query(), $this->site)->toSql();
        $plain = str_replace(['"', '`'], '', $sql);
        expect($plain)->toContain('probe_entry_type_availability as probe_disabled_type',
            'probe_disabled_type.entry_type_id = probe_entries.entry_type_id',
            'probe_entry_type_availability as probe_higher_type', 'probe_higher_type.entry_type_id = probe_entries.entry_type_id');
    } finally {
        $connection->setTablePrefix($prefix);
    }
});
