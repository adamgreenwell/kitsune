<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Benchmarks;

use App\Models\User;
use Composer\InstalledVersions;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Once;
use InvalidArgumentException;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Filament\Schemas\FieldValueRenderer;
use Kitsune\Core\Filament\Widgets\EntryCountsWidget;
use Kitsune\Core\Filament\Widgets\RecentEntriesWidget;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRevision;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Schema\RecordedRevisions;
use Kitsune\Core\Schema\SchemaManager;
use Kitsune\Core\Tenancy\Context;
use ReflectionMethod;
use RuntimeException;

require_once __DIR__.'/SchemaScaleCorpus.php';
require_once __DIR__.'/SchemaScaleMedia.php';
require_once __DIR__.'/SchemaScaleDatabase.php';

/**
 * Opt-in experiment run INSIDE a disposable actual skeleton, with that host's own runtime dependencies.
 * Historical Testbench query-only results remain separate. This class never accepts an ambient database.
 *
 * @internal
 */
final class SchemaScaleExperiment
{
    private int $checks = 0;

    public function __construct(private readonly string $root, private readonly string $out, private readonly int $rows)
    {
        if (! in_array($rows, [10_000, 100_000, 1_000_000], true)) {
            throw new InvalidArgumentException('The comparable scales are exactly 10000, 100000 or 1000000.');
        }
        if (realpath($root) !== $root || realpath($out) !== $out || ! is_dir($out)
            || str_starts_with($out.'/', $root.'/')) {
            throw new InvalidArgumentException('Use a canonical owned output outside the checkout.');
        }
    }

    public function run(): array
    {
        $root = $this->root;
        $out = $this->out;
        $rows = $this->rows;
        $profile = 'consistent-app-panel-media';
        $panelMedia = true;
        $db = DB::connection();
        $database = new SchemaScaleDatabase($db, $root, $out, $rows);
        $database->assertOwnedEmpty();
        $this->check(hash_file('sha256', (new \ReflectionClass(User::class))->getFileName()) === hash_file('sha256', $root.'/skeleton/app/Models/User.php'), 'The actual host model is required.');
        $code = Artisan::call('migrate', ['--database' => $db->getName(), '--path' => [$root.'/packages/core/database/migrations', $root.'/skeleton/database/migrations'], '--realpath' => true, '--force' => true]);
        $this->check($code === 0, 'The owned host migrations failed.');
        $db = DB::connection();
        $database->assertActiveConnection($db);
        $database->foreignKeys();
        $context = app(Context::class);
        $corpus = new SchemaScaleCorpus($rows);
        $media = $panelMedia ? new SchemaScaleMedia($out) : null;
        if ($panelMedia) {
            foreach ([MediaDisks::PRIVATE, MediaDisks::INTAKE, 'local', 'public'] as $disk) {
                $this->check(str_starts_with(Storage::disk($disk)->path(''), $out.'/storage/'), 'A storage disk escaped the owned output.');
            }
            $this->check(rtrim(Storage::disk(MediaDisks::PRIVATE)->path(''), '/') === $media->root(), 'Private media root differs.');
        }
        $orgs = $sites = $types = $storage = $rules = [];
        foreach ([1, 2, 3] as $orgNumber) {
            $orgs[$orgNumber] = Org::create(['slug' => 'scale-'.$orgNumber, 'name' => 'Synthetic scale org '.$orgNumber]);
            $context->forget()->setOrg($orgs[$orgNumber]);
            $types[$orgNumber] = self::entryTypes($orgs[$orgNumber]);
            foreach ([
                ['body', 'rich_text', 1, []],
                ['score', 'number', 1, ['min' => 0, 'max' => 100]],
                ['category', 'select', 1, ['options' => ['news' => 'News', 'guide' => 'Guide', 'opinion' => 'Opinion', 'feature' => 'Feature']]],
                ['tags', 'text', 4, ['maxLength' => 32]],
                ['links', 'relation', 2, []],
            ] as [$handle, $type, $cardinality, $settings]) {
                $field = FieldStorage::create(compact('handle', 'type', 'cardinality', 'settings') + ['pii_class' => 'none', 'is_indexed' => $handle === 'score']);
                $storage[$orgNumber][$handle] = $field;
                foreach ($types[$orgNumber] as $entryType) {
                    Field::create(['entry_type_id' => $entryType->id, 'field_storage_id' => $field->id, 'label' => $handle]);
                }
                if ($handle === 'score') {
                    app(SchemaManager::class)->sync($field);
                }
                $config = new FieldConfig($field);
                $fieldType = app(FieldTypeRegistry::class)->get($type);
                $rules[$orgNumber][$handle] = $fieldType->validationRules($config);
                if ($config->isMultiValue()) {
                    $rules[$orgNumber][$handle.'.*'] = $fieldType->elementValidationRules($config);
                }
            }
        }
        foreach ([1 => 1, 2 => 1, 3 => 2, 4 => 2, 5 => 3] as $siteNumber => $orgNumber) {
            $context->forget()->setOrg($orgs[$orgNumber]);
            $sites[$siteNumber] = Site::create(['handle' => 'site-'.$siteNumber, 'slug' => 'scale-site-'.$siteNumber, 'name' => 'Synthetic site '.$siteNumber, 'locale' => 'en']);
        }

        // A bulk fixture load is not an audited write benchmark. Every field value still meets its actual rules.
        $batch = [];
        $insertMs = $validationMs = 0.0;
        $historyCounts = $populationCounts = [];
        foreach ($corpus->rows() as $row) {
            $start = hrtime(true);
            $scalarRules = array_filter($rules[$row['org']], fn (string $key): bool => $key !== 'links' && $key !== 'links.*', ARRAY_FILTER_USE_KEY);
            $errors = Validator::make($row['values'], $scalarRules)->errors()->all();
            if ($errors !== []) {
                throw new RuntimeException('The synthetic input failed actual field validation: '.json_encode($errors));
            }
            $validationMs += (hrtime(true) - $start) / 1e6;
            $batch[] = [
                'id' => $row['id'], 'org_id' => $orgs[$row['org']]->id,
                'site_id' => $row['site'] === null ? null : $sites[$row['site']]->id,
                'entry_type_id' => $types[$row['org']][$row['kind']]->id, 'type_handle' => $row['kind'],
                'title' => $row['title'], 'slug' => $row['slug'], 'status' => $row['status'],
                'published_at' => $row['status'] === 'published' ? $row['updated_at'] : null,
                'values' => json_encode($row['values'], JSON_THROW_ON_ERROR),
                'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'], 'deleted_at' => $row['deleted_at'],
            ];
            $historyCounts[$row['retained_revisions']] = ($historyCounts[$row['retained_revisions']] ?? 0) + 1;
            $populationCounts[$row['population']] = ($populationCounts[$row['population']] ?? 0) + 1;
            if (count($batch) === 100) {
                $start = hrtime(true);
                $db->table('entries')->insert($batch);
                $insertMs += (hrtime(true) - $start) / 1e6;
                $batch = [];
            }
        }
        $this->check($batch === [] && $db->table('entries')->count() === $rows, 'The entry count differs from the corpus.');
        $identity = $database->advanceEntryIdentity();

        // These real files/rows are bulk fixtures, deliberately separate from actual MediaLibrary writes below.
        $mediaRows = $mediaCount = $mediaBytes = 0;
        $mediaBatch = [];
        $mediaBuildStart = hrtime(true);
        if ($media !== null) {
            foreach ($corpus->rows() as $row) {
                if ($row['kind'] !== 'asset') {
                    continue;
                }
                $metadata = $media->write($row['id']);
                $mediaBatch[] = $metadata + ['created_at' => $row['created_at']];
                $mediaCount++;
                $mediaBytes += $metadata['size_bytes'];
                if (count($mediaBatch) === 100) {
                    $db->table('media_files')->insert($mediaBatch);
                    $mediaBatch = [];
                }
            }
            if ($mediaBatch !== []) {
                $db->table('media_files')->insert($mediaBatch);
            }
            $mediaRows = $db->table('media_files')->count();
            $this->check($mediaRows === $mediaCount, 'Media count differs.');
        }
        $mediaBuildMs = (hrtime(true) - $mediaBuildStart) / 1e6;

        $relations = $revisions = [];
        $historyMs = $relationMs = 0.0;
        $expectedRevisions = $expectedRelations = 0;
        foreach ($corpus->rows() as $row) {
            $context->forget()->setOrg($orgs[$row['org']]);
            if ($row['site'] !== null) {
                $context->setSite($sites[$row['site']]);
            }
            // Shared asset rows have no outgoing links; article links go only to live visible synthetic targets.
            $linkRules = array_intersect_key($rules[$row['org']], array_flip(['links', 'links.*']));
            $this->check(Validator::make(['links' => $row['relation_targets']], $linkRules)->errors()->all() === [], 'A synthetic relation fails actual validation.');
            foreach ($row['relation_targets'] as $ordering => $target) {
                $relations[] = ['org_id' => $orgs[$row['org']]->id, 'source_entry_id' => $row['id'], 'target_entry_id' => $target, 'field_storage_id' => $storage[$row['org']]['links']->id, 'ordering' => $ordering];
                $expectedRelations++;
            }
            if (count($relations) >= 100) {
                $start = hrtime(true);
                $db->table('entry_relations')->insert($relations);
                $relationMs += (hrtime(true) - $start) / 1e6;
                $relations = [];
            }
            for ($version = 0; $version < $row['retained_revisions']; $version++) {
                $last = $version === $row['retained_revisions'] - 1;
                $revisions[] = [
                    'entry_id' => $row['id'], 'entry_type_id' => $types[$row['org']][$row['kind']]->id,
                    'title' => $last ? $row['title'] : $row['title'].' version '.$version,
                    'slug' => $row['slug'], 'status' => $row['status'],
                    'published_at' => $row['status'] === 'published' ? $row['updated_at'] : null,
                    'values' => json_encode($row['values'], JSON_THROW_ON_ERROR),
                    'relation_state' => json_encode($row['relation_targets'] === [] ? [] : [(string) $storage[$row['org']]['links']->id => $row['relation_targets']], JSON_THROW_ON_ERROR),
                    'unsanitized_values' => null, 'author_id' => null,
                    'created_at' => gmdate('Y-m-d H:i:s', strtotime($row['updated_at'].' UTC') - ($row['retained_revisions'] - 1 - $version)),
                    'updated_at' => $row['updated_at'],
                ];
                $expectedRevisions++;
                if (count($revisions) === 100) {
                    $start = hrtime(true);
                    $db->table('entry_revisions')->insert($revisions);
                    $historyMs += (hrtime(true) - $start) / 1e6;
                    $revisions = [];
                }
            }
        }
        foreach (['entry_relations' => $relations, 'entry_revisions' => $revisions] as $table => $rest) {
            if ($rest !== []) {
                $start = hrtime(true);
                $db->table($table)->insert($rest);
                if ($table === 'entry_relations') {
                    $relationMs += (hrtime(true) - $start) / 1e6;
                } else {
                    $historyMs += (hrtime(true) - $start) / 1e6;
                }
            }
        }
        $this->check($db->table('entry_revisions')->count() === $expectedRevisions && $db->table('entry_relations')->count() === $expectedRelations, 'History/relation counts differ.');
        $database->foreignKeys();
        file_put_contents($out.'/seed.json', json_encode(['profile' => $profile, 'entries' => $rows, 'revisions' => $expectedRevisions, 'relations' => $expectedRelations, 'populations' => $populationCounts, 'history_distribution' => $historyCounts, 'all_field_and_element_rules_passed' => true, 'bulk_entries_ms' => $insertMs, 'bulk_revisions_ms' => $historyMs, 'bulk_relations_ms' => $relationMs, 'validation_ms' => $validationMs, 'physical_media_files' => $mediaRows, 'physical_media_bytes' => $mediaBytes, 'bulk_media_ms' => $mediaBuildMs, 'raw_fixture_import_not_model_write_throughput' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");

        $firstIds = [];
        $before = 0;
        foreach ($corpus->populations() as $population) {
            foreach ([1, 2, 11] as $offset) {
                $id = $before + $offset;
                $planned = $corpus->row($id);
                // Only this wholly owned synthetic database: independently compare imported state with model decoding.
                $entry = Entry::withoutGlobalScopes()->findOrFail($id);
                $latest = $entry->revisions()->orderByDesc('id')->firstOrFail();
                $this->check($entry->values == $planned['values'] && $entry->revisions()->count() === $planned['retained_revisions'] && $latest->values == $entry->values && $latest->relation_state == $entry->relationState(), 'Independent persisted-state control differs.');
                foreach (EntryRevision::SNAPSHOT_ATTRIBUTES as $attribute) {
                    $this->check($latest->getAttribute($attribute) == $entry->getAttribute($attribute), 'Snapshot attribute differs: '.$attribute);
                }
                if ($media !== null && $planned['kind'] === 'asset') {
                    $file = $db->table('media_files')->where('entry_id', $id)->first();
                    $absolute = $media->absolutePath($file->path);
                    $this->check(hash_file('sha256', $absolute) === $file->checksum && filesize($absolute) === (int) $file->size_bytes && getimagesize($absolute)[0] === 1, 'Media bytes differ from metadata.');
                }
            }
            if ($population['kind'] === 'article' && ! isset($firstIds[$population['site']])) {
                $firstIds[$population['site']] = $before + 1;
            }
            $before += $population['rows'];
        }

        $capturing = false;
        $overflow = false;
        $events = [];
        DB::listen(function (QueryExecuted $query) use (&$capturing, &$events, &$overflow): void {
            if ($capturing) {
                if (count($events) === 250) {
                    $overflow = true;
                } else {
                    $events[] = ['sql' => $query->sql, 'bindings' => $query->bindings, 'ms' => $query->time];
                }
            }
        });
        $measure = function (callable $run, int $samples = 25) use (&$capturing, &$events, &$overflow, $database): array {
            $run(); // Warm only; no cold-cache claim.
            $times = $counts = [];
            $queries = [];
            for ($i = 0; $i < $samples; $i++) {
                $events = [];
                $overflow = false;
                $capturing = true;
                $start = hrtime(true);
                try {
                    $value = $run();
                } finally {
                    $capturing = false;
                }
                $this->check(! $overflow, 'The query-capture bound was exceeded.');
                $times[] = (hrtime(true) - $start) / 1e6;
                $counts[] = count($events);
                foreach ($events as $event) {
                    $key = hash('sha256', $event['sql'].json_encode($event['bindings'], JSON_THROW_ON_ERROR));
                    if (! isset($queries[$key])) {
                        $queries[$key] = $event + ['plan' => $database->plan($event['sql'], $event['bindings'])];
                    }
                }
            }

            return ['warm_ms' => $times, 'query_counts' => $counts, 'result_count' => is_countable($value) ? count($value) : null, 'queries' => array_values($queries)];
        };
        $reads = [];
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $this->enterSite($sites[1]);
        foreach ([1 => 1, 3 => 2, 5 => 3] as $siteNumber => $orgNumber) {
            $this->enterSite($sites[$siteNumber]);
            $article = $types[$orgNumber]['article'];
            $asset = $types[$orgNumber]['asset'];
            app()->instance(EntryType::class, $article);
            $articleQuery = fn () => EntryResource::getEloquentQuery()->orderByDesc('updated_at')->orderByDesc('id');
            $reads[$siteNumber]['article_first10'] = $measure(fn () => $articleQuery()->limit(10)->get());
            $visibleCount = $articleQuery()->count();
            $reads[$siteNumber]['article_deep10'] = $measure(fn () => $articleQuery()->offset(max(0, $visibleCount - 10))->limit(10)->get());
            $reads[$siteNumber]['article_broad_search10'] = $measure(fn () => $articleQuery()->whereLike('title', '%Synthetic%', caseSensitive: false)->limit(10)->get());
            if ($panelMedia) {
                $selectiveTitle = $corpus->row($firstIds[$siteNumber])['title'];
                $reads[$siteNumber]['article_selective_search10'] = $measure(fn () => $articleQuery()->whereLike('title', '%'.$selectiveTitle.'%', caseSensitive: false)->limit(10)->get());
                $this->check($reads[$siteNumber]['article_selective_search10']['result_count'] > 0, 'Selective search unexpectedly misses.');
            }
            $column = $storage[$orgNumber]['score']->generatedColumnName();
            $reads[$siteNumber]['article_projected_range10'] = $measure(fn () => $articleQuery()->whereBetween($column, [25, 75])->limit(10)->get());
            $this->check($reads[$siteNumber]['article_projected_range10']['result_count'] === 10, 'Projected range unexpectedly misses.');
            app()->instance(EntryType::class, $asset);
            $reads[$siteNumber]['asset_first11_shared'] = $measure(fn () => EntryResource::getEloquentQuery()->orderByDesc('updated_at')->orderByDesc('id')->limit(11)->get());
            $targets = [$orgNumber === 3 ? 'article' : 'asset'];
            $picker = fn () => (new ReflectionMethod(FieldValueRenderer::class, 'relationScope'))->invoke(null, $targets);
            $reads[$siteNumber]['relation_scope_search50_without_permission_filter'] = $measure(fn () => $picker()->whereLike('title', '%Synthetic%', caseSensitive: false)->orderBy('title')->limit(50)->pluck('title', 'id'));
            $currentTypes = collect([$article, $asset]);
            $reads[$siteNumber]['dashboard_counts_query'] = $measure(fn () => EntryCountsWidget::countsByStatus($currentTypes));
            $reads[$siteNumber]['dashboard_recent_query10'] = $measure(fn () => RecentEntriesWidget::recentQuery($currentTypes)->get());
            $sampleEntry = Entry::findOrFail($firstIds[$siteNumber]);
            $reads[$siteNumber]['ordered_relation_hydration'] = $measure(fn () => $sampleEntry->linkedIdsForField($storage[$orgNumber]['links']));
            $this->check($sampleEntry->linkedIdsForField($storage[$orgNumber]['links']) === $corpus->row($firstIds[$siteNumber])['relation_targets'], 'Ordered relations differ.');
            $reads[$siteNumber]['revision_history50'] = $measure(fn () => $sampleEntry->revisions()->orderByDesc('id')->limit(50)->get());
            $this->check($reads[$siteNumber]['revision_history50']['result_count'] === 50, 'The long-history control is missing.');
            // The attacker's view must exclude both the sibling site and every other org, including shared rows.
            $this->check(! Entry::query()->where('org_id', '!=', $orgs[$orgNumber]->id)->exists(), 'Cross-org read admitted.');
            foreach ($sites as $otherNumber => $otherSite) {
                if ($otherNumber !== $siteNumber) {
                    $this->check(! Entry::query()->where('site_id', $otherSite->id)->exists(), 'Sibling or foreign-site read admitted.');
                }
            }
        }

        // Real author writes on the populated database, including conversion, audits, relations, revision/prune and commit.
        $this->enterSite($sites[1]);
        app()->instance(EntryType::class, $types[1]['article']);
        $writes = [];
        $created = [];
        $auditBefore = $db->table('audit_log')->count();
        for ($sample = 0; $sample < 50; $sample++) {
            $input = $corpus->row($sample + 1);
            $this->check(Validator::make($input['values'], array_filter($rules[1], fn (string $k): bool => ! str_starts_with($k, 'links'), ARRAY_FILTER_USE_KEY))->errors()->all() === [], 'A model-write input fails actual rules.');
            app(RecordedRevisions::class)->open();
            $start = hrtime(true);
            $entry = Entry::create(['entry_type_id' => $types[1]['article']->id, 'title' => 'Audited scale sample '.$sample, 'slug' => 'audited-scale-'.$sample, 'values' => $input['values']]);
            $this->check($entry->id > $rows, 'A real writer collided with an explicit fixture id.');
            $entry->writeRelationsAndReconcile(fn () => $entry->syncFieldRelations($storage[1]['links'], $corpus->row(1)['relation_targets']));
            $writes['create_and_relations_ms'][] = (hrtime(true) - $start) / 1e6;
            $this->check($db->transactionLevel() === 0 && $entry->revisions()->count() === 1, 'Create did not outer-commit one revision.');
            $this->check($entry->fresh()->values == $input['values'], 'Actual writer representation differs.');
            $created[] = $entry;
        }
        $entry = $created[0];
        for ($edit = 1; $edit <= 75; $edit++) {
            app(RecordedRevisions::class)->open((int) $entry->id);
            $start = hrtime(true);
            $entry->update(['title' => 'Audited scale edit '.$edit]);
            $writes[$edit < 50 ? 'update_before_cap_ms' : 'update_at_cap_ms'][] = (hrtime(true) - $start) / 1e6;
            $this->check($db->transactionLevel() === 0 && $entry->revisions()->count() === min(50, $edit + 1), 'Update commit or retention differs.');
        }
        $database->assertActiveConnection($db);
        $auditAfter = $db->table('audit_log')->count();
        $this->check($auditAfter - $auditBefore === 125, 'Actual title-write audit count differs.');
        $auditBeforeProvisioning = $auditAfter;
        if ($panelMedia) {
            // The JSON/field update path and actual upload path are measured separately from title-only edits/imports.
            foreach ([1, 2, 11] as $sourceId) {
                $input = $corpus->row($sourceId);
                $entry = $created[$sourceId - 1];
                $values = $input['values'];
                $values['score'] = 100 - $values['score'];
                $values['body'] = str_replace('Synthetic', 'Measured', $values['body']);
                $this->check(Validator::make($values, array_filter($rules[1], fn (string $k): bool => ! str_starts_with($k, 'links'), ARRAY_FILTER_USE_KEY))->errors()->all() === [], 'A field-update input fails actual rules.');
                app(RecordedRevisions::class)->open((int) $entry->id);
                $start = hrtime(true);
                $entry->update(['values' => $values]);
                $writes['field_json_update_ms'][] = (hrtime(true) - $start) / 1e6;
                $this->check($entry->fresh()->values == $values && $db->transactionLevel() === 0, 'Field write representation/commit differs.');
            }
            $source = $db->table('media_files')->value('path');
            for ($sample = 0; $sample < 25; $sample++) {
                $start = hrtime(true);
                $stored = MediaLibrary::store($media->absolutePath($source), 'synthetic.png', $types[1]['asset']);
                $writes['media_library_store_ms'][] = (hrtime(true) - $start) / 1e6;
                $this->check($stored->site_id === null && $db->transactionLevel() === 0, 'Actual media write scope/commit differs.');
            }
            $auditBeforeProvisioning = $db->table('audit_log')->count();
            // Real host users and audited roles in this wholly owned DB; credentials remain only in its private runtime.
            $credentials = [];
            foreach ([1, 2, 3] as $orgNumber) {
                $context->forget()->setOrg($orgs[$orgNumber]);
                $password = bin2hex(random_bytes(24));
                $email = 'scale-owner-'.$orgNumber.'@example.test';
                $user = User::provisionAccount($email, 'Synthetic scale owner '.$orgNumber, app('hash')->make($password));
                $user->admitToOrg($orgs[$orgNumber]);
                foreach ($sites as $site) {
                    if ($site->org_id === $orgs[$orgNumber]->id) {
                        $user->admitToSite($site);
                    }
                }
                $owner = Role::create(['handle' => 'scale-owner', 'name' => 'Synthetic scale owner', 'is_owner' => true]);
                $owner->assignTo($user->getKey());
                $this->check(Permissions::isOwner($user), 'The synthetic owner has no owner permission.');
                $credentials['owner-'.$orgNumber] = compact('email', 'password');
            }
            $context->forget()->setOrg($orgs[1])->setSite($sites[1]);
            $password = bin2hex(random_bytes(24));
            $email = 'scale-editor@example.test';
            $user = User::provisionAccount($email, 'Synthetic article-only editor', app('hash')->make($password));
            $user->admitToOrg($orgs[1]);
            $user->admitToSite($sites[1]);
            $editor = Role::create(['handle' => 'scale-editor', 'name' => 'Synthetic article editor']);
            foreach (['view', 'update'] as $action) {
                $editor->grant('entry.article.'.$action);
            }
            $editor->assignTo($user->getKey());
            $this->check(Permissions::allows($user, 'entry.article.view') && ! Permissions::allows($user, 'entry.asset.view'), 'Article-only permission control differs.');
            $credentials['editor'] = compact('email', 'password');
            file_put_contents($out.'/panel-credentials.json', json_encode($credentials, JSON_THROW_ON_ERROR));
            chmod($out.'/panel-credentials.json', 0600);
        }
        $foreignKeys = $database->foreignKeys();
        $facts = $database->facts();
        $storageFacts = $database->storage();
        $result = [
            'stage' => 'consistent-app-'.$database->driver().'-seed-and-query-baseline', 'profile' => $profile, 'rows' => $rows,
            'checks_passed' => $this->checks, 'dependency_graph' => $this->versions(), 'user_model_source' => (new \ReflectionClass(User::class))->getFileName(),
            'engine' => $database->driver(), 'version' => $facts['version'], 'entry_identity' => $identity,
            'php' => PHP_VERSION, 'php_memory_limit' => ini_get('memory_limit'), 'peak_php_bytes' => memory_get_peak_usage(true),
            'resource_limits_applied' => null, 'cache_condition' => 'warm local process and OS; no cold-cache claim',
            'resource_scope' => 'The application does not attest CPU or host-memory limits. See the paired launcher receipt when available.',
            'reads' => $reads, 'audited_writes' => $writes,
            'title_phase_audit_rows' => $auditAfter - $auditBefore,
            'field_and_media_phase_audit_rows' => $auditBeforeProvisioning - $auditAfter,
            'account_provisioning_audit_rows' => $db->table('audit_log')->count() - $auditBeforeProvisioning,
            'actual_audit_rows_created' => $db->table('audit_log')->count() - $auditBefore,
            'sqlite_pragmas' => $facts['sqlite_pragmas'] ?? null, 'database_session' => $facts,
            'foreign_key_control' => $foreignKeys, 'database_storage' => $storageFacts,
            'database_objects_bytes' => $storageFacts['objects'],
            'remaining' => ['all required driver/scale gates', 'full panel HTTP/browser requests and permission filtering', 'query/write statistics and uncertainty', 'resource-floor comparison', 'ADR findings and full qualification'],
        ];
        file_put_contents($out.'/result.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        $context->forget();

        return $result;
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
        $this->checks++;
    }

    /** @return array<string, EntryType> */
    public static function entryTypes(Org $org): array
    {
        if (app(Context::class)->orgId() !== $org->getKey()) {
            throw new RuntimeException('The fixture org must be current before its types are created.');
        }
        $types = [];
        foreach (['article', 'asset'] as $kind) {
            // EntryType supports global rows; context alone does NOT make one org-owned. A URL resolves by handle.
            $types[$kind] = EntryType::create(['org_id' => $org->getKey(), 'handle' => $kind, 'name' => ucfirst($kind), 'plural_name' => ucfirst($kind).'s', 'is_media' => $kind === 'asset']);
        }

        return $types;
    }

    private function enterSite(Site $site): void
    {
        Filament::setTenant($site, isQuiet: true);
        app(Context::class)->setOrg($site->org)->setSite($site);
        Once::flush();
    }

    private function versions(): array
    {
        $versions = [];
        foreach (['laravel/framework', 'filament/filament', 'livewire/livewire', 'kitsune/core', 'kitsune/person'] as $package) {
            $versions[$package] = InstalledVersions::getPrettyVersion($package);
        }

        return $versions;
    }
}
