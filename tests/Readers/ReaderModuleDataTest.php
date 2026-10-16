<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Models\Reader;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Readers\Contracts\ModuleReaderData;
use Kitsune\Core\Readers\Contracts\ModuleReaderErasureVerifier;
use Kitsune\Core\Readers\ReaderErasure;
use Kitsune\Core\Readers\ReaderExport;
use Kitsune\Core\Readers\ReaderModuleData;
use Kitsune\Core\Readers\ReaderModuleDataUnavailable;
use Kitsune\Core\Readers\ReaderTokens;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Kitsune\Fixture\Module\FixtureModuleServiceProvider;

/** A real installed package supplies this adapter without registering its runtime provider. */
final class ReaderPrivacyFixtureStore implements ModuleReaderData, ModuleReaderErasureVerifier
{
    public bool $failExport = false;

    public bool $failErase = false;

    public bool $failVerify = false;

    public int $captures = 0;

    public int $verifications = 0;

    public function export(Connection $connection, int $orgId, #[SensitiveParameter] string $reader): array
    {
        if ($this->failExport) {
            throw new QueryException($connection->getName(), 'select private from fixture where reader = ?', [$reader], new PDOException('private reader payload'));
        }

        return ['retained' => $connection->table('fixture_module_seeds')->useWritePdo()->where('name', $orgId.'|'.$reader)->count()];
    }

    public function erase(Connection $connection, int $orgId, #[SensitiveParameter] string $reader): int
    {
        $count = $connection->table('fixture_module_seeds')->where('name', $orgId.'|'.$reader)->delete();

        if ($this->failErase) {
            throw new RuntimeException('private reader payload '.$reader);
        }

        return $count;
    }

    public function erasureVerification(Connection $connection, int $orgId, #[SensitiveParameter] string $reader): Closure
    {
        $this->captures++;

        return function () use ($connection, $orgId, $reader): void {
            $this->verifications++;
            if ($this->failVerify) {
                throw new RuntimeException('private reader verification payload '.$reader);
            }
            if ($connection->table('fixture_module_seeds')->useWritePdo()->where('name', $orgId.'|'.$reader)->exists()) {
                throw new RuntimeException('Erased fixture data reappeared.');
            }
        };
    }
}

/** @return array<string, mixed> the real modules table has no Eloquent activity timestamps */
function readerPrivacyReceipt(): array
{
    return ['handle' => 'kitsune/fixture-module', 'version' => 'older-installed-version', 'is_enabled' => false, 'installed_at' => now()];
}

beforeEach(function (): void {
    $this->world = ReaderFixture::world();
    $this->org = $this->world['golfdom'];
    $this->reader = ReaderFixture::reader($this->org, 'private-reader@example.test');
    app(Context::class)->setOrg($this->org);
    FixtureModuleServiceProvider::$calls = [];
    $this->store = new ReaderPrivacyFixtureStore;
    FixtureModuleServiceProvider::$readerDataStore = $this->store;
    DB::table('modules')->insert(readerPrivacyReceipt());
    DB::table('fixture_module_seeds')->insert(['name' => $this->org->getKey().'|'.$this->reader->getKey()]);
    DB::table('fixture_module_seeds')->insert(['name' => $this->world['rival']->getKey().'|'.$this->reader->getKey()]);
});

afterEach(function (): void {
    FixtureModuleServiceProvider::$readerDataStore = null;
    FixtureModuleServiceProvider::$calls = [];
    ReaderFixture::forget();
});

it('exports a disabled stale module through its unregistered privacy adapter, with the current org fenced', function (): void {
    $records = app(ReaderExport::class)->for((string) $this->reader->getKey());

    expect($records['module_data'])->toBe(['kitsune/fixture-module' => ['retained' => 1]])
        ->and(app()->getProviders(FixtureModuleServiceProvider::class))->toBe([])
        ->and(FixtureModuleServiceProvider::$calls)->toBe(['reader-data']);
});

it('keeps the original export shape when installed providers declare no reader store', function (): void {
    FixtureModuleServiceProvider::$readerDataStore = null;

    expect(array_keys(app(ReaderExport::class)->for((string) $this->reader->getKey())))
        ->toBe(['account', 'entitlements', 'pending_links', 'generated_at']);
});

it('runs optional proofs after the callback while keeping deleted-parent counts and legacy callers unchanged', function (): void {
    $key = (string) $this->reader->getKey();
    $called = false;
    $counts = app(ReaderModuleData::class)->erase($key, afterErase: function () use (&$called, $key): void {
        expect($this->store->captures)->toBe(1)->and($this->store->verifications)->toBe(0)
            ->and(DB::table('fixture_module_seeds')->where('name', $this->org->getKey().'|'.$key)->count())->toBe(0);
        $called = true;
    });
    expect($called)->toBeTrue()->and($counts)->toBe(['kitsune/fixture-module' => 1])
        ->and($this->store->captures)->toBe(1)->and($this->store->verifications)->toBe(1)
        ->and(Reader::query()->whereKey($key)->exists())->toBeTrue();
    expect(app(ReaderModuleData::class)->erase($key))->toBe(['kitsune/fixture-module' => 0]);
});

it('preserves the exact account-last callback exception after rolling back module writes', function (): void {
    $key = (string) $this->reader->getKey();
    $expected = new RuntimeException('The host refused to delete the row.');
    $before = DB::table('fixture_module_seeds')->orderBy('id')->get()->all();
    try {
        app(ReaderModuleData::class)->erase($key, afterErase: static function () use ($expected): void {
            throw $expected;
        });
        $this->fail('The account-last callback failure was ignored.');
    } catch (RuntimeException $failure) {
        expect($failure)->toBe($expected);
    }
    expect(DB::table('fixture_module_seeds')->orderBy('id')->get()->all())->toEqual($before)
        ->and($this->store->captures)->toBe(1)->and($this->store->verifications)->toBe(0);
});

it('redacts a final module proof failure after a successful account-last callback', function (): void {
    $key = (string) $this->reader->getKey();
    $this->store->failVerify = true;
    $before = DB::table('fixture_module_seeds')->orderBy('id')->get()->all();
    $called = false;
    try {
        app(ReaderModuleData::class)->erase($key, afterErase: static function () use (&$called): void {
            $called = true;
        });
        $this->fail('The private module proof failure was ignored.');
    } catch (ReaderModuleDataUnavailable $failure) {
        expect($failure->getPrevious())->toBeNull()->and($failure->getMessage())->not->toContain('private reader verification payload', $key);
    }
    expect($called)->toBeTrue()->and(DB::table('fixture_module_seeds')->orderBy('id')->get()->all())->toEqual($before)
        ->and($this->store->verifications)->toBe(1);
});

it('erases only this org\'s module records before deleting the account, including after a host deleted the account already', function (bool $deleted): void {
    if ($deleted) {
        Reader::query()->whereKey($this->reader->getKey())->delete();
    }

    $result = app(ReaderErasure::class)->erase((string) $this->reader->getKey());

    expect($result['account'])->toBe(! $deleted)
        ->and(DB::table('fixture_module_seeds')->pluck('name')->all())->toBe([$this->world['rival']->getKey().'|'.$this->reader->getKey()])
        ->and(Reader::query()->count())->toBe(0)
        ->and(app()->getProviders(FixtureModuleServiceProvider::class))->toBe([]);
})->with(['account retained' => [false], 'host deleted account' => [true]]);

it('rolls back grants, module records and account erasure when the module fails after deleting its rows', function (): void {
    EntitlementFixture::plant($this->world['sites']['golfdom'], (string) $this->reader->getKey(), 'course.private');
    $before = DB::table('fixture_module_seeds')->orderBy('id')->get()->all();
    $this->store->failErase = true;

    expect(fn () => app(ReaderErasure::class)->erase((string) $this->reader->getKey()))->toThrow(ReaderModuleDataUnavailable::class)
        ->and(DB::table('fixture_module_seeds')->orderBy('id')->get()->all())->toEqual($before)
        ->and(DB::table('entitlements')->count())->toBe(1)->and(Reader::query()->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'entitlement.erased')->count())->toBe(0);
});

it('refuses changed installed custody from a host deleted observer without constructing stores again', function (string $change): void {
    $key = (string) $this->reader->getKey();
    EntitlementFixture::plant($this->world['sites']['golfdom'], $key, 'course.private');
    $receipts = DB::table('modules')->orderBy('handle')->get()->all();
    $records = DB::table('fixture_module_seeds')->orderBy('id')->get()->all();
    $events = Reader::getEventDispatcher();
    Reader::setEventDispatcher(clone $events);
    Reader::deleted(static function (Reader $account) use ($key, $change): void {
        if ((string) $account->getKey() !== $key) {
            return;
        }
        if ($change === 'installed') {
            DB::table('modules')->insert(['handle' => 'kitsune/commerce', 'version' => 'older-installed-version', 'is_enabled' => false, 'installed_at' => now()]);
        } elseif ($change === 'deleted') {
            DB::table('modules')->where('handle', 'kitsune/fixture-module')->delete();
        } else {
            DB::table('modules')->where('handle', 'kitsune/fixture-module')->update(['version' => 'changed-during-erasure']);
        }
    });
    try {
        expect(fn () => app(ReaderErasure::class)->erase($key))->toThrow(ReaderModuleDataUnavailable::class);
    } finally {
        Reader::setEventDispatcher($events);
    }
    expect(DB::table('modules')->orderBy('handle')->get()->all())->toEqual($receipts)
        ->and(DB::table('fixture_module_seeds')->orderBy('id')->get()->all())->toEqual($records)
        ->and(Reader::query()->whereKey($key)->exists())->toBeTrue()->and(DB::table('entitlements')->count())->toBe(1)
        ->and(FixtureModuleServiceProvider::$calls)->toBe(['reader-data']);
})->with(['installed', 'deleted', 'version changed']);

it('keeps the original Org when the host account deletion callback changes ambient context', function (): void {
    $key = (string) $this->reader->getKey();
    $org = $this->world['rival'];
    $events = Reader::getEventDispatcher();
    Reader::setEventDispatcher(clone $events);
    Reader::deleted(static fn () => app(Context::class)->setOrg($org));
    try {
        expect(fn () => app(ReaderErasure::class)->erase($key))->toThrow(ReaderModuleDataUnavailable::class);
    } finally {
        Reader::setEventDispatcher($events);
        app(Context::class)->setOrg($this->org);
    }
    expect(Reader::query()->whereKey($key)->exists())->toBeTrue()->and(DB::table('fixture_module_seeds')->count())->toBe(2);
});

it('refuses success when a host silently cancels account deletion after module erasure', function (): void {
    $key = (string) $this->reader->getKey();
    EntitlementFixture::plant($this->world['sites']['golfdom'], $key, 'course.private');
    $events = Reader::getEventDispatcher();
    Reader::setEventDispatcher(clone $events);
    Reader::deleting(static fn (): bool => false);
    try {
        expect(fn () => app(ReaderErasure::class)->erase($key))->toThrow(ReaderModuleDataUnavailable::class);
    } finally {
        Reader::setEventDispatcher($events);
    }
    expect(Reader::query()->whereKey($key)->exists())->toBeTrue()->and(DB::table('fixture_module_seeds')->count())->toBe(2)
        ->and(DB::table('entitlements')->count())->toBe(1);
});

it('refuses account resurrection under the captured primary key even when its Org was transplanted', function (bool $foreign): void {
    $key = (string) $this->reader->getKey();
    $row = (array) DB::table('readers')->where('id', $key)->sole();
    $targetOrg = $foreign ? $this->world['rival']->getKey() : $this->org->getKey();
    $events = Reader::getEventDispatcher();
    Reader::setEventDispatcher(clone $events);
    Reader::deleted(static function (Reader $account) use ($key, $row, $targetOrg): void {
        if ((string) $account->getKey() === $key) {
            DB::table('readers')->insert([...$row, 'org_id' => $targetOrg]);
        }
    });
    try {
        expect(fn () => app(ReaderErasure::class)->erase($key))->toThrow(ReaderModuleDataUnavailable::class);
    } finally {
        Reader::setEventDispatcher($events);
    }
    expect((array) DB::table('readers')->where('id', $key)->sole())->toBe($row)
        ->and(DB::table('fixture_module_seeds')->count())->toBe(2);
})->with(['original Org' => [false], 'foreign Org' => [true]]);

it('refuses an actually erased grant or link transplanted under its captured key after account deletion', function (string $kind, bool $foreign): void {
    $key = (string) $this->reader->getKey();
    $site = $this->world['sites']['golfdom'];
    app(Context::class)->setSite($site);
    EntitlementFixture::plant($site, $key, 'course.private');
    app(ReaderTokens::class)->mint(ReaderTokens::RECOVER, $key);
    app(ReaderTokens::class)->mint(ReaderTokens::REGISTER, $this->reader->email);
    $table = $kind === 'grant' ? 'entitlements' : 'reader_tokens';
    $row = (array) DB::table($table)->when($kind !== 'grant', static fn (Builder $query): Builder => $query->where('purpose', $kind))->sole();
    $target = $foreign ? $this->world['sites']['rival'] : $site;
    $snapshot = static fn (): array => [
        'grants' => DB::table('entitlements')->orderBy('id')->get()->all(),
        'links' => DB::table('reader_tokens')->orderBy('id')->get()->all(),
        'module' => DB::table('fixture_module_seeds')->orderBy('id')->get()->all(),
        'audit' => DB::table('audit_log')->orderBy('id')->get()->all(),
    ];
    $before = $snapshot();
    $level = DB::connection()->transactionLevel();
    $native = DB::connection()->getPdo();
    $events = Reader::getEventDispatcher();
    Reader::setEventDispatcher(clone $events);
    Reader::deleted(static function (Reader $account) use ($key, $kind, $row, $table, $target): void {
        if ((string) $account->getKey() !== $key) {
            return;
        }
        $subject = $kind === 'grant' ? 'reader_id' : 'subject';
        DB::table($table)->insert([...$row, 'org_id' => $target->org_id, 'site_id' => $target->id, $subject => 'different-erased-subject']);
    });
    try {
        expect(fn () => app(ReaderErasure::class)->erase($key))->toThrow(RuntimeException::class);
    } finally {
        Reader::setEventDispatcher($events);
    }
    expect($snapshot())->toEqual($before)->and(Reader::query()->whereKey($key)->exists())->toBeTrue()
        ->and(DB::connection()->transactionLevel())->toBe($level)->and(DB::connection()->getPdo())->toBe($native);
})->with([
    'grant changed reader' => ['grant', false],
    'grant foreign Org and reader' => ['grant', true],
    'recovery changed subject' => [ReaderTokens::RECOVER, false],
    'recovery foreign Org and subject' => [ReaderTokens::RECOVER, true],
    'registration changed subject' => [ReaderTokens::REGISTER, false],
    'registration foreign Org and subject' => [ReaderTokens::REGISTER, true],
]);

it('refuses the complete operation when an installed package is missing, even if it is disabled', function (string $action): void {
    Module::create(['handle' => 'missing/private-store', 'version' => '1.0.0', 'is_enabled' => false, 'installed_at' => now()]);
    $call = $action === 'export'
        ? fn () => app(ReaderExport::class)->for((string) $this->reader->getKey())
        : fn () => app(ReaderErasure::class)->erase((string) $this->reader->getKey());

    expect($call)->toThrow(ReaderModuleDataUnavailable::class)
        ->and(DB::table('fixture_module_seeds')->count())->toBe(2)->and(Reader::query()->count())->toBe(1);
})->with(['export', 'erase']);

it('maps a module database failure without personal bindings or a chained error and refuses CLI success', function (): void {
    $this->store->failExport = true;

    try {
        app(ReaderExport::class)->for((string) $this->reader->getKey());
        $this->fail('a partial export was returned');
    } catch (ReaderModuleDataUnavailable $failure) {
        expect($failure->getPrevious())->toBeNull()->and($failure->getMessage())->toContain('SQLSTATE')
            ->and($failure->getMessage())->not->toContain('private reader payload', 'private-reader@example.test', 'select private');
    }

    $status = Artisan::call('kitsune:readers', ['action' => 'export', '--org' => 'golfdom', '--reader' => (string) $this->reader->getKey()]);

    expect($status)->toBe(1)->and(Artisan::output())->toContain('module data could not be exported')
        ->and(Artisan::output())->not->toContain('private reader payload', 'private-reader@example.test', 'select private', '"account"');
});

it('rechecks authority before resolving any module reader store', function (): void {
    ReaderFixture::signIn($this->reader);
    app(Context::class)->setOrg($this->org);

    expect(fn () => app(ReaderModuleData::class)->export((string) $this->reader->getKey()))->toThrow(EntitlementRefused::class)
        ->and(fn () => app(ReaderModuleData::class)->erase((string) $this->reader->getKey()))->toThrow(EntitlementRefused::class)
        ->and(FixtureModuleServiceProvider::$calls)->toBe([])
        ->and(DB::table('fixture_module_seeds')->count())->toBe(2);
});

it('keeps reader keys out of privacy adapter failure traces under PHP\'s argument-recording default', function (): void {
    foreach ([ModuleReaderData::class, ReaderModuleData::class, ReaderPrivacyFixtureStore::class] as $class) {
        foreach (['export', 'erase'] as $method) {
            $parameters = array_filter((new ReflectionMethod($class, $method))->getParameters(), static fn (ReflectionParameter $parameter): bool => $parameter->getName() === 'reader');

            expect(array_values($parameters)[0]->getAttributes(SensitiveParameter::class))->toHaveCount(1);
        }
    }

    $this->store->failExport = true;
    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        app(ReaderExport::class)->for('81234981');
        $this->fail('a partial export was returned');
    } catch (ReaderModuleDataUnavailable $failure) {
        expect($failure->getTraceAsString())->toContain('SensitiveParameterValue')
            ->and($failure->getTraceAsString())->not->toContain('81234981', 'private reader payload')
            ->and($failure->getPrevious())->toBeNull();
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }
});

it('discovers destructive reader stores through current receipt locks inside an enclosing transaction', function (): void {
    $connection = DB::connection();
    $grammar = $connection->getQueryGrammar();

    if ($connection->getDriverName() === 'sqlite') {
        // SQLite has no FOR UPDATE. Annotate its compilation so the bare-clone suite observes the production lock
        // decision; the separate server regression below measures the repeatable-read behavior itself.
        $connection->setQueryGrammar(new class($connection) extends SQLiteGrammar
        {
            protected function compileLock(Builder $query, $value)
            {
                return $value === true ? '/* current receipt read */' : '';
            }
        });
    }

    $connection->flushQueryLog();
    $connection->enableQueryLog();

    try {
        app(ReaderExport::class)->for((string) $this->reader->getKey());
        $exportQueries = $connection->getQueryLog();
        $connection->flushQueryLog();
        DB::transaction(fn () => app(ReaderErasure::class)->erase((string) $this->reader->getKey()));
        $eraseQueries = $connection->getQueryLog();
    } finally {
        $connection->disableQueryLog();
        $connection->flushQueryLog();
        $connection->setQueryGrammar($grammar);
    }

    foreach (['export' => $exportQueries, 'erase' => $eraseQueries] as $action => $queries) {
        $receipts = array_values(array_filter(array_map(static fn (array $query): string => strtolower(str_replace(['"', '`'], '', $query['query'])), $queries),
            static fn (string $sql): bool => str_starts_with($sql, 'select ') && str_contains($sql, ' from modules ')));
        expect($receipts)->toHaveCount($action === 'erase' ? 2 : 1);
        foreach ($receipts as $receipt) {
            expect($receipt)->toContain('order by handle');
            expect(str_contains($receipt, 'for update') || str_contains($receipt, '/* current receipt read */'))->toBe($action === 'erase');
        }
    }

    expect(DB::table('fixture_module_seeds')->count())->toBe(1)->and(Reader::query()->count())->toBe(0);
});

it('erases a store whose receipt committed after the callers repeatable-read snapshot', function (): void {
    $connection = DB::connection();

    if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('This two-connection regression measures InnoDB repeatable-read current discovery.');
    }

    // Discard this file's ordinary uncommitted fixtures. The rival must commit all rows used by this probe before
    // the caller takes its snapshot, except the module receipt/store deliberately installed afterwards.
    $connection->rollBack();
    $default = (string) config('database.default');
    config(['database.connections.reader-privacy-rival' => config('database.connections.'.$default)]);
    $rival = DB::connection('reader-privacy-rival');
    $now = now();
    $orgId = (int) $rival->table('orgs')->insertGetId(['slug' => 'privacy-snapshot', 'name' => 'Privacy snapshot', 'created_at' => $now, 'updated_at' => $now]);
    $this->afterRollback(function () use ($rival, $orgId): void {
        $rival->table('fixture_module_seeds')->where('name', 'like', $orgId.'|%')->delete();
        $rival->table('modules')->where('handle', 'kitsune/fixture-module')->delete();
        $rival->table('audit_log')->where('org_id', $orgId)->delete();
        $rival->table('readers')->where('org_id', $orgId)->delete();
        $rival->table('sites')->where('org_id', $orgId)->delete();
        $rival->table('orgs')->where('id', $orgId)->delete();
        DB::purge('reader-privacy-rival');
    });
    $rival->table('sites')->insert(['org_id' => $orgId, 'handle' => 'snapshot', 'slug' => 'privacy-snapshot', 'name' => 'Snapshot', 'locale' => 'en', 'created_at' => $now, 'updated_at' => $now]);
    $readerId = (string) $rival->table('readers')->insertGetId(['org_id' => $orgId, 'email' => 'snapshot-reader@example.test', 'created_at' => $now, 'updated_at' => $now]);
    $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connection->beginTransaction();
    expect($connection->table('modules')->count())->toBe(0);
    app(Context::class)->setOrg(Org::query()->findOrFail($orgId));
    $rival->table('modules')->insert(readerPrivacyReceipt());
    $rival->table('fixture_module_seeds')->insert(['name' => $orgId.'|'.$readerId]);

    // This proves a plain primary read is still the stale snapshot before the production erasure starts.
    expect($connection->table('modules')->useWritePdo()->count())->toBe(0);
    expect(app(ReaderErasure::class)->erase($readerId))->toBe(['grants' => 0, 'links' => 0, 'account' => true])
        ->and($connection->table('fixture_module_seeds')->where('name', $orgId.'|'.$readerId)->lockForUpdate()->first())->toBeNull()
        ->and(Reader::query()->count())->toBe(0)->and(FixtureModuleServiceProvider::$calls)->toBe(['reader-data']);
});
