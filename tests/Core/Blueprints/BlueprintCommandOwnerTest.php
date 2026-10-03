<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Panel;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Kitsune\Core\Auth\Contracts\ProvisionsMembership;
use Kitsune\Core\Auth\FirstOwnerCredentials;
use Kitsune\Core\Auth\RegistersOrgAwareProvider;
use Kitsune\Core\Blueprints\BlueprintRegistry;
use Kitsune\Core\Blueprints\Declarations\RoleDeclaration;
use Kitsune\Core\Blueprints\OnCollision;
use Kitsune\Core\Filament\Panels\KitsunePanel;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\FirstOwnerPanelUser;
use Kitsune\Core\Tests\Fixtures\FirstOwnerUnscopedUser;
use Kitsune\Core\Tests\Fixtures\FirstOwnerUser;
use Kitsune\Core\Tests\Fixtures\FixtureBlueprint;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/*
 * `kitsune:blueprint apply --owner` — the first owner, from the console (ADR-026, as amended by ADR-039).
 *
 * ⚠️ STANDARD INPUT IS ALWAYS A STREAM THE TEST OWNS. `applyThroughStream()` hands the command a `php://memory` stream
 * through the console kernel, which reaches both `--owner-password-stdin` and the real `QuestionHelper`. A run that
 * might read standard input is never made through `$this->artisan()`: a change that read it early would read, or wait
 * on, the test runner's own. Nor is a prompt scripted with `expectsQuestion()`: the prompt refuses the process's own
 * standard input unless it is a terminal, and `$this->artisan()` sets no stream, so the prompt would read the runner's.
 *
 * ⚠️ AND NOTHING SECRET COMES OUT. Every run through the stream is checked for the password and for a bcrypt prefix, and
 * every test for an exception reported and for an org left in context.
 */

const OWNER_COMMAND_EMAIL = 'owner@example.test';

const OWNER_COMMAND_PASSWORD = 'correct-horse-battery';

beforeEach(function (): void {
    Site::query()->withoutGlobalScopes()->forceDelete();
    Org::query()->withoutGlobalScopes()->forceDelete();

    config(['auth.providers.users.model' => FirstOwnerUser::class]);
    FirstOwnerUser::reset();

    /* As the skeleton's `AppServiceProvider` does: without it, signing in finds no member before an org is in context. */
    RegistersOrgAwareProvider::on($this->app);

    FixtureBlueprint::reset();
    app(BlueprintRegistry::class)->register(new FixtureBlueprint);

    Exceptions::fake();
});

afterEach(function (): void {
    Exceptions::assertNothingReported();

    expect(app(Context::class)->orgId())->toBeNull()
        ->and(app(Context::class)->siteId())->toBeNull();

    FirstOwnerUser::reset();
    FixtureBlueprint::reset();
    app(Context::class)->forget();
});

/**
 * `kitsune:blueprint apply`, with this standard input, through the console kernel as a shell would run it.
 *
 * @param  array<string, mixed>  $options
 * @return array{0: int, 1: string} the exit code and everything printed
 */
function applyThroughStream(array $options, string $stdin): array
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput(['command' => 'kitsune:blueprint', 'action' => 'apply', ...$options]);
    $input->setStream($stream);

    $status = app(ConsoleKernel::class)->handle($input, $output = new BufferedOutput);
    $printed = $output->fetch();

    expect($printed)->not->toContain(OWNER_COMMAND_PASSWORD)
        ->and($printed)->not->toContain('$2y$');

    return [$status, $printed];
}

/** @return array<string, mixed> Blog into `myblog`, with an owner whose password is piped in */
function ownerOverStdin(array $options = []): array
{
    return [
        'handle' => 'blog',
        '--org' => 'myblog',
        '--owner' => OWNER_COMMAND_EMAIL,
        '--owner-password-stdin' => true,
        '--no-interaction' => true,
        ...$options,
    ];
}

/** @return array<string, mixed> Blog into `myblog`, with an owner whose password is asked for */
function ownerAtPrompt(array $options = []): array
{
    return ['handle' => 'blog', '--org' => 'myblog', '--owner' => OWNER_COMMAND_EMAIL, ...$options];
}

/** @return array<string, int> the row count of every table `apply --owner` writes */
function ownerCommandCounts(): array
{
    $counts = [];

    foreach (['orgs', 'sites', 'users', 'org_user', 'site_user', 'roles', 'role_user', 'audit_log', 'blueprints'] as $table) {
        $counts[$table] = DB::table($table)->count();
    }

    return $counts;
}

function ownerCommandNothingWritten(): void
{
    expect(ownerCommandCounts())->toBe(array_fill_keys(array_keys(ownerCommandCounts()), 0));
}

function ownerCommandUser(): FirstOwnerUser
{
    return FirstOwnerUser::query()->withoutGlobalScopes()->where('email', OWNER_COMMAND_EMAIL)->firstOrFail();
}

describe('creating the first owner', function (): void {
    it('creates the org, its site and its owner, then applies Blog, from a password piped in', function (): void {
        [$status, $out] = applyThroughStream(ownerOverStdin(), OWNER_COMMAND_PASSWORD."\n");

        expect($status)->toBe(0)
            ->and($out)->toContain('Created organisation myblog, its first site myblog and its first owner owner@example.test (ADR-026).')
            ->and($out)->toContain('created  role blog_writer: entry.post.create, entry.post.update, entry.post.view, entry.tag.view')
            ->and($out)->toContain('Applied blog 1.0.0 into myblog. 0 indexed.')
            ->and($out)->toContain('2 roles were created and nobody holds them: an owner assigns them under Roles (ADR-033).')
            ->and($out)->toContain('owner@example.test owns myblog. No Kitsune admin panel is configured, so there is no admin to sign in to yet.')
            ->and($out)->not->toContain('this organisation has no owner yet');

        $owner = ownerCommandUser();
        $actions = AuditLog::query()->withoutGlobalScopes()->pluck('action');

        expect(Hash::check(OWNER_COMMAND_PASSWORD, $owner->password))->toBeTrue()
            ->and(Role::query()->withoutGlobalScopes()->count())->toBe(3)
            ->and(Role::query()->withoutGlobalScopes()->where('is_owner', true)->pluck('handle')->all())->toBe(['owner'])
            ->and(ownerCommandCounts())->toMatchArray(['orgs' => 1, 'sites' => 1, 'users' => 1, 'org_user' => 1, 'site_user' => 1, 'role_user' => 1])
            ->and($actions->filter(fn (string $action): bool => $action === 'role.granted'))->toHaveCount(14)
            ->and($actions->filter(fn (string $action): bool => str_starts_with($action, 'role.owner_') || str_starts_with($action, 'org.'))->values()->all())
            ->toBe(['role.owner_assigned']);
    });

    /** Once it exists, `--owner` is refused for good; the apply itself is as idempotent as ever. */
    it('applies again without `--owner`, and refuses it with', function (): void {
        applyThroughStream(ownerOverStdin(), OWNER_COMMAND_PASSWORD."\n");

        $this->artisan('kitsune:blueprint apply blog --org=myblog --no-interaction')
            ->expectsOutputToContain('already applied at this version')
            ->assertSuccessful();

        [$status, $out] = applyThroughStream(ownerOverStdin(['--owner' => 'second@example.test']), OWNER_COMMAND_PASSWORD."\n");

        expect($status)->toBe(1)
            ->and($out)->toContain('Refusing `--owner`: organisation [myblog] already exists')
            ->and(ownerCommandCounts()['users'])->toBe(1);
    });

    /** ⚠️ THE REAL `QuestionHelper`, which `expectsQuestion()` replaces: hidden, untrimmed, and read from the input's stream. */
    it('asks twice at a hidden prompt, and shows neither answer', function (): void {
        [$status, $out] = applyThroughStream(ownerAtPrompt(), OWNER_COMMAND_PASSWORD."\n".OWNER_COMMAND_PASSWORD."\n");

        expect($status)->toBe(0)
            ->and($out)->toContain(sprintf(FirstOwnerCredentials::PROMPT, OWNER_COMMAND_EMAIL))
            ->and($out)->toContain(FirstOwnerCredentials::CONFIRM)
            ->and($out)->toContain('its first owner owner@example.test');
    });

    /** The line ending is the terminal's, not the password's — on whichever path it arrives. */
    it('stores the password without its line ending, and only that', function (string $ending, bool $piped): void {
        [$status] = $piped
            ? applyThroughStream(ownerOverStdin(), OWNER_COMMAND_PASSWORD.$ending)
            : applyThroughStream(ownerAtPrompt(), OWNER_COMMAND_PASSWORD.$ending.OWNER_COMMAND_PASSWORD.$ending);

        $stored = ownerCommandUser()->password;

        expect($status)->toBe(0)
            ->and(Hash::check(OWNER_COMMAND_PASSWORD, $stored))->toBeTrue()
            ->and(Hash::check(OWNER_COMMAND_PASSWORD.$ending, $stored))->toBeFalse();
    })->with(['LF' => "\n", 'CRLF' => "\r\n"])->with(['piped' => true, 'typed' => false]);

    /*
     * ⚠️ ONE LINE ENDING, AND NOTHING ELSE. A trailing space, or a carriage return before the line end, is the password's
     * own — and refused, as nobody types it again — on either path. Review found both call sites surviving `rtrim()`.
     */
    it('takes only the line ending off, on either path, and refuses what is left', function (string $line, string $refusal, bool $piped): void {
        [$status, $out] = $piped
            ? applyThroughStream(ownerOverStdin(), $line)
            : applyThroughStream(ownerAtPrompt(), $line.$line);

        expect($status)->toBe(1)
            ->and($out)->toContain($refusal);

        ownerCommandNothingWritten();
    })->with([
        'a trailing space' => [OWNER_COMMAND_PASSWORD." \n", 'The password begins or ends with whitespace'],
        'a carriage return before the line end' => [OWNER_COMMAND_PASSWORD."\r\r\n", 'The password contains a control character'],
    ])->with(['piped' => true, 'typed' => false]);

    /** Beside the refusal below: a blueprint that shares the handle quietly leaves core's owner role alone. */
    it('applies a blueprint that skips the owner role, and leaves the owner role as it is', function (): void {
        FixtureBlueprint::$roles = [new RoleDeclaration('owner', 'Owner', ['dispatch' => ['view']], OnCollision::Skip)];

        [$status, $out] = applyThroughStream(ownerOverStdin(['handle' => 'fixture', '--org' => 'acme']), OWNER_COMMAND_PASSWORD."\n");

        expect($status)->toBe(0)
            ->and($out)->toContain('skipped  role owner (already defined here; left as it is — its grants were not added)')
            ->and(Role::query()->withoutGlobalScopes()->where('handle', 'owner')->sole()->is_owner)->toBeTrue();
    });

    /** With Kitsune's panel bound, the last line says where to sign in — the panel's own sign-in page. */
    it('says where to sign in, where there is a panel', function (): void {
        app()->instance(KitsunePanel::PANEL_BINDING, Panel::make()->id('admin')->login());
        Route::get('/admin/login', fn (): string => '')->name('filament.admin.auth.login');
        app('router')->getRoutes()->refreshNameLookups();
        config(['auth.providers.users.model' => FirstOwnerPanelUser::class]);

        [$status, $out] = applyThroughStream(ownerOverStdin(), OWNER_COMMAND_PASSWORD."\n");

        expect($status)->toBe(0)
            ->and($out)->toContain('Sign in at http://localhost/admin/login as owner@example.test.')
            ->and($out)->not->toContain('No Kitsune admin panel is configured');
    });

    /** A panel that signs in some other way is still a panel: the line says it names no page, not that there is no admin. */
    it('does not say there is no admin where the panel has no sign-in page', function (): void {
        app()->instance(KitsunePanel::PANEL_BINDING, Panel::make()->id('admin'));
        config(['auth.providers.users.model' => FirstOwnerPanelUser::class]);

        [$status, $out] = applyThroughStream(ownerOverStdin(), OWNER_COMMAND_PASSWORD."\n");

        expect($status)->toBe(0)
            ->and($out)->toContain('owner@example.test owns myblog. Kitsune\'s admin panel names no sign-in page this command can show')
            ->and($out)->not->toContain('No Kitsune admin panel is configured');
    });
});

describe('refusing at the prompt', function (): void {
    /** ⚠️ Only the real helper trims, so only the real helper can show a trimming prompt — `expectsQuestion()` cannot. */
    it('refuses a password with spaces around it, as typed', function (): void {
        $padded = '  '.OWNER_COMMAND_PASSWORD."  \n";

        [$status, $out] = applyThroughStream(ownerAtPrompt(), $padded.$padded);

        expect($status)->toBe(1)
            ->and($out)->toContain('The password begins or ends with whitespace')
            ->and($out)->not->toContain(FirstOwnerCredentials::CONFIRM);

        ownerCommandNothingWritten();
    });

    /** Refused before the confirmation is asked: the second line would be read as one, and its prompt printed. */
    it('refuses a short password before asking for it again', function (): void {
        [$status, $out] = applyThroughStream(ownerAtPrompt(), str_repeat('a', 14)."\n".str_repeat('a', 14)."\n");

        expect($status)->toBe(1)
            ->and($out)->toContain('The password is shorter than 15 characters.')
            ->and($out)->not->toContain(FirstOwnerCredentials::CONFIRM);

        ownerCommandNothingWritten();
    });

    it('refuses two passwords that differ', function (): void {
        [$status, $out] = applyThroughStream(ownerAtPrompt(), OWNER_COMMAND_PASSWORD."\n".OWNER_COMMAND_PASSWORD."!\n");

        expect($status)->toBe(1)
            ->and($out)->toContain('The two passwords did not match. Nothing was written.');

        ownerCommandNothingWritten();
    });

    it('refuses no answer at all', function (string $stdin): void {
        [$status, $out] = applyThroughStream(ownerAtPrompt(), $stdin);

        expect($status)->toBe(1)
            ->and($out)->toContain('No password was entered. Nothing was written.');

        ownerCommandNothingWritten();
    })->with(['an empty line' => ["\n"], 'nothing' => [''], 'nothing after the first answer' => [OWNER_COMMAND_PASSWORD."\n"]]);

    /*
     * ⚠️ THE PROMPT REFUSES A STANDARD INPUT THAT IS NOT A TERMINAL, where Symfony would read the "hidden" answer plainly
     * while whoever types it watches it echo — `ssh host 'php artisan …'` with no `-t`. `$this->artisan()` sets no stream,
     * so the prompt reads the runner's own standard input; where that is a terminal there is nothing to refuse.
     */
    it('refuses to ask on a standard input that is not a terminal', function (): void {
        $this->artisan('kitsune:blueprint', ['action' => 'apply', ...ownerAtPrompt()])
            ->expectsOutputToContain('Refusing to ask for the password: standard input is not a terminal, so what is typed '
                .'at the prompt could not be hidden')
            ->doesntExpectOutputToContain(sprintf(FirstOwnerCredentials::PROMPT, OWNER_COMMAND_EMAIL))
            ->assertFailed();

        ownerCommandNothingWritten();
    })->skip(@stream_isatty(STDIN), 'standard input is a terminal here');
});

/*
 * ⚠️ EVERY ONE OF THESE IS REFUSED BEFORE A PASSWORD IS ASKED FOR. They run with no question scripted, so a prompt
 * would make Mockery throw — or, where the runner's standard input is no terminal, be refused in its own words, which
 * none of these expects; and each leaves every table empty.
 */
describe('refusing before the password', function (): void {
    it('refuses `--owner` with no address', function (string $command): void {
        $this->artisan($command)
            ->expectsOutputToContain('`--owner` needs the first owner\'s email address, e.g. `--owner=you@example.com`. Nothing was written.')
            ->assertFailed();

        ownerCommandNothingWritten();
    })->with([
        'bare' => ['kitsune:blueprint apply blog --org=myblog --owner'],
        'empty' => ['kitsune:blueprint apply blog --org=myblog --owner='],
    ]);

    it('refuses `--owner-password-stdin` with no `--owner`', function (): void {
        [$status, $out] = applyThroughStream(
            ['handle' => 'blog', '--org' => 'myblog', '--owner-password-stdin' => true, '--no-interaction' => true],
            OWNER_COMMAND_PASSWORD."\n",
        );

        expect($status)->toBe(1)
            ->and($out)->toContain('`--owner-password-stdin` reads the first owner\'s password, and no `--owner` was given. Add '
                .'`--owner=<email>`, or drop `--owner-password-stdin`. Nothing was written.');

        ownerCommandNothingWritten();
    });

    /**
     * ⚠️ READ FROM THE WHOLE OUTPUT, NOT THE OUTPUT MOCK. `doesntExpectOutputToContain()` is checked only against a line
     * no earlier expectation took — and the refusal line, echoing the value, was taken by the one expecting the
     * refusal. The mutation run found the echo passing.
     */
    it('refuses an address the sign-in form would not accept, and does not repeat it', function (string $email, string $part): void {
        [$status, $out] = applyThroughStream(ownerAtPrompt(['--owner' => $email]), '');

        expect($status)->toBe(1)
            ->and($out)->toContain('Refusing `--owner`: that is not an email address the sign-in form accepts')
            ->and($out)->not->toContain($part);

        ownerCommandNothingWritten();
    })->with([
        'not an address' => ['hunter2-not-an-address', 'hunter2'],
        'a quoted local part' => ['"a b"@x.test', '"a b"'],
    ]);

    /** `--owner` writes the owner role first, so a blueprint that will not share the handle would fail after it existed. */
    it('refuses a blueprint that would refuse the owner role', function (): void {
        FixtureBlueprint::$roles = [new RoleDeclaration('owner', 'Owner', ['dispatch' => ['view']])];

        $this->artisan('kitsune:blueprint', ['action' => 'apply', 'handle' => 'fixture', '--org' => 'acme', '--owner' => OWNER_COMMAND_EMAIL])
            ->expectsOutputToContain('Refusing `--owner`: blueprint [fixture] declares a role [owner] with onCollision: fail')
            ->assertFailed();

        ownerCommandNothingWritten();
    });

    it('refuses `--owner` where it may not ask, unless the password is piped in', function (): void {
        $this->artisan('kitsune:blueprint apply blog --org=myblog --owner=owner@example.test --no-interaction')
            ->expectsOutputToContain('Refusing `--owner` under `--no-interaction`: the password is asked at a hidden prompt')
            ->assertFailed();

        ownerCommandNothingWritten();
    });

    it('refuses a user model it cannot create the owner in', function (string $model, string $refusal): void {
        if ($model === 'elsewhere') {
            FirstOwnerUser::$connectionOverride = 'elsewhere';
            $model = FirstOwnerUser::class;
        }

        config(['auth.providers.users.model' => $model]);

        $this->artisan('kitsune:blueprint', ['action' => 'apply', 'handle' => 'blog', '--org' => 'myblog', '--owner' => OWNER_COMMAND_EMAIL])
            ->expectsOutputToContain($refusal)
            ->assertFailed();

        FirstOwnerUser::$connectionOverride = null;
        ownerCommandNothingWritten();
    })->with([
        'none resolves' => ['App\\Missing', 'Refusing `--owner`: no user model resolves'],
        'one without the contract' => [Authenticatable::class, 'does not implement '.ProvisionsMembership::class],
        'one with no membership scope' => [FirstOwnerUnscopedUser::class, 'registers no membership scope'],
        'one on another connection' => ['elsewhere', 'role assignments (`role_user.user_id`) do not refer to'],
    ]);

    /** ⚠️ Before standard input is read: the empty stream would be a refusal of its own, and it is not the one printed. */
    it('refuses an org that already exists', function (bool $piped): void {
        Org::create(['slug' => 'acme', 'name' => 'Acme']);
        $before = ownerCommandCounts();

        $refusal = 'Refusing `--owner`: organisation [acme] already exists, and a first owner is created only by the run '
            .'that creates the first organisation on an empty installation (ADR-026). If an earlier run created acme with '
            .'`--owner`, its owner exists: run the same command without `--owner` and `--owner-password-stdin` to apply, '
            .'or finish applying, [blog]. Nothing was written.';

        if ($piped) {
            [$status, $out] = applyThroughStream(ownerOverStdin(['--org' => 'acme']), '');

            expect($status)->toBe(1)
                ->and($out)->toContain($refusal)
                ->and($out)->not->toContain('standard input had no password');
        } else {
            $this->artisan('kitsune:blueprint', ['action' => 'apply', 'handle' => 'blog', '--org' => 'acme', '--owner' => OWNER_COMMAND_EMAIL])
                ->expectsOutputToContain($refusal)
                ->assertFailed();
        }

        expect(ownerCommandCounts())->toBe($before);
    })->with(['asked' => false, 'piped' => true]);

    it('refuses an installation that is not empty', function (string $what, string $counted): void {
        match ($what) {
            'another org' => Org::create(['slug' => 'other', 'name' => 'Other']),
            'a deleted org' => Org::create(['slug' => 'other', 'name' => 'Other'])->delete(),
            default => DB::table('users')->insert(['email' => 'somebody@example.test']),
        };

        $before = ownerCommandCounts();

        $this->artisan('kitsune:blueprint', ['action' => 'apply', 'handle' => 'blog', '--org' => 'myblog', '--owner' => OWNER_COMMAND_EMAIL])
            ->expectsOutputToContain('Refusing `--owner`: this installation has '.$counted.'. A first owner is created only '
                .'where there are neither')
            ->assertFailed();

        expect(ownerCommandCounts())->toBe($before);
    })->with([
        'another org' => ['another org', '1 organisation(s), deleted ones included, and 0 account(s)'],
        'a deleted org' => ['a deleted org', '1 organisation(s), deleted ones included, and 0 account(s)'],
        'an account no scope can see' => ['an account', '0 organisation(s), deleted ones included, and 1 account(s)'],
    ]);

    it('refuses standard input with no password on its first line', function (string $stdin): void {
        [$status, $out] = applyThroughStream(ownerOverStdin(), $stdin);

        expect($status)->toBe(1)
            ->and($out)->toContain('Refusing `--owner-password-stdin`: standard input had no password on its first line.');

        ownerCommandNothingWritten();
    })->with(['nothing' => [''], 'an empty line' => ["\n"]]);
});

/*
 * ⚠️ THE OWNER COMMITS BEFORE THE APPLY BEGINS, SO AN APPLY THAT FAILS LEAVES THE OWNER. That is the point — the org has
 * somebody who can sign in and finish it — and the output says so, and says how: the same command without `--owner`.
 */
describe('an apply that fails after the owner was created', function (): void {
    it('keeps the owner, says so, and finishes when run again without `--owner`', function (): void {
        $failing = true;
        EntryType::creating(function () use (&$failing): void {
            if ($failing) {
                throw new RuntimeException('the entry type write failed, for the sake of argument');
            }
        });

        [$status, $out] = applyThroughStream(ownerOverStdin(['handle' => 'fixture', '--org' => 'acme']), OWNER_COMMAND_PASSWORD."\n");

        $error = strpos($out, 'the entry type write failed, for the sake of argument');
        $resume = strpos($out, 'Organisation acme, its first site and its first owner owner@example.test were created before '
            .'the apply and remain. Run the same command again without `--owner` and `--owner-password-stdin` to finish '
            .'applying [fixture].');

        expect($status)->toBe(1)
            ->and($error)->toBeInt()
            ->and($resume)->toBeInt()
            ->and($error)->toBeLessThan($resume)
            ->and(ownerCommandCounts())->toMatchArray(['orgs' => 1, 'users' => 1, 'role_user' => 1])
            ->and(Blueprint::query()->withoutGlobalScopes()->sole()->applied_at)->toBeNull();

        $failing = false;

        /* The instruction as printed: the same options, less those two. */
        $options = ownerOverStdin(['handle' => 'fixture', '--org' => 'acme']);
        unset($options['--owner'], $options['--owner-password-stdin']);

        [$finished] = applyThroughStream($options, '');

        expect($finished)->toBe(0);

        [$again, $refused] = applyThroughStream(ownerOverStdin(['handle' => 'fixture', '--org' => 'acme']), OWNER_COMMAND_PASSWORD."\n");

        expect(Blueprint::query()->withoutGlobalScopes()->sole()->applied_at)->not->toBeNull()
            ->and($again)->toBe(1)
            ->and($refused)->toContain('Refusing `--owner`: organisation [acme] already exists');
    });
});
