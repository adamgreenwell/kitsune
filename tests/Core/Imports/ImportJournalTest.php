<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Console\ImportDraftsCommand;
use Kitsune\Core\Imports\ImportJournal;
use Kitsune\Core\Imports\ImportPlan;
use Kitsune\Core\Imports\ImportSchema;
use Kitsune\Core\Imports\StructuredDraftInput;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\ImportActionReceipt;
use Kitsune\Core\Models\ImportExecution;
use Kitsune\Core\Models\ImportMapping;
use Kitsune\Core\Models\ImportSource;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Symfony\Component\Console\Output\BufferedOutput;

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

function journalFixtureDraft(EntryType $type, string $slug = 'first', string $sourceType = 'article', string $sourceId = '42'): array
{
    return ['source_type' => $sourceType, 'source_id' => $sourceId, 'entry_type_id' => $type->id, 'title' => ucfirst($slug), 'slug' => $slug];
}

it('keeps a source identity across approved plans and reuses the exact execution on retry', function (): void {
    $draft = journalFixtureDraft($this->type);
    $first = $this->journal->preview('mactacular:catalogue', 'csv', hash('sha256', 'first'), [$draft], true);
    $ids = $this->journal->apply($first->report(), $first->digest());

    $fresh = new ImportJournal($this->context);
    $second = $fresh->preview('mactacular:catalogue', 'json', hash('sha256', 'second'), [$draft], true);
    expect($fresh->apply($second->report(), $second->digest()))->toBe($ids)
        ->and($fresh->apply($first->report(), $first->digest()))->toBe($ids)
        ->and(ImportSource::count())->toBe(1)
        ->and(ImportExecution::count())->toBe(2);
});

it('allows independent imports across Sites and conceals the original scope', function (string $otherSite): void {
    $report = $this->journal->preview('catalogue', 'json', hash('sha256', 'same'), [], true);
    $this->journal->apply($report->report(), $report->digest());
    $source = ImportSource::firstOrFail();
    $execution = ImportExecution::firstOrFail();
    $this->context->setSite($this->{$otherSite});

    expect(ImportSource::find($source->getKey()))->toBeNull()
        ->and(ImportExecution::find($execution->getKey()))->toBeNull()
        ->and(fn () => $this->journal->apply($report->report(), $report->digest()))->toThrow(DomainException::class);

    $ownReport = $this->journal->preview('catalogue', 'json', hash('sha256', 'same'), [], true);
    $this->journal->apply($ownReport->report(), $ownReport->digest());
    expect(ImportSource::firstOrFail()->is($source))->toBeFalse()
        ->and(ImportExecution::firstOrFail()->is($execution))->toBeFalse();

    expect(fn () => DB::transaction(fn () => DB::table('import_executions')->insert([
        'org_id' => $this->context->orgId(),
        'site_id' => $this->context->siteId(),
        'import_source_id' => $source->getKey(),
        'plan_digest' => $report->digest(),
    ])))->toThrow(QueryException::class)
        ->and(ImportExecution::count())->toBe(1);
})->with(['sibling', 'foreign']);

it('requires a destination context and portable canonical identifiers', function (): void {
    $digest = hash('sha256', 'fixture');
    $plan = $this->journal->preview('valid-key', 'csv', $digest, [], true);
    expect(fn () => $this->journal->preview('UPPERCASE', 'csv', $digest, [], true))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->journal->apply($plan->report(), 'short'))->toThrow(DomainException::class);

    $org = $this->context->org();
    $this->context->setSite(null)->setOrg($org);

    expect(fn () => $this->journal->preview('valid-key', 'csv', $digest, [], true))->toThrow(LogicException::class)
        ->and(fn () => $this->journal->apply($plan->report(), $plan->digest()))->toThrow(LogicException::class)
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
    $draft = journalFixtureDraft($this->type);
    $review = journalFixtureDraft($this->type, 'review', 'review');
    $first = $this->journal->preview('catalogue', 'csv', hash('sha256', 'first'), [$draft, $review], true);
    [$id, $otherId] = $this->journal->apply($first->report(), $first->digest());
    $entry = Entry::findOrFail($id);

    expect($id)->not->toBe($otherId)
        ->and($this->journal->apply($first->report(), $first->digest()))->toBe([$id, $otherId])
        ->and($entry->revisions()->count())->toBe(1)
        ->and(ImportMapping::count())->toBe(2);

    $changed = array_replace($draft, ['title' => 'Revised']);
    $next = $this->journal->preview('catalogue', 'json', hash('sha256', 'second'), [$changed], true);
    expect($this->journal->apply($next->report(), $next->digest()))->toBe([$id])
        ->and($entry->fresh()->title)->toBe('Revised')
        ->and($entry->revisions()->count())->toBe(2)
        ->and(ImportMapping::count())->toBe(2)
        ->and($this->journal->apply($first->report(), $first->digest()))->toBe([$id, $otherId])
        ->and($entry->fresh()->title)->toBe('Revised');
});

it('holds a moved-on destination and rolls back refused mapping writes', function (): void {
    $digest = hash('sha256', 'first');
    $draft = journalFixtureDraft($this->type);
    $first = $this->journal->preview('catalogue', 'csv', $digest, [$draft], true);
    $refuse = true;
    ImportMapping::creating(static function () use (&$refuse): ?bool {
        return $refuse ? false : null;
    });
    expect(fn () => $this->journal->apply($first->report(), $first->digest()))->toThrow(RuntimeException::class, 'mapping write was refused')
        ->and(Entry::count())->toBe(0)
        ->and(ImportMapping::count())->toBe(0);

    $refuse = false;
    [$id] = $this->journal->apply($first->report(), $first->digest());
    $entry = Entry::findOrFail($id);
    $entry->update(['title' => 'Staff edit']);
    $changed = $this->journal->preview('catalogue', 'csv', $digest, [$draft], true);
    expect($changed->findings)->toBe([['position' => 1, 'code' => 'mapped-draft-changed']])
        ->and($entry->fresh()->title)->toBe('Staff edit');

    $entry->delete();
    $deleted = $this->journal->preview('catalogue', 'csv', $digest, [$draft], true);
    expect($deleted->findings)->toBe([['position' => 1, 'code' => 'mapped-draft-unavailable']])
        ->and(ImportMapping::count())->toBe(1)
        ->and(Entry::withTrashed()->count())->toBe(1);
});

/** @param list<array<string, mixed>> $records */
function draftImportReport(Site $site, EntryType $type, array $records, bool $complete = true, string $adapter = 'json'): string
{
    return json_encode([
        'version' => 1,
        'org_id' => $site->org_id,
        'site_id' => $site->id,
        'source_key' => 'catalogue',
        'adapter' => $adapter,
        'snapshot_sha256' => hash('sha256', 'fixture source snapshot'),
        'schema_sha256' => ImportSchema::digest(collect([$type])),
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
    $report = draftImportReport($this->home, $this->type, [$record]);
    $digest = ImportPlan::restore($report)->digest();
    $changed = draftImportReport($this->home, $this->type, [array_replace($record, ['title' => 'Other'])]);
    $incomplete = draftImportReport($this->home, $this->type, [$record], false);
    $malformed = draftImportReport($this->home, $this->type, [$record, ['source_type' => 'article']]);
    $duplicate = draftImportReport($this->home, $this->type, [$record, $record]);

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

it('holds a persisted draft changed without a new revision', function (): void {
    $digest = hash('sha256', 'fixture');
    $draft = journalFixtureDraft($this->type);
    $report = $this->journal->preview('catalogue', 'json', $digest, [$draft], true);
    [$id] = $this->journal->apply($report->report(), $report->digest());
    $entry = Entry::findOrFail($id);

    $revisions = $entry->revisions()->count();
    $entry->withoutRevisions(fn (Entry $draft) => $draft->update(['title' => 'Maintenance edit']));
    $next = $this->journal->preview('catalogue', 'json', $digest, [$draft], true);

    expect($entry->revisions()->count())->toBe($revisions)
        ->and($next->findings)->toBe([['position' => 1, 'code' => 'mapped-draft-changed']])
        ->and($entry->fresh()->title)->toBe('Maintenance edit');
});

it('requires a mapping and its execution to name the same source', function (): void {
    foreach (['catalogue', 'archive'] as $key) {
        $plan = $this->journal->preview($key, 'json', hash('sha256', $key), [], true);
        $this->journal->apply($plan->report(), $plan->digest());
    }
    $source = ImportSource::where('source_key', 'catalogue')->firstOrFail();
    $other = ImportSource::where('source_key', 'archive')->firstOrFail();
    $run = ImportExecution::where('import_source_id', $other->getKey())->firstOrFail();
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

it('admits only one plan for a source until it completes or is abandoned', function (): void {
    $base = [
        'source_type' => 'article',
        'source_id' => 'first',
        'entry_type_id' => $this->type->id,
        'title' => 'First',
        'slug' => 'first',
    ];
    $second = array_replace($base, ['source_id' => 'second', 'title' => 'Second', 'slug' => 'second']);
    $third = array_replace($base, ['source_id' => 'third', 'title' => 'Third', 'slug' => 'third']);
    $old = $this->journal->preview('catalogue', 'json', hash('sha256', 'first snapshot'), [$base, $second], true);
    $next = $this->journal->preview('catalogue', 'json', hash('sha256', 'next snapshot'), [$third], true);

    ImportActionReceipt::creating(static fn (ImportActionReceipt $receipt): ?bool => $receipt->position === 2 ? false : null);
    expect(fn () => $this->journal->apply($old->report(), $old->digest()))
        ->toThrow(RuntimeException::class, 'receipt was refused');

    $retained = $this->journal->progress($old->report(), $old->digest());
    expect(array_column($retained, 'outcome'))->toBe(['created', 'pending'])
        ->and(fn () => $this->journal->apply($next->report(), $next->digest()))
        ->toThrow(DomainException::class, 'Another approved execution is active')
        ->and(Entry::pluck('slug')->all())->toBe(['first']);

    $unstarted = $this->journal->preview('catalogue', 'json', hash('sha256', 'unstarted snapshot'), [$third], true);
    expect(fn () => $this->journal->apply($unstarted->report(), $unstarted->digest()))
        ->toThrow(DomainException::class, 'Another approved execution is active');
    $this->journal->abandon($unstarted->report(), $unstarted->digest());
    expect($this->journal->executionReport($old->report(), $old->digest())['state'])->toBe('active');

    $this->journal->abandon($old->report(), $old->digest());
    $this->journal->abandon($old->report(), $old->digest());
    expect($this->journal->apply($next->report(), $next->digest()))->toHaveCount(1)
        ->and(fn () => $this->journal->apply($unstarted->report(), $unstarted->digest()))->toThrow(DomainException::class, 'already closed')
        ->and(fn () => $this->journal->apply($old->report(), $old->digest()))->toThrow(DomainException::class, 'already closed')
        ->and($this->journal->progress($old->report(), $old->digest()))->toBe($retained)
        ->and(Entry::query()->orderBy('slug')->pluck('slug')->all())->toBe(['first', 'third']);
});

it('uses the registered operator command for an exact preview, apply and status', function (): void {
    $kernel = app(Kernel::class);
    $kernel->registerCommand(app(ImportDraftsCommand::class));
    $path = tempnam(sys_get_temp_dir(), 'kitsune-import-input-');
    if ($path === false) {
        throw new RuntimeException('Could not allocate fixture input.');
    }

    $run = static function (string $action, array $options = []) use ($kernel, $path): array {
        $output = new BufferedOutput;
        $exit = $kernel->call('kitsune:import-drafts', [
            'action' => $action,
            'path' => $path,
            '--org' => 'home',
            '--site' => 'home',
            ...$options,
        ], $output);

        return [$exit, $output->fetch()];
    };
    $this->context->setSite($this->sibling);

    try {
        file_put_contents($path, '[{"entry_type":"stock","source_type":"article","source_id":"code","title":"Draft","slug":"draft"}]');
        [$exit, $bytes] = $run('preview', ['--source' => 'catalogue', '--format' => 'json', '--type' => ['stock=catalogue'], '--complete' => true]);
        expect($exit)->toBe(0)
            ->and($this->context->siteId())->toBe($this->sibling->id)
            ->and(Entry::count())->toBe(0);

        file_put_contents($path, $bytes);
        [$exit, $digest] = $run('digest');
        $approval = ['--approval' => trim($digest)];
        expect($exit)->toBe(0)
            ->and($approval['--approval'])->toBe(ImportPlan::restore($bytes)->digest())
            ->and($run('status', $approval)[1])->toContain('"state":"not-started"')
            ->and($run('apply', ['--approval' => 'wrong'])[0])->toBe(1)
            ->and($run('apply', $approval)[0])->toBe(0)
            ->and($run('status', $approval)[1])->toContain('"state":"completed"', '"outcome":"created"')
            ->and($this->context->siteId())->toBe($this->sibling->id);
    } finally {
        unlink($path);
    }
});

it('validates custom fields through the same contract as the Site API in both formats', function (): void {
    foreach ([
        ['summary', 'text', [], true],
        ['sort_order', 'number', ['format' => 'integer'], false],
        ['permalink', 'slug', [], false],
    ] as [$handle, $type, $settings, $required]) {
        $storage = FieldStorage::create([
            'handle' => $handle, 'type' => $type, 'settings' => $settings,
            'pii_class' => 'none', 'cardinality' => 1,
        ]);
        Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id,
            'label' => $handle, 'is_required' => $required,
        ]);
    }

    $input = app(StructuredDraftInput::class);
    $types = ['catalogue' => $this->type->id];
    $row = [
        'entry_type' => 'catalogue', 'source_type' => 'article', 'source_id' => '42',
        'title' => 'First', 'slug' => 'first',
    ];
    $json = static fn (array $record): string => json_encode([$record], JSON_THROW_ON_ERROR);
    $incorrect = $input->preview('json', 'catalogue', $json($row + [
        'values' => ['summary' => 'Hello', 'permalink' => 'another-slug'],
    ]), $types, true);
    $missing = $input->preview('json', 'catalogue', $json($row), $types, true);
    $invalid = $input->preview('json', 'catalogue', $json($row + ['values' => null]), $types, true);
    expect($incorrect->findings)->toBe([['position' => 1, 'code' => 'invalid-field-values']])
        ->and($missing->findings)->toBe([['position' => 1, 'code' => 'invalid-field-values']])
        ->and($invalid->findings)->toBe([['position' => 1, 'code' => 'invalid-record']])
        ->and(Entry::count())->toBe(0)
        ->and(ImportSource::count())->toBe(0);

    $values = ['summary' => 'First summary', 'sort_order' => 7, 'permalink' => 'first'];
    $preview = $input->preview('json', 'catalogue', $json($row + ['values' => $values]), $types, true);
    expect($preview->complete)->toBeTrue()
        ->and($preview->records[0]['values'])->toBe($values);

    [$id] = $this->journal->apply($preview->report(), $preview->digest());
    $entry = Entry::findOrFail($id);
    expect($entry->values)->toBe(['summary' => 'First summary', 'sort_order' => 7])
        ->and($entry->slug)->toBe('first')
        ->and($entry->status)->toBe('draft');

    $csv = "entry_type,source_type,source_id,title,slug,values_json\ncatalogue,article,42,Updated,first,\"{\"\"summary\"\":\"\"Updated summary\"\"}\"\n";
    $changed = $input->preview('csv', 'catalogue', $csv, $types, true);
    expect($changed->complete)->toBeTrue()
        ->and($this->journal->apply($changed->report(), $changed->digest()))->toBe([$id])
        ->and($entry->fresh()->values)->toBe(['summary' => 'Updated summary', 'sort_order' => 7]);
});

it('resolves ordered forward links by typed source ID and resumes atomically after a link receipt fails', function (): void {
    $storage = FieldStorage::create([
        'handle' => 'related_drafts', 'type' => 'relation', 'settings' => ['targetTypes' => ['catalogue']],
        'pii_class' => 'none', 'cardinality' => 2,
    ]);
    Field::create([
        'entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id, 'label' => 'Related drafts',
    ]);

    $base = ['entry_type' => 'catalogue', 'source_type' => 'article', 'source_id' => 'parent', 'title' => 'Parent', 'slug' => 'parent'];
    $row = $base + ['links' => ['related_drafts' => [
        ['source_type' => 'review', 'source_id' => '42'],
        ['source_type' => 'article', 'source_id' => '42'],
    ]]];
    $article = array_replace($base, ['source_id' => '42', 'title' => 'Article', 'slug' => 'article']);
    $review = array_replace($base, ['source_type' => 'review', 'source_id' => '42', 'title' => 'Review', 'slug' => 'review']);
    $plan = fn (array $rows) => app(StructuredDraftInput::class)->preview(
        'json', 'catalogue', json_encode($rows, JSON_THROW_ON_ERROR), ['catalogue' => $this->type->id], true,
    );

    $incomplete = $plan([$row, $article]);
    expect($incomplete->findings)->toBe([['position' => 1, 'code' => 'unavailable-relation-target']])
        ->and(fn () => $this->journal->apply($incomplete->report(), $incomplete->digest()))->toThrow(DomainException::class)
        ->and(Entry::count())->toBe(0);

    $complete = $plan([$row, $article, $review]);
    expect($complete->complete)->toBeTrue();

    $refuse = true;
    ImportActionReceipt::creating(static function (ImportActionReceipt $receipt) use (&$refuse): ?bool {
        return $refuse && $receipt->outcome === 'linked' ? false : null;
    });
    expect(fn () => $this->journal->apply($complete->report(), $complete->digest()))->toThrow(RuntimeException::class, 'receipt was refused')
        ->and(Entry::count())->toBe(3)
        ->and(ImportMapping::count())->toBe(3)
        ->and(ImportActionReceipt::count())->toBe(3)
        ->and(Entry::where('slug', 'parent')->firstOrFail()->relationState())->toBe([]);

    $refuse = false;
    [$parent, $articleId, $reviewId] = $this->journal->apply($complete->report(), $complete->digest());
    $entry = Entry::findOrFail($parent);
    $report = $this->journal->executionReport($complete->report(), $complete->digest());
    expect($entry->relationState())->toBe([$storage->id => [$reviewId, $articleId]])
        ->and($report['state'])->toBe('completed')
        ->and(array_column($report['records'], 'outcome'))->toBe(['created', 'created', 'created', 'linked'])
        ->and($this->journal->apply($complete->report(), $complete->digest()))->toBe([$parent, $articleId, $reviewId])
        ->and($this->journal->executionReport($complete->report(), $complete->digest()))->toBe($report);

    $clear = $plan([$base + ['links' => ['related_drafts' => []]]]);
    expect($clear->complete)->toBeTrue()
        ->and($this->journal->apply($clear->report(), $clear->digest()))->toBe([$parent])
        ->and($entry->fresh()->relationState())->toBe([]);
});

it('requires a fresh approval when a destination field changes without changing the source bytes', function (): void {
    $storage = FieldStorage::create([
        'handle' => 'summary', 'type' => 'text', 'pii_class' => 'none', 'cardinality' => 1,
    ]);
    $field = Field::create([
        'entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id, 'label' => 'Summary',
    ]);
    $input = app(StructuredDraftInput::class);
    $bytes = '[{"entry_type":"catalogue","source_type":"article","source_id":"42","title":"Draft","slug":"draft","values":{"summary":"Present either way"}}]';
    $mapping = ['catalogue' => $this->type->id];
    $original = $input->preview('json', 'catalogue', $bytes, $mapping, true);
    expect($original->complete)->toBeTrue();

    $field->update(['is_required' => true]);
    expect(fn () => $this->journal->apply($original->report(), $original->digest()))
        ->toThrow(DomainException::class, 'destination schema changed')
        ->and(Entry::count())->toBe(0)
        ->and(ImportExecution::count())->toBe(0)
        ->and(ImportSource::count())->toBe(0);

    $revised = $input->preview('json', 'catalogue', $bytes, $mapping, true);
    expect($revised->complete)->toBeTrue()
        ->and($revised->records)->toBe($original->records)
        ->and($revised->schemaDigest)->not->toBe($original->schemaDigest)
        ->and($revised->digest())->not->toBe($original->digest());
    $committed = $this->journal->apply($revised->report(), $revised->digest());
    $field->update(['is_required' => false]);
    expect($committed)->toHaveCount(1)
        ->and($this->journal->apply($revised->report(), $revised->digest()))->toBe($committed)
        ->and(ImportActionReceipt::count())->toBe(1);
});
