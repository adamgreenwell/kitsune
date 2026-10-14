<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Catalogue;

use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/** The completed Storefront receipt identifies the adaptable product type, even after an owner renames it. @internal */
final class ProductType
{
    public static function current(bool $current = false): ?EntryType
    {
        $context = app(Context::class);

        if ($context->orgId() === null || $context->siteId() === null) {
            return null;
        }

        $site = Site::query()->useWritePdo()->whereKey($context->siteId())->where('org_id', $context->orgId())
            ->when($current, static fn ($query) => $query->lockForUpdate())->first();
        $receipt = Blueprint::query()->useWritePdo()->where('handle', 'storefront')
            ->when($current, static fn ($query) => $query->lockForUpdate())->first();
        $manifest = $receipt?->manifest;
        $rows = $manifest['entry_types'] ?? null;

        if ($site === null || $receipt?->applied_at === null || ! is_array($manifest)
            || ($manifest['version'] ?? null) !== $receipt->version || ! is_array($rows) || ! array_is_list($rows)) {
            return null;
        }

        $product = null;
        $ids = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['handle'] ?? null)
                || (! is_int($row['id'] ?? null) && ! is_string($row['id'] ?? null))) {
                return null;
            }

            $id = (string) $row['id'];

            if (preg_match('/^[1-9][0-9]*$/D', $id) !== 1 || strlen($id) > strlen((string) PHP_INT_MAX)
                || (strlen($id) === strlen((string) PHP_INT_MAX) && strcmp($id, (string) PHP_INT_MAX) > 0)
                || isset($ids[$id])) {
                return null;
            }

            $ids[$id] = true;

            if ($row['handle'] === 'product') {
                if ($product !== null || ($row['outcome'] ?? null) !== 'created') {
                    return null;
                }

                $product = $id;
            }
        }

        if ($product === null) {
            return null;
        }

        $type = EntryType::query()->useWritePdo()->whereKey($product)->where('org_id', $context->orgId())
            ->where('is_media', false)->when($current, static fn ($query) => $query->lockForUpdate())->first();

        return $type !== null && ($current ? self::currentlyEnabled($type, $site) : EntryTypeAvailability::isEnabledFor($type, $site)) ? $type : null;
    }

    /** The sparse hierarchy's ordinary helper can retain a host's older repeatable-read snapshot. */
    private static function currentlyEnabled(EntryType $type, Site $site): bool
    {
        $rows = EntryTypeAvailability::query()->useWritePdo()->where('entry_type_id', $type->getKey())
            ->where(function ($query) use ($site): void {
                $query->where(fn ($query) => $query->where('scope_type', 'site')->where('scope_id', $site->getKey()))
                    ->orWhere(fn ($query) => $query->where('scope_type', 'site_group')->where('scope_id', $site->site_group_id))
                    ->orWhere(fn ($query) => $query->where('scope_type', 'org')->where('scope_id', $site->org_id));
            })->lockForUpdate()->get()->keyBy(static fn (EntryTypeAvailability $row): string => $row->scope_type.':'.$row->scope_id);

        foreach (['site:'.$site->getKey(), 'site_group:'.$site->site_group_id, 'org:'.$site->org_id] as $key) {
            if ($rows->has($key)) {
                return $rows->get($key)->is_enabled;
            }
        }

        return true;
    }
}
