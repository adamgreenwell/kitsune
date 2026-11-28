<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Console\ImportDraftsCommand;
use Kitsune\Core\Http\Middleware\ResolveSiteFromRequest;
use Kitsune\Core\Http\Middleware\SetSiteLocale;
use Kitsune\Core\Imports\ImportJournal;
use Kitsune\Core\Imports\ImportPlan;
use Kitsune\Core\Imports\ImportRedirectPlan;
use Kitsune\Core\Imports\ImportSchema;
use Kitsune\Core\Imports\StructuredDraftInput;
use Kitsune\Core\Media\MediaDelivery;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Models\AuditLog;
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
use Kitsune\Core\Models\SiteEntryRedirect;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Themes\PublicThemePage;
use Kitsune\Core\Themes\ThemeRegistry;
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

it('includes adapter diagnostics in the approved bytes and refuses source blockers', function (): void {
    $draft = journalFixtureDraft($this->type);
    $snapshot = hash('sha256', 'source');
    $notices = [['position' => 0, 'code' => 'export-omissions-not-recoverable']];
    $blockers = [['position' => 1, 'code' => 'required-source-field-unavailable']];
    $previous = $this->journal->preview('catalogue', 'adapter', $snapshot, [$draft], true);
    $reported = $this->journal->preview('catalogue', 'adapter', $snapshot, [$draft], true,
        sourceDiagnostics: ['blockers' => [], 'notices' => $notices]);
    $blocked = $this->journal->preview('catalogue', 'adapter', $snapshot, [$draft], true,
        sourceDiagnostics: ['blockers' => $blockers, 'notices' => $notices]);

    expect(ImportPlan::restore($previous->report())->sourceNotices)->toBe([])
        ->and(ImportPlan::restore($reported->report())->sourceNotices)->toBe($notices)
        ->and($reported->complete)->toBeTrue()
        ->and($reported->digest())->not->toBe($previous->digest())
        ->and($blocked->complete)->toBeFalse()
        ->and($blocked->findings)->toBe($blockers)
        ->and(fn () => $this->journal->apply($reported->report(), $previous->digest()))->toThrow(DomainException::class)
        ->and(fn () => $this->journal->apply($blocked->report(), $blocked->digest()))->toThrow(DomainException::class)
        ->and(Entry::query()->count())->toBe(0)
        ->and(ImportSource::query()->count())->toBe(0);
    expect($this->journal->apply($reported->report(), $reported->digest()))->toHaveCount(1);
});

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

it('approves a local WordPress projection through the command and refuses its source findings', function (): void {
    $kernel = app(Kernel::class);
    $kernel->registerCommand(app(ImportDraftsCommand::class));
    $path = tempnam(sys_get_temp_dir(), 'kitsune-wordpress-projection-');
    if ($path === false) {
        throw new RuntimeException('Could not allocate fixture projection.');
    }

    $run = static function (string $action, array $options = []) use ($kernel, $path): array {
        $output = new BufferedOutput;
        $exit = $kernel->call('kitsune:import-drafts', [
            'action' => $action, 'path' => $path, '--org' => 'home', '--site' => 'home', ...$options,
        ], $output);

        return [$exit, $output->fetch()];
    };
    $projection = [
        'format' => 1, 'blocked' => false, 'records' => [[
            'source' => ['type' => 'post', 'id' => '17'],
            'title' => 'Fixture article', 'slug' => 'fixture-article', 'values' => (object) [], 'media_references' => [],
        ]], 'media' => [], 'findings' => [],
    ];
    $options = ['--source' => 'fixture-wordpress', '--format' => 'wordpress-wxr', '--type' => ['post=catalogue'], '--complete' => true];
    try {
        $blockedInput = array_replace($projection, [
            'blocked' => true, 'findings' => [['source_id' => '17', 'code' => 'ambiguous-acf-value', 'field' => 'summary']],
        ]);
        file_put_contents($path, json_encode($blockedInput, JSON_THROW_ON_ERROR));
        [$exit, $bytes] = $run('preview', $options);
        $blocked = ImportPlan::restore($bytes);
        expect($exit)->toBe(1)
            ->and($blocked->findings)->toBe([[
                'position' => 1, 'code' => 'wordpress-finding-ambiguous-acf-value', 'source_id' => '17', 'field' => 'summary',
            ]])
            ->and($blocked->sourceNotices)->toHaveCount(4);
        file_put_contents($path, $bytes);
        expect($run('digest')[0])->toBe(1)->and(Entry::count())->toBe(0);

        file_put_contents($path, json_encode($projection + ['truncated' => true], JSON_THROW_ON_ERROR));
        expect($run('preview', $options)[0])->toBe(1)->and(Entry::count())->toBe(0);

        file_put_contents($path, json_encode($projection, JSON_THROW_ON_ERROR));
        [$exit, $bytes] = $run('preview', $options);
        $wxr = ImportPlan::restore($bytes);
        expect($exit)->toBe(0)->and($wxr->sourceNotices)->toHaveCount(4);
        file_put_contents($path, $bytes);
        expect($run('apply', ['--approval' => $wxr->digest()])[0])->toBe(0);
        $destinationId = Entry::query()->sole()->id;

        file_put_contents($path, json_encode($projection, JSON_THROW_ON_ERROR));
        [$exit, $bytes] = $run('preview', array_replace($options, ['--format' => 'wordpress-direct']));
        $direct = ImportPlan::restore($bytes);
        expect($exit)->toBe(0)->and($direct->sourceNotices)->toHaveCount(1)
            ->and($direct->digest())->not->toBe($wxr->digest());
        file_put_contents($path, $bytes);
        expect($run('apply', ['--approval' => $direct->digest()])[0])->toBe(0)
            ->and(Entry::query()->sole()->id)->toBe($destinationId)
            ->and(Entry::query()->sole()->status)->toBe('draft');
    } finally {
        unlink($path);
    }
});

it('requires a separate offline source connection for WordPress database preview', function (): void {
    $kernel = app(Kernel::class);
    $kernel->registerCommand(app(ImportDraftsCommand::class));
    $output = new BufferedOutput;
    $status = $kernel->call('kitsune:import-drafts', [
        'action' => 'preview', 'path' => 'unused-source-report.json', '--org' => 'home', '--site' => 'home',
        '--source' => 'confirmed-blog', '--format' => 'wordpress-database',
        '--wordpress-blog' => 'https://fixture.example.test/blog',
        '--wordpress-prefix' => 'wp_', '--wordpress-connection' => DB::getDefaultConnection(),
    ], $output);

    expect($status)->toBe(1)
        ->and($output->fetch())->toContain('Choose a separate connection')
        ->and(ImportSource::query()->count())->toBe(0);
});

it('streams a local WXR export through the operator command for the confirmed blog', function (): void {
    if (! class_exists(XMLReader::class)) {
        $this->markTestSkipped('This import format requires PHP XMLReader.');
    }

    $kernel = app(Kernel::class);
    $kernel->registerCommand(app(ImportDraftsCommand::class));
    [$xml, $schema] = [tempnam(sys_get_temp_dir(), 'kitsune-wxr-'), tempnam(sys_get_temp_dir(), 'kitsune-acf-')];
    if ($xml === false || $schema === false) {
        throw new RuntimeException('Could not allocate WXR fixture input.');
    }
    $fixture = <<<'XML'
        <rss version="2.0" xmlns:wp="https://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/">
          <channel>
            <wp:wxr_version>1.2</wp:wxr_version>
            <wp:base_blog_url>https://fixture.example.test/blog</wp:base_blog_url>
            <item><title>Article</title><wp:post_id>17</wp:post_id><wp:post_type>post</wp:post_type>
              <wp:status>publish</wp:status><wp:post_parent>0</wp:post_parent><wp:post_name>article</wp:post_name>
              <wp:post_date_gmt>2025-01-01 12:00:00</wp:post_date_gmt><content:encoded>Original body</content:encoded>
            </item>
          </channel>
        </rss>
        XML;
    $run = static function (string $blog) use ($kernel, $xml, $schema): array {
        $output = new BufferedOutput;
        $exit = $kernel->call('kitsune:import-drafts', [
            'action' => 'preview', 'path' => $xml, '--org' => 'home', '--site' => 'home',
            '--source' => 'confirmed-blog', '--format' => 'wxr', '--wordpress-blog' => $blog,
            '--acf-schema' => $schema, '--type' => ['post=catalogue'], '--complete' => true,
        ], $output);

        return [$exit, $output->fetch()];
    };
    try {
        file_put_contents($schema, '{"blocked":false,"groups":[]}');
        file_put_contents($xml, $fixture);
        $blog = 'https://fixture.example.test/blog';
        expect($run('https://fixture.example.test/other')[0])->toBe(1);
        [$exit, $bytes] = $run($blog);
        $plan = ImportPlan::restore($bytes);
        expect($exit)->toBe(0)->and($plan->sourceNotices)->toHaveCount(4)
            ->and($plan->records[0]['source_id'])->toBe('17')
            ->and($plan->sourceAddress)->toBe($blog)
            ->and($bytes)->not->toContain('Original body')
            ->and(Entry::count())->toBe(0);

        file_put_contents($xml, str_replace('Original body', 'Changed body', $fixture));
        [$exit, $bytes] = $run($blog);
        expect($exit)->toBe(0)->and(ImportPlan::restore($bytes)->digest())->not->toBe($plan->digest());
        file_put_contents($xml, '<!DOCTYPE rss>'.$fixture);
        expect($run($blog)[0])->toBe(1)->and(Entry::count())->toBe(0);
    } finally {
        unlink($xml);
        unlink($schema);
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
    expect($incorrect->findings)->toBe([['position' => 1, 'code' => 'invalid-field-values', 'field' => 'permalink']])
        ->and($missing->findings)->toBe([['position' => 1, 'code' => 'invalid-field-values', 'field' => 'summary']])
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

it('plans former addresses from committed import identities and holds unroutable paths', function (): void {
    $theme = sys_get_temp_dir().'/kitsune-import-redirect-theme-'.bin2hex(random_bytes(8));
    mkdir($theme.'/journal', 0700, true);
    file_put_contents($theme.'/journal/theme.json', '{"entry_types":["catalogue"]}');
    file_put_contents($theme.'/journal/home.blade.php', 'Home');
    file_put_contents($theme.'/journal/entry.blade.php', 'Entry');
    app()->instance(ThemeRegistry::class, new ThemeRegistry($theme));

    try {
        $this->home->update(['base_url' => 'https://example.test/news', 'theme' => 'journal']);
        $this->context->setSite($this->home);
        Route::get('/news/post/owned', fn () => 'Host')->name('host-owned');
        Route::get('/news/2025/06/claimed', fn () => 'Host')->name('host-dated');
        Route::fallback(function (Request $request, Context $context, PublicThemePage $theme) {
            return $context->site() === null ? abort(404) : ($theme->render($request, $context->site(), 'site.home') ?? abort(404));
        })->middleware([ResolveSiteFromRequest::class, SetSiteLocale::class])->name('site.home');
        $plan = $this->journal->preview('wordpress-fixture', 'wordpress:direct:v1', hash('sha256', 'source'), [
            journalFixtureDraft($this->type, 'story', 'post', '100'),
        ], true, sourceAddress: 'https://example.test/news');
        $input = [
            'source_key' => 'wordpress-fixture',
            'source_blog' => 'https://example.test/news',
            'addresses' => array_map(static fn (string $url): array => ['source_type' => 'post', 'source_id' => '100', 'url' => $url], [
                'https://example.test/news/post/old',
                'https://example.test/news/catalogue/story',
                'https://example.test/news/2025/01/story',
                'https://external.example.test/news/?p=100',
                'https://example.test/news-archive/post/story',
                'https://example.test/news/post/owned',
                'https://EXAMPLE.test:443/news/post/duplicate/',
                'https://example.test/news/post/duplicate',
                'https://example.test/news/?p=100',
                'https://example.test/news/?p=102',
                'https://example.test/news/2025/05/repeated/',
                'https://example.test/news/2025/05/repeated',
                'https://example.test/news/2025/06/claimed',
            ]),
        ];
        $planner = app(ImportRedirectPlan::class);
        $preview = fn (array $values) => $planner->preview($this->home, $plan->report(), $plan->digest(), json_encode($values, JSON_THROW_ON_ERROR), 'site.home');
        expect(fn () => $preview($input))->toThrow(DomainException::class, 'Finish the approved draft import');
        [$id] = $this->journal->apply($plan->report(), $plan->digest());
        $unpublished = json_decode($preview($input), true, flags: JSON_THROW_ON_ERROR);
        expect($unpublished['rows'][0]['findings'])->toBe(['destination-unpublished']);

        Entry::query()->findOrFail($id)->update(['status' => 'published']);
        $report = $preview($input);
        expect($preview($input))->toBe($report);
        file_put_contents($theme.'/import.json', $plan->report());
        file_put_contents($theme.'/inventory.json', json_encode($input, JSON_THROW_ON_ERROR));
        $kernel = app(Kernel::class);
        $kernel->registerCommand(app(ImportDraftsCommand::class));
        $output = new BufferedOutput;
        expect($kernel->call('kitsune:import-drafts', [
            'action' => 'plan-redirects', 'path' => $theme.'/import.json', '--org' => 'home', '--site' => 'home',
            '--approval' => $plan->digest(), '--redirect-inventory' => $theme.'/inventory.json',
        ], $output))->toBe(0)->and($output->fetch())->toBe($report);
        $read = json_decode($report, true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($read['rows'], 'disposition'))->toBe(['candidate', 'no-op', 'held', 'held', 'held', 'held', 'held', 'held', 'held', 'held', 'held', 'held', 'held'])
            ->and($read['rows'][0]['destination']['entry_id'])->toBe($id)
            ->and($read['rows'][2]['findings'])->toContain('unsupported-former-address')
            ->and($read['rows'][3]['findings'])->toContain('outside-source-origin', 'unsupported-former-address')
            ->and($read['rows'][4]['findings'])->toContain('outside-source-blog-path')
            ->and($read['rows'][5]['findings'])->toContain('former-address-not-owned')
            ->and($read['rows'][6]['findings'])->toContain('duplicate-former-address')
            ->and($read['rows'][7]['findings'])->toContain('duplicate-former-address')
            ->and($read['rows'][2]['host_route'])->toBe([
                'origin' => 'https://example.test', 'request_uri' => '/news/2025/01/story',
                'location' => 'https://example.test/news/catalogue/story',
            ])
            ->and($read['rows'][3]['host_route'])->toBeNull()
            ->and($read['rows'][8]['host_route']['request_uri'])->toBe('/news/?p=100')
            ->and($read['rows'][9]['host_route'])->toBeNull()
            ->and($read['rows'][10]['host_route'])->toBeNull()
            ->and($read['rows'][10]['findings'])->toContain('duplicate-old-request')
            ->and($read['rows'][12]['host_route'])->toBeNull()
            ->and($read['rows'][12]['findings'])->toContain('former-address-not-owned')
            ->and(SiteEntryRedirect::query()->count())->toBe(0)
            ->and(fn () => $preview(array_replace($input, ['source_key' => 'other'])))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $preview(array_replace($input, ['source_blog' => 'https://other.example.test/news'])))->toThrow(InvalidArgumentException::class);

        $apply = static function (string $reviewed, string $digest) use ($kernel, $theme, $plan): array {
            file_put_contents($theme.'/redirects.json', $reviewed);
            $output = new BufferedOutput;
            $exit = $kernel->call('kitsune:import-drafts', [
                'action' => 'apply-redirects', 'path' => $theme.'/import.json', '--org' => 'home', '--site' => 'home',
                '--approval' => $plan->digest(), '--redirect-inventory' => $theme.'/inventory.json',
                '--redirect-report' => $theme.'/redirects.json', '--redirect-approval' => $digest,
            ], $output);

            return [$exit, $output->fetch()];
        };
        expect($apply($report, 'wrong')[0])->toBe(1);
        $changingRoutes = $input;
        $changingRoutes['addresses'][] = ['source_type' => 'post', 'source_id' => '100', 'url' => 'https://example.test/news/post/claimed'];
        $beforeClaim = $preview($changingRoutes);
        file_put_contents($theme.'/inventory.json', json_encode($changingRoutes, JSON_THROW_ON_ERROR));
        Route::get('/news/post/claimed', fn () => 'Host claimed the route');
        expect($apply($beforeClaim, ImportRedirectPlan::digest($beforeClaim))[0])->toBe(1)
            ->and(SiteEntryRedirect::query()->count())->toBe(0);
        file_put_contents($theme.'/inventory.json', json_encode($input, JSON_THROW_ON_ERROR));

        Entry::query()->findOrFail($id)->update(['title' => 'Independently revised']);
        expect($apply($report, ImportRedirectPlan::digest($report))[0])->toBe(1)
            ->and(SiteEntryRedirect::query()->count())->toBe(0);

        $report = $preview($input);
        file_put_contents($theme.'/redirects.json', $report);
        $output = new BufferedOutput;
        expect($kernel->call('kitsune:import-drafts', [
            'action' => 'redirect-digest', 'path' => $theme.'/redirects.json', '--org' => 'home', '--site' => 'home',
        ], $output))->toBe(0);
        $digest = trim($output->fetch());
        expect($digest)->toBe(ImportRedirectPlan::digest($report));

        [$exit, $applied] = $apply($report, $digest);
        expect($exit)->toBe(0, $applied)->and($apply($report, $digest))->toBe([$exit, $applied])
            ->and(SiteEntryRedirect::query()->sole()->target_entry_id)->toBe($id)
            ->and(AuditLog::query()->where('action', 'site.redirect_saved')->count())->toBe(1);
        $this->get('https://example.test/news/post/old')->assertStatus(302)
            ->assertRedirect('/news/catalogue/story')->assertHeader('Cache-Control', 'no-store, private');
        $this->get('https://example.test/news/catalogue/story')->assertOk();
    } finally {
        (new Filesystem)->deleteDirectory($theme);
    }
});

it('plans the selected WordPress rich text field and writes only reviewed draft content', function (): void {
    if (! class_exists(XMLReader::class)) {
        $this->markTestSkipped('This import format requires PHP XMLReader.');
    }

    $storage = FieldStorage::create([
        'handle' => 'source_body', 'type' => 'rich_text', 'settings' => [],
        'pii_class' => 'none', 'cardinality' => 1,
    ]);
    Field::create([
        'entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id,
        'label' => 'Body', 'is_required' => false,
    ]);
    $mediaType = EntryType::create([
        'handle' => 'source_asset', 'name' => 'Asset', 'plural_name' => 'Assets', 'is_media' => true,
    ]);
    $relation = FieldStorage::create([
        'handle' => 'illustration', 'type' => 'relation',
        'settings' => ['targetTypes' => ['source_asset']], 'pii_class' => 'none', 'cardinality' => 1,
    ]);
    Field::create([
        'entry_type_id' => $this->type->id, 'field_storage_id' => $relation->id, 'label' => 'Illustration',
    ]);
    $kernel = app(Kernel::class);
    $kernel->registerCommand(app(ImportDraftsCommand::class));
    $temporary = sys_get_temp_dir().'/kitsune-wordpress-body-'.bin2hex(random_bytes(8));
    mkdir($temporary, 0700);
    $export = $temporary.'/export.xml';
    $acf = $temporary.'/acf.json';
    $report = $temporary.'/plan.json';
    file_put_contents($acf, '{"blocked":false,"groups":[]}');
    $fixture = <<<'XML'
        <rss version="2.0" xmlns:wp="https://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/">
          <channel>
            <wp:wxr_version>1.2</wp:wxr_version>
            <wp:base_blog_url>https://fixture.example.test/blog</wp:base_blog_url>
            <item><title>Article</title><wp:post_id>17</wp:post_id><wp:post_type>post</wp:post_type>
              <wp:status>publish</wp:status><wp:post_parent>0</wp:post_parent><wp:post_name>article</wp:post_name>
              <wp:post_password>PASSWORD</wp:post_password><wp:post_date_gmt>2025-01-01 12:00:00</wp:post_date_gmt>
              <content:encoded><![CDATA[BODY]]></content:encoded>
              POSTMETA
            </item>
            ATTACHMENT
          </channel>
        </rss>
        XML;
    $preview = static function (string $body, string $password = '', array $override = [], string $postMeta = '', string $attachment = '') use ($kernel, $fixture, $export, $acf): array {
        file_put_contents($export, strtr($fixture, [
            'BODY' => $body, 'PASSWORD' => $password, 'POSTMETA' => $postMeta, 'ATTACHMENT' => $attachment,
        ]));
        $output = new BufferedOutput;
        $exit = $kernel->call('kitsune:import-drafts', [
            'action' => 'preview', 'path' => $export, '--org' => 'home', '--site' => 'home',
            '--source' => 'wordpress-body-fixture', '--format' => 'wxr', '--wordpress-blog' => 'https://fixture.example.test/blog',
            '--acf-schema' => $acf, '--type' => ['post=catalogue'], '--wordpress-body' => ['post=source_body'],
            '--complete' => true, ...$override,
        ], $output);

        return [$exit, $output->fetch()];
    };

    try {
        $fullBody = '<!-- wp:paragraph --><p>Copy <strong>with emphasis</strong>.</p><!-- /wp:paragraph -->';
        $normalized = '<p>Copy <strong>with emphasis</strong>.</p>';
        [$status, $bytes] = $preview($fullBody);
        $plan = ImportPlan::restore($bytes);
        expect($status)->toBe(0)->and(Entry::count())->toBe(0)
            ->and($plan->records[0]['values'])->toBe(['source_body' => $normalized])
            ->and(array_column($plan->sourceNotices, 'code'))->toContain('wordpress-body-markup-normalized')
            ->not->toContain('wordpress-body-conversion-not-performed')
            ->and($bytes)->not->toContain('wp:paragraph');

        [$classicStatus, $classicReport] = $preview("First & second.\n\nNext paragraph.\nNew line.");
        expect($classicStatus)->toBe(0)
            ->and(ImportPlan::restore($classicReport)->records[0]['values']['source_body'])
            ->toBe('<p>First &amp; second.</p><p>Next paragraph.<br>New line.</p>');
        [$literalStatus] = $preview('<p>Docs show &lt;img&gt; as text.</p>');
        expect($literalStatus)->toBe(0);

        foreach ([
            '<p>Copy</p><img src="https://fixture.example.test/uploads/photo.jpg">',
            '<!-- wp:embed --><p>https://fixture.example.test/uploads/photo.jpg</p><!-- /wp:embed -->',
            '<div style="background-image:url(https://fixture.example.test/uploads/photo.jpg)">Cover</div>',
            '<p><a href="https://fixture.example.test/wp-content/uploads/photo.jpg">A download</a></p>',
        ] as $mediaBody) {
            [$mediaStatus, $mediaBytes] = $preview($mediaBody);
            expect($mediaStatus)->toBe(1)
                ->and(ImportPlan::restore($mediaBytes)->findings)->toContain([
                    'position' => 1, 'code' => 'wordpress-finding-body-inline-media-needs-rehosting',
                    'source_id' => '17', 'field' => 'source_body',
                ])->and($mediaBytes)->not->toContain('photo.jpg');
        }

        [$protectedStatus, $protectedBytes] = $preview($fullBody, 'fixture-password');
        expect($protectedStatus)->toBe(1)
            ->and(array_column(ImportPlan::restore($protectedBytes)->findings, 'code'))->toContain('wordpress-finding-body-is-password-protected')
            ->and($protectedBytes)->not->toContain('fixture-password', 'with emphasis')
            ->and($preview($fullBody, override: ['--wordpress-body' => ['post=unavailable']])[0])->toBe(1);

        $command = [
            'action' => 'apply', 'path' => $report, '--org' => 'home', '--site' => 'home', '--approval' => $plan->digest(),
        ];
        $drifted = ImportPlan::restore(str_replace($normalized, $fullBody, $bytes));
        file_put_contents($report, $drifted->report());
        expect($kernel->call('kitsune:import-drafts', array_replace($command, ['--approval' => $drifted->digest()])))->toBe(1)
            ->and(Entry::count())->toBe(0)
            ->and(ImportSource::count())->toBe(0);

        file_put_contents($report, $bytes);
        expect($kernel->call('kitsune:import-drafts', $command))->toBe(0);
        $entry = Entry::query()->sole();
        expect($entry->status)->toBe('draft')->and($entry->published_at)->toBeNull()
            ->and($entry->values['source_body'])->toContain('<strong>with emphasis</strong>')
            ->and($kernel->call('kitsune:import-drafts', $command))->toBe(0)
            ->and(Entry::query()->sole()->id)->toBe($entry->id);

        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jXWQAAAAASUVORK5CYII=', true);
        file_put_contents($temporary.'/photo.png', $image);
        $checksum = hash('sha256', $image);
        $manifest = $temporary.'/media.json';
        file_put_contents($manifest, json_encode([[
            'source_type' => 'attachment', 'source_id' => '40', 'path' => 'photo.png', 'original_name' => 'photo.png',
            'size' => strlen($image), 'sha256' => $checksum, 'entry_type_id' => $mediaType->id,
        ]], JSON_THROW_ON_ERROR));
        file_put_contents($acf, json_encode(['blocked' => false, 'groups' => [[
            'post_types' => ['post'],
            'fields' => [['name' => 'illustration', 'source_key' => 'field_cover', 'required' => false, 'proposed_kind' => 'media-reference']],
        ]]], JSON_THROW_ON_ERROR));

        $postMeta = <<<'XML'
            <wp:postmeta><wp:meta_key>illustration</wp:meta_key><wp:meta_value>40</wp:meta_value></wp:postmeta>
            <wp:postmeta><wp:meta_key>_illustration</wp:meta_key><wp:meta_value>field_cover</wp:meta_value></wp:postmeta>
            XML;
        $attachment = <<<'XML'
            <item><title>Photo</title><wp:post_id>40</wp:post_id><wp:post_type>attachment</wp:post_type>
              <wp:status>inherit</wp:status><wp:post_parent>17</wp:post_parent><wp:post_name>photo</wp:post_name>
              <wp:post_date_gmt>2025-01-01 12:00:00</wp:post_date_gmt><content:encoded></content:encoded>
              <wp:postmeta><wp:meta_key>_wp_attached_file</wp:meta_key><wp:meta_value>photo.png</wp:meta_value></wp:postmeta>
            </item>
            XML;
        $disk = Storage::fake(MediaDisks::PRIVATE);
        [$withMediaStatus, $withMediaBytes] = $preview('<p>Updated copy and a separately approved image.</p>', override: [
            '--media-manifest' => $manifest, '--media-root' => $temporary,
        ], postMeta: $postMeta, attachment: $attachment);
        $withMedia = ImportPlan::restore($withMediaBytes);
        expect($withMedia->findings)->toBe([])
            ->and($withMediaStatus)->toBe(0)->and($withMedia->records[0]['links']['illustration'])->toBe([
                ['source_type' => 'attachment', 'source_id' => '40'],
            ])->and($disk->allFiles())->toBe([]);
        file_put_contents($report, $withMediaBytes);
        $applyMedia = array_replace($command, ['--approval' => $withMedia->digest()]);
        expect($kernel->call('kitsune:import-drafts', array_replace($applyMedia, [
            'action' => 'stage-media', '--media-root' => $temporary,
        ])))->toBe(0)->and($kernel->call('kitsune:import-drafts', $applyMedia))->toBe(0);
        $related = Entry::where('entry_type_id', $mediaType->id)->sole();
        $file = MediaDelivery::fileFor($related);
        expect($file?->visibility)->toBe('private')
            ->and($file?->checksum)->toBe($checksum)
            ->and($related->site_id)->toBe($this->home->id)
            ->and($entry->fresh()->relationState())->toBe([$relation->id => [$related->id]])
            ->and($entry->fresh()->values['source_body'])->toContain('Updated copy');
    } finally {
        (new Filesystem)->deleteDirectory($temporary);
    }
});
