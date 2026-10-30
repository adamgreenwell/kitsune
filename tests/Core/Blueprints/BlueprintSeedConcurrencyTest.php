<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/** @param list<Process> $processes */
function seedRaceBarrier(string $file, array $processes): array
{
    $until = microtime(true) + 15;
    while (! is_file($file)) {
        foreach ($processes as $process) {
            if (! $process->isRunning()) {
                throw new RuntimeException('Seed rival exited before barrier: '.$process->getOutput().$process->getErrorOutput());
            }
        }
        if (microtime(true) > $until) {
            throw new RuntimeException('Seed rival barrier timed out: '.basename($file));
        }
        usleep(5000);
    }

    return json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
}

/** A disposable file/schema/prefix, never the parent test's transaction or application rows. */
function withSeedRace(string $ordering, callable $exercise): void
{
    $root = dirname(__DIR__, 3);
    $directory = sys_get_temp_dir().'/kitsune-seed-race-'.bin2hex(random_bytes(12));
    mkdir($directory, 0700);
    touch($directory.'/race.sqlite');
    $processes = [];
    $driver = DB::connection()->getDriverName();
    $configuration = DB::connection()->getConfig();
    // ConnectionFactory keeps an existing name. A cloned "pgsql" name would route
    // migration/model helpers back to the parent's connection instead of this alias.
    unset($configuration['name']);
    $admin = null;
    $schema = null;
    $outside = [];
    if ($driver === 'sqlite') {
        $configuration = ['driver' => 'sqlite', 'database' => $directory.'/race.sqlite', 'prefix' => '',
            'foreign_key_constraints' => true, 'busy_timeout' => 250];
    } else {
        app('config')->set('database.connections.seed_probe_admin', $configuration);
        DB::purge('seed_probe_admin');
        $admin = DB::connection('seed_probe_admin');
        $outside = $admin->getSchemaBuilder()->getTableListing(schemaQualified: false);
        sort($outside);
        if ($driver === 'pgsql') {
            $schema = 'seed_'.bin2hex(random_bytes(6));
            expect($admin->select('select nspname from pg_namespace where nspname = ?', [$schema]))->toBe([]);
            $admin->statement('CREATE SCHEMA "'.$schema.'"');
            $configuration['search_path'] = $schema;
            $configuration['prefix'] = '';
        } else {
            // Seven characters keeps the longest default core index within MySQL's 64-byte name limit.
            $configuration['prefix'] = 's'.substr(bin2hex(random_bytes(8)), 0, 5).'_';
            $configuration['prefix_indexes'] = true;
            expect(array_filter($outside, static fn (string $table): bool => str_starts_with($table, $configuration['prefix'])))->toBe([]);
        }
    }
    file_put_contents($directory.'/database.json', json_encode($configuration, JSON_THROW_ON_ERROR));
    chmod($directory.'/database.json', 0600);
    try {
        $child = function (string $mode, string $role) use ($root, $directory, $ordering, &$processes): Process {
            $process = new Process([PHP_BINARY, $root.'/tests/Core/Fixtures/BlueprintSeedRaceProbe.php', $root, $directory, $mode, $role, $ordering], $root);
            $process->setTimeout(20);
            $processes[] = $process;

            return $process;
        };
        $init = $child('init', 'init');
        $init->mustRun();
        expect(is_file($directory.'/initialized.json'))->toBeTrue($init->getOutput().$init->getErrorOutput());
        $initialized = seedRaceBarrier($directory.'/initialized.json', []);
        expect($initialized['level'])->toBe(0)->and($initialized['pdo'])->toBeFalse()->and(count($initialized['tables']))->toBeGreaterThan(20);
        $exercise($child, $directory);
    } finally {
        touch($directory.'/release-A');
        touch($directory.'/retry-B');
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0.2);
            }
        }
        // A failed/partial native initialisation retains its namespace and private configuration for diagnosis.
        if (is_file($directory.'/initialized.json')) {
            $cleanup = $child('cleanup', 'cleanup');
            $cleanup->mustRun();
            expect(is_file($directory.'/cleanup.json'))->toBeTrue($cleanup->getOutput().$cleanup->getErrorOutput());
            expect(seedRaceBarrier($directory.'/cleanup.json', [])['remaining'])->toBe([]);
            if ($schema !== null && $admin !== null) {
                $admin->statement('DROP SCHEMA "'.$schema.'" RESTRICT');
                expect($admin->select('select nspname from pg_namespace where nspname = ?', [$schema]))->toBe([]);
            }
            if ($admin !== null) {
                $after = $admin->getSchemaBuilder()->getTableListing(schemaQualified: false);
                sort($after);
                expect($after)->toBe($outside);
            }
            DB::purge('seed_probe_admin');
            File::deleteDirectory($directory);
            expect(is_dir($directory))->toBeFalse();
        } elseif ($driver === 'sqlite') {
            File::deleteDirectory($directory);
        }
    }
}

it('serializes seed receipt authority against a rival seed merge or reverse at real level zero', function (string $ordering): void {
    withSeedRace($ordering, function (callable $child, string $directory) use ($ordering): void {
        $first = $child('worker', 'A');
        $first->start();
        expect(seedRaceBarrier($directory.'/A-paused.json', [$first]))->toBe(['level' => 1, 'pdo' => true]);
        $child('read', 'paused')->mustRun();
        $paused = seedRaceBarrier($directory.'/paused-readback.json', []);
        foreach (['blueprint_seeds', 'entries', 'entry_relations', 'entry_revisions', 'locks'] as $table) {
            expect($paused['counts'][$table])->toBe(0);
        }
        $second = $child('worker', 'B');
        $second->start();
        seedRaceBarrier($directory.'/B-attempted.json', [$first, $second]);
        $blocked = seedRaceBarrier($directory.'/B-first.json', [$first, $second]);
        expect($blocked['success'])->toBeFalse()->and($blocked['busy'])->toBeTrue()
            ->and($blocked['level'])->toBe(0)->and($blocked['pdo'])->toBeFalse();
        touch($directory.'/release-A');
        $first->wait();
        expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
            ->and(seedRaceBarrier($directory.'/A-first.json', [])['success'])->toBeTrue();
        touch($directory.'/retry-B');
        $second->wait();
        expect($second->isSuccessful())->toBeTrue($second->getErrorOutput());
        $retry = seedRaceBarrier($directory.'/B-retry.json', []);
        expect($retry['level'])->toBe(0)->and($retry['pdo'])->toBeFalse();
        if (in_array($ordering, ['seed-vs-reverse', 'reverse-first'], true)) {
            expect($retry['success'])->toBeFalse()->and($retry['refusal'])->toBeTrue();
        } else {
            expect($retry['success'])->toBeTrue();
        }
        $child('read', 'read')->mustRun();
        $stored = seedRaceBarrier($directory.'/read-readback.json', []);
        expect($stored['level'])->toBe(0)->and($stored['pdo'])->toBeFalse();
        if ($ordering === 'reverse-first') {
            foreach (['blueprints', 'blueprint_seeds', 'entries', 'entry_relations', 'entry_revisions'] as $table) {
                expect($stored['counts'][$table])->toBe(0);
            }
        } else {
            expect($stored['counts']['blueprints'])->toBe(1)->and($stored['counts']['blueprint_seeds'])->toBe(1)
                ->and($stored['counts']['entries'])->toBe(2)->and($stored['counts']['entry_relations'])->toBe(2)
                ->and($stored['locks'])->toBe(2)->and($stored['version'])->toBe($ordering === 'seed-vs-merge' ? '1.1.0' : '1.0.0');
        }
    });
})->with(['seed-vs-seed', 'seed-vs-merge', 'seed-vs-reverse', 'reverse-first']);

it('commits and rolls seed facts content history and locks at level zero on an independent PDO', function (): void {
    withSeedRace('atomic-commit', function (callable $child, string $directory): void {
        $probe = $child('atomic', 'atomic');
        $probe->mustRun();
        expect(is_file($directory.'/atomic.json'))->toBeTrue($probe->getOutput().$probe->getErrorOutput());
        $result = seedRaceBarrier($directory.'/atomic.json', []);
        expect($result['seenInside']['before'])->toBe($result['before'])
            ->and($result['seenInside']['after'])->toBe($result['before'])
            ->and($result['interruptions']['before']['counts'])->toBe($result['before']);
        foreach (['before', 'after'] as $phase) {
            expect($result['interruptions'][$phase]['level'])->toBe(0)->and($result['interruptions'][$phase]['pdo'])->toBeFalse();
        }
        expect($result['committed']['blueprint_seeds'])->toBe(1)->and($result['committed']['entries'])->toBe(2)
            ->and($result['committed']['entry_relations'])->toBe(2)->and($result['committed']['locks'])->toBe(2)
            ->and($result['committed']['entry_revisions'])->toBeGreaterThan(1)
            ->and($result['committed']['audit_log'])->toBeGreaterThan($result['before']['audit_log'])
            ->and($result['interruptions']['after']['counts'])->toBe($result['committed'])
            ->and($result['retryCounts'])->toBe($result['committed'])
            ->and($result['retry'])->toBe(['created' => [], 'skipped' => [], 'retained' => ['first', 'second']])
            ->and($result['level'])->toBe(0)->and($result['pdo'])->toBeFalse();
    });
});
