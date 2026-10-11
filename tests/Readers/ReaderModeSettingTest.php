<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Models\SiteGroup;
use Kitsune\Core\Readers\ReaderAccounts;
use Kitsune\Core\Readers\ReaderMode;
use Kitsune\Core\Settings\SettingsGuard;
use Kitsune\Core\Settings\SettingsResolver;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;

/*
 * The setting `reader_accounts` (ADR-022, ADR-037 as built): `off` by default, and nothing else stored.
 *
 * And `reader_addresses` (ADR-037 decision 1): `hidden` by default, the organisation's alone — never a site group's or
 * a site's — nothing else stored, and anything else read as hidden.
 */

beforeEach(function (): void {
    $this->world = ReaderFixture::world();
});

it('ships off', function (): void {
    expect(config('kitsune.settings.reader_accounts'))->toBe('off')
        ->and(app(ReaderAccounts::class)->mode($this->world['sites']['golfdom']))->toBe(ReaderMode::Off);
});

it('stores each of its three modes, and inherits them from the org', function (string $mode): void {
    ReaderFixture::mode($this->world['golfdom'], $mode);

    expect(app(ReaderAccounts::class)->mode($this->world['sites']['golfdom']))->toBe(ReaderMode::from($mode));
})->with(['off', 'sign-in', 'open']);

it('refuses to store anything else, in words', function (mixed $value, string $named): void {
    $site = $this->world['sites']['golfdom'];

    expect(fn () => $site->update(['settings' => ['reader_accounts' => $value]]))
        ->toThrow(RuntimeException::class, "Refusing [reader_accounts] on Site {$site->getKey()}: it is off, sign-in or open, and {$named} is none of them. To inherit instead, revert the key rather than storing an empty one.");
})->with([
    'another word' => ['on', '"on"'],
    'upper case' => ['OPEN', '"OPEN"'],
    'empty' => ['', '""'],
    'true' => [true, 'bool'],
    'null' => [null, 'null'],
    'a list' => [['open'], 'array'],
]);

it('refuses a host default that is none of them, when the resolver is first built', function (): void {
    expect(fn () => SettingsGuard::check(['reader_accounts' => 'yes'], 'the configured defaults (config/kitsune.php)'))
        ->toThrow(RuntimeException::class, 'Refusing [reader_accounts] on the configured defaults (config/kitsune.php): it is off, sign-in or open, and "yes" is none of them.');
});

it('reads a host settings map that dropped the key as off', function (): void {
    config(['kitsune.settings' => ['timezone' => 'UTC']]);
    app()->forgetScopedInstances();

    expect(app(ReaderAccounts::class)->mode($this->world['sites']['golfdom']))->toBe(ReaderMode::Off);
});

// ---- reader_addresses -----------------------------------------------------------------------------------------------

/**
 * The `reader_addresses` setting at an org, stored as an operator's write would store it — or tried at a site or a site
 * group, through the same save.
 */
function readerModeSettingAddresses(Org|SiteGroup|Site $scope, mixed $value): void
{
    $settings = $scope->settings ?? [];
    $settings['reader_addresses'] = $value;
    $scope->settings = $settings;

    try {
        $scope->save();
    } finally {
        ReaderFixture::forget();
    }
}

/**
 * A site group of golfdom's with golfdom-fr in it — the level between the org and the site.
 *
 * @param  array{golfdom: Org, rival: Org, sites: array<string, Site>}  $world
 */
function readerModeSettingGroup(array $world): SiteGroup
{
    app(Context::class)->forget()->setOrg($world['golfdom']);

    try {
        $group = SiteGroup::create(['org_id' => $world['golfdom']->getKey(), 'handle' => 'golfdom-intl', 'name' => 'Golfdom International']);
        $world['sites']['golfdom-fr']->update(['site_group_id' => $group->getKey()]);

        return $group;
    } finally {
        ReaderFixture::forget();
    }
}

/** The words `SettingsGuard` refuses a site or a site group, naming the holder. */
function readerModeSettingOrgOnly(string $holder): string
{
    return "Refusing [reader_addresses] on {$holder}: it is set for a whole organisation, whose readers they are — never for a site group or a site, where a sibling would name the same readers. Set it on the organisation instead.";
}

it('ships addresses hidden', function (): void {
    expect(config('kitsune.settings.reader_addresses'))->toBe('hidden')
        ->and(app(ReaderAccounts::class)->addresses($this->world['sites']['golfdom']))->toBeFalse();
});

it('stores each of its two values, and inherits them from the org', function (string $value, bool $shown): void {
    readerModeSettingAddresses($this->world['golfdom'], $value);

    expect(app(ReaderAccounts::class)->addresses($this->world['sites']['golfdom']))->toBe($shown)
        ->and(app(ReaderAccounts::class)->addresses($this->world['sites']['golfdom-fr']))->toBe($shown)
        // The other org's sites read their own platform default.
        ->and(app(ReaderAccounts::class)->addresses($this->world['sites']['rival']))->toBeFalse();
})->with(['hidden' => ['hidden', false], 'shown' => ['shown', true]]);

it('reads a platform default of shown as shown, and an organisation\'s hidden over it', function (): void {
    config(['kitsune.settings.reader_addresses' => 'shown']);
    app()->forgetScopedInstances();

    expect(app(ReaderAccounts::class)->addresses($this->world['sites']['golfdom']))->toBeTrue()
        ->and(app(ReaderAccounts::class)->addresses($this->world['sites']['rival']))->toBeTrue();

    readerModeSettingAddresses($this->world['golfdom'], 'hidden');

    expect(app(ReaderAccounts::class)->addresses($this->world['sites']['golfdom']))->toBeFalse()
        ->and(app(ReaderAccounts::class)->addresses($this->world['sites']['golfdom-fr']))->toBeFalse()
        // ⚠️ The other org, from its side: golfdom's choice is golfdom's.
        ->and(app(ReaderAccounts::class)->addresses($this->world['sites']['rival']))->toBeTrue();
});

it('decides per organisation: a site cannot hide them beside a sibling that shows them, nor show them beside one that hides them', function (string $org, string $tried): void {
    readerModeSettingAddresses($this->world['golfdom'], $org);
    $fr = $this->world['sites']['golfdom-fr'];

    expect(fn () => readerModeSettingAddresses($fr, $tried))->toThrow(RuntimeException::class, readerModeSettingOrgOnly("Site {$fr->getKey()}"));

    app()->forgetScopedInstances();

    expect(DB::table('sites')->where('id', $fr->getKey())->value('settings'))->toBeNull()
        ->and(app(ReaderAccounts::class)->addresses($fr))->toBe($org === 'shown')
        ->and(app(ReaderAccounts::class)->addresses($this->world['sites']['golfdom']))->toBe($org === 'shown');
})->with([
    'a site hiding them under an org that shows them' => ['shown', 'hidden'],
    'a site showing them under an org that hides them' => ['hidden', 'shown'],
]);

it('refuses an address setting at a site or a site group whatever its value, in words, at every door', function (string $door, mixed $value): void {
    readerModeSettingAddresses($this->world['golfdom'], 'shown');
    $group = readerModeSettingGroup($this->world);
    $fr = $this->world['sites']['golfdom-fr'];
    $map = ['reader_addresses' => $value];

    [$attempt, $holder] = match ($door) {
        'a site saved' => [fn () => $fr->update(['settings' => $map]), "Site {$fr->getKey()}"],
        'a site created' => [function () use ($map): void {
            app(Context::class)->forget()->setOrg($this->world['golfdom']);

            try {
                Site::create(['handle' => 'golfdom-de', 'slug' => 'golfdom-de', 'name' => 'Golfdom DE', 'locale' => 'de', 'base_url' => '/golfdom-de', 'settings' => $map]);
            } finally {
                ReaderFixture::forget();
            }
        }, 'Site (new)'],
        'a site group saved' => [fn () => $group->update(['settings' => $map]), "SiteGroup {$group->getKey()}"],
        'a site written past the per-row refusal' => [fn () => Site::withoutScopeBecause('the test writes past the per-row refusal', fn ($query) => $query
            ->whereKey($fr->getKey())
            ->update(['settings' => json_encode($map)])), 'Site (new)'],
        'a site group written past the per-row refusal' => [fn () => SiteGroup::withoutScopeBecause('the test writes past the per-row refusal', fn ($query) => $query
            ->whereKey($group->getKey())
            ->update(['settings' => json_encode($map)])), 'SiteGroup (new)'],
    };

    expect($attempt)->toThrow(RuntimeException::class, readerModeSettingOrgOnly($holder))
        ->and(DB::table('sites')->where('id', $fr->getKey())->value('settings'))->toBeNull()
        ->and(DB::table('site_groups')->where('id', $group->getKey())->value('settings'))->toBeNull()
        ->and(DB::table('sites')->where('handle', 'golfdom-de')->exists())->toBeFalse();

    app()->forgetScopedInstances();

    // Still the organisation's choice, on the site the write was aimed at and beside it.
    expect(app(ReaderAccounts::class)->addresses($fr))->toBeTrue()
        ->and(app(ReaderAccounts::class)->addresses($this->world['sites']['golfdom']))->toBeTrue();
})->with([
    'a site saved',
    'a site created',
    'a site group saved',
    'a site written past the per-row refusal',
    'a site group written past the per-row refusal',
])->with([
    'shown' => ['shown'],
    'hidden' => ['hidden'],
    // Refused for where it is before for what it is.
    'a word it does not have' => ['SHOWN'],
]);

it('judges the level a map is held at: the same map is the organisation\'s or the defaults\', and refused below them', function (): void {
    $map = ['reader_addresses' => 'shown'];

    expect(fn () => SettingsGuard::check($map, 'Org 1', 'org'))->not->toThrow(RuntimeException::class)
        ->and(fn () => SettingsGuard::check($map, 'the configured defaults (config/kitsune.php)'))->not->toThrow(RuntimeException::class)
        ->and(fn () => SettingsGuard::check($map, 'SiteGroup 1', 'site_group'))->toThrow(RuntimeException::class, readerModeSettingOrgOnly('SiteGroup 1'))
        ->and(fn () => SettingsGuard::check($map, 'Site 1', 'site'))->toThrow(RuntimeException::class, readerModeSettingOrgOnly('Site 1'))
        // The mode is not the organisation's alone: a site holds its own.
        ->and(fn () => SettingsGuard::check(['reader_accounts' => 'open'], 'Site 1', 'site'))->not->toThrow(RuntimeException::class);

    // Each holder is judged at its own level.
    expect(SettingsGuard::levelOf($this->world['golfdom']))->toBe('org')
        ->and(SettingsGuard::levelOf(new SiteGroup))->toBe('site_group')
        ->and(SettingsGuard::levelOf($this->world['sites']['golfdom']))->toBe('site');
});

it('refuses to store any other address setting at the organisation or as the platform\'s default, in words', function (string $door, mixed $value, string $named): void {
    $site = $this->world['sites']['golfdom'];
    $org = $this->world['golfdom'];

    [$attempt, $holder] = match ($door) {
        'an org saved' => [fn () => $org->update(['settings' => ['reader_addresses' => $value]]), "Org {$org->getKey()}"],
        'the configured defaults, when the resolver is first built' => [function () use ($value): void {
            config(['kitsune.settings.reader_addresses' => $value]);
            app()->forgetScopedInstances();
            app(SettingsResolver::class);
        }, 'the configured defaults (config/kitsune.php)'],
    };

    expect($attempt)
        ->toThrow(RuntimeException::class, "Refusing [reader_addresses] on {$holder}: it is hidden or shown, and {$named} is neither. To inherit instead, revert the key rather than storing an empty one.")
        ->and(DB::table('sites')->where('id', $site->getKey())->value('settings'))->toBeNull()
        ->and(DB::table('orgs')->where('id', $org->getKey())->value('settings'))->toBeNull();
})->with([
    'an org saved',
    'the configured defaults, when the resolver is first built',
])->with([
    'another word' => ['visible', '"visible"'],
    'upper case' => ['SHOWN', '"SHOWN"'],
    'padded' => [' shown', '" shown"'],
    // The console's word for a revert, which is never stored.
    'inherit' => ['inherit', '"inherit"'],
    'a mode\'s word' => ['open', '"open"'],
    'empty' => ['', '""'],
    'true' => [true, 'bool'],
    'null' => [null, 'null'],
    'a list' => [['shown'], 'array'],
]);

it('reads a host settings map that dropped the key as hidden', function (): void {
    config(['kitsune.settings' => ['timezone' => 'UTC']]);
    app()->forgetScopedInstances();

    expect(app(ReaderAccounts::class)->addresses($this->world['sites']['golfdom']))->toBeFalse();
});

it('reads an organisation\'s address setting nothing could have stored as hidden', function (string $stored): void {
    $id = $this->world['golfdom']->getKey();

    // Below Eloquent, as only a hand-written query could: `SettingsGuard` refuses it on every save.
    DB::table('orgs')->where('id', $id)->update(['settings' => json_encode(['reader_addresses' => json_decode($stored)])]);
    app()->forgetScopedInstances();

    expect(app(ReaderAccounts::class)->addresses($this->world['sites']['golfdom']))->toBeFalse();

    // ⚠️ The control: the same write of `shown` is read as shown, so the hidden above is the value's doing, not the path's.
    DB::table('orgs')->where('id', $id)->update(['settings' => json_encode(['reader_addresses' => 'shown'])]);
    app()->forgetScopedInstances();

    expect(app(ReaderAccounts::class)->addresses($this->world['sites']['golfdom']))->toBeTrue();
})->with([
    'upper case' => ['"SHOWN"'],
    'title case' => ['"Shown"'],
    'padded' => ['" shown"'],
    'true' => ['true'],
    'one' => ['1'],
    'a list' => ['["shown"]'],
    'a map' => ['{"shown": true}'],
]);

it('reads any address setting a site or a site group holds as hidden, even under an org that shows them — a value only a write below Eloquent could leave', function (string $level, string $stored): void {
    readerModeSettingAddresses($this->world['golfdom'], 'shown');
    $group = readerModeSettingGroup($this->world);
    $fr = $this->world['sites']['golfdom-fr'];
    [$table, $id] = $level === 'at a site' ? ['sites', $fr->getKey()] : ['site_groups', $group->getKey()];

    // Below Eloquent, as only a hand-written query could: `SettingsGuard` refuses it on every save, whatever the value.
    DB::table($table)->where('id', $id)->update(['settings' => json_encode(['reader_addresses' => json_decode($stored)])]);
    app()->forgetScopedInstances();

    expect(app(ReaderAccounts::class)->addresses($fr))->toBeFalse()
        // The sibling, outside the group and holding nothing, reads the organisation's.
        ->and(app(ReaderAccounts::class)->addresses($this->world['sites']['golfdom']))->toBeTrue();

    // ⚠️ The control: the same row emptied the same way reads the organisation's shown, so the hidden above is the level's doing, not the path's.
    DB::table($table)->where('id', $id)->update(['settings' => null]);
    app()->forgetScopedInstances();

    expect(app(ReaderAccounts::class)->addresses($fr))->toBeTrue();
})->with(['at a site', 'at a site group'])->with([
    // ⚠️ Even the word an organisation would store: the level is refused, not the value.
    'shown' => ['"shown"'],
    'hidden' => ['"hidden"'],
    'upper case' => ['"SHOWN"'],
    'title case' => ['"Shown"'],
    'padded' => ['" shown"'],
    'true' => ['true'],
    'one' => ['1'],
    'a list' => ['["shown"]'],
    'a map' => ['{"shown": true}'],
]);
