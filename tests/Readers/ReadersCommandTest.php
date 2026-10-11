<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Models\Reader;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Models\Entitlement;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Readers\ReaderAccounts;
use Kitsune\Core\Readers\ReaderErasure;
use Kitsune\Core\Readers\ReaderTokens;
use Kitsune\Core\Settings\SettingsWriter;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture;
use Kitsune\Core\Tests\Fixtures\KeyNotIdentifierReader;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Kitsune\Core\Tests\Fixtures\ShoutingReader;
use Kitsune\Core\Tests\Fixtures\TestReader;
use Kitsune\Core\Tests\Fixtures\TestUser;
use Kitsune\Core\Tests\Fixtures\UnscopedFindReader;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

/*
 * `kitsune:readers` — the operator's door to reader accounts (ADR-037, as built; ADR-020's export and erasure).
 *
 * ⚠️ AN ADDRESS COMES FROM A PROMPT OR STANDARD INPUT, NEVER AN OPTION, and nothing printed repeats it.
 */

beforeEach(function (): void {
    $this->world = ReaderFixture::world();
    $this->reader = ReaderFixture::reader($this->world['golfdom'], 'subscriber@kitsune.test');
});

afterEach(function (): void {
    expect(app(Context::class)->orgId())->toBeNull();
});

/**
 * The command through the console kernel, as a shell runs it, with this on standard input — or nothing.
 *
 * @param  array<string, mixed>  $parameters
 * @return array{0: int, 1: string}
 */
function readersRun(array $parameters, string $stdin = ''): array
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput(['command' => 'kitsune:readers', ...$parameters]);
    $input->setStream($stream);

    $status = app(ConsoleKernel::class)->handle($input, $output = new BufferedOutput);

    return [$status, trim($output->fetch())];
}

it('refuses an action it does not have', function (): void {
    expect(readersRun(['action' => 'delete']))
        ->toBe([1, 'Refusing: kitsune:readers has six actions — status, mode, addresses, find, export and erase. Nothing was read or written.']);
});

it('needs an organisation for everything but status, and names one it cannot find', function (string $action): void {
    expect(readersRun(['action' => $action, 'value' => 'open']))->toBe([1, "Refusing: {$action} needs --org=<slug>. Nothing was read or written."])
        ->and(readersRun(['action' => $action, 'value' => 'open', '--org' => 'nowhere', '--force' => true]))
        ->toBe([1, 'Refusing: no organisation has the slug [nowhere]. Nothing was read or written.']);
})->with(['mode', 'find', 'export', 'erase']);

// ---- status ---------------------------------------------------------------------------------------------------------

it('says the skeleton\'s declaration is usable, and exits 0', function (): void {
    expect(readersRun(['action' => 'status']))->toBe([0, implode("\n", [
        'Reader guard: declared and usable [readers].',
        'Reader accounts: usable — the model implements ReaderAccount.',
        'Reader mail: through [array], allowed only because the environment is [testing].',
        'Passwords: at least 15 characters.',
    ])]);
});

it('says no guard is declared without failing a deploy that has none', function (): void {
    config(['kitsune.readers.guard' => null]);

    expect(readersRun(['action' => 'status']))->toBe([0, implode("\n", [
        'Reader guard: none declared (kitsune.readers.guard) — no reader can sign in.',
        'Reader accounts: NOT usable — no usable reader guard is declared, so no reader can sign in.',
        'Reader mail: through [array], allowed only because the environment is [testing].',
        'Passwords: at least 15 characters.',
    ])]);
});

it('fails when a guard is declared that accounts cannot use', function (array $config, string $line): void {
    config($config);

    [$status, $output] = readersRun(['action' => 'status']);

    expect($status)->toBe(1)
        ->and(explode("\n", $output)[1])->toBe($line);
})->with([
    'a model without the contract' => [
        ['auth.providers.readers.model' => TestReader::class],
        'Reader accounts: NOT usable — the reader guard\'s model [Kitsune\Core\Tests\Fixtures\TestReader] does not implement Kitsune\Core\Readers\Contracts\ReaderAccount, so core\'s sign-in cannot use it.',
    ],
    'a token guard' => [
        ['auth.guards.readers.driver' => 'token'],
        'Reader accounts: NOT usable — the reader guard [readers] is not a session guard, so a sign-in would not outlive the request.',
    ],
    'a guard that does not exist' => [
        ['kitsune.readers.guard' => 'nonesuch'],
        'Reader accounts: NOT usable — no usable reader guard is declared, so no reader can sign in.',
    ],
]);

it('lists each site\'s mode and where it comes from', function (): void {
    ReaderFixture::mode($this->world['golfdom'], 'open');
    ReaderFixture::mode($this->world['sites']['golfdom-fr'], 'sign-in');
    ReaderFixture::site($this->world['golfdom'], 'admin-only', 'Admin only', null);

    [$status, $output] = readersRun(['action' => 'status', '--org' => 'golfdom']);

    expect($status)->toBe(0)
        ->and(array_slice(explode("\n", $output), 4))->toBe([
            'Site [admin-only]: open (inherited from the organisation); addresses hidden from owners (platform default) — it has no public address yet, so no reader page can be reached; kitsune:site address gives it one.',
            'Site [golfdom]: open (inherited from the organisation); addresses hidden from owners (platform default).',
            'Site [golfdom-fr]: sign-in (set on this site); addresses hidden from owners (platform default).',
        ]);

    expect(readersRun(['action' => 'status', '--org' => 'rival'])[1])->toContain('Site [rival]: off (platform default); addresses hidden from owners (platform default).');
});

// ---- mode -----------------------------------------------------------------------------------------------------------

it('switches an organisation\'s accounts on, audited as any settings change is', function (): void {
    $audited = DB::table('audit_log')->where('action', SettingsWriter::SET)->count();

    expect(readersRun(['action' => 'mode', 'value' => 'open', '--org' => 'golfdom']))->toBe([0, 'Reader accounts on [golfdom]: open.'])
        ->and($this->world['golfdom']->fresh()->settings)->toBe(['reader_accounts' => 'open'])
        ->and(DB::table('audit_log')->where('action', SettingsWriter::SET)->count())->toBe($audited + 1);
});

it('sets a site\'s mode, and reverts it to inherit', function (): void {
    expect(readersRun(['action' => 'mode', 'value' => 'sign-in', '--org' => 'golfdom', '--site' => 'golfdom-fr']))
        ->toBe([0, 'Reader accounts on [golfdom-fr]: sign-in.'])
        ->and($this->world['sites']['golfdom-fr']->fresh()->settings)->toBe(['reader_accounts' => 'sign-in']);

    expect(readersRun(['action' => 'mode', 'value' => 'inherit', '--org' => 'golfdom', '--site' => 'golfdom-fr']))
        ->toBe([0, 'Reader accounts on [golfdom-fr]: inherit.'])
        ->and($this->world['sites']['golfdom-fr']->fresh()->settings)->toBeNull();
});

it('refuses a mode it does not have, and a site the org does not have', function (): void {
    expect(readersRun(['action' => 'mode', 'value' => 'on', '--org' => 'golfdom']))
        ->toBe([1, 'Refusing: a site\'s reader accounts are off, sign-in or open — or inherit, to use the level above. Nothing was written.'])
        ->and(readersRun(['action' => 'mode', '--org' => 'golfdom']))
        ->toBe([1, 'Refusing: a site\'s reader accounts are off, sign-in or open — or inherit, to use the level above. Nothing was written.'])
        ->and(readersRun(['action' => 'mode', 'value' => 'open', '--org' => 'golfdom', '--site' => 'rival']))
        ->toBe([1, 'Refusing: [golfdom] has no site with the handle [rival]. Nothing was written.'])
        ->and($this->world['sites']['rival']->fresh()->settings)->toBeNull();
});

// ---- addresses ------------------------------------------------------------------------------------------------------

/**
 * Every settings change audited so far, oldest first, as the action, the org it is filed under and its target.
 *
 * @return list<array{0: string, 1: string, 2: string, 3: string}>
 */
function readersSettingsAudit(): array
{
    return DB::table('audit_log')
        ->whereIn('action', [SettingsWriter::SET, SettingsWriter::REVERTED])
        ->orderBy('id')
        ->get(['action', 'org_id', 'target_type', 'target_id'])
        ->map(static fn (object $row): array => [(string) $row->action, (string) $row->org_id, (string) $row->target_type, (string) $row->target_id])
        ->all();
}

/** The stored settings of an org or a site, read below every scope — what the row holds, whoever owns it. */
function readersStored(string $table, mixed $id): mixed
{
    $settings = DB::table($table)->where('id', $id)->value('settings');

    return $settings === null ? null : json_decode((string) $settings, true, 512, JSON_THROW_ON_ERROR);
}

it('needs an organisation to set addresses, and names one it cannot find', function (): void {
    expect(readersRun(['action' => 'addresses', 'value' => 'shown']))->toBe([1, 'Refusing: addresses needs --org=<slug>. Nothing was read or written.'])
        ->and(readersRun(['action' => 'addresses', 'value' => 'shown', '--org' => 'nowhere']))
        ->toBe([1, 'Refusing: no organisation has the slug [nowhere]. Nothing was read or written.'])
        ->and(readersSettingsAudit())->toBe([]);
});

it('shows an organisation\'s readers\' addresses to its owners and hides them again, audited as any settings change is', function (): void {
    $golfdom = $this->world['golfdom'];
    ReaderFixture::mode($golfdom, 'open');
    $target = [(string) $golfdom->getKey(), $golfdom->getMorphClass(), (string) $golfdom->getKey()];

    expect(readersRun(['action' => 'addresses', 'value' => 'shown', '--org' => 'golfdom']))->toBe([0, 'Readers\' addresses on [golfdom]: shown.'])
        // Beside the mode, which it leaves as it was.
        ->and(readersStored('orgs', $golfdom->getKey()))->toBe(['reader_accounts' => 'open', 'reader_addresses' => 'shown'])
        ->and(readersSettingsAudit())->toBe([[SettingsWriter::SET, ...$target]])
        ->and(app(ReaderAccounts::class)->addresses($this->world['sites']['golfdom']))->toBeTrue()
        ->and(app(ReaderAccounts::class)->addresses($this->world['sites']['golfdom-fr']))->toBeTrue();

    // ⚠️ The other org, from its side: nothing of golfdom's choice reaches it.
    expect(readersStored('orgs', $this->world['rival']->getKey()))->toBeNull()
        ->and(app(ReaderAccounts::class)->addresses($this->world['sites']['rival']))->toBeFalse()
        ->and(app(ReaderAccounts::class)->addresses($this->world['sites']['shop']))->toBeFalse();

    expect(readersRun(['action' => 'addresses', 'value' => 'hidden', '--org' => 'golfdom']))->toBe([0, 'Readers\' addresses on [golfdom]: hidden.'])
        ->and(readersStored('orgs', $golfdom->getKey()))->toBe(['reader_accounts' => 'open', 'reader_addresses' => 'hidden'])
        ->and(app(ReaderAccounts::class)->addresses($this->world['sites']['golfdom']))->toBeFalse();

    expect(readersRun(['action' => 'addresses', 'value' => 'inherit', '--org' => 'golfdom']))->toBe([0, 'Readers\' addresses on [golfdom]: inherit.'])
        ->and(readersStored('orgs', $golfdom->getKey()))->toBe(['reader_accounts' => 'open'])
        ->and(readersSettingsAudit())->toBe([[SettingsWriter::SET, ...$target], [SettingsWriter::SET, ...$target], [SettingsWriter::REVERTED, ...$target]]);

    // A revert of a key nothing holds changes nothing, so nothing is recorded.
    expect(readersRun(['action' => 'addresses', 'value' => 'inherit', '--org' => 'golfdom']))->toBe([0, 'Readers\' addresses on [golfdom]: inherit.'])
        ->and(readersSettingsAudit())->toHaveCount(3);
});

/**
 * The refusal of a site: an address setting is the organisation's alone.
 *
 * @return array{0: int, 1: string}
 */
function readersSiteRefused(): array
{
    return [1, 'Refusing: readers\' addresses are shown or hidden for a whole organisation, whose readers they are — a site\'s owners could name the same readers on a sibling\'s page. Leave --site off. Nothing was written.'];
}

/**
 * The refusal of a value the switch does not have.
 *
 * @return array{0: int, 1: string}
 */
function readersValueRefused(): array
{
    return [1, 'Refusing: readers\' addresses are hidden or shown to an organisation\'s owners — or inherit, to use the platform\'s default. Nothing was written.'];
}

it('refuses a site before it looks for the organisation, and writes nothing — a handle the org has, one only another org has, or one none has', function (string $org, string $handle, string $value): void {
    $fr = $this->world['sites']['golfdom-fr'];
    // A site holding a setting of its own, which the refusal leaves as it was.
    ReaderFixture::mode($fr, 'sign-in');
    readersRun(['action' => 'addresses', 'value' => 'shown', '--org' => 'golfdom']);
    $audited = readersSettingsAudit();

    expect(readersRun(['action' => 'addresses', 'value' => $value, '--org' => $org, '--site' => $handle]))->toBe(readersSiteRefused())
        // Before the organisation is looked for: a slug no organisation has is not what it says.
        ->and(readersRun(['action' => 'addresses', 'value' => $value, '--org' => 'nowhere', '--site' => $handle]))->toBe(readersSiteRefused())
        ->and(readersSettingsAudit())->toBe($audited)
        ->and(readersStored('sites', $fr->getKey()))->toBe(['reader_accounts' => 'sign-in'])
        ->and(readersStored('sites', $this->world['sites']['golfdom']->getKey()))->toBeNull()
        ->and(readersStored('sites', $this->world['sites']['rival']->getKey()))->toBeNull()
        ->and(readersStored('sites', $this->world['sites']['shop']->getKey()))->toBeNull()
        ->and(readersStored('orgs', $this->world['golfdom']->getKey()))->toBe(['reader_addresses' => 'shown'])
        ->and(readersStored('orgs', $this->world['rival']->getKey()))->toBeNull()
        // Every site still reads its own organisation's choice.
        ->and(app(ReaderAccounts::class)->addresses($fr))->toBeTrue()
        ->and(app(ReaderAccounts::class)->addresses($this->world['sites']['golfdom']))->toBeTrue()
        ->and(app(ReaderAccounts::class)->addresses($this->world['sites']['shop']))->toBeFalse();
})->with([
    'a site of the org' => ['golfdom', 'golfdom-fr'],
    // ⚠️ From the other side: rival naming golfdom's site.
    'another org\'s site' => ['rival', 'golfdom-fr'],
    'a handle only another org has' => ['golfdom', 'shop'],
    'a handle no site has' => ['golfdom', 'nowhere'],
])->with(['shown', 'hidden', 'inherit']);

it('refuses a value it does not have before anything else it would refuse, and writes nothing', function (?string $value): void {
    $parameters = $value === null ? ['action' => 'addresses'] : ['action' => 'addresses', 'value' => $value];

    expect(readersRun([...$parameters, '--org' => 'golfdom']))->toBe(readersValueRefused())
        // Before a site is refused, and before the organisation is looked for.
        ->and(readersRun([...$parameters, '--org' => 'golfdom', '--site' => 'golfdom-fr']))->toBe(readersValueRefused())
        ->and(readersRun([...$parameters, '--org' => 'nowhere']))->toBe(readersValueRefused())
        ->and(DB::table('orgs')->whereNotNull('settings')->count())->toBe(0)
        ->and(DB::table('sites')->whereNotNull('settings')->count())->toBe(0)
        ->and(readersSettingsAudit())->toBe([]);
})->with([
    'none' => [null],
    'empty' => [''],
    'upper case' => ['SHOWN'],
    'title case' => ['Shown'],
    'padded' => [' shown'],
    'another word' => ['visible'],
    'true' => ['true'],
    'one' => ['1'],
    'a mode\'s word' => ['open'],
    'off' => ['off'],
]);

it('keeps the two switches apart: a mode is not an address setting, nor the reverse, and only a mode is set on a site', function (): void {
    $mode = 'Refusing: a site\'s reader accounts are off, sign-in or open — or inherit, to use the level above. Nothing was written.';

    expect(readersRun(['action' => 'addresses', 'value' => 'sign-in', '--org' => 'golfdom']))->toBe(readersValueRefused())
        ->and(readersRun(['action' => 'mode', 'value' => 'shown', '--org' => 'golfdom']))->toBe([1, $mode])
        ->and(readersRun(['action' => 'mode', 'value' => 'hidden', '--org' => 'golfdom', '--site' => 'golfdom-fr']))->toBe([1, $mode])
        ->and(readersSettingsAudit())->toBe([]);

    // The same site, each switch its own way: a mode is set on it, and an address setting is refused there.
    expect(readersRun(['action' => 'mode', 'value' => 'open', '--org' => 'golfdom', '--site' => 'golfdom-fr']))->toBe([0, 'Reader accounts on [golfdom-fr]: open.'])
        ->and(readersRun(['action' => 'addresses', 'value' => 'shown', '--org' => 'golfdom', '--site' => 'golfdom-fr']))->toBe(readersSiteRefused())
        ->and(readersStored('sites', $this->world['sites']['golfdom-fr']->getKey()))->toBe(['reader_accounts' => 'open'])
        ->and(readersStored('orgs', $this->world['golfdom']->getKey()))->toBeNull()
        ->and(readersSettingsAudit())->toHaveCount(1);
});

it('says on each site\'s line whether owners see addresses and where that comes from — and never an address', function (): void {
    $rivalReader = ReaderFixture::reader($this->world['rival'], 'rival-subscriber@kitsune.test');
    EntitlementFixture::plant($this->world['sites']['golfdom'], (string) $this->reader->getKey(), 'course.advanced-php');
    EntitlementFixture::plant($this->world['sites']['rival'], (string) $rivalReader->getKey(), 'course.advanced-php');
    // A site's own mode, beside its organisation's addresses.
    ReaderFixture::mode($this->world['sites']['golfdom-fr'], 'sign-in');
    readersRun(['action' => 'addresses', 'value' => 'shown', '--org' => 'golfdom']);

    [$status, $output] = readersRun(['action' => 'status', '--org' => 'golfdom']);

    expect($status)->toBe(0)
        ->and(array_slice(explode("\n", $output), 4))->toBe([
            'Site [golfdom]: off (platform default); addresses shown to owners (inherited from the organisation).',
            'Site [golfdom-fr]: sign-in (set on this site); addresses shown to owners (inherited from the organisation).',
        ]);

    // ⚠️ The other org, from its side: golfdom's choice is not on its lines.
    [$status, $rival] = readersRun(['action' => 'status', '--org' => 'rival']);

    expect($status)->toBe(0)
        ->and(array_slice(explode("\n", $rival), 4))->toBe([
            'Site [rival]: off (platform default); addresses hidden from owners (platform default).',
            'Site [shop]: off (platform default); addresses hidden from owners (platform default).',
        ]);

    readersRun(['action' => 'addresses', 'value' => 'hidden', '--org' => 'golfdom']);
    $outputs = [$output, $rival, $hidden = readersRun(['action' => 'status', '--org' => 'golfdom'])[1]];

    expect(array_slice(explode("\n", $hidden), 4))->toBe([
        'Site [golfdom]: off (platform default); addresses hidden from owners (inherited from the organisation).',
        'Site [golfdom-fr]: sign-in (set on this site); addresses hidden from owners (inherited from the organisation).',
    ]);

    readersRun(['action' => 'addresses', 'value' => 'inherit', '--org' => 'golfdom']);
    $outputs[] = $inherited = readersRun(['action' => 'status', '--org' => 'golfdom'])[1];

    expect(array_slice(explode("\n", $inherited), 4))->toBe([
        'Site [golfdom]: off (platform default); addresses hidden from owners (platform default).',
        'Site [golfdom-fr]: sign-in (set on this site); addresses hidden from owners (platform default).',
    ]);

    // A platform default of shown, said as one.
    config(['kitsune.settings.reader_addresses' => 'shown']);
    app()->forgetScopedInstances();
    $outputs[] = $default = readersRun(['action' => 'status', '--org' => 'golfdom'])[1];

    expect(array_slice(explode("\n", $default), 4))->toBe([
        'Site [golfdom]: off (platform default); addresses shown to owners (platform default).',
        'Site [golfdom-fr]: sign-in (set on this site); addresses shown to owners (platform default).',
    ]);

    // Readers with grants on these sites, and not one address, nor any part of one, on any line.
    foreach ($outputs as $printed) {
        expect(str_contains($printed, '@'))->toBeFalse($printed)
            ->and(str_contains($printed, 'subscriber'))->toBeFalse($printed);
    }
});

it('says hidden on the status line for any address setting a site holds — a value only a write below Eloquent could leave', function (string $stored): void {
    readersRun(['action' => 'addresses', 'value' => 'shown', '--org' => 'golfdom']);
    // Below Eloquent, as only a hand-written query could: `SettingsGuard` refuses it on every save, whatever its value.
    DB::table('sites')->where('id', $this->world['sites']['golfdom-fr']->getKey())->update(['settings' => json_encode(['reader_addresses' => $stored])]);

    expect(array_slice(explode("\n", readersRun(['action' => 'status', '--org' => 'golfdom'])[1]), 4))->toBe([
        'Site [golfdom]: off (platform default); addresses shown to owners (inherited from the organisation).',
        // Not "set on this site": the site chose nothing, since the setting is the organisation's (review).
        'Site [golfdom-fr]: off (platform default); addresses hidden from owners (a value stored below the organisation is ignored — the setting is the organisation\'s).',
    ]);
})->with([
    'a word nothing stores' => ['SHOWN'],
    // ⚠️ Even the organisation's own word: a site's value is not read.
    'shown' => ['shown'],
    'hidden' => ['hidden'],
]);

it('says addresses cannot be shown on each site\'s line where this installation\'s reader accounts cannot name readers, even with the org set to shown', function (array $config, int $exit): void {
    readersRun(['action' => 'addresses', 'value' => 'shown', '--org' => 'golfdom']);

    // ⚠️ The control: the skeleton's reader can be named, so what follows is the accounts' doing, not the organisation's.
    expect(array_slice(explode("\n", readersRun(['action' => 'status', '--org' => 'golfdom'])[1]), 4))->toBe([
        'Site [golfdom]: off (platform default); addresses shown to owners (inherited from the organisation).',
        'Site [golfdom-fr]: off (platform default); addresses shown to owners (inherited from the organisation).',
    ]);

    config($config);
    // A new process, as a deploy that changed its configuration would run: no guard built yet.
    ReaderFixture::forget();

    [$status, $output] = readersRun(['action' => 'status', '--org' => 'golfdom']);

    expect($status)->toBe($exit)
        ->and(array_slice(explode("\n", $output), 4))->toBe([
            'Site [golfdom]: off (platform default); addresses cannot be shown to owners (this installation\'s reader accounts cannot name readers).',
            'Site [golfdom-fr]: off (platform default); addresses cannot be shown to owners (this installation\'s reader accounts cannot name readers).',
        ])
        // The organisation's choice stands, for when they can be named.
        ->and(readersStored('orgs', $this->world['golfdom']->getKey()))->toBe(['reader_addresses' => 'shown']);
})->with([
    'a reader model identified by another column than its key' => [['auth.providers.readers.model' => KeyNotIdentifierReader::class], 0],
    'a token guard over the skeleton\'s reader' => [['auth.guards.readers.driver' => 'token'], 1],
    'a model without the contract' => [['auth.providers.readers.model' => TestReader::class], 1],
    'no guard declared' => [['kitsune.readers.guard' => null], 0],
]);

it('says, when it writes the address setting, that it has no effect yet where readers cannot be named', function (array $config): void {
    config($config);
    ReaderFixture::forget();

    foreach (['shown', 'hidden', 'inherit'] as $value) {
        expect(readersRun(['action' => 'addresses', 'value' => $value, '--org' => 'golfdom']))->toBe([0, implode("\n", [
            "Readers' addresses on [golfdom]: {$value}.",
            'This installation\'s reader accounts cannot name readers yet, so the entitlements page shows identifiers until they can — kitsune:readers status says why.',
        ])]);
    }

    // Written all the same, for when they can be named.
    expect(readersStored('orgs', $this->world['golfdom']->getKey()))->toBeNull();
})->with([
    'a reader model identified by another column than its key' => [['auth.providers.readers.model' => KeyNotIdentifierReader::class]],
    'a token guard over the skeleton\'s reader' => [['auth.guards.readers.driver' => 'token']],
]);

it('says nothing more when it writes the address setting where readers can be named', function (): void {
    expect(readersRun(['action' => 'addresses', 'value' => 'shown', '--org' => 'golfdom']))->toBe([0, "Readers' addresses on [golfdom]: shown."]);
});

// ---- find -----------------------------------------------------------------------------------------------------------

it('finds a reader by an address piped in, and does not repeat the address', function (): void {
    [$status, $output] = readersRun(['action' => 'find', '--org' => 'golfdom'], "Subscriber@Kitsune.test\n");

    expect($status)->toBe(0)
        ->and($output)->toBe('Reader '.$this->reader->getKey().'.');
});

/*
 * ⚠️ THE PROMPT IS ASKED ON A TERMINAL ONLY, and a terminal cannot be made inside this process: so the decision is run in a
 * child attached to a pseudo-terminal, and to a pipe, and the prompt itself is Laravel's `ask()`.
 */
function readersAsksIn(bool $pty, bool $interactive): string
{
    $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
    $process = new Process([PHP_BINARY, '-r', "require '{$autoload}'; "
        .'echo Kitsune\Core\Console\ReadersCommand::asks(STDIN, '.($interactive ? 'true' : 'false').') ? "ASKS" : "READS";']);
    $process->setPty($pty);
    $process->setTimeout(30);
    $process->run();

    return trim($process->getOutput());
}

it('asks for the address at a prompt on a terminal', function (): void {
    expect(readersAsksIn(pty: true, interactive: true))->toContain('ASKS')
        ->and(readersAsksIn(pty: true, interactive: false))->toContain('READS');
})->skip(! Process::isPtySupported(), 'no pseudo-terminal on this host');

it('reads the address from a pipe without a prompt, even when the run may ask', function (): void {
    expect(readersAsksIn(pty: false, interactive: true))->toBe('READS');
});

it('says when no reader of the org has the address, without repeating it', function (): void {
    ReaderFixture::reader($this->world['rival'], 'rival-only@kitsune.test');

    expect(readersRun(['action' => 'find', '--org' => 'golfdom'], "rival-only@kitsune.test\n"))
        ->toBe([1, 'No reader in [golfdom] has that address.']);
});

it('names no other org\'s reader, even when a host\'s lookup finds one', function (): void {
    config(['auth.providers.readers.model' => UnscopedFindReader::class]);

    expect(readersRun(['action' => 'find', '--org' => 'rival'], "subscriber@kitsune.test\n"))
        ->toBe([1, 'No reader in [rival] has that address.'])
        ->and(readersRun(['action' => 'erase', '--org' => 'rival', '--force' => true], "subscriber@kitsune.test\n"))
        ->toBe([1, 'No reader in [rival] has that address, so nothing held under a reader\'s identifier was looked for — for a reader the host deleted, run again with --reader=<id>. Erased 0 pending sign-up links.'])
        ->and(Reader::withoutScopeBecause('a test counts', static fn ($query) => $query->count()))->toBe(1);
});

it('refuses an address no reader could have, and does not repeat it', function (string $typed): void {
    expect(readersRun(['action' => 'find', '--org' => 'golfdom'], $typed))
        ->toBe([1, 'Refusing: that is not an address a reader could have signed up with. The value is not repeated. Nothing was read or written.']);
})->with([
    'nothing' => [''],
    'not an address' => ["not-an-address\n"],
    'not ASCII' => ["jané@kitsune.test\n"],
    'longer than a line is read' => [str_repeat('a', 5000)."@kitsune.test\n"],
]);

it('takes no address from an option', function (): void {
    expect(fn () => readersRun(['action' => 'find', '--org' => 'golfdom', '--email' => 'subscriber@kitsune.test']))
        ->toThrow(InvalidOptionException::class);
});

// ---- export ---------------------------------------------------------------------------------------------------------

it('exports one reader: the host\'s account and core\'s entitlements, and no secret', function (bool $byAddress): void {
    EntitlementFixture::plant($this->world['sites']['golfdom'], (string) $this->reader->getKey(), 'course.advanced-php');

    [$status, $output] = $byAddress
        ? readersRun(['action' => 'export', '--org' => 'golfdom'], "subscriber@kitsune.test\n")
        : readersRun(['action' => 'export', '--org' => 'golfdom', '--reader' => (string) $this->reader->getKey()]);

    $export = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    expect($status)->toBe(0)
        ->and(array_keys($export))->toBe(['account', 'entitlements', 'pending_links', 'generated_at'])
        ->and(array_keys($export['account']))->toBe(['reader', 'email', 'email_verified_at', 'has_password', 'created_at', 'updated_at'])
        ->and($export['account']['reader'])->toBe((string) $this->reader->getKey())
        ->and($export['account']['email'])->toBe('subscriber@kitsune.test')
        ->and($export['account']['has_password'])->toBeTrue()
        ->and($export['entitlements'])->toHaveCount(1)
        ->and($export['entitlements'][0]['entitlement'])->toBe('course.advanced-php')
        ->and($output)->not->toContain('$2y$')
        ->and($output)->not->toContain('"password"')
        ->and($output)->not->toContain('remember');
})->with(['by identifier' => [false], 'by address' => [true]]);

it('exports a reader the host has deleted: no account, their entitlements still', function (): void {
    EntitlementFixture::plant($this->world['sites']['golfdom'], (string) $this->reader->getKey(), 'course.advanced-php');
    Reader::withoutScopeBecause('the host deleting its own row', fn ($query) => $query->whereKey($this->reader->getKey())->delete());

    [$status, $output] = readersRun(['action' => 'export', '--org' => 'golfdom', '--reader' => (string) $this->reader->getKey()]);
    $export = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    expect($status)->toBe(0)
        ->and($export['account'])->toBeNull()
        ->and($export['entitlements'])->toHaveCount(1);
});

it('refuses an identifier no reader here can have, without repeating it', function (string $given): void {
    expect(readersRun(['action' => 'export', '--org' => 'golfdom', '--reader' => $given]))
        ->toBe([1, 'Refusing: that is not an identifier a reader can have here. The value is not repeated. Nothing was read or written.']);
})->with(['a word' => ['abc'], 'a fraction' => ['1.5'], 'a leading zero' => ['007']]);

it('refuses to read or write accounts a model cannot hold', function (string $action): void {
    config(['auth.providers.readers.model' => TestReader::class]);

    expect(readersRun(['action' => $action, '--org' => 'golfdom', '--reader' => '1', '--force' => true], "subscriber@kitsune.test\n"))
        ->toBe([1, 'Refusing: reader accounts are not usable here — the reader guard\'s model [Kitsune\Core\Tests\Fixtures\TestReader] does not implement Kitsune\Core\Readers\Contracts\ReaderAccount, so core\'s sign-in cannot use it. `kitsune:entitlements forget` still erases a reader\'s entitlements. Nothing was read or written.']);
})->with(['find', 'export', 'erase']);

// ---- erase ----------------------------------------------------------------------------------------------------------

it('refuses to erase without --force, before reading anything', function (): void {
    expect(readersRun(['action' => 'erase', '--org' => 'golfdom', '--reader' => (string) $this->reader->getKey()]))
        ->toBe([1, 'Refusing: erase deletes the reader\'s account, every entitlement they hold on every site of [golfdom], and any link waiting for them. Run it again with --force. Nothing was written.'])
        ->and(Reader::withoutScopeBecause('a test counts', static fn ($query) => $query->count()))->toBe(1);
});

it('erases a reader\'s entitlements on every site of the org, then their account — and a second run erases nothing', function (bool $byAddress): void {
    EntitlementFixture::plant($this->world['sites']['golfdom'], (string) $this->reader->getKey(), 'course.advanced-php');
    EntitlementFixture::plant($this->world['sites']['golfdom-fr'], (string) $this->reader->getKey(), 'course.advanced-php');
    $erase = static fn (): array => $byAddress
        ? readersRun(['action' => 'erase', '--org' => 'golfdom', '--force' => true], "subscriber@kitsune.test\n")
        : readersRun(['action' => 'erase', '--org' => 'golfdom', '--force' => true, '--reader' => (string) test()->reader->getKey()]);

    expect($erase())->toBe([0, 'Erased 2 entitlement grants and 0 pending links; the account was deleted.'])
        ->and(Reader::withoutScopeBecause('a test counts', static fn ($query) => $query->count()))->toBe(0)
        ->and(Entitlement::withoutScopeBecause('a test counts', static fn ($query) => $query->count()))->toBe(0)
        ->and(DB::table('audit_log')->where('action', 'entitlement.erased')->count())->toBe(2);

    expect($erase())->toBe($byAddress
        ? [1, 'No reader in [golfdom] has that address, so nothing held under a reader\'s identifier was looked for — for a reader the host deleted, run again with --reader=<id>. Erased 0 pending sign-up links.']
        : [0, 'Erased 0 entitlement grants and 0 pending links; no account had that identifier.']);
})->with(['by identifier' => [false], 'by address' => [true]]);

it('erases in one org only: another org\'s reader with the same identifier is untouched', function (): void {
    EntitlementFixture::plant($this->world['sites']['golfdom'], (string) $this->reader->getKey(), 'course.advanced-php');

    expect(readersRun(['action' => 'erase', '--org' => 'rival', '--force' => true, '--reader' => (string) $this->reader->getKey()]))
        ->toBe([0, 'Erased 0 entitlement grants and 0 pending links; no account had that identifier.'])
        ->and(Reader::withoutScopeBecause('a test counts', static fn ($query) => $query->count()))->toBe(1)
        ->and(Entitlement::withoutScopeBecause('a test counts', static fn ($query) => $query->count()))->toBe(1);
});

it('erases a reader of an organisation that has been deleted', function (): void {
    $this->world['golfdom']->delete();

    expect(readersRun(['action' => 'erase', '--org' => 'golfdom', '--force' => true, '--reader' => (string) $this->reader->getKey()]))
        ->toBe([0, 'Erased 0 entitlement grants and 0 pending links; the account was deleted.']);
});

it('erases nothing of the account when the entitlements cannot be erased — they go first', function (): void {
    EntitlementFixture::plant($this->world['sites']['golfdom'], (string) $this->reader->getKey(), 'course.advanced-php');
    // A member of staff who is not an owner: forget() refuses them before it writes anything.
    app(Context::class)->forget()->setOrg($this->world['golfdom']);
    config(['auth.providers.users.model' => TestUser::class]);
    CredentialFixture::member(owner: false, email: 'member@kitsune.test');

    expect(static fn () => app(ReaderErasure::class)->erase((string) test()->reader->getKey()))
        ->toThrow(EntitlementRefused::class);

    app(Context::class)->forget();

    expect(Reader::withoutScopeBecause('a test counts', static fn ($query) => $query->count()))->toBe(1)
        ->and(Entitlement::withoutScopeBecause('a test counts', static fn ($query) => $query->count()))->toBe(1);
});

it('keeps everything when the account cannot be erased', function (): void {
    EntitlementFixture::plant($this->world['sites']['golfdom'], (string) $this->reader->getKey(), 'course.advanced-php');
    Reader::deleting(static function (): bool {
        throw new RuntimeException('The host refused to delete the row.');
    });

    expect(readersRun(['action' => 'erase', '--org' => 'golfdom', '--force' => true, '--reader' => (string) $this->reader->getKey()]))
        ->toBe([1, 'The host refused to delete the row.'])
        ->and(Reader::withoutScopeBecause('a test counts', static fn ($query) => $query->count()))->toBe(1)
        ->and(Entitlement::withoutScopeBecause('a test counts', static fn ($query) => $query->count()))->toBe(1);
});

// ---- Pending links --------------------------------------------------------------------------------------------------

/** A link minted at a site, as its page mints one. */
function readersLink(Site $site, string $purpose, string $subject): void
{
    app(Context::class)->forget()->setSite($site);

    try {
        app(ReaderTokens::class)->mint($purpose, $subject);
    } finally {
        ReaderFixture::forget();
    }
}

it('exports the links waiting for a reader, and nothing that opens them', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-09 12:00:00', 'UTC'));
    readersLink($this->world['sites']['golfdom-fr'], ReaderTokens::RECOVER, (string) $this->reader->getKey());
    $hash = (string) DB::table('reader_tokens')->value('token_hash');

    [$status, $output] = readersRun(['action' => 'export', '--org' => 'golfdom', '--reader' => (string) $this->reader->getKey()]);
    $export = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    expect($status)->toBe(0)
        ->and($export['pending_links'])->toBe([['purpose' => 'recover', 'site' => 'golfdom-fr', 'expires_at' => '2026-10-09T13:00:00Z']])
        ->and($output)->not->toContain($hash);

    // Another org's link for the same key is that org's to export.
    expect(json_decode(readersRun(['action' => 'export', '--org' => 'rival', '--reader' => (string) $this->reader->getKey()])[1], true)['pending_links'])->toBe([]);
});

it('says what it did for an address with no account, and exits 1: nothing under an identifier was looked for', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-09 12:00:00', 'UTC'));
    readersLink($this->world['sites']['golfdom'], ReaderTokens::REGISTER, 'newcomer@kitsune.test');
    readersLink($this->world['sites']['rival'], ReaderTokens::REGISTER, 'newcomer@kitsune.test');

    expect(readersRun(['action' => 'export', '--org' => 'golfdom'], "NewComer@Kitsune.test\n"))
        ->toBe([1, 'No reader in [golfdom] has that address, so nothing held under a reader\'s identifier was looked for — for a reader the host deleted, run again with --reader=<id>. A sign-up link mailed to it waits unused at [golfdom], until 2026-10-09T13:00:00Z.'])
        ->and(readersRun(['action' => 'export', '--org' => 'golfdom'], "nobody@kitsune.test\n"))
        ->toBe([1, 'No reader in [golfdom] has that address, so nothing held under a reader\'s identifier was looked for — for a reader the host deleted, run again with --reader=<id>. No sign-up link mailed to it is waiting.'])
        ->and(readersRun(['action' => 'erase', '--org' => 'golfdom', '--force' => true], "newcomer@kitsune.test\n"))
        ->toBe([1, 'No reader in [golfdom] has that address, so nothing held under a reader\'s identifier was looked for — for a reader the host deleted, run again with --reader=<id>. Erased 1 pending sign-up link.'])
        ->and(DB::table('reader_tokens')->pluck('org_id')->all())->toBe([$this->world['rival']->getKey()]);
});

it('erases a reader\'s links with their account — the recovery link by key, a sign-up link by the address normalised', function (): void {
    config(['auth.providers.readers.model' => ShoutingReader::class]);
    readersLink($this->world['sites']['golfdom'], ReaderTokens::RECOVER, (string) $this->reader->getKey());
    // Minted before the host imported the account, so it waits under the address.
    readersLink($this->world['sites']['golfdom-fr'], ReaderTokens::REGISTER, 'subscriber@kitsune.test');
    readersLink($this->world['sites']['rival'], ReaderTokens::REGISTER, 'subscriber@kitsune.test');

    expect(readersRun(['action' => 'erase', '--org' => 'golfdom', '--force' => true, '--reader' => (string) $this->reader->getKey()]))
        ->toBe([0, 'Erased 0 entitlement grants and 2 pending links; the account was deleted.'])
        ->and(DB::table('reader_tokens')->pluck('org_id')->all())->toBe([$this->world['rival']->getKey()]);
});

it('keeps a reader\'s links when the account cannot be erased', function (): void {
    readersLink($this->world['sites']['golfdom'], ReaderTokens::RECOVER, (string) $this->reader->getKey());
    Reader::deleting(static function (): bool {
        throw new RuntimeException('The host refused to delete the row.');
    });

    expect(readersRun(['action' => 'erase', '--org' => 'golfdom', '--force' => true, '--reader' => (string) $this->reader->getKey()])[0])->toBe(1)
        ->and(DB::table('reader_tokens')->count())->toBe(1);
});

it('says on the status line whether mail can reach a reader, and how long a password must be', function (): void {
    app()->detectEnvironment(static fn (): string => 'production');
    config(['app.env' => 'production', 'mail.default' => 'log', 'kitsune.passwords.min_characters' => 'twenty']);

    $lines = explode("\n", readersRun(['action' => 'status'])[1]);

    expect($lines[2])->toBe('Reader mail: CLOSED — the mailer [log] uses the [log] transport, which keeps mail instead of sending it, so sign-up and recovery answer 503. Sign-in still works.')
        ->and($lines[3])->toStartWith('Passwords: at least 15 characters — ');

    config(['mail.default' => 'smtp', 'mail.from.address' => 'readers@kitsunecms.org', 'kitsune.passwords.min_characters' => 20]);

    $lines = explode("\n", readersRun(['action' => 'status'])[1]);

    expect($lines[2])->toBe('Reader mail: deliverable through [smtp], from [readers@kitsunecms.org].')
        ->and($lines[3])->toBe('Passwords: at least 20 characters.');
});

it('names each site no link can be mailed from, and why', function (): void {
    app()->detectEnvironment(static fn (): string => 'production');
    config(['app.env' => 'production']);
    DB::table('sites')->where('id', $this->world['sites']['shop']->getKey())->update(['base_url' => '//rival.test/shop']);

    $lines = explode("\n", readersRun(['action' => 'status', '--org' => 'rival'])[1]);

    expect(array_slice($lines, 4))->toBe([
        'Site [rival]: off (platform default); addresses hidden from owners (platform default) — no link can be mailed from it, because it has no host of its own; kitsune:site address gives it one.',
        'Site [shop]: off (platform default); addresses hidden from owners (platform default) — no link can be mailed from it, because its address names no scheme; kitsune:site address gives it one.',
    ]);
});

it('warns that a strict session cookie stops a link clicked in a webmail page, and says nothing for lax', function (): void {
    config(['session.same_site' => 'strict']);

    expect(explode("\n", readersRun(['action' => 'status'])[1])[3])
        ->toBe('Sessions: SameSite is strict (SESSION_SAME_SITE), so a mailed link opened from a webmail page arrives without its session and answers 410; pasted into the address bar it works. lax, Laravel\'s default, works both ways.');

    config(['session.same_site' => 'lax']);

    expect(readersRun(['action' => 'status'])[1])->not->toContain('SameSite');
});

it('reads staging as an environment where mail must reach a reader, on the status line too', function (): void {
    app()->detectEnvironment(static fn (): string => 'staging');
    config(['app.env' => 'staging', 'mail.default' => 'log']);

    expect(explode("\n", readersRun(['action' => 'status'])[1])[2])->toStartWith('Reader mail: CLOSED — the mailer [log]');

    config(['mail.default' => 'smtp', 'mail.from.address' => 'readers@kitsunecms.org']);

    expect(explode("\n", readersRun(['action' => 'status'])[1])[2])->toBe('Reader mail: deliverable through [smtp], from [readers@kitsunecms.org].');
});
