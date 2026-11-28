<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Imports;

use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Media\MediaBytes;
use Kitsune\Core\Media\MediaCustodyFailure;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\ImportExecution;
use Kitsune\Core\Models\ImportMapping;
use Kitsune\Core\Models\ImportMediaReservation;
use Kitsune\Core\Models\ImportSource;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;

/**
 * Record the selected storage key before a provider is asked to stage an approved asset.
 *
 * @internal
 */
final readonly class ImportMediaReservations
{
    public function __construct(private Context $context) {}

    /** @return list<int> */
    public function stage(int $executionId, ImportPlan $plan, string $root): array
    {
        $reservations = [];
        foreach ($plan->media as $asset) {
            $reservation = $this->reserve($executionId, $plan, $asset);
            $this->stageReserved($executionId, $plan, $reservation->id, $asset, $root);
            $reservations[] = $reservation->id;
        }

        return $reservations;
    }

    public function finalize(int $executionId, ImportPlan $plan): void
    {
        foreach ($plan->media as $asset) {
            $this->prepareManaged($executionId, $plan, $asset);
            TransactionRecovery::run(DB::connection(), function () use ($executionId, $plan, $asset): void {
                $execution = $this->lockExecution($executionId, $plan, ['completed']);
                if ($execution->state === 'completed') {
                    return;
                }

                $reservation = $this->stagedReservation($plan, $executionId, $asset);

                if ($reservation->entry_id !== null) {
                    $entry = $this->verifyManaged($plan->siteId, $reservation);
                    if ($reservation->path === null) {
                        $mapping = $this->sourceMapping($plan->siteId, $reservation->import_source_id, $asset, lock: true);
                        $this->verifyMappedState($mapping, $entry);
                    }

                    return;
                }

                $hash = $reservation->managed_sha256 ?? throw new DomainException('The prepared media checksum is unavailable.');
                $this->verifyManagedVersion($reservation);
                MediaLibrary::adoptReservedPrivate($asset->originalName, $this->availableMediaType($plan, $asset),
                    $reservation->managed_path, $hash, function (Entry $entry) use ($execution, $reservation, $asset): void {
                        $this->recordMapping($execution, $reservation, $entry, $asset);
                    });
            });
        }
    }

    private function prepareManaged(int $executionId, ImportPlan $plan, ImportMediaAsset $asset): void
    {
        $reservation = TransactionRecovery::run(DB::connection(), function () use ($executionId, $plan, $asset): ?ImportMediaReservation {
            if ($this->lockExecution($executionId, $plan, ['completed'])->state === 'completed') {
                return null;
            }

            $reservation = $this->stagedReservation($plan, $executionId, $asset);
            if ($reservation->entry_id !== null || $this->preparedObjectExists($reservation)) {
                return null;
            }

            return $reservation;
        });
        if ($reservation === null) {
            return;
        }

        $path = $reservation->path ?? throw new DomainException('The media staging path is unavailable.');
        $this->verifyStagedVersion($reservation, $path);
        $temporary = MediaBytes::toTemporary($reservation->disk, $path, $reservation->content_sha256);
        try {
            $this->verifyStagedVersion($reservation, $path);
            MediaLibrary::prepareReservedPrivate($temporary, $asset->originalName, $this->availableMediaType($plan, $asset),
                function (string $prepared, string $hash) use ($executionId, $plan, $asset): void {
                    $this->installPrepared($plan, $executionId, $asset, $prepared, $hash);
                });
        } finally {
            @unlink($temporary);
        }
    }

    private function stagedReservation(ImportPlan $plan, int $executionId, ImportMediaAsset $asset): ImportMediaReservation
    {
        $reservation = $this->lockReservation($plan, $executionId, $asset);
        if ($reservation === null || ! $reservation->staged || $reservation->disk !== MediaLibrary::diskFor('private')) {
            throw new DomainException('Stage the approved media before applying the report.');
        }

        return $reservation;
    }

    private function availableMediaType(ImportPlan $plan, ImportMediaAsset $asset): EntryType
    {
        $type = EntryType::query()->where(fn ($query) => $query->whereNull('org_id')->orWhere('org_id', $plan->orgId))
            ->where('is_media', true)->find($asset->entryTypeId);
        if ($type === null || ! EntryTypeAvailability::isEnabledFor($type, $this->context->site())) {
            throw new DomainException('The approved media type is unavailable for this Site.');
        }

        return $type;
    }

    private function preparedObjectExists(ImportMediaReservation $reservation): bool
    {
        $actual = MediaBytes::hash($reservation->disk, $reservation->managed_path);
        if ($actual === null) {
            return false;
        }
        if (! MediaBytes::same($reservation->managed_sha256, $actual)) {
            throw new DomainException('The managed object differs from the prepared media checksum.');
        }

        $this->verifyManagedVersion($reservation);

        return true;
    }

    private function verifyManagedVersion(ImportMediaReservation $reservation): void
    {
        if ($reservation->managed_object_version !== null
            && $reservation->managed_object_version !== MediaBytes::reservedObjectVersion($reservation->disk, $reservation->managed_path)) {
            throw new DomainException('The managed object differs from the recorded provider version.');
        }
    }

    private function installPrepared(ImportPlan $plan, int $executionId, ImportMediaAsset $asset, string $prepared, string $hash): void
    {
        $commitChecksum = TransactionRecovery::run(DB::connection(), function () use ($plan, $executionId, $asset, $hash): bool {
            if ($this->lockExecution($executionId, $plan, ['completed'])->state === 'completed') {
                return false;
            }

            $reservation = $this->stagedReservation($plan, $executionId, $asset);
            if ($reservation->entry_id !== null || $this->preparedObjectExists($reservation)) {
                return false;
            }

            if ($reservation->managed_sha256 !== null) {
                if ($reservation->managed_sha256 !== $hash) {
                    throw new DomainException('The preparation output changed after the checksum was recorded. Review the approved report.');
                }

                return true;
            }

            $reservation->managed_sha256 = $hash;
            if (! $reservation->save()) {
                throw new RuntimeException('The prepared media checksum could not be recorded.');
            }

            return true;
        });
        if (! $commitChecksum) {
            return;
        }

        TransactionRecovery::run(DB::connection(), function () use ($plan, $executionId, $asset, $prepared, $hash): void {
            if ($this->lockExecution($executionId, $plan, ['completed'])->state === 'completed') {
                return;
            }

            $reservation = $this->stagedReservation($plan, $executionId, $asset);
            if ($reservation->entry_id !== null || $this->preparedObjectExists($reservation)) {
                return;
            }
            if ($reservation->managed_sha256 !== $hash) {
                throw new DomainException('Another attempt prepared the managed media. Retry the approved report.');
            }

            $version = MediaBytes::createVerified($reservation->disk, $reservation->managed_path, $prepared, $hash);
            if ($version !== null) {
                $reservation->managed_object_version = $version;
                if (! $reservation->save()) {
                    throw new RuntimeException('The managed object version could not be recorded.');
                }
            }
        });
    }

    /** @return list<int> */
    public function purgeStaged(int $executionId, ImportPlan $plan): array
    {
        $purged = [];
        foreach ($plan->media as $asset) {
            $id = TransactionRecovery::run(DB::connection(), function () use ($executionId, $plan, $asset): ?int {
                $execution = $this->lockExecution($executionId, $plan, ['completed', 'abandoned']);
                if (! in_array($execution->state, ['completed', 'abandoned'], true)) {
                    throw new DomainException('Complete or abandon the approved import before purging staged media.');
                }

                $reservation = $this->lockReservation($plan, $executionId, $asset);
                if ($reservation === null && $execution->state === 'abandoned') {
                    return null;
                }
                if ($reservation === null || $reservation->disk !== MediaLibrary::diskFor('private')
                    || ($execution->state === 'completed' && $reservation->entry_id === null)) {
                    throw new DomainException('The closed import has no media reservation for this asset.');
                }
                if ($reservation->path === null) {
                    return null;
                }
                if (! $reservation->staging_purged) {
                    $this->purgeReservation($plan, $reservation);
                    $reservation->staging_purged = true;
                    if (! $reservation->save()) {
                        throw new RuntimeException('The staging cleanup receipt was refused.');
                    }
                }

                return $reservation->id;
            });
            if ($id !== null) {
                $purged[] = $id;
            }
        }

        return $purged;
    }

    private function purgeReservation(ImportPlan $plan, ImportMediaReservation $reservation): void
    {
        $path = $reservation->path ?? throw new DomainException('The media staging path is unavailable.');
        if ($reservation->entry_id === null && MediaBytes::containsReservedObject($reservation->disk, $reservation->managed_path)) {
            $this->purgeUncommittedManaged($reservation);
        }

        if (! $reservation->staged && ! MediaBytes::containsReservedObject($reservation->disk, $path)) {
            return;
        }

        try {
            MediaBytes::purgeReserved($reservation->disk, $path, $reservation->content_sha256,
                $reservation->staged_object_version, function () use ($plan, $reservation): void {
                    if ($reservation->entry_id !== null) {
                        $this->verifyManaged($plan->siteId, $reservation);
                    }
                });
        } catch (MediaCustodyFailure $failure) {
            if ($failure->reason === 'verify' && $failure->path === $path) {
                throw new DomainException('The file at the staging path differs from the approved asset.', previous: $failure);
            }

            throw $failure;
        }
    }

    private function purgeUncommittedManaged(ImportMediaReservation $reservation): void
    {
        $hash = $reservation->managed_sha256;
        $version = $reservation->managed_object_version;
        if ($hash === null || (! MediaBytes::local($reservation->disk) && ($version === null || $version === 'null'))) {
            throw new DomainException('The abandoned reservation has an uncommitted managed file that requires review.');
        }

        MediaBytes::purgeReserved($reservation->disk, $reservation->managed_path, $hash, $version, static function () use ($reservation): void {
            // Media paths are globally unique. A registered file is outside abandoned-import cleanup.
            if (MediaFile::query()->where('path', $reservation->managed_path)->lockForUpdate()->exists()) {
                throw new DomainException('The managed path belongs to a registered media file.');
            }
        });
    }

    private function lockReservation(ImportPlan $plan, int $executionId, ImportMediaAsset $asset): ?ImportMediaReservation
    {
        return ImportMediaReservation::query()->where('site_id', $plan->siteId)->where('import_execution_id', $executionId)
            ->where('source_type', $asset->sourceType)->where('source_id_hash', hash('sha256', $asset->sourceId))
            ->lockForUpdate()->first();
    }

    private function verifyManaged(int $siteId, ImportMediaReservation $reservation, bool $lock = true): Entry
    {
        $entry = Entry::query()->where('site_id', $siteId)->when($lock, fn ($query) => $query->lockForUpdate())
            ->findOrFail($reservation->entry_id);
        $file = $entry->mediaFile()->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        if ($file->path !== $reservation->managed_path || $file->disk !== $reservation->disk
            || $file->visibility !== 'private'
            || ($reservation->managed_sha256 !== null && ! MediaBytes::same($reservation->managed_sha256, $file->checksum))
            || ! MediaBytes::same($file->checksum, MediaBytes::hash($file->disk, $file->path))) {
            throw new DomainException('The finalized media no longer matches its reservation.');
        }

        $this->verifyManagedVersion($reservation);

        return $entry;
    }

    private function sourceMapping(int $siteId, int $sourceId, ImportMediaAsset $asset, bool $lock): ?ImportMapping
    {
        return ImportMapping::query()->where('site_id', $siteId)->where('import_source_id', $sourceId)
            ->where('source_type', $asset->sourceType)->where('source_id_hash', hash('sha256', $asset->sourceId))
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
    }

    private function verifyMappedState(?ImportMapping $mapping, Entry $entry): void
    {
        if ($mapping === null || $mapping->entry_id !== $entry->id
            || $mapping->entry_revision_id !== $entry->revisions()->latest('id')->first()?->id
            || ! hash_equals($mapping->entry_state_hash, ImportJournal::entryState($entry))) {
            throw new DomainException('The mapped media entry has changed since the import.');
        }
    }

    public function previewConflict(?ImportSource $source, int $siteId, ImportMediaAsset $asset): ?string
    {
        if ($source === null) {
            return null;
        }

        try {
            $this->previousAsset($siteId, $source->id, $asset, lock: false);
        } catch (DomainException|ModelNotFoundException|MediaCustodyFailure) {
            return 'mapped-media-conflict';
        }

        return null;
    }

    private function previousAsset(int $siteId, int $sourceId, ImportMediaAsset $asset, bool $lock): ?ImportMediaReservation
    {
        $mapping = $this->sourceMapping($siteId, $sourceId, $asset, $lock);
        $reservations = ImportMediaReservation::query()->where('site_id', $siteId)->where('import_source_id', $sourceId)
            ->where('source_type', $asset->sourceType)->where('source_id_hash', hash('sha256', $asset->sourceId))
            ->when($lock, fn ($query) => $query->lockForUpdate());
        if ($mapping === null) {
            if ($reservations->where('staging_purged', false)
                ->whereHas('execution', fn ($query) => $query->where('state', 'abandoned'))->exists()) {
                throw new DomainException('An abandoned media reservation requires review.');
            }

            return null;
        }

        $previous = $reservations->where('import_execution_id', $mapping->last_execution_id)->first();
        if ($previous === null || $previous->entry_id !== $mapping->entry_id
            || $previous->descriptor_sha256 !== $asset->descriptorHash() || $previous->disk !== MediaLibrary::diskFor('private')) {
            throw new DomainException('The approved asset conflicts with an existing media mapping.');
        }

        $this->verifyMappedState($mapping, $this->verifyManaged($siteId, $previous, $lock));

        return $previous;
    }

    private function recordMapping(ImportExecution $execution, ImportMediaReservation $reservation, Entry $entry, ImportMediaAsset $asset): void
    {
        $entry->refresh();
        $revision = $entry->revisions()->latest('id')->firstOrFail();
        $mapping = (new ImportMapping)->forceFill([
            'import_source_id' => $execution->import_source_id,
            'source_type' => $asset->sourceType,
            'source_id_hash' => hash('sha256', $asset->sourceId),
            'entry_id' => $entry->id,
            'entry_revision_id' => $revision->getKey(),
            'entry_state_hash' => ImportJournal::entryState($entry),
            'last_execution_id' => $execution->id,
        ]);
        $reservation->entry_id = $entry->id;
        if (! $mapping->save() || ! $reservation->save()) {
            throw new RuntimeException('The managed media mapping was refused.');
        }
    }

    private function writeReserved(string $root, ImportMediaAsset $asset, ImportMediaReservation $reservation, string $path): void
    {
        ImportMediaInput::withVerifiedCopy($root, $asset,
            fn (string $copy) => MediaBytes::createVerified(
                $reservation->disk, $path, $copy, $reservation->content_sha256
            ));
    }

    private function reserve(int $executionId, ImportPlan $plan, ImportMediaAsset $asset): ImportMediaReservation
    {
        return TransactionRecovery::run(DB::connection(), function () use ($executionId, $plan, $asset): ImportMediaReservation {
            $execution = $this->lockExecution($executionId, $plan);
            $siteId = $plan->siteId;
            $orgId = $plan->orgId;
            $disk = MediaLibrary::diskFor('private');
            $descriptor = $asset->descriptorHash();
            $key = ['import_source_id' => $execution->import_source_id, 'source_type' => $asset->sourceType, 'source_id_hash' => hash('sha256', $asset->sourceId)];

            $reservation = $this->lockReservation($plan, $executionId, $asset);
            if ($reservation !== null) {
                if ($reservation->descriptor_sha256 !== $descriptor || $reservation->disk !== $disk) {
                    throw new DomainException('The media identity already has a different approved reservation.');
                }

                return $reservation;
            }

            $prior = $this->previousAsset($siteId, $execution->import_source_id, $asset, lock: true);
            $reservation = (new ImportMediaReservation)->forceFill([
                ...$key,
                'import_execution_id' => $executionId,
                'descriptor_sha256' => $descriptor,
                'disk' => $disk,
                'path' => $prior === null ? sprintf('imports/staging/%d/%d/%s', $orgId, $siteId, bin2hex(random_bytes(16))) : null,
                'managed_path' => $prior->managed_path ?? MediaLibrary::reservePrivatePath($asset->originalName),
                'managed_sha256' => $prior?->managed_sha256,
                'managed_object_version' => $prior?->managed_object_version,
                'entry_id' => $prior?->entry_id,
                'staged' => $prior !== null,
                'content_sha256' => $asset->sha256,
                'source_size_bytes' => $asset->size,
            ]);
            if (! $reservation->save()) {
                throw new RuntimeException('The import media reservation was refused.');
            }

            return $reservation;
        });
    }

    private function stageReserved(int $executionId, ImportPlan $plan, int $reservationId, ImportMediaAsset $asset, string $root): void
    {
        TransactionRecovery::run(DB::connection(), function () use ($executionId, $plan, $reservationId, $asset, $root): void {
            $execution = $this->lockExecution($executionId, $plan);
            $reservation = ImportMediaReservation::query()->where('site_id', $this->context->siteId())
                ->where('import_source_id', $execution->import_source_id)->where('import_execution_id', $executionId)
                ->lockForUpdate()->findOrFail($reservationId);

            if ($reservation->disk !== MediaLibrary::diskFor('private')) {
                throw new DomainException('The approved private disk is no longer configured.');
            }
            $path = $reservation->path;
            if ($path === null) {
                return;
            }
            if (! $reservation->staged && MediaBytes::hash($reservation->disk, $path) === null) {
                $this->writeReserved($root, $asset, $reservation, $path);
            }

            $version = MediaBytes::reservedObjectVersion($reservation->disk, $path);
            if (! MediaBytes::same($reservation->content_sha256, MediaBytes::hash($reservation->disk, $path))
                || Storage::disk($reservation->disk)->size($path) !== $reservation->source_size_bytes
                || $version !== MediaBytes::reservedObjectVersion($reservation->disk, $path)
                || ($reservation->staged && $version !== $reservation->staged_object_version)) {
                throw new DomainException('The reserved media object does not match the approved asset.');
            }

            if (! $reservation->staged) {
                $reservation->staged = true;
                $reservation->staged_object_version = $version;
                if (! $reservation->save()) {
                    throw new RuntimeException('The staged media receipt was refused.');
                }
            }
        });
    }

    private function verifyStagedVersion(ImportMediaReservation $reservation, string $path): void
    {
        if ($reservation->staged_object_version !== MediaBytes::reservedObjectVersion($reservation->disk, $path)) {
            throw new DomainException('The staged media version differs from the approved reservation.');
        }
    }

    /** @param list<string> $acceptedClosedStates */
    private function lockExecution(int $executionId, ImportPlan $plan, array $acceptedClosedStates = []): ImportExecution
    {
        $siteId = $this->context->siteId() ?? throw new DomainException('The import media reservation requires a destination Site.');
        if ($siteId !== $plan->siteId || $this->context->orgId() !== $plan->orgId || ! $plan->complete) {
            throw new DomainException('The media plan does not belong to the current approved scope.');
        }
        $execution = ImportExecution::query()->where('site_id', $siteId)->where('plan_digest', $plan->digest())
            ->lockForUpdate()->findOrFail($executionId);
        if (in_array($execution->state, $acceptedClosedStates, true)) {
            return $execution;
        }

        $source = ImportSource::query()->where('site_id', $siteId)->lockForUpdate()->findOrFail($execution->import_source_id);
        if ($execution->state !== 'active' || $source->active_execution_id !== $executionId) {
            throw new DomainException('The approved import execution is no longer active.');
        }

        return $execution;
    }
}
