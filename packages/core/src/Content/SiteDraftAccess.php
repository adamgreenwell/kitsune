<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Content;

use Closure;
use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Database\EntryWriteReads;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/** @internal Resolves the current Site and draft authorization for field inspection and writing. */
final readonly class SiteDraftAccess
{
    public function __construct(private Context $context) {}

    public function activeActor(Authenticatable $actor): Authenticatable
    {
        $current = Permissions::currentUser();
        if ($current === null || $current::class !== $actor::class || $current->getAuthIdentifier() === null
            || (string) $current->getAuthIdentifier() !== (string) $actor->getAuthIdentifier()) {
            throw new DomainException('Draft access requires the active staff actor.');
        }

        return $current;
    }

    public function revision(Entry $entry): ?int
    {
        $id = $entry->revisions()->latest('id')->lockForUpdate()->value('id');

        return $id === null ? null : (int) $id;
    }

    public function site(int $siteId): Site
    {
        $orgId = $this->context->orgId();
        $site = $orgId === null || $siteId !== $this->context->siteId() ? null
            : EntryWriteReads::query(Site::query()->useWritePdo())->where('org_id', $orgId)->find($siteId);
        if ($site === null) {
            throw new DomainException('The selected draft fields are unavailable.');
        }

        return $site;
    }

    public function type(Site $site, string $handle, Authenticatable $actor): EntryType
    {
        $type = $this->typeQuery($site->org_id, $handle)
            ->with([
                'fields' => static fn ($query) => EntryWriteReads::query($query->getQuery()->useWritePdo()),
                'fields.fieldStorage' => static fn ($query) => EntryWriteReads::query($query->getQuery()->useWritePdo()),
            ])->first();
        Permissions::forget();
        if ($type === null || $type->is_media || ! EntryTypeAvailability::isEnabledFor($type, $site)
            || ! Permissions::allows($actor, Permissions::forEntryType($handle, 'view'))) {
            throw new DomainException('The selected draft fields are unavailable.');
        }

        return $type;
    }

    public function writeTarget(int $siteId, EntryType $type, Authenticatable $actor, ?int $entryId, bool $lock = false): ?Entry
    {
        if ($entryId === null) {
            if (! Permissions::allows($actor, Permissions::forEntryType($type->handle, 'create'))) {
                throw new DomainException('The selected draft fields are unavailable.');
            }

            return null;
        }

        $entry = Entry::query()->useWritePdo()->where('org_id', $this->context->orgId())
            ->where('site_id', $siteId)->where('entry_type_id', $type->getKey())->where('status', 'draft')
            ->when($lock, static fn (Builder $query) => $query->lockForUpdate())->find($entryId);
        if ($entry === null || ! Gate::forUser($actor)->allows('update', $entry)) {
            throw new DomainException('The selected draft fields are unavailable.');
        }

        return $entry;
    }

    /** @return Closure(Entry): bool */
    public function relationReader(Site $site, Authenticatable $actor): Closure
    {
        $viewable = [];

        return function (Entry $target) use ($site, $actor, &$viewable): bool {
            if ($target->org_id !== $site->org_id || ($target->site_id !== null && $target->site_id !== $site->id)) {
                return false;
            }
            if (! array_key_exists($target->type_handle, $viewable)) {
                $viewable[$target->type_handle] = $this->viewableTypeId($site, $target->type_handle, $actor);
            }

            return $viewable[$target->type_handle] === $target->entry_type_id && Gate::forUser($actor)->allows('view', $target);
        };
    }

    private function viewableTypeId(Site $site, string $handle, Authenticatable $actor): ?int
    {
        $type = $this->typeQuery($site->org_id, $handle)->first();

        return $type !== null && EntryTypeAvailability::isEnabledFor($type, $site)
            && Permissions::allows($actor, Permissions::forEntryType($type->handle, 'view')) ? (int) $type->getKey() : null;
    }

    /** @return Builder<EntryType> */
    private function typeQuery(int $orgId, string $handle): Builder
    {
        // Use current definitions even when navigation has memoized the list of available types.
        return EntryWriteReads::query(EntryType::query()->useWritePdo())->where('handle', $handle)
            ->where(static fn (Builder $query) => $query->whereNull('org_id')->orWhere('org_id', $orgId))
            ->orderByRaw('CASE WHEN org_id IS NULL THEN 1 ELSE 0 END');
    }
}
