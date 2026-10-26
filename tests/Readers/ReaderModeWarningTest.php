<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Media\MediaVisibility;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Models\SiteGroup;
use Kitsune\Core\Readers\ReaderAccounts;
use Kitsune\Core\Readers\ReaderMode;
use Kitsune\Core\Readers\ReaderModeImpact;
use Kitsune\Core\Settings\SettingsResolver;
use Kitsune\Core\Settings\SettingsWriter;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Kitsune\Core\Tests\Fixtures\TestUser;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);
    Storage::fake(MediaDisks::PRIVATE);
    Storage::fake('public');
    $this->world = ReaderFixture::world();
});

afterEach(function (): void {
    expect(app(Context::class)->orgId())->toBeNull();
});

/** A real stored offer, without reading any reader or granting access. */
function modeWarningOffer(Site $site): Entry
{
    app(Context::class)->setSite($site);
    $type = EntryType::query()->firstOrCreate(
        ['org_id' => $site->org_id, 'handle' => 'mode-warning-files'],
        ['name' => 'File', 'plural_name' => 'Files', 'is_media' => true],
    );
    $path = tempnam(sys_get_temp_dir(), 'kitsune-mode-warning-');
    file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

    try {
        $entry = MediaLibrary::store($path, 'private-canary.png', $type, 'private', title: 'PRIVATE TITLE CANARY', siteOnly: true);
        MediaVisibility::offer($entry, 'private.name-canary');

        return $entry;
    } finally {
        @unlink($path);
        ReaderFixture::forget();
    }
}

/** @return array{int, string} */
function modeWarningRun(string $value, ?string $site = 'golfdom', bool $force = false): array
{
    $parameters = ['command' => 'kitsune:readers', 'action' => 'mode', 'value' => $value, '--org' => 'golfdom', '--no-interaction' => true];
    if ($site !== null) {
        $parameters['--site'] = $site;
    }
    if ($force) {
        $parameters['--force'] = true;
    }
    $status = app(ConsoleKernel::class)->handle(new ArrayInput($parameters), $output = new BufferedOutput);

    return [$status, trim($output->fetch())];
}

it('refuses the actual disable command before writing and counts only this org and site', function (): void {
    ReaderFixture::mode($this->world['golfdom'], 'open');
    $entry = modeWarningOffer($this->world['sites']['golfdom']);
    modeWarningOffer($this->world['sites']['golfdom-fr']);
    modeWarningOffer($this->world['sites']['golfdom-fr']);
    modeWarningOffer($this->world['sites']['rival']);
    $audited = DB::table('audit_log')->where('action', SettingsWriter::SET)->count();

    [$status, $output] = modeWarningRun('off');

    expect($status)->toBe(1)
        ->and($output)->toContain('offered files: 1; affected sites: 1', 'download links', '--force', 'Nothing was written.')
        ->and($output)->not->toContain('PRIVATE TITLE CANARY', 'private.name-canary', 'private-canary.png', 'rival', 'golfdom-fr')
        ->and(app(ReaderAccounts::class)->mode($this->world['sites']['golfdom']))->toBe(ReaderMode::Open)
        ->and(DB::table('audit_log')->where('action', SettingsWriter::SET)->count())->toBe($audited)
        ->and(MediaFile::query()->where('entry_id', $entry->getKey())->value('reader_entitlement'))->toBe('private.name-canary');
});

it('prints the consequence before an acknowledged change and keeps offers and file bytes', function (): void {
    ReaderFixture::mode($this->world['golfdom'], 'open');
    $entry = modeWarningOffer($this->world['sites']['golfdom']);
    $file = MediaFile::query()->where('entry_id', $entry->getKey())->firstOrFail();
    $bytes = Storage::disk($file->disk)->get($file->path);
    $reader = ReaderFixture::reader($this->world['golfdom'], 'warning-holder@kitsune.test');
    EntitlementFixture::plant($this->world['sites']['golfdom'], (string) $reader->getKey(), 'private.name-canary');
    EntitlementFixture::plant($this->world['sites']['golfdom-fr'], (string) $reader->getKey(), 'sibling.name-canary');
    $grants = DB::table('entitlements')->orderBy('id')->get()->all();
    $audited = DB::table('audit_log')->where('action', SettingsWriter::SET)->count();

    [$status, $output] = modeWarningRun('off', force: true);

    expect($status)->toBe(0)
        ->and($output)->toContain('offered files: 1; affected sites: 1', 'download links', 'Reader accounts on [golfdom]: off.')
        ->and(strpos($output, 'download links'))->toBeLessThan(strpos($output, 'Reader accounts on'))
        ->and(app(ReaderAccounts::class)->mode($this->world['sites']['golfdom']))->toBe(ReaderMode::Off)
        ->and($file->fresh()->reader_entitlement)->toBe('private.name-canary')
        ->and(Storage::disk($file->disk)->get($file->path))->toBe($bytes)
        ->and(DB::table('entitlements')->orderBy('id')->get()->all())->toEqual($grants)
        ->and(DB::table('audit_log')->where('action', SettingsWriter::SET)->count())->toBe($audited + 1);
});

it('changes an unaffected site without a warning even when a sibling and foreign org offer files', function (): void {
    ReaderFixture::mode($this->world['golfdom'], 'open');
    modeWarningOffer($this->world['sites']['golfdom']);
    modeWarningOffer($this->world['sites']['rival']);

    expect(modeWarningRun('off', 'golfdom-fr'))->toBe([0, 'Reader accounts on [golfdom-fr]: off.'])
        ->and(app(ReaderAccounts::class)->mode($this->world['sites']['golfdom']))->toBe(ReaderMode::Open);
});

it('previews an org change through lower-level overrides rather than counting all its offers', function (): void {
    ReaderFixture::mode($this->world['golfdom'], 'open');
    ReaderFixture::mode($this->world['sites']['golfdom-fr'], 'sign-in');
    modeWarningOffer($this->world['sites']['golfdom']);
    modeWarningOffer($this->world['sites']['golfdom-fr']);
    modeWarningOffer($this->world['sites']['golfdom-fr']);

    [$status, $output] = modeWarningRun('off', null);

    expect($status)->toBe(1)
        ->and($output)->toContain('offered files: 1; affected sites: 1')
        ->and($this->world['golfdom']->fresh()->settings)->toBe(['reader_accounts' => 'open']);

    expect(modeWarningRun('off', null, true)[0])->toBe(0)
        ->and(app(ReaderAccounts::class)->mode($this->world['sites']['golfdom-fr']))->toBe(ReaderMode::SignIn);
});

it('also refuses inherit when reverting the site would close its reader links', function (): void {
    ReaderFixture::mode($this->world['sites']['golfdom'], 'open');
    modeWarningOffer($this->world['sites']['golfdom']);

    [$status, $output] = modeWarningRun('inherit');

    expect($status)->toBe(1)
        ->and($output)->toContain('offered files: 1; affected sites: 1', '--force')
        ->and($this->world['sites']['golfdom']->fresh()->settings)->toBe(['reader_accounts' => 'open']);

    expect(modeWarningRun('inherit', force: true)[0])->toBe(0)
        ->and($this->world['sites']['golfdom']->fresh()->settings)->toBeNull();
});

it('also previews reverting the org to the off default and preserves site overrides', function (): void {
    ReaderFixture::mode($this->world['golfdom'], 'open');
    ReaderFixture::mode($this->world['sites']['golfdom-fr'], 'open');
    modeWarningOffer($this->world['sites']['golfdom']);
    modeWarningOffer($this->world['sites']['golfdom-fr']);

    [$status, $output] = modeWarningRun('inherit', null);

    expect($status)->toBe(1)
        ->and($output)->toContain('offered files: 1; affected sites: 1')
        ->and($this->world['golfdom']->fresh()->settings)->toBe(['reader_accounts' => 'open']);

    expect(modeWarningRun('inherit', null, true)[0])->toBe(0)
        ->and(app(ReaderAccounts::class)->mode($this->world['sites']['golfdom']))->toBe(ReaderMode::Off)
        ->and(app(ReaderAccounts::class)->mode($this->world['sites']['golfdom-fr']))->toBe(ReaderMode::Open);
});

it('uses the stored SiteGroup fallback when reverting a site without closing access', function (): void {
    app(Context::class)->setOrg($this->world['golfdom']);
    $group = SiteGroup::create(['handle' => 'brand', 'name' => 'Brand', 'settings' => ['reader_accounts' => 'sign-in']]);
    $site = $this->world['sites']['golfdom'];
    $site->update(['site_group_id' => $group->getKey()]);
    ReaderFixture::mode($site, 'open');
    modeWarningOffer($site);

    expect(modeWarningRun('inherit'))->toBe([0, 'Reader accounts on [golfdom]: inherit.'])
        ->and(app(ReaderAccounts::class)->mode($site))->toBe(ReaderMode::SignIn);
});

it('does not warn for an org override hidden by the SiteGroup', function (): void {
    ReaderFixture::mode($this->world['golfdom'], 'open');
    app(Context::class)->setOrg($this->world['golfdom']);
    $group = SiteGroup::create(['handle' => 'brand', 'name' => 'Brand', 'settings' => ['reader_accounts' => 'sign-in']]);
    $site = $this->world['sites']['golfdom'];
    $site->update(['site_group_id' => $group->getKey()]);
    modeWarningOffer($site);

    expect(modeWarningRun('off', null))->toBe([0, 'Reader accounts on [golfdom]: off.'])
        ->and(app(ReaderAccounts::class)->mode($site))->toBe(ReaderMode::SignIn);
});

it('uses a host default that keeps sign-in open when the org override is reverted', function (): void {
    config(['kitsune.settings.reader_accounts' => 'sign-in']);
    app()->forgetInstance(SettingsResolver::class);
    app()->forgetInstance(ReaderAccounts::class);
    app()->forgetInstance(ReaderModeImpact::class);
    ReaderFixture::mode($this->world['golfdom'], 'open');
    modeWarningOffer($this->world['sites']['golfdom']);

    expect(modeWarningRun('inherit', null))->toBe([0, 'Reader accounts on [golfdom]: inherit.'])
        ->and(app(ReaderAccounts::class)->mode($this->world['sites']['golfdom']))->toBe(ReaderMode::SignIn);
});

it('does not warn again when reader pages were already off', function (): void {
    modeWarningOffer($this->world['sites']['golfdom']);

    expect(modeWarningRun('off'))->toBe([0, 'Reader accounts on [golfdom]: off.']);
});

it('does not count a non-offer, public, shared, trashed or mismatched-org record', function (string $case): void {
    ReaderFixture::mode($this->world['golfdom'], 'open');
    $entry = modeWarningOffer($this->world['sites']['golfdom']);
    match ($case) {
        'not offered' => DB::table('media_files')->where('entry_id', $entry->getKey())->update(['reader_entitlement' => null]),
        'empty name' => DB::table('media_files')->where('entry_id', $entry->getKey())->update(['reader_entitlement' => '']),
        'public' => DB::table('media_files')->where('entry_id', $entry->getKey())->update(['visibility' => 'public']),
        'shared' => DB::table('entries')->where('id', $entry->getKey())->update(['site_id' => null]),
        'trashed' => DB::table('entries')->where('id', $entry->getKey())->update(['deleted_at' => now()]),
        'wrong org' => DB::table('entries')->where('id', $entry->getKey())->update(['org_id' => $this->world['rival']->getKey()]),
    };

    expect(modeWarningRun('off'))->toBe([0, 'Reader accounts on [golfdom]: off.']);
})->with(['not offered', 'empty name', 'public', 'shared', 'trashed', 'wrong org']);

it('fails closed before reading offers with no org or another org in context', function (bool $otherOrg): void {
    ReaderFixture::mode($this->world['golfdom'], 'open');
    modeWarningOffer($this->world['sites']['golfdom']);
    $context = app(Context::class);
    if ($otherOrg) {
        $context->setOrg($this->world['rival']);
    }

    try {
        expect(fn () => app(ReaderModeImpact::class)->count($this->world['sites']['golfdom'], 'off'))
            ->toThrow(RuntimeException::class, 'Refusing to count reader offers outside the current organisation. Nothing was read or written.');
        expect(fn () => app(ReaderModeImpact::class)->count($this->world['golfdom'], 'off'))
            ->toThrow(RuntimeException::class);
    } finally {
        ReaderFixture::forget();
    }
})->with(['no context' => false, 'another org' => true]);

it('counts an offer after the first bounded site batch without changing settings', function (): void {
    ReaderFixture::mode($this->world['golfdom'], 'open');
    for ($i = 0; $i < 101; $i++) {
        $site = ReaderFixture::site($this->world['golfdom'], 'extra-'.$i, 'Extra '.$i, '/extra-'.$i);
    }
    modeWarningOffer($site);
    app(Context::class)->setOrg($this->world['golfdom']);

    try {
        expect(app(ReaderModeImpact::class)->count($this->world['golfdom'], 'off'))->toBe(['files' => 1, 'sites' => 1])
            ->and($this->world['golfdom']->fresh()->settings)->toBe(['reader_accounts' => 'open']);
    } finally {
        ReaderFixture::forget();
    }
});
