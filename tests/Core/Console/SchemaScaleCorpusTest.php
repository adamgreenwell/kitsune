<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Kitsune\Benchmarks\SchemaScaleCorpus;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

require_once dirname(__DIR__, 3).'/bin/benchmarks/SchemaScaleCorpus.php';

it('keeps the three scales comparable without losing smaller populations', function (): void {
    foreach ([10_000, 100_000, 1_000_000] as $size) {
        $corpus = new SchemaScaleCorpus($size);
        $populations = $corpus->populations();
        expect(array_sum(array_column($populations, 'rows')))->toBe($size)
            ->and(array_unique(array_column($populations, 'org')))->toHaveCount(3)
            ->and(array_values(array_unique(array_filter(array_column($populations, 'site')))))->toHaveCount(5);
        $shared = array_filter($populations, fn (array $p): bool => $p['site'] === null);
        expect(array_column($shared, 'kind'))->each->toBe('asset')
            ->and(array_sum(array_column($shared, 'rows')))->toBe((int) ($size / 5));
    }
});

it('supplies live panel read targets in each Site at the three scales', function (): void {
    foreach ([10_000, 100_000, 1_000_000] as $size) {
        $corpus = new SchemaScaleCorpus($size);
        $targets = $corpus->panelReadTargets();
        expect(array_column($targets, 'site'))->toBe([1, 2, 3, 4, 5])
            ->and(array_column($targets, 'owner'))->toBe([1, 1, 2, 2, 3]);

        $media = $corpus->panelMediaTargets();
        foreach (['shared' => 1, 'other_org' => 2] as $key => $org) {
            $row = $corpus->row($media[$key]);
            expect($row['org'])->toBe($org)
                ->and($row['kind'])->toBe('asset')
                ->and($row['site'])->toBeNull()
                ->and($row['deleted_at'])->toBeNull();
        }

        // Another title cannot extend a search ID with more digits at these corpus sizes.
        expect($media['shared'] * 10)->toBeGreaterThan($size)
            ->and($targets[0]['selective_article'] * 10)->toBeGreaterThan($size)
            ->and($targets[2]['selective_article'] * 10)->toBeGreaterThan($size);

        $siteOnly = $corpus->row($media['site_only']);
        expect($siteOnly['org'])->toBe(1)
            ->and($siteOnly['kind'])->toBe('asset')
            ->and($siteOnly['site'])->toBe(1)
            ->and($siteOnly['deleted_at'])->toBeNull();

        foreach ($targets as $target) {
            foreach ([$target['article'], $target['selective_article']] as $id) {
                $row = $corpus->row($id);
                expect($row['site'])->toBe($target['site'])
                    ->and($row['org'])->toBe($target['owner'])
                    ->and($row['kind'])->toBe('article')
                    ->and($row['deleted_at'])->toBeNull();
            }
        }
    }
});

it('streams an exact corpus with distinct ids and both scope boundaries', function (): void {
    $corpus = new SchemaScaleCorpus(10_000);
    $ids = $visible = [];
    $iterator = $corpus->rows();
    expect($iterator)->toBeInstanceOf(Generator::class);
    foreach ($iterator as $row) {
        expect(isset($ids[$row['id']]))->toBeFalse();
        $ids[$row['id']] = true;
        if ($row['org'] === 1 && ($row['site'] === 1 || $row['site'] === null)) {
            $visible[] = $row;
        }
    }
    expect(array_keys($ids))->toBe(range(1, 10_000))
        ->and($visible)->toHaveCount(5000)
        ->and(array_unique(array_column($visible, 'org')))->toBe([1])
        ->and(array_filter($visible, fn (array $r): bool => $r['site'] === 2))->toBe([]);
});

it('has real bounded histories and all body sizes in live records', function (): void {
    $corpus = new SchemaScaleCorpus(10_000);
    $retained = $sizes = [];
    $largeLiveLongHistories = 0;
    foreach ($corpus->rows() as $row) {
        $retained[$row['retained_revisions']] = true;
        if ($row['deleted_at'] === null) {
            $sizes[strlen($row['values']['body'])] = true;
            if (strlen($row['values']['body']) === 32768 && $row['retained_revisions'] === 50) {
                $largeLiveLongHistories++;
            }
        }
        expect($row['retained_revisions'])->toBe(min(50, $row['authored_edits'] + 1));
    }
    $retained = array_keys($retained);
    $sizes = array_keys($sizes);
    sort($retained);
    sort($sizes);
    expect($retained)->toBe([1, 4, 11, 50])->and($sizes)->toBe([768, 4096, 32768])
        ->and($largeLiveLongHistories)->toBeGreaterThanOrEqual(9);
});

it('reproduces the same valid values independently of iteration order', function (): void {
    $first = new SchemaScaleCorpus(10_000);
    $second = new SchemaScaleCorpus(10_000);
    foreach ([1, 100, 4999, 5000, 9999, 10_000] as $id) {
        $row = $first->row($id);
        expect($second->row($id))->toBe($row)
            ->and($row['values']['score'])->toBeGreaterThanOrEqual(0)
            ->and($row['values']['score'])->toBeLessThanOrEqual(100)
            ->and($row['values']['category'])->toBeIn(['news', 'guide', 'opinion', 'feature'])
            ->and($row['values']['tags'])->toHaveCount(2);
        if ($row['site'] === null) {
            expect($row['slug'])->toBeNull();
        } else {
            expect($row['slug'])->toStartWith('scale-');
        }
    }
});

it('refuses a different scale and out of range ids rather than changing the workload', function (): void {
    expect(fn () => new SchemaScaleCorpus(9999))->toThrow(InvalidArgumentException::class);
    $corpus = new SchemaScaleCorpus(10_000);
    expect(fn () => $corpus->row(0))->toThrow(OutOfBoundsException::class)
        ->and(fn () => $corpus->row(10_001))->toThrow(OutOfBoundsException::class);
});

it('uses values the actual configured scalar and element rules admit', function (): void {
    $rules = [];
    foreach ([
        ['body', 'rich_text', 1, []],
        ['score', 'number', 1, ['min' => 0, 'max' => 100]],
        ['category', 'select', 1, ['options' => ['news' => 'News', 'guide' => 'Guide', 'opinion' => 'Opinion', 'feature' => 'Feature']]],
        ['tags', 'text', 4, ['maxLength' => 32]],
    ] as [$handle, $type, $cardinality, $settings]) {
        $config = new FieldConfig(new FieldStorage(compact('handle', 'type', 'cardinality', 'settings')));
        $field = app(FieldTypeRegistry::class)->get($type);
        $rules[$handle] = $field->validationRules($config);
        if ($config->isMultiValue()) {
            $rules[$handle.'.*'] = $field->elementValidationRules($config);
        }
    }
    $corpus = new SchemaScaleCorpus(10_000);
    foreach ([1, 2, 10, 11, 100, 101, 4502, 8502, 9502, 10_000] as $id) {
        expect(Validator::make($corpus->row($id)['values'], $rules)->errors()->all())->toBe([]);
    }
    $invalid = $corpus->row(2)['values'];
    $invalid['score'] = 101;
    $invalid['category'] = 'unknown';
    $invalid['tags'] = [str_repeat('x', 33)];
    expect(Validator::make($invalid, $rules)->errors()->keys())->toContain('score', 'category', 'tags.0');
});

it('points ordered relations only at live visible records, including the empty-media control', function (): void {
    $corpus = new SchemaScaleCorpus(10_000);
    $linkedOrgs = [];
    foreach ($corpus->rows() as $row) {
        foreach ($row['relation_targets'] as $target) {
            $other = $corpus->row($target);
            expect($other['org'])->toBe($row['org'])
                ->and($other['deleted_at'])->toBeNull()
                ->and($other['site'] === null || $other['site'] === $row['site'])->toBeTrue();
            $linkedOrgs[$row['org']] = true;
        }
    }
    expect(array_keys($linkedOrgs))->toBe([1, 2, 3]);
});

it('exercises a projected range hit on every measured site at the smallest scale', function (): void {
    $hits = [1 => 0, 3 => 0, 5 => 0];
    foreach ((new SchemaScaleCorpus(10_000))->rows() as $row) {
        if ($row['kind'] === 'article' && isset($hits[$row['site']]) && $row['deleted_at'] === null
            && $row['values']['score'] >= 25 && $row['values']['score'] <= 75) {
            $hits[$row['site']]++;
        }
    }
    expect($hits)->each->toBeGreaterThanOrEqual(10);
});

it('bulk fixture values agree with what the actual audited writer stores', function (): void {
    $context = app(Context::class);
    $org = Org::create(['slug' => 'scale-control', 'name' => 'Synthetic scale control']);
    $context->setOrg($org);
    $site = Site::create(['handle' => 'control', 'slug' => 'scale-control', 'name' => 'Synthetic control']);
    $context->setSite($site);
    $type = EntryType::create(['handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
    foreach ([['body', 'rich_text', 1, []], ['score', 'number', 1, ['min' => 0, 'max' => 100]], ['category', 'select', 1, ['options' => ['news' => 'News', 'guide' => 'Guide', 'opinion' => 'Opinion', 'feature' => 'Feature']]], ['tags', 'text', 4, ['maxLength' => 32]]] as [$handle, $fieldType, $cardinality, $settings]) {
        $storage = FieldStorage::create(['handle' => $handle, 'type' => $fieldType, 'cardinality' => $cardinality, 'settings' => $settings, 'pii_class' => 'none']);
        Field::create(['entry_type_id' => $type->id, 'field_storage_id' => $storage->id, 'label' => $handle]);
    }
    try {
        foreach ([1, 2, 11] as $id) {
            $values = (new SchemaScaleCorpus(10_000))->row($id)['values'];
            $entry = Entry::create(['entry_type_id' => $type->id, 'title' => 'Synthetic control '.$id, 'values' => $values]);
            expect($entry->fresh()->values)->toEqual($values);
        }
    } finally {
        $context->forget();
    }
});
