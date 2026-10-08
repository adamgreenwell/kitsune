<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Models\Reader;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Models\Entitlement;
use Kitsune\Core\Readers\ReaderErasure;
use Kitsune\Core\Settings\SettingsWriter;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
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
        ->toBe([1, 'Refusing: kitsune:readers has five actions — status, mode, find, export and erase. Nothing was read or written.']);
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
    ])]);
});

it('says no guard is declared without failing a deploy that has none', function (): void {
    config(['kitsune.readers.guard' => null]);

    expect(readersRun(['action' => 'status']))->toBe([0, implode("\n", [
        'Reader guard: none declared (kitsune.readers.guard) — no reader can sign in.',
        'Reader accounts: NOT usable — no usable reader guard is declared, so no reader can sign in.',
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
        ->and(array_slice(explode("\n", $output), 2))->toBe([
            'Site [admin-only]: open (inherited from the organisation) — it has no public address yet, so no reader page can be reached; kitsune:site address gives it one.',
            'Site [golfdom]: open (inherited from the organisation).',
            'Site [golfdom-fr]: sign-in (set on this site).',
        ]);

    expect(readersRun(['action' => 'status', '--org' => 'rival'])[1])->toContain('Site [rival]: off (platform default).');
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
        ->toBe([1, 'No reader in [rival] has that address.'])
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
        ->and(array_keys($export))->toBe(['account', 'entitlements', 'generated_at'])
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
        ->toBe([1, 'Refusing: erase deletes the reader\'s account and every entitlement they hold on every site of [golfdom]. Run it again with --force. Nothing was written.'])
        ->and(Reader::withoutScopeBecause('a test counts', static fn ($query) => $query->count()))->toBe(1);
});

it('erases a reader\'s entitlements on every site of the org, then their account — and a second run erases nothing', function (bool $byAddress): void {
    EntitlementFixture::plant($this->world['sites']['golfdom'], (string) $this->reader->getKey(), 'course.advanced-php');
    EntitlementFixture::plant($this->world['sites']['golfdom-fr'], (string) $this->reader->getKey(), 'course.advanced-php');
    $erase = static fn (): array => $byAddress
        ? readersRun(['action' => 'erase', '--org' => 'golfdom', '--force' => true], "subscriber@kitsune.test\n")
        : readersRun(['action' => 'erase', '--org' => 'golfdom', '--force' => true, '--reader' => (string) test()->reader->getKey()]);

    expect($erase())->toBe([0, 'Erased 2 entitlement grants; the account was deleted.'])
        ->and(Reader::withoutScopeBecause('a test counts', static fn ($query) => $query->count()))->toBe(0)
        ->and(Entitlement::withoutScopeBecause('a test counts', static fn ($query) => $query->count()))->toBe(0)
        ->and(DB::table('audit_log')->where('action', 'entitlement.erased')->count())->toBe(2);

    expect($erase())->toBe($byAddress
        ? [1, 'No reader in [golfdom] has that address.']
        : [0, 'Erased 0 entitlement grants; no account had that identifier.']);
})->with(['by identifier' => [false], 'by address' => [true]]);

it('erases in one org only: another org\'s reader with the same identifier is untouched', function (): void {
    EntitlementFixture::plant($this->world['sites']['golfdom'], (string) $this->reader->getKey(), 'course.advanced-php');

    expect(readersRun(['action' => 'erase', '--org' => 'rival', '--force' => true, '--reader' => (string) $this->reader->getKey()]))
        ->toBe([0, 'Erased 0 entitlement grants; no account had that identifier.'])
        ->and(Reader::withoutScopeBecause('a test counts', static fn ($query) => $query->count()))->toBe(1)
        ->and(Entitlement::withoutScopeBecause('a test counts', static fn ($query) => $query->count()))->toBe(1);
});

it('erases a reader of an organisation that has been deleted', function (): void {
    $this->world['golfdom']->delete();

    expect(readersRun(['action' => 'erase', '--org' => 'golfdom', '--force' => true, '--reader' => (string) $this->reader->getKey()]))
        ->toBe([0, 'Erased 0 entitlement grants; the account was deleted.']);
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
