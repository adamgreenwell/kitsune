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
use Kitsune\Core\Readers\ReaderErasure;
use Kitsune\Core\Readers\ReaderExport;
use Kitsune\Core\Readers\ReaderModuleData;
use Kitsune\Core\Readers\ReaderModuleDataUnavailable;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Kitsune\Fixture\Module\FixtureModuleServiceProvider;

/** A real installed package supplies this adapter without registering its runtime provider. */
final class ReaderPrivacyFixtureStore implements ModuleReaderData
{
    public bool $failExport = false;

    public bool $failErase = false;

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
        expect($receipts)->toHaveCount(1)->and($receipts[0])->toContain('order by handle');
        expect(str_contains($receipts[0], 'for update') || str_contains($receipts[0], '/* current receipt read */'))->toBe($action === 'erase');
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
