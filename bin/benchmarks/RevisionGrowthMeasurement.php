<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

/*
 * Opt-in experiment, outside the default tests/ directory. Run through Pest, which owns Testbench's lifecycle:
 * KITSUNE_REVISION_BENCH_OUT=/new/absolute/benchmark-directory KITSUNE_REVISION_BENCH_CASE=small-scalar \
 *     php vendor/bin/pest bin/benchmarks/RevisionGrowthMeasurement.php
 *
 * Only new, owned SQLite files are opened. Existing databases and environment database settings are never used.
 * Synthetic warm-local measurements, not a resource-floor qualification or a production workload distribution.
 * Current application writes and prototype encoding/database writes have different scope and are labelled apart.
 */

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Kitsune\Benchmarks\RevisionDiffPrototype;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\KitsuneServiceProvider;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRevision;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Schema\RecordedRevisions;
use Kitsune\Core\Tenancy\Context;
use Orchestra\Testbench\TestCase as Orchestra;

require_once __DIR__.'/RevisionDiffPrototype.php';
require_once __DIR__.'/RevisionGrowthOperations.php';
require_once __DIR__.'/RevisionGrowthPersistence.php';

$root = dirname(__DIR__, 2);
$requested = getenv('KITSUNE_REVISION_BENCH_OUT');
$case = getenv('KITSUNE_REVISION_BENCH_CASE');
$sizes = ['small' => 2048, 'medium' => 32768, 'large' => 262144];
$parts = explode('-', is_string($case) ? $case : '');

if (! is_string($requested) || ! str_starts_with($requested, '/') || file_exists($requested)
    || ! is_dir(dirname($requested)) || count($parts) !== 2 || ! isset($sizes[$parts[0]])
    || ! in_array($parts[1], ['scalar', 'insert', 'replace'], true)) {
    throw new RuntimeException('Supply a NEW absolute output directory and a small|medium|large-scalar|insert|replace case.');
}

$out = realpath(dirname($requested)).'/'.basename($requested);
if (str_starts_with($out.'/', realpath($root).'/')) {
    throw new RuntimeException('Benchmark output must be outside the checkout.');
}

mkdir($out, 0700);
touch($out.'/baseline.sqlite');
foreach (['framework/views', 'framework/cache/data', 'framework/sessions', 'logs'] as $part) {
    mkdir($out.'/storage/'.$part, 0700, true);
}

abstract class RevisionGrowthMeasurementHarness extends Orchestra
{
    public static string $output;

    protected function resolveApplication()
    {
        // Before core registers its private disk definitions; changing storage after registration is refused.
        $app = parent::resolveApplication();
        $app->useStoragePath(self::$output.'/storage');

        return $app;
    }

    protected function getPackageProviders($app): array
    {
        return [BladeIconsServiceProvider::class, BladeHeroiconsServiceProvider::class, KitsuneServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'revision_benchmark');
        $app['config']->set('database.connections.revision_benchmark', [
            'driver' => 'sqlite', 'database' => self::$output.'/baseline.sqlite', 'prefix' => '',
            'foreign_key_constraints' => true, 'busy_timeout' => 5000,
        ]);
        $app['config']->set('view.compiled', self::$output.'/storage/framework/views');
        $app['config']->set('logging.default', 'null');
    }
}

RevisionGrowthMeasurementHarness::$output = $out;
uses(RevisionGrowthMeasurementHarness::class);

function revisionMeasurementState(EntryRevision $revision): array
{
    return [
        ...$revision->snapshot(),
        'relation_state' => $revision->relation_state,
        'unsanitized_values' => $revision->unsanitized_values,
        'metadata' => [
            'id' => $revision->id, 'entry_id' => $revision->entry_id, 'author_id' => $revision->author_id,
            'created_at' => $revision->getRawOriginal('created_at'), 'updated_at' => $revision->getRawOriginal('updated_at'),
        ],
    ];
}

function revisionMeasurementPersist(PDO $pdo, array $before, array $after, ?int $interruptAfterWrites = null): int
{
    $old = array_column($before, 'checksum', 'sequence');
    $bytes = 0;
    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare('delete from history where sequence < ?');
        $delete->execute([$after[0]['sequence']]);
        $writes = 0;
        if ($interruptAfterWrites === 0) {
            throw new RuntimeException('Injected persistence interruption.');
        }
        $write = $pdo->prepare('insert into history (sequence, data) values (?, ?) on conflict(sequence) do update set data = excluded.data');
        foreach ($after as $row) {
            if (($old[$row['sequence']] ?? null) !== $row['checksum']) {
                $json = RevisionDiffPrototype::json($row);
                $bytes += strlen($json);
                $write->execute([$row['sequence'], $json]);
                if (++$writes === $interruptAfterWrites) {
                    throw new RuntimeException('Injected persistence interruption.');
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    return $bytes;
}

function revisionMeasurementValidate(array $storage, array $input, ?Entry $entry = null): void
{
    $rules = [];
    foreach ($storage as $handle => $field) {
        $config = new FieldConfig($field, record: $entry);
        $type = app(FieldTypeRegistry::class)->get($field->type);
        $rules[$handle] = $type->validationRules($config);
        if ($config->isMultiValue()) {
            $rules[$handle.'.*'] = $type->elementValidationRules($config);
        }
    }

    // Validation is outside the measured write, but the corpus must be authorable through these field rules.
    expect(Validator::make($input, $rules)->errors()->all())->toBe([]);
}

function revisionMeasurementRead(PDO $pdo, string $key, int $interval, int $sequence): array
{
    $query = $pdo->prepare('select data from history where sequence <= ? order by sequence desc');
    $query->execute([$sequence]);
    $rows = [];
    while (($json = $query->fetchColumn()) !== false) {
        $row = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        $rows[] = $row;
        if ($row['kind'] === 'checkpoint') {
            break;
        }
    }
    $query->closeCursor();

    return RevisionDiffPrototype::fromRows($key, $interval, array_reverse($rows))->read($sequence);
}

it('measures the actual bounded writer and a disposable checkpoint/diff encoding', function () use ($root, $out, $case, $parts, $sizes): void {
    Artisan::call('migrate', ['--database' => 'revision_benchmark', '--path' => [$root.'/packages/core/database/migrations', $root.'/tests/Core/migrations'], '--realpath' => true, '--force' => true]);
    $db = DB::connection();
    expect($db->getDriverName())->toBe('sqlite')->and($db->getDatabaseName())->toBe($out.'/baseline.sqlite')->and($db->transactionLevel())->toBe(0);

    $context = app(Context::class);
    $fixtures = [];
    foreach ([1, 2] as $number) {
        $org = Org::create(['name' => 'Synthetic org '.$number, 'slug' => 'bench-'.$number]);
        $context->forget()->setOrg($org);
        $type = EntryType::create(['org_id' => $org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
        $assets = EntryType::create(['org_id' => $org->id, 'handle' => 'asset', 'name' => 'Asset', 'plural_name' => 'Assets', 'is_media' => true]);
        $storage = [];
        foreach ([['body', 'rich_text', 'none', 1], ['personal', 'text', 'personal', 1], ['note', 'rich_text', 'personal', 1], ['public_slug', 'slug', 'personal', 1], ['media_refs', 'relation', 'none', -1], ['contacts', 'relation', 'personal', -1]] as [$handle, $fieldType, $pii, $cardinality]) {
            $field = FieldStorage::create(['org_id' => $org->id, 'handle' => $handle, 'type' => $fieldType, 'pii_class' => $pii, 'cardinality' => $cardinality]);
            Field::create(['entry_type_id' => $type->id, 'field_storage_id' => $field->id, 'label' => $handle]);
            $storage[$handle] = $field;
        }
        $sites = $entries = [];
        foreach ([1, 2] as $siteNumber) {
            $site = Site::create(['org_id' => $org->id, 'handle' => 'site-'.$siteNumber, 'slug' => 'bench-'.$number.'-'.$siteNumber, 'name' => 'Synthetic site']);
            $context->setSite($site);
            $sites[] = $site;
            $entries[] = Entry::create(['entry_type_id' => $type->id, 'title' => 'Synthetic neighbouring entry']);
        }
        $context->setSite($sites[0]);
        $shared = Entry::create(['entry_type_id' => $type->id, 'site_id' => null, 'title' => 'Synthetic org-shared entry']);
        $fixtures[] = compact('org', 'sites', 'entries', 'shared', 'type', 'assets', 'storage');
    }
    $first = $fixtures[0];
    $context->forget()->setOrg($first['org'])->setSite($first['sites'][0]);
    expect(Entry::find($first['entries'][1]->id))->toBeNull()->and(Entry::find($fixtures[1]['entries'][0]->id))->toBeNull()->and(Entry::find($first['shared']->id))->not->toBeNull();
    $assetIds = [];
    foreach ([1, 2] as $number) {
        $assetIds[] = Entry::create(['entry_type_id' => $first['assets']->id, 'site_id' => $first['sites'][0]->id, 'title' => 'Synthetic media reference '.$number])->id;
    }

    // Negative control: the original pilot accidentally used short text for a long body. Never use those timings.
    $shortTextRules = app(FieldTypeRegistry::class)->get('text')->validationRules(new FieldConfig($first['storage']['personal']));
    expect(Validator::make(['old_body' => str_repeat('x', 2048)], ['old_body' => $shortTextRules])->fails())->toBeTrue();

    $body = '<p>'.substr(str_repeat('Synthetic editorial content. ', (int) ceil($sizes[$parts[0]] / 29)), 0, $sizes[$parts[0]] - 7).'</p>';
    $initial = ['body' => $body, 'personal' => 'synthetic-private-marker', 'note' => '<p>Synthetic note</p><script>synthetic-private-original</script>'];
    revisionMeasurementValidate($first['storage'], [...$initial, 'public_slug' => 'synthetic-private-slug', 'media_refs' => $assetIds, 'contacts' => [$first['entries'][0]->id]]);
    app(RecordedRevisions::class)->open();
    $entry = Entry::create(['entry_type_id' => $first['type']->id, 'title' => 'Edit 0', 'slug' => 'synthetic-private-slug', 'values' => $initial]);
    $sync = function (bool $reverse = false) use ($entry, $first, $assetIds): void {
        $entry->writeRelationsAndReconcile(function () use ($entry, $first, $assetIds, $reverse): void {
            $entry->syncFieldRelations($first['storage']['media_refs'], $reverse ? array_reverse($assetIds) : $assetIds);
            $entry->syncFieldRelations($first['storage']['contacts'], [$first['entries'][0]->id]);
        });
    };
    $sync();
    expect($entry->revisions()->count())->toBe(1);
    $key = 'org:'.$entry->org_id.'/site:'.$entry->site_id.'/entry:'.$entry->id;
    $histories = $stores = $writeTimes = $bytesWritten = [];
    foreach ([1, 5, 10, 25, 50] as $interval) {
        $histories[$interval] = new RevisionDiffPrototype($key, $interval);
        $stores[$interval] = new PDO('sqlite:'.$out.'/prototype-'.$interval.'.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $stores[$interval]->exec('create table history (sequence integer primary key, data text not null)');
        $writeTimes[$interval] = $bytesWritten[$interval] = [];
    }
    $currentTimes = $milestones = [];
    for ($edit = 0; $edit <= 1000; $edit++) {
        if ($edit > 0) {
            $values = $entry->values;
            if ($parts[1] === 'insert') {
                $values['body'] = substr($body, 0, 100).' insertion '.$edit.' '.substr($body, 100);
            } elseif ($parts[1] === 'replace') {
                $values['body'] = '<p>'.substr(str_repeat(hash('sha256', 'fixed-seed-262-'.$edit), (int) ceil(strlen($body) / 64)), 0, strlen($body) - 7).'</p>';
            }
            if ($edit % 25 === 0) {
                $values['note'] = '<p>Synthetic note '.$edit.'</p><script>synthetic-private-original</script>';
            }
            revisionMeasurementValidate($first['storage'], [...$values, 'public_slug' => $entry->slug, 'media_refs' => $assetIds, 'contacts' => [$first['entries'][0]->id]], $entry);
            app(RecordedRevisions::class)->open((int) $entry->id);
            $start = hrtime(true);
            $entry->update(['title' => 'Edit '.$edit, 'values' => $values]);
            $sync($parts[1] === 'replace' && $edit % 2 === 1);
            $currentTimes[] = (hrtime(true) - $start) / 1e6;
            expect($db->transactionLevel())->toBe(0);
        }
        $state = revisionMeasurementState($entry->revisions()->latest('id')->firstOrFail());
        foreach ($histories as $interval => $history) {
            $before = $history->rows();
            $start = hrtime(true);
            $history->append($state);
            $bytesWritten[$interval][] = revisionMeasurementPersist($stores[$interval], $before, $history->rows());
            $writeTimes[$interval][] = (hrtime(true) - $start) / 1e6;
        }
        if (in_array($edit, [0, 1, 10, 49, 50, 51, 100, 1000], true)) {
            $oracle = $entry->revisions()->orderBy('id')->get()->map(fn (EntryRevision $r): array => revisionMeasurementState($r))->all();
            expect($oracle)->toHaveCount(min(50, $edit + 1));
            foreach ($histories as $interval => $history) {
                foreach ($history->rows() as $index => $row) {
                    expect(revisionMeasurementRead($stores[$interval], $key, $interval, $row['sequence']))->toEqual($oracle[$index]);
                }
            }
            $milestones[$edit] = ['retained' => count($oracle), 'current_logical_state_bytes' => array_sum(array_map(fn (array $s): int => strlen(RevisionDiffPrototype::json($s)), $oracle)), 'prototype_row_bytes' => array_map(fn (RevisionDiffPrototype $h): int => array_sum(array_map(fn (array $r): int => strlen(RevisionDiffPrototype::json($r)), $h->rows())), $histories)];
        }
    }
    $operations = revisionMeasurementOperations($out, (int) $entry->id, $key, $histories, $stores, $first['storage'], [$first['shared']->id]);
    $oracle = $entry->revisions()->orderBy('id')->get()->map(fn (EntryRevision $r): array => revisionMeasurementState($r))->all();
    $persistence = revisionMeasurementPersistenceControls($out, $key, $histories, $stores, $oracle);
    $db = DB::connection();
    $tablePages = $db->select('select name, sum(pgsize) as bytes from dbstat group by name order by name');
    $result = ['stage' => 'storage-write-baseline-only', 'case' => $case, 'php' => PHP_VERSION, 'sqlite' => $db->selectOne('select sqlite_version() as v')->v, 'body_type' => 'rich_text', 'input_field_and_element_validation_passed' => true, 'validation_outside_write_timing' => true, 'body_input_bytes' => strlen($body), 'keep_revisions' => Entry::KEEP_REVISIONS, 'edits' => 1000, 'outer_commits_verified' => true, 'milestones' => $milestones, 'current_application_write_ms' => $currentTimes, 'prototype_encoding_and_sqlite_write_ms' => $writeTimes, 'prototype_bytes_written' => $bytesWritten, 'database_table_pages' => $tablePages, 'peak_php_bytes' => memory_get_peak_usage(true), 'resource_limits_applied' => false, 'remaining' => ['read and guarded-restore samples', 'actual erasure and prototype rewrite', 'persisted corruption/recovery and rollback controls', 'migration and transient storage costs', 'statistics and findings'], 'source_sha256' => array_combine(['Entry.php', 'EntryRevision.php', 'RevisionDiffPrototype.php', 'RevisionGrowthMeasurement.php'], array_map('hash_file', array_fill(0, 4, 'sha256'), [$root.'/packages/core/src/Models/Entry.php', $root.'/packages/core/src/Models/EntryRevision.php', __DIR__.'/RevisionDiffPrototype.php', __FILE__]))];
    $result['stage'] = 'storage-write-read-restore-erasure';
    $result['operations'] = $operations;
    $result['persistence'] = $persistence;
    $result['prototype_physical_footprints'] = array_combine(array_keys($stores), array_map(fn (PDO $pdo, int $interval): array => revisionMeasurementFootprint($pdo, $out.'/prototype-'.$interval.'.sqlite'), array_values($stores), array_keys($stores)));
    $result['write_statistics'] = ['snapshot_application' => revisionMeasurementSummary($currentTimes), 'prototype_codec_sqlite' => array_map('revisionMeasurementSummary', $writeTimes)];
    $result['stage'] = 'single-case-complete-measurement';
    $result['remaining'] = ['all size/edit-pattern cases and findings'];
    file_put_contents($out.'/result.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    $context->forget();
});
