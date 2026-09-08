<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Resources\EntryTypes\Pages;

use Filament\Resources\Pages\CreateRecord;
use Kitsune\Core\Filament\Resources\EntryTypes\EntryTypeResource;
use Kitsune\Core\Tenancy\Context;

class CreateEntryType extends CreateRecord
{
    protected static string $resource = EntryTypeResource::class;

    /**
     * Stamp the org, because EntryType is `#[Unscoped]`.
     *
     * `EnforcesScope` stamps the scope key on create for scoped models; an
     * unscoped one has none, so an org-owned type created without this would
     * save with `org_id` NULL — a GLOBAL system type, visible to every org.
     * The one place where forgetting a line makes an ORG's schema public.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['org_id'] = app(Context::class)->orgId();

        return $data;
    }

    /** Fields come next, and the type has to exist before they can. */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
