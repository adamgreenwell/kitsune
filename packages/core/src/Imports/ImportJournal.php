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
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\ImportActionReceipt;
use Kitsune\Core\Models\ImportExecution;
use Kitsune\Core\Models\ImportMapping;
use Kitsune\Core\Models\ImportSource;
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
 */
final readonly class ImportJournal
{
    public function __construct(private Context $context) {}

    /** @param list<array<string, mixed>> $drafts Draft records from the source adapter. */
    public function preview(string $sourceKey, string $adapter, string $snapshot, array $drafts, bool $sourceComplete): ImportPlan
    {
        $siteId = $this->siteId();
        $orgId = $this->context->orgId() ?? throw new LogicException('The import journal requires a destination Org.');
        $source = ImportSource::query()->where('site_id', $siteId)->where('source_key', $sourceKey)->first();
        $availableTypes = EntryType::visibleFor($this->context->site(), $orgId)->keyBy('id');
        $findings = $sourceComplete ? [] : [['position' => 0, 'code' => 'source-incomplete']];
        $records = [];
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
            $conflict = match (true) {
                isset($identities[$identity]) => 'repeated-source-identity',
                isset($slugs[$slug]) => 'repeated-destination-slug',
                ! $availableTypes->has($action['entry_type_id']) => 'unavailable-entry-type',
                default => $this->previewTarget($source, $siteId, $action),
            };

            $identities[$identity] = true;
            $slugs[$slug] = true;
            if ($conflict !== null) {
                $findings[] = ['position' => $position, 'code' => $conflict];

                continue;
            }

            $records[] = $action;
        }

        return ImportPlan::prepare($orgId, $siteId, $sourceKey, $adapter, $snapshot, $records, $findings);
    }

    /** @param DraftAction $action */
    private function previewTarget(?ImportSource $source, int $siteId, array &$action): ?string
    {
        $mapping = $source === null ? null : $this->mappingQuery($siteId, (int) $source->getKey(), $action['source_type'], $action['source_id'])->first();
        $entry = $mapping === null ? null : Entry::query()->where('site_id', $siteId)->find($mapping->entry_id);
        $expectedRevision = null;

        if ($mapping !== null) {
            if (! $this->usableDraft($entry, $action['entry_type_id'])) {
                return 'mapped-draft-unavailable';
            }

            $expectedRevision = $this->revisionId($entry, lock: false);
            if ($expectedRevision !== $mapping->entry_revision_id || self::entryState($entry) !== $mapping->entry_state_hash) {
                return 'mapped-draft-changed';
            }
        }

        $collision = Entry::withTrashed()->where('site_id', $siteId)->where('entry_type_id', $action['entry_type_id'])
            ->where('slug', $action['slug']);
        if ($entry !== null) {
            $collision->whereKeyNot($entry->getKey());
        }

        if ($collision->exists()) {
            return 'destination-slug-in-use';
        }

        $action['expected_revision_id'] = $expectedRevision;

        return null;
    }

    /** @return list<int> Destination entry IDs in plan order. */
    public function apply(string $report, string $approvedDigest): array
    {
        $plan = $this->checkedPlan($report, $approvedDigest);
        if (! $plan->complete) {
            throw new DomainException('The incomplete report requires operator review.');
        }

        $availableTypes = EntryType::visibleFor($this->context->site(), $plan->orgId)->keyBy('id');
        if (array_any($plan->records, static fn (array $action): bool => ! $availableTypes->has($action['entry_type_id']))) {
            throw new DomainException('A destination entry type is no longer available.');
        }

        $source = $this->source($plan->sourceKey);
        $execution = $this->recordApproval((int) $source->getKey(), $approvedDigest);

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
                ['title' => $action['title'], 'slug' => $action['slug']],
                $action['expected_revision_id'],
                $index + 1,
            );
            $entries[] = (int) $entry->getKey();
        }

        return $entries;
    }

    /**
     * Read receipts for this exact approved plan. Pending includes actions
     * whose outcome was never committed.
     *
     * @return list<array{position: int, source_type: string, source_id: string, outcome: string, entry_id: ?int, revision_id: ?int}>
     */
    public function progress(string $report, string $approvedDigest): array
    {
        $plan = $this->checkedPlan($report, $approvedDigest);
        $source = ImportSource::query()->useWritePdo()->where('site_id', $plan->siteId)->where('source_key', $plan->sourceKey)->first();
        $execution = $source === null ? null : ImportExecution::query()->useWritePdo()->where('site_id', $plan->siteId)
            ->where('import_source_id', $source->getKey())->where('plan_digest', $approvedDigest)->first();
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

        return $progress;
    }

    private function checkedPlan(string $report, string $approvedDigest): ImportPlan
    {
        $plan = ImportPlan::restore($report);
        $siteId = $this->siteId();
        if (! hash_equals($plan->digest(), $approvedDigest)) {
            throw new DomainException('The import report differs from the approved report.');
        }

        if ($plan->siteId !== $siteId || $plan->orgId !== $this->context->orgId()) {
            throw new DomainException('The import report belongs to a different Org or Site.');
        }

        return $plan;
    }

    /** @return Builder<ImportActionReceipt> */
    private function receiptQuery(int $siteId, int $executionId): Builder
    {
        return ImportActionReceipt::query()->useWritePdo()->where('site_id', $siteId)->where('import_execution_id', $executionId);
    }

    public function source(string $key): ImportSource
    {
        $siteId = $this->siteId();

        if (! self::validKey($key)) {
            throw new InvalidArgumentException('Invalid import source identifier.');
        }

        return ImportSource::query()->where('site_id', $siteId)->firstOrCreate(['source_key' => $key]);
    }

    public function recordApproval(int $sourceId, string $planDigest): ImportExecution
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
    public function writeDraft(
        int $executionId,
        string $sourceType,
        string $sourceId,
        int $entryTypeId,
        array $content,
        ?int $expectedRevisionId,
        ?int $position = null,
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
        $actionHash = self::hashState([$entryTypeId, $expectedRevisionId, $attributes]);

        return TransactionRecovery::run(DB::connection(), function () use (
            $siteId, $executionId, $sourceType, $sourceId, $entryTypeId, $attributes, $expectedRevisionId, $actionHash, $position
        ): Entry {
            $execution = ImportExecution::query()->where('site_id', $siteId)->lockForUpdate()->findOrFail($executionId);

            // Serialize actions for this source on row-locking engines. SQLite
            // can refuse a stale snapshot at the write and the caller can retry.
            ImportSource::query()->where('site_id', $siteId)->lockForUpdate()->findOrFail($execution->import_source_id);

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
                    if ($mapping->action_hash !== $actionHash) {
                        throw new RuntimeException('The retried action differs from the action already applied.');
                    }

                    return $entry;
                }

                if ($revisionId !== $expectedRevisionId) {
                    throw new RuntimeException('The approved destination revision is stale.');
                }
            }

            $entry->fill($attributes);

            if (! $entry->save()) {
                throw new RuntimeException('The destination draft write was refused.');
            }

            $entry->refresh();
            $revisionId = $this->revisionId($entry) ?? throw new RuntimeException('The destination draft has no revision.');
            $mapping->forceFill([
                'entry_id' => $entry->getKey(),
                'entry_revision_id' => $revisionId,
                'entry_state_hash' => self::entryState($entry),
                'action_hash' => $actionHash,
                'last_execution_id' => $execution->id,
            ]);

            if (! $mapping->save()) {
                throw new RuntimeException('The import mapping write was refused.');
            }

            if ($position !== null) {
                $receipt = (new ImportActionReceipt)->forceFill([
                    'import_source_id' => $execution->import_source_id,
                    'import_execution_id' => $execution->id,
                    'position' => $position,
                    'outcome' => $creating ? 'created' : 'updated',
                    'entry_id' => $entry->getKey(),
                    'entry_revision_id' => $revisionId,
                ]);

                if (! $receipt->save()) {
                    throw new RuntimeException('The import action receipt was refused.');
                }
            }

            return $entry;
        });
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

    private static function entryState(Entry $entry): string
    {
        $attributes = $entry->getRawOriginal();
        ksort($attributes, SORT_STRING);

        return self::hashState($attributes);
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
