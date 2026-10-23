<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Benchmarks\RevisionDiffPrototype;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRevision;
use Kitsune\Core\Schema\RevisionWrites;

/** Warm, single-process samples. A bootstrap interval measures sample variability, not workload realism. */
function revisionMeasurementSummary(array $values): array
{
    sort($values, SORT_NUMERIC);
    $n = count($values);
    $median = static fn (array $v): float => ($v[(int) floor((count($v) - 1) / 2)] + $v[(int) ceil((count($v) - 1) / 2)]) / 2;
    $seed = 262;
    $boot = [];
    for ($iteration = 0; $iteration < 250; $iteration++) {
        $resample = [];
        for ($i = 0; $i < $n; $i++) {
            $seed = (1664525 * $seed + 1013904223) & 0xFFFFFFFF;
            $resample[] = $values[$seed % $n];
        }
        sort($resample, SORT_NUMERIC);
        $boot[] = $median($resample);
    }
    sort($boot, SORT_NUMERIC);

    return ['n' => $n, 'median_ms' => $median($values), 'p95_ms' => $values[(int) ceil($n * .95) - 1],
        'median_bootstrap_95pct_ms' => [$boot[6], $boot[243]], 'raw_ms' => $values];
}

function revisionMeasurementLive(Entry $entry): array
{
    $entry->refresh();

    return [...$entry->only([...EntryRevision::SNAPSHOT_ATTRIBUTES, 'values']), 'relation_state' => $entry->relationState()];
}

function revisionMeasurementExpectedLive(array $state): array
{
    return array_intersect_key($state, array_fill_keys([...EntryRevision::SNAPSHOT_ATTRIBUTES, 'values', 'relation_state'], true));
}

/** The only normalized attribute is revision updated_at: actual erasure writes it; the codec has no clock. */
function revisionMeasurementWithoutRewriteTime(array $state): array
{
    unset($state['metadata']['updated_at']);

    return $state;
}

function revisionMeasurementConnect(string $path): void
{
    DB::purge('revision_benchmark');
    config(['database.connections.revision_benchmark.database' => $path]);
}

function revisionMeasurementClone(string $out, string $name): string
{
    DB::disconnect('revision_benchmark');
    $path = $out.'/'.$name.'.sqlite';
    if (file_exists($path) || ! copy($out.'/baseline.sqlite', $path)) {
        throw new RuntimeException('Cannot create a fresh owned database copy.');
    }
    revisionMeasurementConnect($path);

    return $path;
}

function revisionMeasurementOpenStore(string $path): PDO
{
    return new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

/** Verify persisted rows against a separate immutable full-state oracle, never the candidate's own decoder. */
function revisionMeasurementVerify(PDO $store, string $key, int $interval, array $oracle, bool $rewriteTimes = false): void
{
    $rows = $store->query('select data from history order by sequence')->fetchAll(PDO::FETCH_COLUMN);
    expect($rows)->toHaveCount(count($oracle));
    foreach ($rows as $index => $json) {
        $row = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        $actual = revisionMeasurementRead($store, $key, $interval, $row['sequence']);
        $expected = $oracle[$index];
        if ($rewriteTimes) {
            $actual = revisionMeasurementWithoutRewriteTime($actual);
            $expected = revisionMeasurementWithoutRewriteTime($expected);
        }
        expect($actual)->toEqual($expected);
    }
}

/** Actual writer reads/restores/erasure plus a codec/materialization adapter, not a production diff writer. */
function revisionMeasurementOperations(string $out, int $entryId, string $key, array $histories, array $stores, array $storage, array $replacementContacts): array
{
    $entry = Entry::findOrFail($entryId);
    $oracle = $entry->revisions()->orderBy('id')->get()->map(fn (EntryRevision $r): array => revisionMeasurementState($r))->all();
    expect($oracle)->toHaveCount(50);
    $targets = ['oldest' => 0, 'middle' => 24, 'newest' => 49];
    $reads = $lists = $restoreTimes = [];
    for ($sample = 0; $sample < 25; $sample++) {
        $start = hrtime(true);
        $actualList = $entry->revisions()->orderBy('id')->get(['id', 'title', 'author_id', 'created_at'])->map(fn (EntryRevision $r): array => ['id' => $r->id, 'title' => $r->title, 'author_id' => $r->author_id, 'created_at' => $r->getRawOriginal('created_at')])->all();
        $lists['snapshot'][] = (hrtime(true) - $start) / 1e6;
        expect($actualList)->toHaveCount(50);
        foreach ($targets as $name => $index) {
            $start = hrtime(true);
            $state = revisionMeasurementState($entry->revisions()->whereKey($oracle[$index]['metadata']['id'])->firstOrFail());
            $reads['snapshot'][$name][] = (hrtime(true) - $start) / 1e6;
            expect($state)->toEqual($oracle[$index]);
        }
        foreach ($stores as $interval => $store) {
            $start = hrtime(true);
            $rows = array_map(fn (string $json): array => json_decode($json, true, 64, JSON_THROW_ON_ERROR), $store->query('select data from history order by sequence')->fetchAll(PDO::FETCH_COLUMN));
            $history = RevisionDiffPrototype::fromRows($key, $interval, $rows);
            $candidateList = [];
            foreach ($rows as $row) {
                $state = $history->read($row['sequence']);
                $candidateList[] = ['id' => $state['metadata']['id'], 'title' => $state['title'], 'author_id' => $state['metadata']['author_id'], 'created_at' => $state['metadata']['created_at']];
            }
            $lists[$interval][] = (hrtime(true) - $start) / 1e6;
            expect($candidateList)->toEqual($actualList);
            foreach ($targets as $name => $index) {
                $sequence = $rows[$index]['sequence'];
                $start = hrtime(true);
                $state = revisionMeasurementRead($store, $key, $interval, $sequence);
                $reads[$interval][$name][] = (hrtime(true) - $start) / 1e6;
                expect($state)->toEqual($oracle[$index]);
            }
        }
    }
    // Copy setup is excluded. Every timed restore actually commits at outer transaction level zero.
    // The adapter removes the old snapshot rows in its private copy, then counts decode, materialize, guard, record, cleanup.
    foreach (['snapshot', 1, 5, 10, 25, 50] as $representation) {
        foreach ($targets as $name => $index) {
            for ($sample = 0; $sample < 3; $sample++) {
                revisionMeasurementConnect($out.'/baseline.sqlite');
                revisionMeasurementClone($out, 'restore-'.$representation.'-'.$name.'-'.$sample);
                $entry = Entry::findOrFail($entryId);
                if ($representation !== 'snapshot') {
                    $entry->revisions()->delete();
                }
                expect(DB::connection()->transactionLevel())->toBe(0);
                $start = hrtime(true);
                if ($representation === 'snapshot') {
                    $revision = $entry->revisions()->whereKey($oracle[$index]['metadata']['id'])->firstOrFail();
                } else {
                    $sequence = $histories[$representation]->rows()[$index]['sequence'];
                    $decoded = revisionMeasurementRead($stores[$representation], $key, $representation, $sequence);
                    $revision = new EntryRevision;
                    $revision->forceFill([...array_diff_key($decoded, ['metadata' => true]), ...$decoded['metadata']]);
                    $revision->save();
                }
                $entry->restoreRevision($revision);
                if ($representation !== 'snapshot') {
                    $revision->delete();
                }
                $restoreTimes[$representation][$name][] = (hrtime(true) - $start) / 1e6;
                expect(DB::connection()->transactionLevel())->toBe(0)
                    ->and(revisionMeasurementLive($entry))->toEqual(revisionMeasurementExpectedLive($oracle[$index]));
            }
        }
    }
    revisionMeasurementConnect($out.'/baseline.sqlite');

    // Independent erasure oracle: null relation means unknown, not an empty relation assertion.
    $contactKey = (string) $storage['contacts']->id;
    $erasedOracle = array_map(function (array $state) use ($contactKey): array {
        $state['values']['personal'] = $state['values']['note'] = null;
        unset($state['unsanitized_values']['personal'], $state['unsanitized_values']['note']);
        if ($state['unsanitized_values'] === []) {
            $state['unsanitized_values'] = null;
        }
        $state['slug'] = null;
        $state['relation_state'][$contactKey] = null;

        return $state;
    }, $oracle);
    $erasure = [];
    revisionMeasurementClone($out, 'erasure-snapshot');
    $entry = Entry::findOrFail($entryId);
    $start = hrtime(true);
    $rewritten = [];
    foreach (['personal', 'note', 'public_slug', 'contacts'] as $handle) {
        $rewritten[$handle] = $entry->redactField($handle);
    }
    $erasure['snapshot'] = ['ms' => (hrtime(true) - $start) / 1e6, 'rewritten_counts' => $rewritten];
    expect(DB::connection()->transactionLevel())->toBe(0);
    $actualErased = $entry->revisions()->orderBy('id')->get()->map(fn (EntryRevision $r): array => revisionMeasurementState($r))->all();
    expect(array_map('revisionMeasurementWithoutRewriteTime', $actualErased))->toEqual(array_map('revisionMeasurementWithoutRewriteTime', $erasedOracle));
    // Preserve a replacement contact while restoring every erased version. Restore may prune source rows,
    // so use a fresh copy per target; the immutable oracle remains external to both readers.
    DB::disconnect('revision_benchmark');
    $erasedPath = $out.'/erasure-snapshot.sqlite';
    foreach (['snapshot', 1, 5, 10, 25, 50] as $representation) {
        if ($representation !== 'snapshot') {
            $copy = $out.'/erasure-prototype-'.$representation.'.sqlite';
            expect(copy($out.'/prototype-'.$representation.'.sqlite', $copy))->toBeTrue();
            $store = revisionMeasurementOpenStore($copy);
            $rows = array_map(fn (string $j): array => json_decode($j, true, 64, JSON_THROW_ON_ERROR), $store->query('select data from history order by sequence')->fetchAll(PDO::FETCH_COLUMN));
            $history = RevisionDiffPrototype::fromRows($key, $representation, $rows);
            $start = hrtime(true);
            $history->redact(['personal', 'note'], ['slug' => null], [$contactKey]);
            $bytes = revisionMeasurementPersist($store, $rows, $history->rows());
            $erasure[$representation] = ['ms' => (hrtime(true) - $start) / 1e6, 'encoded_bytes_written' => $bytes];
            revisionMeasurementVerify($store, $key, $representation, $erasedOracle, true);
            expect(RevisionDiffPrototype::json($history->rows()))->not->toContain('synthetic-private-marker', 'synthetic-private-slug', 'synthetic-private-original');
        }
        foreach ($erasedOracle as $index => $expected) {
            $path = $out.'/erased-restore-'.$representation.'-'.$index.'.sqlite';
            expect(copy($erasedPath, $path))->toBeTrue();
            revisionMeasurementConnect($path);
            $entry = Entry::findOrFail($entryId);
            RevisionWrites::suspend(fn () => $entry->syncFieldRelations($storage['contacts'], $replacementContacts));
            $expectedLive = revisionMeasurementExpectedLive($expected);
            $expectedLive['relation_state'][$contactKey] = $replacementContacts;
            if ($representation === 'snapshot') {
                $revision = $entry->revisions()->whereKey($expected['metadata']['id'])->firstOrFail();
            } else {
                $entry->revisions()->delete();
                $decoded = revisionMeasurementRead($store, $key, $representation, $rows[$index]['sequence']);
                $revision = new EntryRevision;
                $revision->forceFill([...array_diff_key($decoded, ['metadata' => true]), ...$decoded['metadata']])->save();
            }
            $entry->restoreRevision($revision);
            expect(revisionMeasurementLive($entry))->toEqual($expectedLive)
                ->and(DB::connection()->transactionLevel())->toBe(0);
            DB::disconnect('revision_benchmark');
            unlink($path);
        }
        if ($representation !== 'snapshot') {
            unset($store);
        }
    }
    revisionMeasurementConnect($out.'/baseline.sqlite');

    return ['warm_reads' => array_map(fn (array $r): array => array_map('revisionMeasurementSummary', $r), $reads),
        'warm_history_lists' => array_map('revisionMeasurementSummary', $lists),
        'guarded_outer_commit_restores' => array_map(fn (array $r): array => array_map('revisionMeasurementSummary', $r), $restoreTimes),
        'erasure' => $erasure, 'erased_restore_checks' => 300, 'replacement_relation_preserved' => true,
        'metadata_normalization' => 'Only revision metadata.updated_at ignored when comparing erasure rewrites; identity, created_at, schema, content and relation order compared.',
        'restore_scope' => 'Snapshot query+guarded restore. Prototype decode+temporary full-row materialization+same guarded restore+delete. Common current full-snapshot recorder retained. Copy setup excluded. Three samples per target, insufficient for reliable tails.',
        'list_scope' => 'Same id/title/author/created_at output. Candidate reconstructs all fifty rows: no unimplemented sidecar index assumed.',
        'condition' => 'Warm local PHP/SQLite/OS caches; no cold-cache or resource-floor claim. Read n25 per target; restore n3 is exploratory.'];
}
