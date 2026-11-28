<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Imports;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Media\MediaFormats;
use Kitsune\Core\Media\MediaIntake;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\ImportActionReceipt;
use Kitsune\Core\Models\ImportExecution;
use Kitsune\Core\Models\ImportMapping;
use Kitsune\Core\Models\ImportMediaReservation;
use Kitsune\Core\Models\ImportSource;
use Kitsune\Core\Relations\EntryRelationWriter;
use Kitsune\Core\Tenancy\Context;
use LogicException;
use RuntimeException;

/**
 * The durable source and plan identities used by the migration writer.
 * The caller verifies operator approval before recording it in the journal.
 *
 * @internal
 *
 * @phpstan-import-type DraftAction from ImportPlan
 * @phpstan-import-type Finding from ImportPlan
 */
final readonly class ImportJournal
{
    public function __construct(private Context $context) {}

    /**
     * @param  list<array<string, mixed>>  $drafts
     * @param  list<ImportMediaAsset>  $media
     * @param  array{blockers: list<Finding>, notices: list<Finding>}|null  $sourceDiagnostics
     */
    public function preview(
        string $sourceKey, string $adapter, string $snapshot, array $drafts, bool $sourceComplete,
        array $media = [], ?string $mediaRoot = null, ?array $sourceDiagnostics = null, ?string $sourceAddress = null,
    ): ImportPlan {
        $siteId = $this->siteId();
        $orgId = $this->context->orgId() ?? throw new LogicException('The import journal requires a destination Org.');
        $source = ImportSource::query()->where('site_id', $siteId)->where('source_key', $sourceKey)->first();
        $availableTypes = EntryType::visibleFor($this->context->site(), $orgId)->keyBy('id');
        $findings = $sourceComplete ? [] : [['position' => 0, 'code' => 'source-incomplete']];
        array_push($findings, ...($sourceDiagnostics['blockers'] ?? []));
        $records = [];
        $positions = [];
        $identities = [];
        $slugs = [];

        foreach ($drafts as $index => $draft) {
            $position = $index + 1;

            try {
                $action = ImportPlan::draft((object) ($draft + ['expected_revision_id' => null]));
            } catch (InvalidArgumentException) {
                $findings[] = ['position' => $position, 'code' => 'invalid-record'];

                continue;
            }

            $identity = $action['source_type'].':'.hash('sha256', $action['source_id']);
            $slug = $action['entry_type_id'].':'.$action['slug'];
            $type = $availableTypes->get($action['entry_type_id']);
            $conflicts = match (true) {
                isset($identities[$identity]) => [['code' => 'repeated-source-identity']],
                isset($slugs[$slug]) => [['code' => 'repeated-destination-slug']],
                $type === null => [['code' => 'unavailable-entry-type']],
                default => $this->previewTarget($source, $siteId, $type, $action),
            };

            $identities[$identity] = true;
            $slugs[$slug] = true;
            if ($conflicts !== []) {
                foreach ($conflicts as $conflict) {
                    $findings[] = ['position' => $position, ...$conflict];
                }

                continue;
            }

            $records[] = $action;
            $positions[] = $position;
        }

        foreach ($this->relationFindings($records, $source, $siteId, $availableTypes, $media) as $finding) {
            $findings[] = ['position' => $positions[$finding['position'] - 1], 'code' => $finding['code']];
        }

        foreach ($media as $index => $asset) {
            $position = count($drafts) + $index + 1;
            if ($asset->entryTypeId !== null) {
                $type = $availableTypes->get($asset->entryTypeId)?->fresh();
                if ($type?->is_media !== true) {
                    $findings[] = ['position' => $position, 'code' => 'unavailable-media-type'];
                } else {
                    $formats = MediaFormats::namedBy($type->settings, $type->handle);
                    if ($formats !== null && ! in_array(MediaFormats::of(MediaIntake::extensionOf($asset->originalName)), $formats, true)) {
                        $findings[] = ['position' => $position, 'code' => 'unsupported-media-format'];
                    }
                }
            }
            $conflict = app(ImportMediaReservations::class)->previewConflict($source, $siteId, $asset);
            if ($conflict !== null) {
                $findings[] = ['position' => $position, 'code' => $conflict];
            }
            try {
                ImportMediaInput::inspect($mediaRoot ?? '', $asset);
            } catch (DomainException) {
                $findings[] = ['position' => $position, 'code' => 'unavailable-media-asset'];
            }
        }

        $schemaDigest = ImportSchema::digest($availableTypes->only([...array_column($records, 'entry_type_id'), ...array_filter(array_column($media, 'entryTypeId'))]));

        return ImportPlan::prepare(
            $orgId, $siteId, $sourceKey, $adapter, $snapshot, $schemaDigest, $records, $findings, $media,
            $sourceDiagnostics['notices'] ?? [], $sourceAddress,
        );
    }

    /**
     * @param  DraftAction  $action
     * @return list<array{code: string, field?: string}>
     */
    private function previewTarget(?ImportSource $source, int $siteId, EntryType $type, array &$action): array
    {
        $mapping = $source === null ? null : $this->mappingQuery($siteId, (int) $source->getKey(), $action['source_type'], $action['source_id'])->first();
        $entry = $mapping === null ? null : Entry::query()->where('site_id', $siteId)->find($mapping->entry_id);
        $expectedRevision = null;

        if ($mapping !== null) {
            if (! $this->usableDraft($entry, $action['entry_type_id'])) {
                return [['code' => 'mapped-draft-unavailable']];
            }

            $expectedRevision = $this->revisionId($entry, lock: false);
            if ($expectedRevision !== $mapping->entry_revision_id || self::entryState($entry) !== $mapping->entry_state_hash) {
                return [['code' => 'mapped-draft-changed']];
            }
        }

        $collision = Entry::withTrashed()->where('site_id', $siteId)->where('entry_type_id', $action['entry_type_id'])
            ->where('slug', $action['slug']);
        if ($entry !== null) {
            $collision->whereKeyNot($entry->getKey());
        }

        if ($collision->exists()) {
            return [['code' => 'destination-slug-in-use']];
        }

        try {
            DraftFieldValues::attributes($type, $entry, ['title' => $action['title'], 'slug' => $action['slug']], $action['values'] ?? []);
        } catch (ValidationException $failure) {
            $invalid = [];
            foreach (array_keys($failure->errors()) as $attribute) {
                if (preg_match('/^values\.([a-z0-9][a-z0-9_-]{0,95})(?:\.|$)/D', $attribute, $parts) === 1) {
                    $invalid[$parts[1]] = ['code' => 'invalid-field-values', 'field' => $parts[1]];
                }
            }

            return $invalid === [] ? [['code' => 'invalid-field-values']] : array_values($invalid);
        }

        $action['expected_revision_id'] = $expectedRevision;

        return [];
    }

    /**
     * @param  list<DraftAction>  $records
     * @param  Collection<int, EntryType>  $types
     * @param  list<ImportMediaAsset>  $media
     * @return list<array{position:int, code:string}>
     */
    private function relationFindings(array $records, ?ImportSource $source, int $siteId, Collection $types, array $media): array
    {
        $planned = [];
        foreach ($records as $action) {
            $planned[DraftRelations::identity($action['source_type'], $action['source_id'])] = $types->get($action['entry_type_id'])->handle;
        }

        foreach ($media as $asset) {
            if ($asset->entryTypeId !== null && $types->get($asset->entryTypeId)?->is_media === true) {
                $planned[DraftRelations::identity($asset->sourceType, $asset->sourceId)] = $types->get($asset->entryTypeId)->handle;
            }
        }

        $targetType = function (string $kind, string $id) use ($planned, $source, $siteId, $types): ?string {
            $key = DraftRelations::identity($kind, $id);
            if (isset($planned[$key])) {
                return $planned[$key];
            }

            $target = $source === null ? null : $this->mappedEntry($siteId, (int) $source->getKey(), $kind, $id);

            return $target !== null && $types->has($target->entry_type_id) ? $target->type_handle : null;
        };

        $findings = [];
        foreach ($records as $index => $action) {
            try {
                DraftRelations::validate($types->get($action['entry_type_id']), $action['links'] ?? [], $action['expected_revision_id'] === null, $targetType);
            } catch (DomainException $conflict) {
                $findings[] = ['position' => $index + 1, 'code' => $conflict->getMessage()];
            }
        }

        return $findings;
    }

    private function mappedEntry(int $siteId, int $sourceId, string $type, string $id): ?Entry
    {
        $mapping = $this->mappingQuery($siteId, $sourceId, $type, $id)->useWritePdo()->first();

        return $mapping === null ? null : Entry::query()->where('site_id', $siteId)->useWritePdo()->find($mapping->entry_id);
    }

    /** @return list<int> Reservation IDs in report order. */
    public function stageMedia(string $report, string $approvedDigest, string $root): array
    {
        $plan = $this->checkedPlan($report, $approvedDigest);
        if (! $plan->complete || $plan->media === []) {
            throw new DomainException('Staging requires a complete report with approved media.');
        }

        $source = $this->source($plan->sourceKey);
        $execution = $this->recordApproval((int) $source->getKey(), $approvedDigest);
        $this->transitionExecution($plan->siteId, $execution->id, 'active');

        return app(ImportMediaReservations::class)->stage($execution->id, $plan, $root);
    }

    /** @return list<int> Purged reservation IDs in report order. */
    public function purgeStagedMedia(string $report, string $approvedDigest): array
    {
        $plan = $this->checkedPlan($report, $approvedDigest);
        $execution = $this->executionForPlan($plan, $approvedDigest);
        if ($execution === null || ! in_array($execution->state, ['completed', 'abandoned'], true) || $plan->media === []) {
            throw new DomainException('Purging requires a completed or abandoned approved report with media.');
        }

        return app(ImportMediaReservations::class)->purgeStaged($execution->id, $plan);
    }

    /** @return list<int> Destination entry IDs in plan order. */
    public function apply(string $report, string $approvedDigest): array
    {
        $plan = $this->checkedPlan($report, $approvedDigest);
        if (! $plan->complete) {
            throw new DomainException('The incomplete report requires operator review.');
        }

        $recorded = $this->executionForPlan($plan, $approvedDigest);
        if ($recorded !== null && $recorded->state === 'completed') {
            $receipts = $this->receiptQuery($plan->siteId, $recorded->id)
                ->where('position', '<=', count($plan->records))->orderBy('position')->get();
            if ($receipts->count() !== count($plan->records)) {
                throw new RuntimeException('The completed import is missing action receipts.');
            }

            return $receipts->pluck('entry_id')->all();
        }

        $availableTypes = EntryType::visibleFor($this->context->site(), $plan->orgId)->keyBy('id');
        if (array_any($plan->records, static fn (array $action): bool => ! $availableTypes->has($action['entry_type_id']))) {
            throw new DomainException('A destination entry type is no longer available.');
        }

        foreach ($plan->media as $asset) {
            if ($asset->entryTypeId === null || $availableTypes->get($asset->entryTypeId)?->is_media !== true) {
                throw new DomainException('The report needs an available media type for final import custody.');
            }
        }

        $schema = ImportSchema::digest($availableTypes->only([
            ...array_column($plan->records, 'entry_type_id'), ...array_column($plan->media, 'entryTypeId'),
        ]));
        if (! hash_equals($plan->schemaDigest, $schema)) {
            throw new DomainException('The destination schema changed. Review a new import preview.');
        }

        $source = ImportSource::query()->where('site_id', $plan->siteId)->where('source_key', $plan->sourceKey)->first();
        if ($this->relationFindings($plan->records, $source, $plan->siteId, $availableTypes, $plan->media) !== []) {
            throw new DomainException('The approved draft relationships are no longer available.');
        }

        $source ??= $this->source($plan->sourceKey);
        $execution = $this->recordApproval((int) $source->getKey(), $approvedDigest);
        $this->transitionExecution($plan->siteId, $execution->id, 'active');

        app(ImportMediaReservations::class)->finalize($execution->id, $plan);

        $entries = [];
        foreach ($plan->records as $index => $action) {
            $receipt = $this->receiptQuery($plan->siteId, $execution->id)->where('position', $index + 1)->first();
            if ($receipt !== null) {
                $entries[] = $receipt->entry_id;

                continue;
            }

            $entry = $this->writeDraft(
                $execution->id,
                $action['source_type'],
                $action['source_id'],
                $action['entry_type_id'],
                ['title' => $action['title'], 'slug' => $action['slug'], 'values' => $action['values'] ?? []],
                $action['expected_revision_id'],
                $index + 1,
            );
            $entries[] = (int) $entry->getKey();
        }

        foreach ($plan->records as $index => $action) {
            if (($action['links'] ?? []) !== []) {
                $this->writeLinks($plan->siteId, $execution->id, count($plan->records) + $index + 1, $action, $availableTypes);
            }
        }
        $this->transitionExecution($plan->siteId, $execution->id, 'completed');

        return $entries;
    }

    /**
     * @param  DraftAction  $action
     * @param  Collection<int, EntryType>  $types
     */
    private function writeLinks(int $siteId, int $executionId, int $position, array $action, Collection $types): void
    {
        TransactionRecovery::run(DB::connection(), function () use ($siteId, $executionId, $position, $action, $types): void {
            $execution = ImportExecution::query()->where('site_id', $siteId)->lockForUpdate()->findOrFail($executionId);
            $source = ImportSource::query()->where('site_id', $siteId)->lockForUpdate()->findOrFail($execution->import_source_id);
            if ($this->receiptQuery($siteId, $executionId)->where('position', $position)->lockForUpdate()->exists()) {
                return;
            }
            if ($source->active_execution_id !== $execution->id) {
                throw new DomainException('The approved execution is no longer active.');
            }

            $mapping = $this->mappingQuery($siteId, $execution->import_source_id, $action['source_type'], $action['source_id'])
                ->lockForUpdate()->firstOrFail();
            $entry = Entry::query()->where('site_id', $siteId)->lockForUpdate()->find($mapping->entry_id);
            if (! $this->usableDraft($entry, $action['entry_type_id']) || $mapping->last_execution_id !== $executionId
                || $this->revisionId($entry) !== $mapping->entry_revision_id || self::entryState($entry) !== $mapping->entry_state_hash) {
                throw new DomainException('The destination draft changed before its relationship write.');
            }

            $links = $action['links'] ?? [];
            $resolved = [];
            $fields = DraftRelations::validate($types->get($action['entry_type_id']), $links, false,
                function (string $kind, string $id) use ($siteId, $execution, $types, &$resolved): ?string {
                    $target = $this->mappedEntry($siteId, $execution->import_source_id, $kind, $id);
                    if ($target === null || ! $types->has($target->entry_type_id)) {
                        return null;
                    }
                    $resolved[DraftRelations::identity($kind, $id)] = $target->id;

                    return $target->type_handle;
                });
            $sameSite = static fn (Entry $candidate): bool => $candidate->site_id === $siteId;
            foreach ($links as $handle => $refs) {
                $choices = array_map(static fn (array $ref): array => [
                    'entry' => $resolved[DraftRelations::identity($ref['source_type'], $ref['source_id'])],
                    'pin' => true,
                ], $refs);
                app(EntryRelationWriter::class)->replace($entry, $fields[$handle], $choices, $sameSite, $sameSite);
            }

            $entry->refresh();
            $revision = $this->revisionId($entry) ?? throw new RuntimeException('The related draft has no revision.');
            $mapping->forceFill(['entry_revision_id' => $revision, 'entry_state_hash' => self::entryState($entry)]);
            if (! $mapping->save()) {
                throw new RuntimeException('The import relationship checkpoint was refused.');
            }
            $this->saveReceipt($execution, $position, 'linked', $entry, $revision);
        });
    }

    public function abandon(string $report, string $approvedDigest): void
    {
        $plan = $this->checkedPlan($report, $approvedDigest);
        $execution = $this->executionForPlan($plan, $approvedDigest)
            ?? throw new DomainException('The approved execution does not exist.');

        $this->transitionExecution($plan->siteId, $execution->id, 'abandoned');
    }

    private function transitionExecution(int $siteId, int $executionId, string $next): void
    {
        TransactionRecovery::run(DB::connection(), function () use ($siteId, $executionId, $next): void {
            $execution = ImportExecution::query()->where('site_id', $siteId)->lockForUpdate()->findOrFail($executionId);
            $source = ImportSource::query()->where('site_id', $siteId)->lockForUpdate()->findOrFail($execution->import_source_id);

            if ($execution->state === $next || ($next === 'active' && $execution->state === 'completed')) {
                return;
            }

            if ($next === 'abandoned' && $execution->state === 'open') {
                $execution->state = 'abandoned';
                if (! $execution->save()) {
                    throw new RuntimeException('The import execution state change was refused.');
                }

                return;
            }

            if (in_array($execution->state, ['completed', 'abandoned'], true)) {
                throw new DomainException('The approved execution is already closed.');
            }

            if ($source->active_execution_id !== null && $source->active_execution_id !== $execution->id) {
                throw new DomainException('Another approved execution is active for this source.');
            }

            if ($next !== 'active' && $source->active_execution_id !== $execution->id) {
                throw new DomainException('Only the active approved execution can be closed.');
            }

            $source->active_execution_id = $next === 'active' ? $execution->id : null;
            $execution->state = $next;

            if (! $source->save() || ! $execution->save()) {
                throw new RuntimeException('The import execution state change was refused.');
            }
        });
    }

    /**
     * Read receipts for this exact approved plan. Pending includes actions
     * whose outcome was never committed.
     *
     * @return list<array{position: int, source_type: string, source_id: string, outcome: string, entry_id: ?int, revision_id: ?int}>
     */
    public function progress(string $report, string $approvedDigest): array
    {
        return $this->executionReport($report, $approvedDigest)['records'];
    }

    /**
     * @return array{
     *     state: string,
     *     records: list<array{position: int, source_type: string, source_id: string, outcome: string, entry_id: ?int, revision_id: ?int}>,
     *     media?: list<array{source_type: string, source_id: string, outcome: string, entry_id: ?int}>
     * }
     */
    public function executionReport(string $report, string $approvedDigest): array
    {
        $plan = $this->checkedPlan($report, $approvedDigest);
        $execution = $this->executionForPlan($plan, $approvedDigest);
        $receipts = $execution === null ? collect() : $this->receiptQuery($plan->siteId, $execution->id)->get()->keyBy('position');
        $progress = [];

        foreach ($plan->records as $index => $action) {
            $receipt = $receipts->get($index + 1);
            $progress[] = [
                'position' => $index + 1,
                'source_type' => $action['source_type'],
                'source_id' => $action['source_id'],
                'outcome' => $receipt->outcome ?? 'pending',
                'entry_id' => $receipt?->entry_id,
                'revision_id' => $receipt?->entry_revision_id,
            ];
        }

        foreach ($plan->records as $index => $action) {
            if (($action['links'] ?? []) !== []) {
                $position = count($plan->records) + $index + 1;
                $receipt = $receipts->get($position);
                $progress[] = [
                    'position' => $position, 'source_type' => $action['source_type'], 'source_id' => $action['source_id'],
                    'outcome' => $receipt->outcome ?? 'pending', 'entry_id' => $receipt?->entry_id, 'revision_id' => $receipt?->entry_revision_id,
                ];
            }
        }

        $report = ['state' => $execution->state ?? 'not-started', 'records' => $progress];
        if ($plan->media === []) {
            return $report;
        }

        $reservations = $execution === null ? collect() : ImportMediaReservation::query()->useWritePdo()
            ->where('site_id', $plan->siteId)->where('import_execution_id', $execution->id)->get()
            ->keyBy(static fn (ImportMediaReservation $reservation): string => DraftRelations::identity($reservation->source_type, $reservation->source_id_hash));
        $report['media'] = [];
        foreach ($plan->media as $asset) {
            $reservation = $reservations->get(DraftRelations::identity($asset->sourceType, hash('sha256', $asset->sourceId)));
            $outcome = 'pending';
            if ($reservation?->staged === true) {
                $outcome = match (true) {
                    $reservation->path === null => 'reused',
                    $reservation->entry_id !== null => 'finalized',
                    default => 'staged',
                };
            }

            $report['media'][] = [
                'source_type' => $asset->sourceType, 'source_id' => $asset->sourceId,
                'outcome' => $outcome, 'entry_id' => $reservation?->entry_id,
            ];
        }

        return $report;
    }

    private function executionForPlan(ImportPlan $plan, string $approvedDigest): ?ImportExecution
    {
        $source = ImportSource::query()->useWritePdo()->where('site_id', $plan->siteId)->where('source_key', $plan->sourceKey)->first();

        return $source === null ? null : ImportExecution::query()->useWritePdo()->where('site_id', $plan->siteId)
            ->where('import_source_id', $source->getKey())->where('plan_digest', $approvedDigest)->first();
    }

    private function checkedPlan(string $report, string $approvedDigest): ImportPlan
    {
        $plan = ImportPlan::restore($report);
        $siteId = $this->siteId();
        if (! hash_equals($plan->digest(), $approvedDigest)) {
            throw new DomainException('The import report differs from the approved report.');
        }

        if ($plan->siteId !== $siteId || $plan->orgId !== $this->context->orgId() || $plan->orgId !== $this->context->site()?->org_id) {
            throw new DomainException('The import report belongs to a different Org or Site.');
        }

        return $plan;
    }

    /** @return Builder<ImportActionReceipt> */
    private function receiptQuery(int $siteId, int $executionId): Builder
    {
        return ImportActionReceipt::query()->useWritePdo()->where('site_id', $siteId)->where('import_execution_id', $executionId);
    }

    private function source(string $key): ImportSource
    {
        $siteId = $this->siteId();

        if (! self::validKey($key)) {
            throw new InvalidArgumentException('Invalid import source identifier.');
        }

        return ImportSource::query()->where('site_id', $siteId)->firstOrCreate(['source_key' => $key]);
    }

    private function recordApproval(int $sourceId, string $planDigest): ImportExecution
    {
        $siteId = $this->siteId();

        if (preg_match('/\A[0-9a-f]{64}\z/', $planDigest) !== 1) {
            throw new InvalidArgumentException('Invalid import plan digest.');
        }

        $source = ImportSource::query()->where('site_id', $siteId)->findOrFail($sourceId);

        return ImportExecution::query()->where('site_id', $siteId)->firstOrCreate([
            'import_source_id' => $source->getKey(),
            'plan_digest' => $planDigest,
        ]);
    }

    /**
     * Apply one approved draft action. The plan supplies the expected target revision for updates.
     *
     * @param  array{title: string, slug: string, values?: array<string, mixed>}  $content
     */
    private function writeDraft(
        int $executionId,
        string $sourceType,
        string $sourceId,
        int $entryTypeId,
        array $content,
        ?int $expectedRevisionId,
        int $position,
    ): Entry {
        $siteId = $this->siteId();

        if (! self::validKey($sourceType) || $sourceId === '' || strlen($sourceId) > 4096) {
            throw new InvalidArgumentException('Invalid import record identity.');
        }

        $attributes = [
            'title' => $content['title'],
            'slug' => $content['slug'],
            ...array_intersect_key($content, ['values' => true]),
        ];

        return TransactionRecovery::run(DB::connection(), function () use (
            $siteId, $executionId, $sourceType, $sourceId, $entryTypeId, $attributes, $expectedRevisionId, $position
        ): Entry {
            $execution = ImportExecution::query()->where('site_id', $siteId)->lockForUpdate()->findOrFail($executionId);

            // Serialize actions for this source on row-locking engines. SQLite
            // can refuse a stale snapshot at the write and the caller can retry.
            $source = ImportSource::query()->where('site_id', $siteId)->lockForUpdate()->findOrFail($execution->import_source_id);
            $receipt = $this->receiptQuery($siteId, $execution->id)->where('position', $position)->lockForUpdate()->first();
            if ($receipt !== null) {
                return Entry::withTrashed()->where('site_id', $siteId)->findOrFail($receipt->entry_id);
            }
            if ($source->active_execution_id !== $execution->id) {
                throw new DomainException('The approved execution is no longer active.');
            }

            $identity = [
                'import_source_id' => $execution->import_source_id,
                'source_type' => $sourceType,
                'source_id_hash' => hash('sha256', $sourceId),
            ];

            $mapping = $this->mappingQuery($siteId, $execution->import_source_id, $sourceType, $sourceId)->lockForUpdate()->first();
            $creating = $mapping === null;
            if ($mapping === null) {
                if ($expectedRevisionId !== null) {
                    throw new RuntimeException('The expected import mapping no longer exists.');
                }

                $mapping = (new ImportMapping)->forceFill($identity);
                $entry = new Entry(['entry_type_id' => $entryTypeId, 'status' => 'draft']);
            } else {
                $entry = Entry::query()->where('site_id', $siteId)->lockForUpdate()->find($mapping->entry_id);
                if (! $this->usableDraft($entry, $entryTypeId)) {
                    throw new RuntimeException('The import mapping does not point to an available destination draft of the expected type.');
                }

                $revisionId = $this->revisionId($entry);
                if ($revisionId !== $mapping->entry_revision_id || self::entryState($entry) !== $mapping->entry_state_hash) {
                    throw new RuntimeException('The destination draft changed after the previous import.');
                }

                if ($mapping->last_execution_id === $execution->id) {
                    throw new RuntimeException('The applied draft is missing its action receipt.');
                }

                if ($revisionId !== $expectedRevisionId) {
                    throw new RuntimeException('The approved destination revision is stale.');
                }
            }

            $type = EntryType::query()->findOrFail($entryTypeId);
            $entry->fill(DraftFieldValues::attributes(
                $type, $creating ? null : $entry, ['title' => $attributes['title'], 'slug' => $attributes['slug']],
                $attributes['values'] ?? [],
            ));

            if (! $entry->save()) {
                throw new RuntimeException('The destination draft write was refused.');
            }

            $entry->refresh();
            $revisionId = $this->revisionId($entry) ?? throw new RuntimeException('The destination draft has no revision.');
            $mapping->forceFill([
                'entry_id' => $entry->getKey(),
                'entry_revision_id' => $revisionId,
                'entry_state_hash' => self::entryState($entry),
                'last_execution_id' => $execution->id,
            ]);

            if (! $mapping->save()) {
                throw new RuntimeException('The import mapping write was refused.');
            }

            $this->saveReceipt($execution, $position, $creating ? 'created' : 'updated', $entry, $revisionId);

            return $entry;
        });
    }

    private function saveReceipt(ImportExecution $execution, int $position, string $outcome, Entry $entry, int $revisionId): void
    {
        $receipt = (new ImportActionReceipt)->forceFill([
            'import_source_id' => $execution->import_source_id,
            'import_execution_id' => $execution->id,
            'position' => $position,
            'outcome' => $outcome,
            'entry_id' => $entry->getKey(),
            'entry_revision_id' => $revisionId,
        ]);

        if (! $receipt->save()) {
            throw new RuntimeException('The import action receipt was refused.');
        }
    }

    /** @return Builder<ImportMapping> */
    private function mappingQuery(int $siteId, int $sourceId, string $type, string $recordId): Builder
    {
        return ImportMapping::query()->where('site_id', $siteId)->where([
            'import_source_id' => $sourceId,
            'source_type' => $type,
            'source_id_hash' => hash('sha256', $recordId),
        ]);
    }

    /** @phpstan-assert-if-true Entry $entry */
    private function usableDraft(?Entry $entry, int $typeId): bool
    {
        return $entry !== null && $entry->entry_type_id === $typeId && $entry->status === 'draft';
    }

    private function revisionId(Entry $entry, bool $lock = true): ?int
    {
        $revisions = $entry->revisions()->latest('id');
        if ($lock) {
            $revisions->lockForUpdate();
        }
        $revision = $revisions->first();

        return $revision === null ? null : (int) $revision->getKey();
    }

    public static function entryState(Entry $entry): string
    {
        $attributes = $entry->getRawOriginal();
        ksort($attributes, SORT_STRING);

        return self::hashState([$attributes, $entry->relationState()]);
    }

    /** @param array<array-key, mixed> $state */
    private static function hashState(array $state): string
    {
        return hash('sha256', serialize($state));
    }

    private static function validKey(string $key): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9._:-]{0,95}$/D', $key) === 1;
    }

    private function siteId(): int
    {
        return $this->context->siteId() ?? throw new LogicException('The import journal requires a destination Site.');
    }
}
