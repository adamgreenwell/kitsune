<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Kitsune\Core\Tests\Fixtures\CommerceRaceNamespace;
use Symfony\Component\Process\Process;

it('refuses an occupied namespace before migrating and leaves its data for its owner', function (string $probe, string $family): void {
    $driver = DB::connection()->getDriverName();
    if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
        $this->markTestSkipped('Namespace custody is checked on the server engine legs.');
    }

    $root = dirname(__DIR__, 2);
    $work = sys_get_temp_dir().'/kitsune-race-custody-'.bin2hex(random_bytes(8));
    $claimant = $work.'/claimant';
    $owner = $work.'/owner';
    File::makeDirectory($claimant, 0700, true);
    File::makeDirectory($owner, 0700, true);
    $name = $family.substr(hash('sha256', $claimant), 0, 5).'_';
    $key = 'commerce-race-custody';
    $config = config('database.connections.'.config('database.default'));
    $config = $driver === 'pgsql' ? [...$config, 'search_path' => $name] : [...$config, 'prefix' => $name];
    config(['database.connections.'.$key => $config]);
    $connection = DB::connection($key);
    $namespace = new CommerceRaceNamespace($connection, $name, $owner);
    try {
        $namespace->prepare('init');
        $connection->getSchemaBuilder()->create('custody_sentinel', function (Blueprint $table): void {
            $table->string('marker');
        });
        $connection->table('custody_sentinel')->insert(['marker' => 'original fixture']);

        foreach (['init', 'worker', 'cleanup'] as $mode) {
            $target = $claimant.'/app-'.$mode;
            CommerceRaceNamespace::copyApplication($root.'/vendor/orchestra/testbench-core/laravel', $target);
            $process = new Process([PHP_BINARY, $root."/tests/Commerce/Fixtures/{$probe}RaceProbe.php",
                $root, $claimant, $mode, $mode, 'custody'], $root);
            $process->setTimeout(45);
            $process->run();
            if ($mode === 'cleanup') {
                expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            } else {
                expect($process->isSuccessful())->toBeFalse($process->getErrorOutput().$process->getOutput())
                    ->and($process->getErrorOutput().$process->getOutput())->toContain(
                        $mode === 'init' ? 'namespace is already in use' : 'namespace has no verified owner',
                    );
            }
            expect($connection->table('custody_sentinel')->useWritePdo()->value('marker'))->toBe('original fixture')
                ->and(is_file($claimant.'/database-owner'))->toBeFalse()
                ->and($connection->getSchemaBuilder()->hasTable('migrations'))->toBeFalse();
        }

        // A setup failure after reserving a namespace can leave only some tables. The owner can still clean it.
        $namespace->prepare('cleanup');
        if ($driver === 'pgsql') {
            expect($connection->selectOne('select 1 from pg_catalog.pg_namespace where nspname = ?', [$name]))->toBeNull();
        } else {
            expect(array_filter($connection->getSchemaBuilder()->getTableListing($connection->getDatabaseName(), false),
                static fn (string $table): bool => str_starts_with($table, $name)))->toBe([]);
        }
    } finally {
        try {
            $namespace->prepare('cleanup');
        } finally {
            DB::purge($key);
            File::deleteDirectory($work);
        }
    }
})->with([
    'financial' => ['Financial', 'f'],
    'free order' => ['FreeOrder', 'p'],
    'order claim' => ['OrderClaim', 'p'],
    'paid stock' => ['PaidStock', 'p'],
]);

it('keeps a second active namespace intact through owner verification and cleanup', function (): void {
    $driver = DB::connection()->getDriverName();
    if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
        $this->markTestSkipped('Namespace custody is checked on the server engine legs.');
    }

    $work = sys_get_temp_dir().'/kitsune-race-siblings-'.bin2hex(random_bytes(8));
    $config = config('database.connections.'.config('database.default'));
    $fixtures = [];
    try {
        foreach (['first' => 'f', 'second' => 'p'] as $key => $prefix) {
            $directory = $work.'/'.$key;
            File::makeDirectory($directory, 0700, true);
            $name = $prefix.substr(hash('sha256', $directory), 0, 5).'_';
            $connectionName = 'commerce-custody-'.$key;
            config(['database.connections.'.$connectionName => $driver === 'pgsql'
                ? [...$config, 'search_path' => $name] : [...$config, 'prefix' => $name]]);
            $connection = DB::connection($connectionName);
            $namespace = new CommerceRaceNamespace($connection, $name, $directory);
            $fixtures[$key] = [$connectionName, $connection, $name, $namespace];
            $namespace->prepare('init');
            $connection->getSchemaBuilder()->create('custody_sentinel', function (Blueprint $table): void {
                $table->string('marker');
            });
            $connection->table('custody_sentinel')->insert(['marker' => $key]);
        }

        [, $first, $name, $firstOwner] = $fixtures['first'];
        [, $second] = $fixtures['second'];
        $wrongOwner = new CommerceRaceNamespace($first, $name, $work.'/second');
        expect(fn () => $wrongOwner->prepare('cleanup'))->toThrow(RuntimeException::class, 'belongs to another run')
            ->and($first->table('custody_sentinel')->value('marker'))->toBe('first');

        $firstOwner->prepare('cleanup');
        $firstOwner->prepare('cleanup');
        expect($first->getSchemaBuilder()->hasTable('custody_sentinel'))->toBeFalse()
            ->and($second->table('custody_sentinel')->value('marker'))->toBe('second');
    } finally {
        try {
            foreach ($fixtures as [$connectionName, , , $namespace]) {
                try {
                    $namespace->prepare('cleanup');
                } finally {
                    DB::purge($connectionName);
                }
            }
        } finally {
            File::deleteDirectory($work);
        }
    }
});
