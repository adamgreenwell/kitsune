<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Settings\Concerns;

use Kitsune\Core\Settings\SettingsGuard;
use Kitsune\Core\Settings\SettingsResolver;

/**
 * An org, site group or site: a level of ADR-022's hierarchy, holding its overrides in a `settings` column.
 *
 * Two jobs, both on model events and both for every path that saves the model, the settings writer included:
 *
 * - **Validation**, on `saving`. `SettingsGuard` refuses a map that is not one and a timezone that is not an IANA
 *   identifier, so the write fails before it reaches the row. The model's `columnsRequiringModelSave()` names
 *   `settings`, which is what makes this the only door: `ScopedBuilder` refuses the bulk and quiet paths that
 *   would skip the event.
 *
 * - **Invalidation**, on `saved` and `deleted` — ADR-022: "cache invalidation must be correct and automatic". A
 *   write that reaches `settings` by `$site->update([...])` invalidates exactly as one through `SettingsWriter`
 *   does, because it is the same event.
 *
 * ⚠️ ANY CHANGE TO THE ROW INVALIDATES, NOT ONLY A CHANGE TO `settings`. Resolution also reads a site's
 * `site_group_id`, which decides which brand it inherits from, and the `name` of the site and its group, which the
 * provenance label quotes. A list of "the columns resolution reads" is one more thing to keep in step with the
 * resolver; the cost of not keeping one is re-reading at most three rows.
 */
trait HoldsSettings
{
    public static function bootHoldsSettings(): void
    {
        static::saving(static function (self $model): void {
            SettingsGuard::check($model->getAttribute('settings'), class_basename($model).' '.($model->getKey() ?? '(new)'));
        });

        static::saved(static function (self $model): void {
            if ($model->wasChanged()) {
                self::forgetResolvedSettings($model);
            }
        });

        static::deleted(static function (self $model): void {
            self::forgetResolvedSettings($model);
        });
    }

    /**
     * ⚠️ ONLY WHEN THIS REQUEST HAS A RESOLVER, so a write that nothing has resolved against does not build one — and
     * does not fail because the configured defaults would.
     */
    private static function forgetResolvedSettings(self $model): void
    {
        if (app()->resolved(SettingsResolver::class)) {
            app(SettingsResolver::class)->forget($model);
        }
    }
}
