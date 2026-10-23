<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Benchmarks\RevisionDiffPrototype;

/** Logical/physical observations are separate; freed SQLite pages are not returned to the filesystem automatically. */
function revisionMeasurementFootprint(PDO $pdo, string $path): array
{
    clearstatcache();

    return ['page_size' => (int) $pdo->query('pragma page_size')->fetchColumn(),
        'pages' => (int) $pdo->query('pragma page_count')->fetchColumn(),
        'free_pages' => (int) $pdo->query('pragma freelist_count')->fetchColumn(),
        'database_bytes' => filesize($path), 'wal_bytes' => is_file($path.'-wal') ? filesize($path.'-wal') : 0,
        'shared_memory_bytes' => is_file($path.'-shm') ? filesize($path.'-shm') : 0];
}

function revisionMeasurementStoredRows(PDO $pdo): array
{
    return array_map(fn (string $j): array => json_decode($j, true, 64, JSON_THROW_ON_ERROR), $pdo->query('select data from history order by sequence')->fetchAll(PDO::FETCH_COLUMN));
}

function revisionMeasurementPersistenceControls(string $out, string $key, array $histories, array $stores, array $oracle): array
{
    $controls = $migration = [];
    foreach ($histories as $interval => $history) {
        $rows = $history->rows();
        $path = $out.'/recovery-'.$interval.'.sqlite';
        expect(copy($out.'/prototype-'.$interval.'.sqlite', $path))->toBeTrue();
        $store = revisionMeasurementOpenStore($path);
        // Accidental corruption of one persisted envelope. Checksums are not storage authentication.
        $damaged = 12;
        $corrupt = $rows[$damaged];
        $corrupt['after_hash'] = str_repeat('0', 64);
        $update = $store->prepare('update history set data = ? where sequence = ?');
        $update->execute([RevisionDiffPrototype::json($corrupt), $corrupt['sequence']]);
        expect(fn () => revisionMeasurementRead($store, $key, $interval, $corrupt['sequence']))->toThrow(RuntimeException::class, 'checksum');
        $nextCheckpoint = null;
        foreach ($rows as $index => $row) {
            if ($index > $damaged && $row['kind'] === 'checkpoint') {
                $nextCheckpoint = $index;
                break;
            }
        }
        expect($nextCheckpoint)->not->toBeNull();
        expect(revisionMeasurementRead($store, $key, $interval, $rows[$nextCheckpoint]['sequence']))->toEqual($oracle[$nextCheckpoint]);
        $update->execute([RevisionDiffPrototype::json($rows[$damaged]), $corrupt['sequence']]);
        $store->exec('delete from history where sequence = '.(int) $corrupt['sequence']);
        expect(fn () => revisionMeasurementRead($store, $key, $interval, $corrupt['sequence']))->toThrow(RuntimeException::class);
        expect(revisionMeasurementRead($store, $key, $interval, $rows[$nextCheckpoint]['sequence']))->toEqual($oracle[$nextCheckpoint]);
        $insert = $store->prepare('insert into history(sequence,data) values(?,?)');
        $insert->execute([$corrupt['sequence'], RevisionDiffPrototype::json($rows[$damaged])]);

        $nextState = $oracle[49];
        $nextState['title'] = 'Synthetic next edit';
        $nextState['metadata']['id']++;
        $next = RevisionDiffPrototype::fromRows($key, $interval, $rows);
        $next->append($nextState); // Prune, rebase oldest and append must be one persisted commit.
        $redacted = RevisionDiffPrototype::fromRows($key, $interval, $rows);
        $redacted->redact(['personal', 'note'], ['slug' => null], [array_key_last($oracle[0]['relation_state'])]);
        $interruptions = 0;
        foreach ([$next->rows(), $redacted->rows()] as $after) {
            foreach ([0, 1] as $point) {
                expect(fn () => revisionMeasurementPersist($store, $rows, $after, $point))->toThrow(RuntimeException::class, 'Injected');
                expect($store->inTransaction())->toBeFalse()->and(revisionMeasurementStoredRows($store))->toEqual($rows);
                $reopened = revisionMeasurementOpenStore($path);
                revisionMeasurementVerify($reopened, $key, $interval, $oracle);
                unset($reopened);
                $interruptions++;
            }
        }
        // Exit a child with an open transaction. This is process teardown recovery, not power-loss testing.
        unset($update, $insert, $store);
        $code = '$p=new PDO("sqlite:".$argv[1]);$p->beginTransaction();$p->exec("delete from history");exit(91);';
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-r', $code, $path], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $out);
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start isolated SQLite rollback control.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(91)->and($stdout)->toBe('')->and($stderr)->toBe('');
        $store = revisionMeasurementOpenStore($path);
        expect(revisionMeasurementStoredRows($store))->toEqual($rows);
        revisionMeasurementVerify($store, $key, $interval, $oracle);
        $controls[$interval] = ['corruption_refused' => true, 'missing_revision_refused' => true,
            'recovery_checkpoint_sequence' => $rows[$nextCheckpoint]['sequence'], 'exception_rollbacks' => $interruptions, 'process_exit_rollback' => true,
            'limits' => 'No power-loss, competing writer, malicious storage rewrite or server-database crash claim.'];
        unset($store);

        // A staged per-entry transcode retains the fifty original full rows and their identities until a cutover.
        // No production reader/writer is switched. Source tokens make a retry refuse a changed source.
        DB::disconnect('revision_benchmark');
        $path = $out.'/migration-'.$interval.'.sqlite';
        expect(copy($out.'/baseline.sqlite', $path))->toBeTrue();
        $store = revisionMeasurementOpenStore($path);
        expect(strtolower((string) $store->query('pragma journal_mode=wal')->fetchColumn()))->toBe('wal');
        $sourceRows = $store->query('select * from entry_revisions order by id')->fetchAll(PDO::FETCH_ASSOC);
        $sourceDigest = hash('sha256', RevisionDiffPrototype::json($sourceRows));
        $before = revisionMeasurementFootprint($store, $path);
        $attempt = function (bool $interrupt, string $expectedDigest) use ($store, $sourceDigest, $oracle, $key, $interval, $path): array {
            $store->exec('begin immediate');
            try {
                $observed = hash('sha256', RevisionDiffPrototype::json($store->query('select * from entry_revisions order by id')->fetchAll(PDO::FETCH_ASSOC)));
                if (! hash_equals($expectedDigest, $observed)) {
                    throw new RuntimeException('Source changed; retry must restart this entry.');
                }
                $store->exec('create table if not exists revision_diff_candidate(sequence integer primary key, revision_id integer unique not null, data text not null)');
                $store->exec('delete from revision_diff_candidate');
                $write = $store->prepare('insert into revision_diff_candidate values(?,?,?)');
                $candidate = new RevisionDiffPrototype($key, $interval);
                foreach ($oracle as $index => $state) {
                    $candidate->append($state);
                    $row = $candidate->rows()[$index];
                    $write->execute([$row['sequence'], $state['metadata']['id'], RevisionDiffPrototype::json($row)]);
                    if ($interrupt && $index === 24) {
                        throw new RuntimeException('Injected migration interruption.');
                    }
                }
                $footprint = revisionMeasurementFootprint($store, $path);
                $store->exec('commit');

                return ['during_transaction' => $footprint, 'source_digest' => $sourceDigest];
            } catch (Throwable $error) {
                $store->exec('rollback');
                throw $error;
            }
        };
        expect(fn () => $attempt(true, $sourceDigest))->toThrow(RuntimeException::class, 'Injected');
        expect((int) $store->query("select count(*) from sqlite_master where name='revision_diff_candidate'")->fetchColumn())->toBe(0);
        expect(fn () => $attempt(false, str_repeat('0', 64)))->toThrow(RuntimeException::class, 'Source changed');
        $start = hrtime(true);
        $during = $attempt(false, $sourceDigest);
        $elapsed = (hrtime(true) - $start) / 1e6;
        $after = revisionMeasurementFootprint($store, $path);
        $firstRows = $store->query('select * from revision_diff_candidate order by sequence')->fetchAll(PDO::FETCH_ASSOC);
        $candidate = RevisionDiffPrototype::fromRows($key, $interval, array_map(fn (array $r): array => json_decode($r['data'], true, 64, JSON_THROW_ON_ERROR), $firstRows));
        foreach ($firstRows as $index => $row) {
            expect((int) $row['revision_id'])->toBe($oracle[$index]['metadata']['id'])
                ->and($candidate->read((int) $row['sequence']))->toEqual($oracle[$index]);
        }
        $attempt(false, $sourceDigest);
        expect($store->query('select * from revision_diff_candidate order by sequence')->fetchAll(PDO::FETCH_ASSOC))->toEqual($firstRows)
            ->and($store->query('select * from entry_revisions order by id')->fetchAll(PDO::FETCH_ASSOC))->toEqual($sourceRows);
        // Reverse codec staging also preserves original identities; production-table import remains separate.
        $start = hrtime(true);
        $store->beginTransaction();
        $store->exec('create table revision_snapshot_candidate(revision_id integer primary key, data text not null)');
        $write = $store->prepare('insert into revision_snapshot_candidate values(?,?)');
        foreach ($firstRows as $row) {
            $state = $candidate->read((int) $row['sequence']);
            $write->execute([$state['metadata']['id'], RevisionDiffPrototype::json($state)]);
        }
        $store->commit();
        $reverseMs = (hrtime(true) - $start) / 1e6;
        $reverse = array_map(fn (string $json): array => json_decode($json, true, 64, JSON_THROW_ON_ERROR), $store->query('select data from revision_snapshot_candidate order by revision_id')->fetchAll(PDO::FETCH_COLUMN));
        expect($reverse)->toEqual($oracle);
        $double = revisionMeasurementFootprint($store, $path);
        $store->exec('drop table revision_snapshot_candidate');
        $store->exec('drop table revision_diff_candidate');
        $store->query('pragma wal_checkpoint(truncate)')->fetchAll();
        $dropped = revisionMeasurementFootprint($store, $path);
        expect($store->query('pragma quick_check')->fetchColumn())->toBe('ok');
        $migration[$interval] = ['transcode_ms' => $elapsed, 'reverse_codec_staging_ms' => $reverseMs,
            'before' => $before, 'during' => $during['during_transaction'], 'after_commit' => $after,
            'both_codec_staging_tables' => $double, 'after_drop_checkpoint_truncate' => $dropped,
            'halfway_rollback_verified' => true, 'stale_source_refused' => true, 'restart_idempotent' => true,
            'original_full_rows_unchanged' => true, 'identities_and_all_states_preserved' => true,
            'scope' => 'One-entry atomic SQLite staging only. Current full rows retained. No online cutover, live concurrent writer protocol, multi-entry job orchestration or production schema migration implemented.'];
        unset($write, $attempt, $store);
    }

    return ['persisted_controls' => $controls, 'migration' => $migration];
}
