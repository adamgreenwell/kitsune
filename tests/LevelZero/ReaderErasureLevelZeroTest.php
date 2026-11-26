<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Models\Reader;
use App\Providers\AppServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Kitsune\Core\Entitlements\EntitlementWriter;
use Kitsune\Core\Privacy\ErasureJournalCipher;
use Kitsune\Core\Readers\ReaderErasure;
use Kitsune\Core\Readers\ReaderErasureReceipts;
use Kitsune\Core\Readers\ReaderTokens;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\ElsewhereReader;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/*
 * One reader's erasure at transaction level 0, on a file another process can read — ADR-037, as built: the entitlements,
 * the links waiting for them and the account go together or not at all, so a reader is never half-erased.
 */

beforeEach(function (): void {
    Artisan::call('migrate', ['--database' => 'custody', '--path' => TestCase::READERS_MIGRATIONS, '--realpath' => true, '--force' => true]);
    config(AppServiceProvider::readerConfig());
    Fx::boot();

    $this->org = Fx::org('zero');
    $this->site = Fx::site($this->org, 'zero');
    $this->reader = Reader::createReader('zero@kitsune.test', Hash::make('correct-horse-battery-staple'), null);
    Fx::plant($this->site, (string) $this->reader->getKey(), 'course.advanced-php');
    Fx::plant($this->site, (string) $this->reader->getKey(), 'course.linked');
    app(ReaderTokens::class)->mint(ReaderTokens::RECOVER, (string) $this->reader->getKey());
    Fx::nobody();
    app(Context::class)->forget()->setOrg($this->org);
});

afterEach(fn () => Fx::tearDown());

/** What another process sees. @return array{readers: int, entitlements: int, links: int} */
function readerZeroSeen(string $file): array
{
    $pdo = new PDO('sqlite:'.$file);

    return [
        'readers' => (int) $pdo->query('select count(*) from readers')->fetchColumn(),
        'entitlements' => (int) $pdo->query('select count(*) from entitlements')->fetchColumn(),
        'links' => (int) $pdo->query('select count(*) from reader_tokens')->fetchColumn(),
    ];
}

it('commits the entitlements\' erasure, the links\' and the account\'s together', function (): void {
    expect(readerZeroSeen($this->custodyFile))->toBe(['readers' => 1, 'entitlements' => 2, 'links' => 1]);

    expect(app(ReaderErasure::class)->erase((string) $this->reader->getKey()))->toBe(['grants' => 2, 'links' => 1, 'account' => true])
        ->and(readerZeroSeen($this->custodyFile))->toBe(['readers' => 0, 'entitlements' => 0, 'links' => 0])
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('allows an ordinary account erase with no anchor and refuses a requested replay receipt', function (): void {
    $key = (string) $this->reader->getKey();
    DB::table('readers')->where('id', $key)->update(['erasure_anchor' => null]);

    [$exit] = readerZeroJournal('erase', ['--reader' => $key, '--force' => true, '--record' => true]);
    expect($exit)->toBe(1)
        ->and(readerZeroSeen($this->custodyFile))->toBe(['readers' => 1, 'entitlements' => 2, 'links' => 1]);

    app(Context::class)->setOrg($this->org);
    expect(app(ReaderErasure::class)->erase($key))->toBe(['grants' => 2, 'links' => 1, 'account' => true])
        ->and(readerZeroSeen($this->custodyFile))->toBe(['readers' => 0, 'entitlements' => 0, 'links' => 0])
        ->and(DB::table('reader_erasure_receipts')->count())->toBe(0);
});

it('erases both grants when only one has an anchor and records the replayable grant', function (): void {
    $key = (string) $this->reader->getKey();
    $oldest = DB::table('entitlements')->where('reader_id', $key)->min('id');
    DB::table('entitlements')->where('id', $oldest)->update(['erasure_anchor' => null]);
    $anchor = DB::table('entitlements')->where('reader_id', $key)->whereNotNull('erasure_anchor')->value('erasure_anchor');

    expect(app(EntitlementWriter::class)->forget($key))->toBe(2)
        ->and(readerZeroSeen($this->custodyFile))->toBe(['readers' => 1, 'entitlements' => 0, 'links' => 1])
        ->and(DB::table('reader_erasure_receipts')->where('kind', 'grant')->pluck('anchor')->all())->toBe([$anchor]);
});

it('refuses to drop established erasure anchors on rollback', function (string $file, string $table): void {
    $migration = require dirname(__DIR__, 2).'/'.$file;
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Preserve reader erasure anchors')
        ->and(DB::table($table)->whereNotNull('erasure_anchor')->exists())->toBeTrue();
})->with([
    ['packages/core/database/migrations/0001_01_01_000027_anchor_reader_links.php', 'reader_tokens'],
    ['packages/core/database/migrations/0001_01_01_000028_anchor_entitlement_grants.php', 'entitlements'],
    ['skeleton/database/migrations/0001_01_01_000021_add_reader_erasure_anchor.php', 'readers'],
]);

it('keeps the entitlements and the links when the account cannot be erased', function (): void {
    Reader::deleting(static function (): bool {
        throw new RuntimeException('The host refused to delete the row.');
    });

    expect(fn () => app(ReaderErasure::class)->erase((string) $this->reader->getKey()))
        ->toThrow(RuntimeException::class, 'The host refused to delete the row.');

    expect(readerZeroSeen($this->custodyFile))->toBe(['readers' => 1, 'entitlements' => 2, 'links' => 1])
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

/** @param array<string,mixed> $options @return array{int,string} */
function readerZeroJournal(string $action, array $options = [], string $input = ''): array
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $input."\n");
    rewind($stream);
    $args = new ArrayInput(['command' => 'kitsune:readers', 'action' => $action] + $options + ['--org' => 'zero']);
    $args->setStream($stream);
    $output = new BufferedOutput;

    try {
        return [app(Kernel::class)->handle($args, $output), trim($output->fetch())];
    } finally {
        fclose($stream);
    }
}

it('replays a payload-free reader receipt without touching a reused reader ID in another physical backup', function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'app.maintenance.driver' => 'cache',
        'app.maintenance.store' => 'array',
    ]);
    $key = $this->reader->getKey();
    $anchor = $this->reader->getRawOriginal('erasure_anchor');
    DB::table('reader_consent_events')->insert([
        'org_id' => $this->org->id, 'site_id' => $this->site->id, 'reader_key' => (string) $key,
        'purpose' => 'profiling', 'notice_version' => 'v1', 'granted' => true,
        'recorded_at' => now('UTC'), 'expires_at' => now('UTC')->addDay(), 'retain_until' => now('UTC')->addDay(),
    ]);
    $foreignOrg = Fx::org('other');
    app(Context::class)->setOrg($foreignOrg);
    $foreign = Reader::createReader('foreign@kitsune.test', null, null);
    DB::table('readers')->where('id', $foreign->id)->update(['erasure_anchor' => $anchor]);
    app(Context::class)->forget()->setOrg($this->org);

    $backups = [];
    try {
        foreach (['original', 'reused'] as $fixture) {
            $path = (string) tempnam(sys_get_temp_dir(), 'kitsune-reader-replay-');
            unlink($path);
            $backups[$fixture] = $path;
            DB::connection()->statement('VACUUM INTO ?', [$path]);
        }

        [$exit, $message] = readerZeroJournal('erase', ['--reader' => (string) $key, '--force' => true, '--record' => true]);
        expect($exit)->toBe(0, $message)
            ->and(DB::table('reader_erasure_receipts')->where('org_id', $this->org->id)->pluck('anchor')->all())->toBe([$anchor])
            ->and(DB::table('reader_consent_events')->count())->toBe(0);

        [$exit, $journal] = readerZeroJournal('export-erasure-journal');
        expect($exit)->toBe(0, $journal);
        $decoded = json_decode(app(ErasureJournalCipher::class)->unseal($journal), true, flags: JSON_THROW_ON_ERROR);
        expect($decoded)->toBe(['format' => 'kitsune.reader-erasure.3', 'org' => $this->org->id, 'receipts' => ['signup' => [], 'grant' => [], 'account' => [$anchor]]]);

        [$exit, $message] = readerZeroJournal('replay-erasure-journal', ['--force' => true], $journal);
        expect($exit)->toBe(1)->and($message)->toContain('maintenance mode');
        app()->maintenanceMode()->activate([]);
        [$exit, $message] = readerZeroJournal('replay-erasure-journal', ['--force' => true, '--org' => 'other'], $journal);
        expect($exit)->toBe(1)->and($message)->toContain('does not match this Org')
            ->and(DB::table('readers')->where('id', $foreign->id)->exists())->toBeTrue();

        foreach ($backups as $fixture => $path) {
            DB::purge('reader_restore');
            config(['database.connections.reader_restore' => [
                'driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true,
            ]]);
            DB::setDefaultConnection('reader_restore');
            if ($fixture === 'reused') {
                DB::table('readers')->where('id', $key)->update([
                    'email' => 'different@kitsune.test', 'erasure_anchor' => Str::uuid()->toString(),
                ]);
            }

            [$exit, $message] = readerZeroJournal('replay-erasure-journal', ['--force' => true], $journal);
            $result = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
            if ($fixture === 'reused') {
                expect($exit)->toBe(1)
                    ->and($result)->toBe(['replayed' => 0, 'already_erased' => 0, 'unavailable' => 1, 'module_unavailable' => 0, 'failed' => 0])
                    ->and(DB::table('readers')->where('id', $key)->value('email'))->toBe('different@kitsune.test')
                    ->and(DB::table('reader_erasure_receipts')->where('anchor', $anchor)->exists())->toBeTrue()
                    ->and(DB::table('reader_erasure_receipts')->whereNotNull('erased_at')->exists())->toBeFalse()
                    ->and(DB::table('entitlements')->count())->toBe(2)
                    ->and(DB::table('reader_tokens')->count())->toBe(1)
                    ->and(DB::table('reader_consent_events')->count())->toBe(1);
                [$exit, $nextJournal] = readerZeroJournal('export-erasure-journal');
                expect($exit)->toBe(0, $nextJournal)
                    ->and(json_decode(app(ErasureJournalCipher::class)->unseal($nextJournal), true, flags: JSON_THROW_ON_ERROR))->toBe($decoded);
                [$exit, $message] = readerZeroJournal('replay-erasure-journal', ['--force' => true], $journal);
                expect($exit)->toBe(1)
                    ->and(json_decode($message, true, flags: JSON_THROW_ON_ERROR))->toBe($result);
            } else {
                expect($exit)->toBe(0, $message)
                    ->and($result)->toBe(['replayed' => 1, 'already_erased' => 0, 'unavailable' => 0, 'module_unavailable' => 0, 'failed' => 0])
                    ->and(DB::table('readers')->where('id', $key)->exists())->toBeFalse()
                    ->and(DB::table('entitlements')->count())->toBe(0)
                    ->and(DB::table('reader_tokens')->count())->toBe(0)
                    ->and(DB::table('reader_consent_events')->count())->toBe(0);
                [$exit, $message] = readerZeroJournal('replay-erasure-journal', ['--force' => true], $journal);
                expect($exit)->toBe(0, $message)
                    ->and(json_decode($message, true, flags: JSON_THROW_ON_ERROR))->toBe(['replayed' => 0, 'already_erased' => 1, 'unavailable' => 0, 'module_unavailable' => 0, 'failed' => 0])
                    ->and(DB::table('reader_erasure_receipts')->count())->toBe(1);
            }

            expect(DB::table('readers')->where('id', $foreign->id)->value('email'))->toBe('foreign@kitsune.test');
        }
    } finally {
        app()->maintenanceMode()->deactivate();
        DB::setDefaultConnection('custody');
        DB::purge('reader_restore');
        foreach ($backups as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
});

it('backfills a pre-upgrade account and assigns a distinct immutable anchor to a new account', function (): void {
    $migration = require TestCase::READERS_MIGRATIONS[1];
    DB::table('readers')->update(['erasure_anchor' => null]);
    $migration->down();
    expect(DB::table('readers')->where('id', $this->reader->id)->value('email'))->toBe('zero@kitsune.test');

    $migration->up();
    $old = $this->reader->fresh();
    $new = Reader::createReader('post-upgrade@kitsune.test', null, null);
    $anchor = $old->getRawOriginal('erasure_anchor');
    expect(ReaderErasureReceipts::validAnchor($anchor))->toBeTrue()
        ->and(ReaderErasureReceipts::validAnchor($new->getRawOriginal('erasure_anchor')))->toBeTrue()
        ->and($new->getRawOriginal('erasure_anchor'))->not->toBe($anchor)
        ->and($old->toArray())->not->toHaveKey('erasure_anchor');

    $old->setAttribute('erasure_anchor', Str::uuid()->toString());
    expect(fn () => $old->save())->toThrow(LogicException::class, 'cannot be changed')
        ->and($old->fresh()->getRawOriginal('erasure_anchor'))->toBe($anchor);
});

it('replays an erased signup link while preserving a reissued link for the same address', function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
        'app.maintenance.driver' => 'cache',
        'app.maintenance.store' => 'array',
    ]);
    $context = app(Context::class)->setSite($this->site);
    $address = 'unclaimed@kitsune.test';
    expect(app(ReaderTokens::class)->mint(ReaderTokens::REGISTER, $address))->not->toBeNull();
    $token = DB::table('reader_tokens')->where('purpose', ReaderTokens::REGISTER)->sole();
    $options = ['--force' => true];
    $backups = [];

    try {
        foreach (['original', 'pre-upgrade', 'reissued'] as $fixture) {
            $path = (string) tempnam(sys_get_temp_dir(), 'kitsune-signup-replay-');
            unlink($path);
            $backups[$fixture] = $path;
            DB::connection()->statement('VACUUM INTO ?', [$path]);
        }

        [$exit, $message] = readerZeroJournal('erase', $options + ['--record' => true], $address);
        expect($exit)->toBe(0, $message)
            ->and($message)->toContain('recorded its replay receipt')
            ->and(DB::table('reader_tokens')->where('purpose', ReaderTokens::REGISTER)->count())->toBe(0)
            ->and(DB::table('reader_tokens')->where('purpose', ReaderTokens::RECOVER)->count())->toBe(1);
        [$exit, $journal] = readerZeroJournal('export-erasure-journal');
        expect($exit)->toBe(0, $journal);
        expect(json_decode(app(ErasureJournalCipher::class)->unseal($journal), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'format' => 'kitsune.reader-erasure.3', 'org' => $this->org->id,
            'receipts' => ['signup' => [$token->erasure_anchor], 'grant' => [], 'account' => []],
        ]);

        app()->maintenanceMode()->activate([]);
        foreach ($backups as $fixture => $path) {
            DB::purge('signup_restore');
            config(['database.connections.signup_restore' => [
                'driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true,
            ]]);
            DB::setDefaultConnection('signup_restore');

            $context->setOrg($this->org)->setSite($this->site);
            if ($fixture === 'pre-upgrade') {
                $migration = require __DIR__.'/../../packages/core/database/migrations/0001_01_01_000027_anchor_reader_links.php';
                DB::table('reader_tokens')->update(['erasure_anchor' => null]);
                $migration->down();
                $migration->up();
                expect(DB::table('reader_tokens')->where('purpose', ReaderTokens::REGISTER)->value('erasure_anchor'))->toBe($token->erasure_anchor);
            }
            $replacement = null;
            if ($fixture === 'reissued') {
                expect(app(ReaderTokens::class)->mint(ReaderTokens::REGISTER, $address))->not->toBeNull();
                $replacement = DB::table('reader_tokens')->where('purpose', ReaderTokens::REGISTER)->sole();
                expect($replacement->erasure_anchor)->not->toBe($token->erasure_anchor);
            }

            [$exit, $message] = readerZeroJournal('replay-erasure-journal', $options, $journal);
            $result = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
            if ($replacement === null) {
                expect($exit)->toBe(0, $message)
                    ->and($result)->toBe(['replayed' => 1, 'already_erased' => 0, 'unavailable' => 0, 'module_unavailable' => 0, 'failed' => 0])
                    ->and(DB::table('reader_tokens')->where('purpose', ReaderTokens::REGISTER)->exists())->toBeFalse()
                    ->and(DB::table('reader_erasure_receipts')->whereNotNull('erased_at')->count())->toBe(1);
                [$exit, $message] = readerZeroJournal('replay-erasure-journal', $options, $journal);
                expect($exit)->toBe(0, $message)
                    ->and(json_decode($message, true, flags: JSON_THROW_ON_ERROR)['already_erased'])->toBe(1);
            } else {
                expect($exit)->toBe(1)
                    ->and($result)->toBe(['replayed' => 0, 'already_erased' => 0, 'unavailable' => 1, 'module_unavailable' => 0, 'failed' => 0])
                    ->and((array) DB::table('reader_tokens')->where('purpose', ReaderTokens::REGISTER)->sole())->toBe((array) $replacement)
                    ->and(DB::table('reader_erasure_receipts')->whereNull('erased_at')->count())->toBe(1);
            }

            expect(DB::table('readers')->count())->toBe(1)
                ->and(DB::table('reader_tokens')->where('purpose', ReaderTokens::RECOVER)->count())->toBe(1)
                ->and(DB::table('entitlements')->count())->toBe(2);
        }
    } finally {
        app()->maintenanceMode()->deactivate();
        DB::setDefaultConnection('custody');
        DB::purge('signup_restore');
        foreach ($backups as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
});

it('replays exact orphaned grants while preserving a reused grant ID and its replacement account', function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('g', 32)),
        'app.maintenance.driver' => 'cache',
        'app.maintenance.store' => 'array',
    ]);
    $key = $this->reader->id;
    DB::table('readers')->where('id', $key)->delete();
    $grants = DB::table('entitlements')->orderBy('id')->get();
    expect($grants)->toHaveCount(2);
    $sibling = Fx::site($this->org, 'sibling');
    DB::table('entitlements')->where('id', $grants[1]->id)->update(['site_id' => $sibling->id]);
    $rivalOrg = Fx::org('grant-rival');
    $rivalSite = Fx::site($rivalOrg, 'grant-rival');
    $rivalGrant = Fx::plant($rivalSite, (string) $key, 'course.rival');
    DB::table('entitlements')->where('id', $rivalGrant)->update(['erasure_anchor' => $grants[0]->erasure_anchor]);
    app(Context::class)->forget()->setOrg($this->org);
    $options = ['--force' => true];
    $backups = [];

    try {
        foreach (['original', 'reused'] as $fixture) {
            $path = (string) tempnam(sys_get_temp_dir(), 'kitsune-grant-replay-');
            unlink($path);
            $backups[$fixture] = $path;
            DB::connection()->statement('VACUUM INTO ?', [$path]);
        }
        expect(Artisan::call('kitsune:entitlements', ['action' => 'forget', '--org' => 'zero', '--reader' => (string) $key, ...$options]))->toBe(0, Artisan::output())
            ->and(DB::table('entitlements')->where('org_id', $this->org->id)->count())->toBe(0)
            ->and(DB::table('reader_erasure_receipts')->where('kind', 'grant')->whereNotNull('erased_at')->count())->toBe(2);
        [$exit, $journal] = readerZeroJournal('export-erasure-journal');
        expect($exit)->toBe(0, $journal)
            ->and(json_decode(app(ErasureJournalCipher::class)->unseal($journal), true, flags: JSON_THROW_ON_ERROR))->toBe([
                'format' => 'kitsune.reader-erasure.3', 'org' => $this->org->id,
                'receipts' => ['signup' => [], 'grant' => $grants->pluck('erasure_anchor')->sort()->values()->all(), 'account' => []],
            ]);

        app()->maintenanceMode()->activate([]);
        foreach ($backups as $fixture => $path) {
            DB::purge('grant_restore');
            config(['database.connections.grant_restore' => [
                'driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true,
            ]]);
            DB::setDefaultConnection('grant_restore');
            if ($fixture === 'reused') {
                DB::table('readers')->insert(['id' => $key, 'org_id' => $this->org->id, 'email' => 'replacement@kitsune.test', 'erasure_anchor' => Str::uuid()->toString()]);
                DB::table('entitlements')->where('id', $grants[0]->id)->update([
                    'erasure_anchor' => Str::uuid()->toString(),
                    'entitlement' => 'course.replacement',
                ]);
            }
            [$exit, $message] = readerZeroJournal('replay-erasure-journal', $options, $journal);
            $result = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
            if ($fixture === 'reused') {
                expect($exit)->toBe(1)
                    ->and($result)->toBe(['replayed' => 1, 'already_erased' => 0, 'unavailable' => 1, 'module_unavailable' => 0, 'failed' => 0])
                    ->and(DB::table('entitlements')->where('org_id', $this->org->id)->sole()->entitlement)->toBe('course.replacement')
                    ->and(DB::table('readers')->where('id', $key)->value('email'))->toBe('replacement@kitsune.test');
            } else {
                expect($exit)->toBe(0, $message)
                    ->and($result)->toBe(['replayed' => 2, 'already_erased' => 0, 'unavailable' => 0, 'module_unavailable' => 0, 'failed' => 0])
                    ->and(DB::table('entitlements')->where('org_id', $this->org->id)->count())->toBe(0)
                    ->and(DB::table('audit_log')->where('action', 'entitlement.erased')->count())->toBe(2);
                [$exit, $message] = readerZeroJournal('replay-erasure-journal', $options, $journal);
                expect($exit)->toBe(0, $message)
                    ->and(json_decode($message, true, flags: JSON_THROW_ON_ERROR)['already_erased'])->toBe(2);
            }

            expect(DB::table('reader_tokens')->where('purpose', ReaderTokens::RECOVER)->count())->toBe(1)
                ->and(DB::table('entitlements')->where('id', $rivalGrant)->value('entitlement'))->toBe('course.rival');
        }
    } finally {
        app()->maintenanceMode()->deactivate();
        DB::setDefaultConnection('custody');
        DB::purge('grant_restore');
        foreach ($backups as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
});

it('commits and replays an anchored account across two physical databases', function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('s', 32)),
        'app.maintenance.driver' => 'cache',
        'app.maintenance.store' => 'array',
        'auth.providers.readers.model' => ElsewhereReader::class,
        'database.connections.'.ElsewhereReader::CONNECTION => config('database.connections.secondary'),
    ]);
    app('auth')->forgetGuards();
    expect(Artisan::call('migrate', ['--database' => ElsewhereReader::CONNECTION, '--path' => TestCase::READERS_MIGRATIONS, '--realpath' => true, '--force' => true]))->toBe(0, Artisan::output());
    $host = DB::connection(ElsewhereReader::CONNECTION);
    $host->table('orgs')->insert((array) DB::table('orgs')->where('id', $this->org->id)->sole());
    $reader = ElsewhereReader::createReader('split-reader@example.test', null, null);
    expect($reader->id)->toBe($this->reader->id);

    $grant = DB::table('reader_tokens')->where('purpose', ReaderTokens::RECOVER)->sole();
    DB::table('reader_consent_events')->insert([
        'org_id' => $this->org->id, 'site_id' => $this->site->id, 'reader_key' => (string) $reader->id,
        'purpose' => 'profiling', 'notice_version' => 'v1', 'granted' => true,
        'recorded_at' => now('UTC'), 'expires_at' => now('UTC')->addDay(), 'retain_until' => now('UTC')->addDay(),
    ]);
    $backups = [];
    try {
        foreach (['split_restore_core' => DB::connection(), 'split_restore_host' => $host, 'skew_restore_core' => DB::connection()] as $name => $connection) {
            $path = (string) tempnam(sys_get_temp_dir(), 'kitsune-split-reader-');
            unlink($path);
            $backups[$name] = $path;
            $connection->statement('VACUUM INTO ?', [$path]);
        }
        $armed = true;
        ElsewhereReader::deleted(static function () use (&$armed, $grant): void {
            if ($armed) {
                DB::table('reader_tokens')->insert((array) $grant);
            }
        });
        expect(fn () => app(ReaderErasure::class)->erase((string) $reader->id, requireReceipt: true))
            ->toThrow(RuntimeException::class, 'Erased reader link custody changed')
            ->and($host->table('readers')->where('id', $reader->id)->exists())->toBeTrue()
            ->and(DB::table('entitlements')->count())->toBe(2)
            ->and(DB::table('reader_tokens')->count())->toBe(1)
            ->and(DB::table('reader_consent_events')->count())->toBe(1)
            ->and(DB::table('reader_erasure_receipts')->count())->toBe(0);
        $armed = false;

        $grantId = DB::table('entitlements')->min('id');
        $grantAnchor = DB::table('entitlements')->where('id', $grantId)->value('erasure_anchor');
        DB::table('entitlements')->where('id', $grantId)->update(['erasure_anchor' => null]);
        expect(fn () => app(ReaderErasure::class)->erase((string) $reader->id, requireReceipt: true))
            ->toThrow(RuntimeException::class, 'The erased record has no usable replay anchor')
            ->and($host->table('readers')->where('id', $reader->id)->exists())->toBeTrue()
            ->and(DB::table('entitlements')->count())->toBe(2)
            ->and(DB::table('reader_tokens')->count())->toBe(1)
            ->and(DB::table('reader_consent_events')->count())->toBe(1)
            ->and(DB::table('reader_erasure_receipts')->count())->toBe(0);
        DB::table('entitlements')->where('id', $grantId)->update(['erasure_anchor' => $grantAnchor]);

        $host->statement('CREATE TABLE split_commit_probe (org_id INTEGER REFERENCES orgs(id) DEFERRABLE INITIALLY DEFERRED)');
        $failAtHostCommit = true;
        ElsewhereReader::deleted(static function () use (&$failAtHostCommit, $host): void {
            if ($failAtHostCommit) {
                $host->table('split_commit_probe')->insert(['org_id' => 999999]);
            }
        });
        expect(fn () => app(ReaderErasure::class)->erase((string) $reader->id, requireReceipt: true))
            ->toThrow(PDOException::class, 'FOREIGN KEY constraint failed')
            ->and($host->table('readers')->where('id', $reader->id)->exists())->toBeTrue()
            ->and($host->transactionLevel())->toBe(0)
            ->and($host->getPdo()->inTransaction())->toBeFalse()
            ->and(DB::table('entitlements')->count())->toBe(0)
            ->and(DB::table('reader_consent_events')->count())->toBe(0)
            ->and(DB::table('reader_erasure_receipts')->where('host_pending', true)->whereNull('erased_at')->count())->toBe(1);
        $failAtHostCommit = false;
        [$exit, $pendingJournal] = readerZeroJournal('export-erasure-journal');
        expect($exit)->toBe(0, $pendingJournal);
        app()->maintenanceMode()->activate([]);
        [$exit, $message] = readerZeroJournal('replay-erasure-journal', ['--force' => true, '--restore-host' => ElsewhereReader::CONNECTION], $pendingJournal);
        expect($exit)->toBe(0, $message)
            ->and(json_decode($message, true, flags: JSON_THROW_ON_ERROR)['replayed'])->toBe(1)
            ->and($host->table('readers')->where('id', $reader->id)->exists())->toBeFalse()
            ->and(DB::table('readers')->where('id', $reader->id)->exists())->toBeTrue()
            ->and(DB::table('reader_erasure_receipts')->whereNotNull('erased_at')->count())->toBe(3)
            ->and(DB::table('reader_erasure_receipts')->where('host_pending', true)->count())->toBe(0);
        [$exit, $journal] = readerZeroJournal('export-erasure-journal');
        expect($exit)->toBe(0, $journal);

        $afterHost = (string) tempnam(sys_get_temp_dir(), 'kitsune-after-host-');
        unlink($afterHost);
        $backups['skew_restore_host'] = $afterHost;
        $host->statement('VACUUM INTO ?', [$afterHost]);
        DB::purge(ElsewhereReader::CONNECTION);
        foreach (['split_restore_core' => 'split_restore_core', ElsewhereReader::CONNECTION => 'split_restore_host'] as $name => $backup) {
            config(["database.connections.{$name}" => [
                'driver' => 'sqlite', 'database' => $backups[$backup], 'prefix' => '', 'foreign_key_constraints' => true,
            ]]);
        }
        DB::setDefaultConnection('split_restore_core');
        app()->maintenanceMode()->activate([]);
        foreach ([[], ['--restore-host' => 'custody']] as $confirmation) {
            [$exit, $message] = readerZeroJournal('replay-erasure-journal', ['--force' => true] + $confirmation, $journal);
            expect($exit)->toBe(1)
                ->and($message)->toContain('--restore-host='.ElsewhereReader::CONNECTION)
                ->and(DB::table('reader_erasure_receipts')->count())->toBe(0)
                ->and(DB::table('entitlements')->count())->toBe(2)
                ->and(DB::connection(ElsewhereReader::CONNECTION)->table('readers')->count())->toBe(1);
        }
        DB::unprepared("CREATE TRIGGER split_finish_probe BEFORE UPDATE OF host_pending ON reader_erasure_receipts
            WHEN OLD.host_pending = 1 AND NEW.host_pending = 0 BEGIN SELECT RAISE(ABORT, 'receipt finalization interrupted'); END");
        [$exit, $message] = readerZeroJournal('replay-erasure-journal', ['--force' => true, '--restore-host' => ElsewhereReader::CONNECTION], $journal);
        expect($exit)->toBe(1, $message)
            ->and(json_decode($message, true, flags: JSON_THROW_ON_ERROR)['failed'])->toBe(1)
            ->and(DB::connection(ElsewhereReader::CONNECTION)->table('readers')->count())->toBe(0)
            ->and(DB::table('reader_erasure_receipts')->where('host_pending', true)->whereNull('erased_at')->count())->toBe(1);
        DB::unprepared('DROP TRIGGER split_finish_probe');
        [$exit, $message] = readerZeroJournal('replay-erasure-journal', ['--force' => true, '--restore-host' => ElsewhereReader::CONNECTION], $journal);
        expect($exit)->toBe(0, $message)
            ->and(json_decode($message, true, flags: JSON_THROW_ON_ERROR)['already_erased'])->toBe(3)
            ->and(DB::connection(ElsewhereReader::CONNECTION)->table('readers')->count())->toBe(0)
            ->and(DB::table('readers')->count())->toBe(1)
            ->and(DB::table('entitlements')->count())->toBe(0)
            ->and(DB::table('reader_consent_events')->count())->toBe(0);
        [$exit, $message] = readerZeroJournal('replay-erasure-journal', ['--force' => true, '--restore-host' => ElsewhereReader::CONNECTION], $journal);
        expect($exit)->toBe(0, $message)
            ->and(json_decode($message, true, flags: JSON_THROW_ON_ERROR)['already_erased'])->toBe(3)
            ->and(DB::table('reader_erasure_receipts')->whereNotNull('erased_at')->count())->toBe(3);

        DB::purge(ElsewhereReader::CONNECTION);
        foreach (['skew_restore_core' => 'skew_restore_core', ElsewhereReader::CONNECTION => 'skew_restore_host'] as $name => $backup) {
            config(["database.connections.{$name}" => [
                'driver' => 'sqlite', 'database' => $backups[$backup], 'prefix' => '', 'foreign_key_constraints' => true,
            ]]);
        }
        DB::setDefaultConnection('skew_restore_core');
        [$exit, $message] = readerZeroJournal('replay-erasure-journal', ['--force' => true, '--restore-host' => ElsewhereReader::CONNECTION], $journal);
        expect($exit)->toBe(1, $message)
            ->and(json_decode($message, true, flags: JSON_THROW_ON_ERROR))->toBe([
                'replayed' => 2, 'already_erased' => 0, 'unavailable' => 1, 'module_unavailable' => 0, 'failed' => 0,
            ])
            ->and(DB::table('entitlements')->count())->toBe(0)
            ->and(DB::table('reader_consent_events')->count())->toBe(1)
            ->and(DB::connection(ElsewhereReader::CONNECTION)->table('readers')->count())->toBe(0);
    } finally {
        app()->maintenanceMode()->deactivate();
        DB::setDefaultConnection('custody');
        DB::purge('split_restore_core');
        DB::purge('skew_restore_core');
        DB::purge(ElsewhereReader::CONNECTION);
        foreach ($backups as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
});

it('rolls back if a host callback leaves the same account anchor under a fresh key', function (): void {
    $reader = $this->reader;
    Reader::deleted(static function (Reader $model) use ($reader): void {
        if ($model->getKey() === $reader->getKey()) {
            $row = $model->getRawOriginal();
            unset($row['id']);
            DB::table('readers')->insert($row);
        }
    });

    expect(fn () => app(ReaderErasure::class)->erase((string) $reader->getKey(), requireReceipt: true))
        ->toThrow(RuntimeException::class)
        ->and(DB::table('readers')->where('id', $reader->getKey())->exists())->toBeTrue()
        ->and(DB::table('readers')->where('erasure_anchor', $reader->getRawOriginal('erasure_anchor'))->count())->toBe(1)
        ->and(DB::table('entitlements')->count())->toBe(2)
        ->and(DB::table('reader_tokens')->count())->toBe(1)
        ->and(DB::table('reader_erasure_receipts')->count())->toBe(0);
});
