<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Imports\ImportJournal;
use Kitsune\Core\Imports\ImportPlan;
use Kitsune\Core\Imports\StructuredDraftInput;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\ImportActionReceipt;
use Kitsune\Core\Models\ImportExecution;
use Kitsune\Core\Models\ImportMapping;
use Kitsune\Core\Models\ImportSource;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

beforeEach(function (): void {
    $this->context = app(Context::class);
    $this->journal = app(ImportJournal::class);
    $home = Org::create(['name' => 'Home', 'slug' => 'home']);
    $other = Org::create(['name' => 'Other', 'slug' => 'other']);

    foreach (['home' => $home, 'sibling' => $home, 'foreign' => $other] as $name => $org) {
        $this->context->setOrg($org);
        $this->{$name} = Site::create([
            'handle' => $name,
            'slug' => $name,
            'name' => ucfirst($name),
            'locale' => 'en',
        ]);
    }

    $this->context->setSite($this->home);
    $this->type = EntryType::create(['handle' => 'catalogue', 'name' => 'Catalogue', 'plural_name' => 'Catalogues']);
});

afterEach(fn () => app(Context::class)->forget());

it('keeps a source identity across approved plans and reuses the exact execution on retry', function (): void {
    $source = $this->journal->source('mactacular:catalogue');
    $first = $this->journal->recordApproval($source->getKey(), hash('sha256', 'first approved plan'));
    $second = $this->journal->recordApproval($source->getKey(), hash('sha256', 'changed approved plan'));

    $freshJournal = new ImportJournal($this->context);
    expect($freshJournal->source('mactacular:catalogue')->is($source))->toBeTrue()
        ->and($first->is($second))->toBeFalse()
        ->and($freshJournal->recordApproval($source->getKey(), hash('sha256', 'first approved plan'))->is($first))->toBeTrue()
        ->and(ImportSource::count())->toBe(1)
        ->and(ImportExecution::count())->toBe(2);
});

it('allows independent imports across Sites and conceals the original scope', function (string $otherSite): void {
    $source = $this->journal->source('catalogue');
    $plan = hash('sha256', 'same plan bytes');
    $execution = $this->journal->recordApproval($source->getKey(), $plan);
    $this->context->setSite($this->{$otherSite});

    expect(ImportSource::find($source->getKey()))->toBeNull()
        ->and(ImportExecution::find($execution->getKey()))->toBeNull()
        ->and(fn () => $this->journal->recordApproval($source->getKey(), $plan))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $this->journal->writeDraft(
            $execution->id, 'article', '42', $this->type->id, ['title' => 'Draft', 'slug' => 'draft'], null
        ))->toThrow(ModelNotFoundException::class);

    $other = $this->journal->source('catalogue');
    expect($other->is($source))->toBeFalse()
        ->and($this->journal->recordApproval($other->getKey(), $plan)->is($execution))->toBeFalse();

    expect(fn () => DB::transaction(fn () => DB::table('import_executions')->insert([
        'org_id' => $this->context->orgId(),
        'site_id' => $this->context->siteId(),
        'import_source_id' => $source->getKey(),
        'plan_digest' => $plan,
    ])))->toThrow(QueryException::class);

    expect(ImportExecution::count())->toBe(1);
})->with(['sibling', 'foreign']);

it('requires a destination context and portable canonical identifiers', function (): void {
    $source = $this->journal->source('valid-key');
    expect(fn () => $this->journal->source('UPPERCASE'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->journal->recordApproval($source->getKey(), 'short'))->toThrow(InvalidArgumentException::class);

    $org = $this->context->org();
    $this->context->setSite(null)->setOrg($org);

    expect(fn () => $this->journal->source('valid-key'))->toThrow(LogicException::class)
        ->and(fn () => $this->journal->recordApproval($source->getKey(), str_repeat('a', 64)))->toThrow(LogicException::class)
        ->and(ImportSource::count())->toBe(0)
        ->and(ImportExecution::count())->toBe(0);
});

it('rejects a source when its Site does not belong to the recorded Org', function (): void {
    expect(fn () => DB::transaction(fn () => DB::table('import_sources')->insert([
        'org_id' => $this->foreign->org_id,
        'site_id' => $this->home->getKey(),
        'source_key' => 'misplaced',
    ])))->toThrow(QueryException::class);

    expect(ImportSource::count())->toBe(0);
});

it('retries an approved write in place and updates the same draft under a changed approval', function (): void {
    $source = $this->journal->source('catalogue');
    $first = $this->journal->recordApproval($source->getKey(), hash('sha256', 'first'));
    $second = $this->journal->recordApproval($source->getKey(), hash('sha256', 'second'));
    $draft = ['title' => 'First', 'slug' => 'first'];

    $entry = $this->journal->writeDraft($first->id, 'article', '42', $this->type->id, $draft, null);
    $differentType = $this->journal->writeDraft(
        $first->id, 'review', '42', $this->type->id, ['title' => 'Review', 'slug' => 'review'], null
    );
    $revisionId = $entry->revisions()->latest('id')->firstOrFail()->getKey();

    expect($differentType->is($entry))->toBeFalse()
        ->and($this->journal->writeDraft($first->id, 'article', '42', $this->type->id, $draft, null)->is($entry))->toBeTrue()
        ->and($entry->revisions()->count())->toBe(1)
        ->and(ImportMapping::count())->toBe(2);

    $changed = ['title' => 'Revised', 'slug' => 'first'];
    $updated = $this->journal->writeDraft($second->id, 'article', '42', $this->type->id, $changed, $revisionId);
    expect($updated->is($entry))->toBeTrue()
        ->and($updated->title)->toBe('Revised')
        ->and($updated->revisions()->count())->toBe(2)
        ->and(ImportMapping::count())->toBe(2);

    expect(fn () => $this->journal->writeDraft($first->id, 'article', '42', $this->type->id, $draft, null))
        ->toThrow(RuntimeException::class, 'approved destination revision is stale');
});

it('holds a moved-on destination and rolls back refused mapping writes', function (): void {
    $source = $this->journal->source('catalogue');
    $first = $this->journal->recordApproval($source->getKey(), hash('sha256', 'first'));
    $draft = ['title' => 'First', 'slug' => 'first'];

    $refuse = true;
    ImportMapping::creating(static function () use (&$refuse): ?bool {
        return $refuse ? false : null;
    });
    expect(fn () => $this->journal->writeDraft($first->id, 'article', '42', $this->type->id, $draft, null))
        ->toThrow(RuntimeException::class, 'mapping write was refused')
        ->and(Entry::count())->toBe(0)
        ->and(ImportMapping::count())->toBe(0);

    $refuse = false;
    $entry = $this->journal->writeDraft($first->id, 'article', '42', $this->type->id, $draft, null);
    $revisionId = $entry->revisions()->latest('id')->firstOrFail()->getKey();

    $entry->update(['title' => 'Staff edit']);
    $second = $this->journal->recordApproval($source->getKey(), hash('sha256', 'second'));
    expect(fn () => $this->journal->writeDraft($second->id, 'article', '42', $this->type->id, $draft, $revisionId))
        ->toThrow(RuntimeException::class, 'destination draft changed')
        ->and($entry->fresh()->title)->toBe('Staff edit');

    $entry->delete();
    expect(fn () => $this->journal->writeDraft($first->id, 'article', '42', $this->type->id, $draft, null))
        ->toThrow(RuntimeException::class, 'available destination draft')
        ->and(ImportMapping::count())->toBe(1)
        ->and(Entry::withTrashed()->count())->toBe(1);
});

/** @param list<array<string, mixed>> $records */
function draftImportReport(Site $site, array $records, bool $complete = true, string $adapter = 'json'): string
{
    return json_encode([
        'version' => 1,
        'org_id' => $site->org_id,
        'site_id' => $site->id,
        'source_key' => 'catalogue',
        'adapter' => $adapter,
        'snapshot_sha256' => hash('sha256', 'fixture source snapshot'),
        'complete' => $complete,
        'records' => $records,
        'findings' => $complete ? [] : [['position' => 0, 'code' => 'source-incomplete']],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

it('requires the exact complete report and destination before opening an import execution', function (): void {
    $record = [
        'source_type' => 'article',
        'source_id' => '42',
        'entry_type_id' => $this->type->id,
        'title' => 'Draft',
        'slug' => 'draft',
        'expected_revision_id' => null,
    ];
    $report = draftImportReport($this->home, [$record]);
    $digest = ImportPlan::restore($report)->digest();
    $changed = draftImportReport($this->home, [array_replace($record, ['title' => 'Other'])]);
    $incomplete = draftImportReport($this->home, [$record], false);
    $malformed = draftImportReport($this->home, [$record, ['source_type' => 'article']]);
    $duplicate = draftImportReport($this->home, [$record, $record]);

    expect(fn () => $this->journal->apply($changed, $digest))->toThrow(DomainException::class, 'differs from the approved')
        ->and(fn () => $this->journal->apply($incomplete, ImportPlan::restore($incomplete)->digest()))
        ->toThrow(DomainException::class, 'incomplete report')
        ->and(fn () => $this->journal->apply($malformed, 'invalid'))->toThrow(InvalidArgumentException::class, 'invalid draft action')
        ->and(fn () => $this->journal->apply($duplicate, 'invalid'))->toThrow(InvalidArgumentException::class, 'repeats a source record')
        ->and(fn () => ImportPlan::restore($report."\n"))->toThrow(InvalidArgumentException::class, 'canonical byte format');

    foreach ([$this->sibling, $this->foreign] as $site) {
        $this->context->setSite($site);
        expect(fn () => $this->journal->apply($report, $digest))->toThrow(DomainException::class, 'different Org or Site')
            ->and(Entry::count())->toBe(0)
            ->and(ImportExecution::count())->toBe(0);
    }

    $this->context->setSite($this->home);
    expect(Entry::count())->toBe(0)
        ->and(ImportSource::count())->toBe(0)
        ->and(ImportExecution::count())->toBe(0)
        ->and(ImportMapping::count())->toBe(0);

    [$id] = $this->journal->apply($report, $digest);
    $entry = Entry::findOrFail($id);
    expect($entry->title)->toBe('Draft')
        ->and($this->journal->apply($report, $digest))->toBe([$id])
        ->and(ImportExecution::count())->toBe(1)
        ->and(ImportMapping::count())->toBe(1)
        ->and($entry->revisions()->count())->toBe(1);
});

it('checks persisted draft state and payload even when the revision or execution ID still matches', function (): void {
    $source = $this->journal->source('catalogue');
    $run = $this->journal->recordApproval($source->getKey(), hash('sha256', 'approved'));
    $draft = ['title' => 'First', 'slug' => 'first'];
    $entry = $this->journal->writeDraft($run->id, 'article', '42', $this->type->id, $draft, null);

    expect(fn () => $this->journal->writeDraft(
        $run->id, 'article', '42', $this->type->id, ['title' => 'Changed retry', 'slug' => 'first'], null
    ))->toThrow(RuntimeException::class, 'retried action differs');

    $revisions = $entry->revisions()->count();
    $entry->withoutRevisions(fn (Entry $draft) => $draft->update(['title' => 'Maintenance edit']));

    expect($entry->revisions()->count())->toBe($revisions)
        ->and(fn () => $this->journal->writeDraft($run->id, 'article', '42', $this->type->id, $draft, null))
        ->toThrow(RuntimeException::class, 'destination draft changed')
        ->and($entry->fresh()->title)->toBe('Maintenance edit');
});

it('requires a mapping and its execution to name the same source', function (): void {
    $source = $this->journal->source('catalogue');
    $other = $this->journal->source('archive');
    $run = $this->journal->recordApproval($other->getKey(), hash('sha256', 'other source'));
    $fingerprint = str_repeat('a', 64);

    expect(fn () => DB::transaction(fn () => DB::table('migration_map')->insert([
        'org_id' => $this->home->org_id,
        'site_id' => $this->home->getKey(),
        'import_source_id' => $source->getKey(),
        'source_type' => 'article',
        'source_id_hash' => $fingerprint,
        'entry_id' => 1,
        'entry_revision_id' => 1,
        'entry_state_hash' => $fingerprint,
        'action_hash' => $fingerprint,
        'last_execution_id' => $run->id,
    ])))->toThrow(QueryException::class);

    expect(ImportMapping::count())->toBe(0);
});

it('previews all draft conflicts using only database reads', function (): void {
    Entry::create([
        'entry_type_id' => $this->type->id,
        'title' => 'Retained draft',
        'slug' => 'occupied',
        'status' => 'draft',
    ])->delete();
    $draft = [
        'source_type' => 'article',
        'source_id' => '42',
        'entry_type_id' => $this->type->id,
        'title' => 'Draft',
        'slug' => 'draft',
    ];
    $drafts = [
        $draft,
        array_replace($draft, ['source_id' => '43', 'slug' => 'occupied']),
        array_replace($draft, ['source_id' => '44']),
        array_replace($draft, ['slug' => 'new-slug']),
        array_replace($draft, ['source_id' => '45', 'slug' => 'wrong-type', 'entry_type_id' => 99999]),
        ['source_type' => 'bad'],
    ];

    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $plan = $this->journal->preview('catalogue', 'csv', hash('sha256', 'snapshot'), $drafts, false);
        $queries = array_column(DB::getQueryLog(), 'query');
    } finally {
        DB::disableQueryLog();
    }

    expect($queries)->not->toBe([])
        ->and(array_all($queries, static fn (string $sql): bool => str_starts_with(strtolower(ltrim($sql)), 'select ')))->toBeTrue()
        ->and($plan->complete)->toBeFalse()
        ->and($plan->findings)->toBe([
            ['position' => 0, 'code' => 'source-incomplete'],
            ['position' => 2, 'code' => 'destination-slug-in-use'],
            ['position' => 3, 'code' => 'repeated-destination-slug'],
            ['position' => 4, 'code' => 'repeated-source-identity'],
            ['position' => 5, 'code' => 'unavailable-entry-type'],
            ['position' => 6, 'code' => 'invalid-record'],
        ])
        ->and($plan->records)->toBe([$draft + ['expected_revision_id' => null]])
        ->and(fn () => $this->journal->apply($plan->report(), $plan->digest()))->toThrow(DomainException::class, 'incomplete report')
        ->and(ImportSource::count())->toBe(0)
        ->and(ImportExecution::count())->toBe(0)
        ->and(ImportMapping::count())->toBe(0)
        ->and(Entry::withTrashed()->count())->toBe(1);
});

it('previews and applies a changed source to the original mapped draft', function (): void {
    $draft = [
        'source_type' => 'article',
        'source_id' => '42',
        'entry_type_id' => $this->type->id,
        'title' => 'Draft',
        'slug' => 'draft',
    ];
    $before = $this->journal->preview('catalogue', 'csv', hash('sha256', 'first'), [$draft], true);
    expect($before->complete)->toBeTrue()
        ->and(ImportSource::count())->toBe(0);

    [$id] = $this->journal->apply($before->report(), $before->digest());
    $entry = Entry::findOrFail($id);
    $revisionId = $entry->revisions()->latest('id')->firstOrFail()->getKey();
    $changed = array_replace($draft, ['title' => 'Revised']);
    $next = $this->journal->preview('catalogue', 'json', hash('sha256', 'second'), [$changed], true);

    expect($next->complete)->toBeTrue()
        ->and($next->records)->toBe([$changed + ['expected_revision_id' => $revisionId]])
        ->and($this->journal->apply($next->report(), $next->digest()))->toBe([$id])
        ->and($entry->fresh()->title)->toBe('Revised')
        ->and(ImportMapping::count())->toBe(1);

    $entry->refresh()->update(['title' => 'Staff edit']);
    $hold = $this->journal->preview('catalogue', 'json', hash('sha256', 'third'), [$changed], true);
    expect($hold->findings)->toBe([['position' => 1, 'code' => 'mapped-draft-changed']])
        ->and($hold->complete)->toBeFalse();
});

it('routes actual CSV and JSON bytes through the same scoped source mapping', function (): void {
    $input = app(StructuredDraftInput::class);
    $types = ['catalogue' => $this->type->id];
    $csv = "source_type,source_id,entry_type,title,slug\r\narticle,0042,catalogue,\"Notebook, 13\"\"\",notebook\r\n";
    $plan = $input->preview('csv', 'catalogue', $csv, $types, true);

    expect($plan->complete)->toBeTrue()
        ->and($plan->records[0]['source_id'])->toBe('0042')
        ->and($plan->records[0]['title'])->toBe('Notebook, 13"')
        ->and(json_decode($plan->report(), true)['snapshot_sha256'])->toBe(hash('sha256', $csv))
        ->and(ImportSource::count())->toBe(0);

    [$id] = $this->journal->apply($plan->report(), $plan->digest());
    $json = <<<'JSON'
[
    {"source_type":"article","source_id":"0042","entry_type":"catalogue","title":"Updated notebook","slug":"notebook"}
]
JSON;
    $next = $input->preview('json', 'catalogue', $json, $types, true);

    expect($next->complete)->toBeTrue()
        ->and(json_decode($next->report(), true)['snapshot_sha256'])->toBe(hash('sha256', $json))
        ->and($next->records[0]['expected_revision_id'])->not->toBeNull()
        ->and(fn () => $this->journal->apply($next->report(), $plan->digest()))->toThrow(DomainException::class, 'differs from the approved')
        ->and($this->journal->apply($next->report(), $next->digest()))->toBe([$id])
        ->and(Entry::findOrFail($id)->title)->toBe('Updated notebook')
        ->and(ImportMapping::count())->toBe(1)
        ->and(ImportExecution::count())->toBe(2);
});

it('holds malformed CSV rows and preserves JSON IDs only when their source supplies strings', function (): void {
    $input = app(StructuredDraftInput::class);
    $types = ['catalogue' => $this->type->id];
    $csv = "source_type,source_id,entry_type,title,slug\narticle,good,catalogue,First,first\narticle,bad,catalogue,\"Second\"oops,second\n";
    $plan = $input->preview('csv', 'catalogue', $csv, $types, true);
    $truncated = "source_type,source_id,entry_type,title,slug\narticle,bad,catalogue,\"Still open,broken";
    $truncatedPlan = $input->preview('csv', 'catalogue', $truncated, $types, true);
    $jsonPlan = $input->preview(
        'json',
        'catalogue',
        '[{"source_type":"article","source_id":42,"entry_type":"catalogue","title":"Numeric ID","slug":"numeric"}]',
        $types,
        true
    );

    expect($plan->findings)->toBe([['position' => 2, 'code' => 'invalid-record']])
        ->and($plan->records[0]['source_id'])->toBe('good')
        ->and($truncatedPlan->findings)->toBe([['position' => 1, 'code' => 'invalid-record']])
        ->and($jsonPlan->findings)->toBe([['position' => 1, 'code' => 'invalid-record']])
        ->and(fn () => $input->preview('json', 'catalogue', '{"incomplete":', $types, true))
        ->toThrow(InvalidArgumentException::class, 'invalid JSON')
        ->and(fn () => $this->journal->apply($plan->report(), $plan->digest()))
        ->toThrow(DomainException::class, 'incomplete report')
        ->and(ImportSource::count())->toBe(0)
        ->and(Entry::count())->toBe(0);
});

it('recovers a partly applied plan from its durable action receipts', function (): void {
    $base = [
        'source_type' => 'article',
        'source_id' => 'first',
        'entry_type_id' => $this->type->id,
        'title' => 'First',
        'slug' => 'first',
    ];
    $second = array_replace($base, ['source_id' => 'second', 'title' => 'Second', 'slug' => 'second']);
    $plan = $this->journal->preview('catalogue', 'json', hash('sha256', 'first snapshot'), [$base, $second], true);

    $interrupt = true;
    ImportActionReceipt::creating(static function (ImportActionReceipt $receipt) use (&$interrupt): ?bool {
        return $interrupt && $receipt->position === 2 ? false : null;
    });

    expect(fn () => $this->journal->apply($plan->report(), $plan->digest()))
        ->toThrow(RuntimeException::class, 'receipt was refused')
        ->and(Entry::count())->toBe(1)
        ->and(ImportMapping::count())->toBe(1)
        ->and(ImportActionReceipt::count())->toBe(1);

    $nextJournal = new ImportJournal($this->context);
    $progress = $nextJournal->progress($plan->report(), $plan->digest());
    $firstId = Entry::query()->firstOrFail()->getKey();
    expect(array_column($progress, 'outcome'))->toBe(['created', 'pending'])
        ->and(array_column($progress, 'entry_id'))->toBe([$firstId, null])
        ->and(array_column($progress, 'source_id'))->toBe(['first', 'second']);

    $interrupt = false;
    $entries = $nextJournal->apply($plan->report(), $plan->digest());
    $completed = $nextJournal->progress($plan->report(), $plan->digest());
    expect($entries[0])->toBe($firstId)
        ->and(array_column($completed, 'outcome'))->toBe(['created', 'created'])
        ->and(array_column($completed, 'entry_id'))->toBe($entries)
        ->and(ImportActionReceipt::count())->toBe(2);

    $changed = array_replace($base, ['title' => 'Updated first']);
    $later = $nextJournal->preview('catalogue', 'csv', hash('sha256', 'changed snapshot'), [$changed], true);
    expect($nextJournal->apply($later->report(), $later->digest()))->toBe([$firstId])
        ->and(array_column($nextJournal->progress($later->report(), $later->digest()), 'outcome'))->toBe(['updated'])
        ->and($nextJournal->progress($plan->report(), $plan->digest()))->toBe($completed)
        ->and($nextJournal->apply($plan->report(), $plan->digest()))->toBe($entries)
        ->and(Entry::findOrFail($firstId)->title)->toBe('Updated first')
        ->and(ImportActionReceipt::count())->toBe(3);

    $this->context->setSite($this->sibling);
    expect(fn () => $nextJournal->progress($plan->report(), $plan->digest()))
        ->toThrow(DomainException::class, 'different Org or Site')
        ->and(ImportActionReceipt::count())->toBe(0);
});
