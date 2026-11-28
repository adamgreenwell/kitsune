<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Content;

use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/** @internal Resolves the current Site and draft authorization for field inspection and writing. */
final readonly class SiteDraftAccess
{
    public function __construct(private Context $context) {}

    public function site(int $siteId): Site
    {
        $orgId = $this->context->orgId();
        $site = $orgId === null || $siteId !== $this->context->siteId() ? null
            : Site::query()->useWritePdo()->where('org_id', $orgId)->find($siteId);
        if ($site === null) {
            throw new DomainException('The selected draft fields are unavailable.');
        }

        return $site;
    }

    public function type(Site $site, string $handle, Authenticatable $actor): EntryType
    {
        $type = $this->typeQuery($site->org_id, $handle)
            ->with([
                'fields' => static fn ($query) => $query->useWritePdo(),
                'fields.fieldStorage' => static fn ($query) => $query->useWritePdo(),
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

    public function viewableTypeId(Site $site, string $handle, Authenticatable $actor): ?int
    {
        $type = $this->typeQuery($site->org_id, $handle)->first();

        return $type !== null && EntryTypeAvailability::isEnabledFor($type, $site)
            && Permissions::allows($actor, Permissions::forEntryType($type->handle, 'view')) ? (int) $type->getKey() : null;
    }

    /** @return Builder<EntryType> */
    private function typeQuery(int $orgId, string $handle): Builder
    {
        // Use current definitions even when navigation has memoized the list of available types.
        return EntryType::query()->useWritePdo()->where('handle', $handle)
            ->where(static fn (Builder $query) => $query->whereNull('org_id')->orWhere('org_id', $orgId))
            ->orderByRaw('CASE WHEN org_id IS NULL THEN 1 ELSE 0 END');
    }
}
