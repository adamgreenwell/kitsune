<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament;

use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Models\AuditLog;

/**
 * Who each audit row names, in words, for the owner's pages: the credentials page's "by whom" and an entitlement's
 * history — one copy of the recipe and of its words (`kitsune::audit.who.*`; Adam, 2026-10-07).
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 *
 * ⚠️ NAMED ONLY THROUGH THE PANEL'S OWN USER MODEL. Its query is membership-scoped, so a departed member is not found
 * and reads "someone no longer in this organisation"; a row naming any other class reads "an account of another kind",
 * and that class is never built. One query for the people, none when no row names one.
 */
final class AuditActors
{
    /**
     * @param  iterable<AuditLog>  $records  each with `id`, `actor_type` and `actor_id` loaded
     * @return array<int, string> keyed by the audit row's id
     */
    public static function of(iterable $records): array
    {
        $records = collect($records);
        $model = Permissions::userModel();
        $morph = $model !== null ? (new $model)->getMorphClass() : null;

        $ids = $records
            ->filter(static fn (AuditLog $record): bool => $morph !== null && $record->actor_type === $morph && $record->actor_id !== null)
            ->map(static fn (AuditLog $record): string => (string) $record->actor_id)
            ->unique()
            ->values()
            ->all();

        $users = [];

        if ($model !== null && $ids !== []) {
            foreach ($model::query()->whereKey(Permissions::userKeys($ids, $model))->get() as $user) {
                $users[(string) $user->getKey()] = $user;
            }
        }

        $by = [];

        foreach ($records as $record) {
            $by[(int) $record->id] = match (true) {
                $record->actor_type === null => __('kitsune::audit.who.system'),
                $record->actor_type !== $morph => __('kitsune::audit.who.other'),
                isset($users[(string) $record->actor_id]) => self::nameOf($users[(string) $record->actor_id]),
                default => __('kitsune::audit.who.former'),
            };
        }

        return $by;
    }

    private static function nameOf(Model $user): string
    {
        if ($user instanceof HasName) {
            return $user->getFilamentName();
        }

        foreach (['name', 'email'] as $attribute) {
            $value = $user->getAttributeValue($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '#'.$user->getKey();
    }
}
