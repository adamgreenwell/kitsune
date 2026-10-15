<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Credentials\CredentialCipher;
use Kitsune\Core\Credentials\CredentialConsoleAuthority;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialSlots;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Credential;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Fx;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    Fx::boot();
    $this->org = Fx::org('operator-credentials');
    Auth::guard('web')->logout();
    Permissions::forget();
});

afterEach(fn () => Fx::tearDown());

/** @return array{0:int,1:string} */
function credentialOperatorCall(string $action, array $options = [], string $stdin = '', bool $interactive = false): array
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $stdin);
    rewind($stream);
    $input = new ArrayInput(['command' => 'kitsune:credentials:'.$action, ...$options]);
    $input->setStream($stream);
    $input->setInteractive($interactive);
    try {
        $status = app(ConsoleKernel::class)->handle($input, $output = new BufferedOutput);

        return [$status, $output->fetch()];
    } finally {
        fclose($stream);
    }
}

function credentialOperatorSeed(string $slot = Fx::SHARED, ?CredentialMode $mode = null, ?string $value = null): void
{
    $value ??= Fx::value('', 40);
    CredentialConsoleAuthority::run(app(Context::class), 'set', $slot, $mode, null,
        fn (CredentialConsoleAuthority $console) => Fx::writer()->set($slot, $mode, $value, console: $console));
}

function credentialOperatorAuditListener(Closure $listener, Closure $work): void
{
    $events = AuditLog::getEventDispatcher();
    $name = 'eloquent.creating: '.AuditLog::class;
    $before = $events->getRawListeners()[$name] ?? [];
    AuditLog::creating($listener);
    try {
        $work();
    } finally {
        $events->forget($name);
        foreach ($before as $old) {
            $events->listen($name, $old);
        }
    }
}

it('sets a credential from stdin and restores the exact previous site context', function (): void {
    $other = Fx::org('operator-other');
    $site = Site::create(['handle' => 'other', 'slug' => 'operator-other', 'name' => 'Other', 'locale' => 'en']);
    app(Context::class)->setSite($site);
    $value = Fx::value('', 40);
    [$code, $printed] = credentialOperatorCall('set', ['slot' => Fx::SHARED, '--org' => $this->org->slug, '--stdin' => true], $value."\r\nignored");

    expect($code)->toBe(0)->and($printed)->toContain('Credential saved.')->not->toContain($value)
        ->and(app(Context::class)->orgId())->toBe($other->getKey())
        ->and(app(Context::class)->siteId())->toBe($site->getKey())
        ->and(Credential::query()->count())->toBe(0);
    $row = DB::table('credentials')->where('org_id', $this->org->getKey())->first();
    $audit = DB::table('audit_log')->where('action', CredentialWriter::SET)->first();
    expect($row->mode)->toBe('none')->and($row->ciphertext)->not->toContain($value)
        ->and($audit->org_id)->toBe($this->org->getKey())->and($audit->site_id)->toBeNull()->and($audit->actor_id)->toBeNull();
    app(Context::class)->forget()->setOrg($this->org);
    expect(Fx::reader()->secret(Fx::SHARED)->reveal())->toBe($value);
});

it('sets both explicit modes without a local password or accidental mode switch', function (CredentialMode $mode, string $prefix): void {
    $value = Fx::value($prefix);
    [$code, $printed] = credentialOperatorCall('set', ['slot' => Fx::PAYMENT, '--org' => $this->org->slug, '--mode' => $mode->value, '--stdin' => true], $value."\n");
    expect($code)->toBe(0)->and($printed)->not->toContain($value)
        ->and(DB::table('credentials')->value('mode'))->toBe($mode->value)
        ->and(DB::table('org_credential_modes')->count())->toBe(0);
})->with([[CredentialMode::Test, 'fx_test_'], [CredentialMode::Live, 'fx_live_']]);

it('refuses invalid inputs without printing any supplied value or changing context', function (array $options, string $stdin): void {
    $original = app(Context::class)->orgId();
    [$code, $printed] = credentialOperatorCall('set', ['slot' => Fx::SHARED, '--org' => $this->org->slug, '--stdin' => true, ...$options], $stdin);
    expect($code)->toBe(1)->and($printed)->toContain('Refusing:')
        ->and(DB::table('credentials')->count())->toBe(0)->and(app(Context::class)->orgId())->toBe($original);
    foreach ([$stdin, $options['slot'] ?? '', $options['--mode'] ?? '', $options['--org'] ?? ''] as $typed) {
        if ($typed !== '') {
            expect($printed)->not->toContain($typed);
        }
    }
})->with([
    'unknown slot' => [['slot' => 'fx.undeclared'], 'privateinput'],
    'pasted slot' => [['slot' => 'accidentally pasted value'], 'privateinput'],
    'bad mode' => [['--mode' => 'secret-typed-as-mode'], 'privateinput'],
    'neutral mode' => [['--mode' => 'live'], 'privateinput'],
    'missing org' => [['--org' => null], 'privateinput'],
    'unknown org' => [['--org' => 'secret-typed-as-org'], 'privateinput'],
    'empty' => [[], ''],
    'overlong' => [[], str_repeat('private', 200)],
    'spaces' => [[], 'private invalid spaces'],
]);

it('refuses a pipe as an implicit prompt under either interaction setting', function (bool $interactive): void {
    [$code, $printed] = credentialOperatorCall('set', ['slot' => Fx::SHARED, '--org' => $this->org->slug], Fx::value('', 40), $interactive);
    expect($code)->toBe(1)->and($printed)->not->toContain('New credential value (hidden)')
        ->and(DB::table('credentials')->count())->toBe(0);
})->with([false, true]);

it('does not turn a signed-in owner or nonowner into a console system actor', function (bool $owner): void {
    Fx::member(owner: $owner);
    [$code] = credentialOperatorCall('set', ['slot' => Fx::SHARED, '--org' => $this->org->slug, '--stdin' => true], Fx::value('', 40));
    expect($code)->toBe(1)->and(DB::table('credentials')->count())->toBe(0);
})->with([true, false]);

it('requires explicit removal and mode confirmations and keeps their audit semantics', function (): void {
    credentialOperatorSeed();
    expect(credentialOperatorCall('remove', ['slot' => Fx::SHARED, '--org' => $this->org->slug])[0])->toBe(1)
        ->and(Credential::query()->whereNotNull('ciphertext')->count())->toBe(1)
        ->and(credentialOperatorCall('remove', ['slot' => Fx::SHARED, '--org' => $this->org->slug, '--force' => true])[0])->toBe(0)
        ->and(Credential::query()->whereNull('ciphertext')->count())->toBe(1)
        ->and(credentialOperatorCall('remove', ['slot' => Fx::SHARED, '--org' => $this->org->slug, '--force' => true])[0])->toBe(0)
        ->and(AuditLog::query()->where('action', CredentialWriter::REMOVED)->count())->toBe(1);
    expect(credentialOperatorCall('mode', ['mode' => 'live', '--org' => $this->org->slug])[0])->toBe(1)
        ->and(DB::table('org_credential_modes')->count())->toBe(0)
        ->and(credentialOperatorCall('mode', ['mode' => 'live', '--org' => $this->org->slug, '--force' => true])[0])->toBe(0)
        ->and(DB::table('org_credential_modes')->value('mode'))->toBe('live')
        ->and(credentialOperatorCall('mode', ['mode' => 'live', '--org' => $this->org->slug, '--force' => true])[0])->toBe(0)
        ->and(AuditLog::query()->where('action', CredentialWriter::MODE_LIVE)->count())->toBe(1);
});

it('re-encrypts undeclared retained credentials without changing their value or change timestamp', function (): void {
    $value = Fx::value('', 40);
    credentialOperatorSeed(value: $value);
    $before = DB::table('credentials')->first();
    $oldKey = config('app.key');
    config(['app.key' => Fx::appKey(), 'app.previous_keys' => [$oldKey]]);
    Fx::forget();
    app(CredentialSlots::class)->flush();
    [$code, $printed] = credentialOperatorCall('reencrypt', ['--org' => $this->org->slug]);
    $after = DB::table('credentials')->first();

    expect($code)->toBe(0)->and($printed)->toContain('Re-encrypted 1; unchanged 0.')->not->toContain($value)
        ->not->toContain($before->ciphertext)->not->toContain($before->key_id)->not->toContain($after->key_id)
        ->and($after->ciphertext)->not->toBe($before->ciphertext)->and($after->changed_at)->toBe($before->changed_at)
        ->and($after->key_id)->toBe(app(CredentialCipher::class)->currentKeyId())
        ->and(app(CredentialCipher::class)->open(Credential::query()->first())->reveal())->toBe($value)
        ->and(AuditLog::query()->where('action', CredentialWriter::REENCRYPTED)->count())->toBe(1);
    expect(credentialOperatorCall('reencrypt', ['--org' => $this->org->slug])[1])->toContain('Re-encrypted 0; unchanged 1.')
        ->and(AuditLog::query()->where('action', CredentialWriter::REENCRYPTED)->count())->toBe(1);
});

it('bounds scanned rows and resumes past current rows and tombstones without rotating another org', function (): void {
    $oldKey = config('app.key');
    credentialOperatorSeed(Fx::PAYMENT, CredentialMode::Test, Fx::value('fx_test_'));
    credentialOperatorSeed(Fx::HOOK, CredentialMode::Test, Fx::value('fxhook_'));
    CredentialConsoleAuthority::run(app(Context::class), 'remove', Fx::HOOK, CredentialMode::Test, null,
        fn ($console) => Fx::writer()->remove(Fx::HOOK, CredentialMode::Test, console: $console));
    $ids = DB::table('credentials')->orderBy('id')->pluck('id')->all();
    $other = Fx::org('operator-rotation-other');
    credentialOperatorSeed();
    $otherBefore = DB::table('credentials')->where('org_id', $other->getKey())->first();
    app(Context::class)->setOrg($this->org);
    config(['app.key' => Fx::appKey(), 'app.previous_keys' => [$oldKey]]);
    Fx::forget();
    [$code, $out] = credentialOperatorCall('reencrypt', ['--org' => $this->org->slug, '--limit' => '1']);
    expect($code)->toBe(0)->and($out)->toContain('Re-encrypted 1; unchanged 0.')->toContain('--after='.$ids[0]);
    [$code, $out] = credentialOperatorCall('reencrypt', ['--org' => $this->org->slug, '--limit' => '1', '--after' => (string) $ids[0]]);
    expect($code)->toBe(0)->and($out)->toContain('Re-encrypted 0; unchanged 1.')->toContain('reached the end')
        ->and(DB::table('credentials')->where('org_id', $other->getKey())->first())->toEqual($otherBefore);
});

it('stops on unreadable data and reports the completed boundary so a repaired run resumes safely', function (): void {
    $oldKey = config('app.key');
    credentialOperatorSeed(Fx::PAYMENT, CredentialMode::Test, Fx::value('fx_test_'));
    credentialOperatorSeed(Fx::HOOK, CredentialMode::Test, Fx::value('fxhook_'));
    credentialOperatorSeed();
    $rows = DB::table('credentials')->orderBy('id')->get();
    DB::table('credentials')->where('id', $rows[1]->id)->update(['ciphertext' => 'broken-fixture-ciphertext']);
    config(['app.key' => Fx::appKey(), 'app.previous_keys' => [$oldKey]]);
    Fx::forget();
    $before = DB::transactionLevel();
    [$code, $out] = credentialOperatorCall('reencrypt', ['--org' => $this->org->slug]);
    expect($code)->toBe(1)->and($out)->toContain('Re-encrypted 1; unchanged 0.')->toContain('Last completed row ID: '.$rows[0]->id)
        ->not->toContain('broken-fixture-ciphertext')->not->toContain($rows[1]->ciphertext)
        ->and(DB::transactionLevel())->toBe($before)
        ->and(DB::table('credentials')->where('id', $rows[2]->id)->value('key_id'))->toBe($rows[2]->key_id)
        ->and(AuditLog::query()->where('action', CredentialWriter::REENCRYPTED)->count())->toBe(1);
    DB::table('credentials')->where('id', $rows[1]->id)->update(['ciphertext' => $rows[1]->ciphertext]);
    [$code, $out] = credentialOperatorCall('reencrypt', ['--org' => $this->org->slug, '--after' => (string) $rows[0]->id]);
    expect($code)->toBe(0)->and($out)->toContain('Re-encrypted 2; unchanged 0.')
        ->and(AuditLog::query()->where('action', CredentialWriter::REENCRYPTED)->count())->toBe(3);
});

it('rotates retained credentials for a soft-deleted org but refuses new consumer writes there', function (): void {
    credentialOperatorSeed();
    $oldKey = config('app.key');
    $this->org->delete();
    config(['app.key' => Fx::appKey(), 'app.previous_keys' => [$oldKey]]);
    Fx::forget();
    expect(credentialOperatorCall('reencrypt', ['--org' => $this->org->slug])[0])->toBe(0)
        ->and(credentialOperatorCall('set', ['slot' => Fx::SHARED, '--org' => $this->org->slug, '--stdin' => true], Fx::value('', 40))[0])->toBe(1);
});

it('refuses a stored credential when there is no current app key even if its key tag is missing', function (bool $missingTag): void {
    credentialOperatorSeed();
    if ($missingTag) {
        DB::table('credentials')->update(['key_id' => null]);
    }
    $before = DB::table('credentials')->first();
    config(['app.key' => '']);
    Fx::forget();
    expect(credentialOperatorCall('reencrypt', ['--org' => $this->org->slug])[0])->toBe(1)
        ->and(DB::table('credentials')->first())->toEqual($before)
        ->and(AuditLog::query()->where('action', CredentialWriter::REENCRYPTED)->count())->toBe(0);
})->with([false, true]);

it('does not overwrite a replacement made after the batch selected its row ID', function (): void {
    credentialOperatorSeed();
    $oldKey = config('app.key');
    $id = Credential::query()->value('id');
    config(['app.key' => Fx::appKey(), 'app.previous_keys' => [$oldKey]]);
    Fx::forget();
    $replacement = Fx::value('', 40);
    $events = Credential::getEventDispatcher();
    $name = 'eloquent.retrieved: '.Credential::class;
    $beforeListeners = $events->getRawListeners()[$name] ?? [];
    $interleaved = false;
    Credential::retrieved(function (Credential $row) use (&$interleaved, $id, $replacement): void {
        // The batch carries only IDs. Replace after that read, before the writer independently reloads its row.
        if (! $interleaved && $row->getKey() === $id && array_keys($row->getAttributes()) === ['id']) {
            $interleaved = true;
            credentialOperatorSeed(value: $replacement);
        }
    });
    try {
        [$code, $out] = credentialOperatorCall('reencrypt', ['--org' => $this->org->slug]);
        expect($interleaved)->toBeTrue()->and($code)->toBe(0)->and($out)->toContain('Re-encrypted 0; unchanged 1.')
            ->and(Fx::reader()->secret(Fx::SHARED)->reveal())->toBe($replacement)
            ->and(AuditLog::query()->where('action', CredentialWriter::REENCRYPTED)->count())->toBe(0)
            ->and(AuditLog::query()->where('action', CredentialWriter::REPLACED)->count())->toBe(1);
    } finally {
        $events->forget($name);
        foreach ($beforeListeners as $listener) {
            $events->listen($name, $listener);
        }
    }
});

it('refuses malformed batch bounds without reading or rotating rows', function (string $option, mixed $value): void {
    credentialOperatorSeed();
    $before = DB::table('credentials')->first();
    expect(credentialOperatorCall('reencrypt', ['--org' => $this->org->slug, $option => $value])[0])->toBe(1)
        ->and(DB::table('credentials')->first())->toEqual($before);
})->with([
    ['--limit', '0'], ['--limit', '1001'], ['--limit', '01'], ['--limit', '1.0'], ['--limit', '-1'],
    ['--after', '-1'], ['--after', '01'], ['--after', '9223372036854775808'], ['--after', '1e2'],
]);

it('rolls back a cancelled rotation audit and restores the original context', function (): void {
    credentialOperatorSeed();
    $oldKey = config('app.key');
    config(['app.key' => Fx::appKey(), 'app.previous_keys' => [$oldKey]]);
    Fx::forget();
    $before = DB::table('credentials')->first();
    $level = DB::transactionLevel();
    credentialOperatorAuditListener(static fn (AuditLog $audit): ?bool => $audit->action === CredentialWriter::REENCRYPTED ? false : null,
        function () use ($before, $level): void {
            expect(credentialOperatorCall('reencrypt', ['--org' => $this->org->slug])[0])->toBe(1)
                ->and(DB::table('credentials')->first())->toEqual($before)
                ->and(DB::transactionLevel())->toBe($level)->and(app(Context::class)->orgId())->toBe($this->org->getKey());
        });
});

it('redacts throwing audit listeners and rolls their credential change back', function (): void {
    $value = Fx::value('', 40);
    credentialOperatorAuditListener(static fn () => throw new RuntimeException($value),
        function () use ($value): void {
            [$code, $out] = credentialOperatorCall('set', ['slot' => Fx::SHARED, '--org' => $this->org->slug, '--stdin' => true], $value);
            expect($code)->toBe(1)->and($out)->not->toContain($value)
                ->and(DB::table('credentials')->count())->toBe(0);
        });
});
