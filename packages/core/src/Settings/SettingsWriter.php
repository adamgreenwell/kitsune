<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Settings;

use Closure;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Models\SiteGroup;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;

/**
 * The audited way to change a setting at one level of ADR-022's hierarchy.
 *
 * Two operations, because ADR-022 has two: an override, and a revert to inherited. A revert REMOVES the key —
 * "absent means inherit", and there is no unset sentinel — so the level below the one reverted resolves the key
 * from the level above again, with its provenance saying so.
 *
 * Validation and invalidation are not here, deliberately. Both happen on the model's own events (`HoldsSettings`),
 * so a write through this class and a plain `$site->update(['settings' => …])` are checked and invalidated by the
 * same code; this class adds the two things a direct model write does not have — the audit row, and a merge that
 * starts from the row as stored.
 *
 * ⚠️ RECORDED HERE, AND ONLY HERE, BECAUSE NOTHING ELSE RECORDS IT. Measured before this was written: an org, site
 * group or site update — through the model or in bulk — writes no audit row, because `AuditedBuilder` is bound to
 * `Entry` alone. So this is the one record per change, and a direct model write is validated and invalidated but
 * NOT audited, like every other column on those three tables. Should their writes ever be audited at the builder,
 * this call becomes the second record of each change — the defect ADR-020's amendment describes for entries — and
 * the test asserting exactly one row fails.
 *
 * ⚠️ THE ACTION AND THE LEVEL, NEVER THE VALUE (ADR-020). `Auditor` has no parameter for one, and the action names
 * neither the key nor the value: a setting may one day be a secret (ADR-036), and a key is caller-supplied text.
 * The row says who changed a setting on which org, site group or site, and when.
 */
final class SettingsWriter
{
    public const SET = 'settings.set';

    public const REVERTED = 'settings.reverted';

    public function __construct(
        private readonly Auditor $auditor,
        private readonly Context $context,
    ) {}

    /** Override a key at this level. */
    public function set(Org|SiteGroup|Site $scope, string $key, mixed $value): void
    {
        $this->write($scope, self::SET, static function (array $settings) use ($key, $value): array {
            $settings[$key] = $value;

            return $settings;
        });
    }

    /** Remove this level's override, so the key is inherited from the level above. */
    public function revert(Org|SiteGroup|Site $scope, string $key): void
    {
        $this->write($scope, self::REVERTED, static function (array $settings) use ($key): array {
            unset($settings[$key]);

            return $settings;
        });
    }

    /**
     * @param  Closure(array<string, mixed>): array<string, mixed>  $change
     */
    private function write(Org|SiteGroup|Site $scope, string $action, Closure $change): void
    {
        $this->refuseOutsideContext($scope);

        $written = $scope->getConnection()->transaction(function () use ($scope, $action, $change): ?array {
            $row = $this->lockedRow($scope);
            $before = self::settingsOf($row);
            $after = self::asStored($change($before));

            /*
             * Nothing changed, so nothing is written and nothing is recorded: the log counts changes, not calls.
             *
             * ⚠️ "CHANGED" AS THE ROW SEES IT, NOT AS PHP DOES. `$before` is the stored JSON decoded, and `===`
             * against the caller's raw value called a repeat a change whenever the value does not survive JSON
             * unchanged — `1.0` is stored as `1`, an object as a map — and whenever a map's keys come back in
             * another order, which MySQL's own ordering does to a map set exactly as before. Measured on SQLite: a
             * `settings.set` row for each such repeat, for `1.0` and an object with no UPDATE issued at all. So
             * `$after` is what the column will hold, and a map is compared without regard to its keys' order —
             * which Eloquent's own dirty check does not do, so the save below cannot be the judge of this.
             */
            if (self::sameValue($after, $before)) {
                return null;
            }

            // An empty map is stored as NULL — "overrides nothing" — rather than as a JSON `[]`.
            $row->setAttribute('settings', $after === [] ? null : $after);

            /*
             * ⚠️ RECORDED FROM THE WRITE'S EFFECT, NOT FROM THE ATTEMPT — `Role::grant()`'s rule, and ADR-020's
             * amendment: the audited set and the written set are the same set. A listener that returns false
             * cancels the save and `save()` says so, which this did not ask: it recorded a change that was never
             * written and set the caller's instance to it.
             */
            if (! $row->save()) {
                throw new RuntimeException(sprintf(
                    'Refusing to change a setting on %s %s: a listener cancelled the save, so nothing was written, '
                    .'and nothing is recorded.',
                    class_basename($row),
                    (string) $row->getKey(),
                ));
            }

            // And a save that a listener emptied of the change wrote nothing to record.
            if (! $row->wasChanged('settings')) {
                return null;
            }

            // Inside the transaction, so a write that cannot be recorded is not kept (ADR-020).
            $this->auditor->recordOrFail($action, $row);

            return ['settings' => $row->getAttribute('settings')];
        });

        if ($written !== null) {
            /*
             * The caller's own instance, so it does not go on describing the row as it was. As with any Eloquent
             * save, an enclosing transaction that later rolls back does not restore it; the resolver, which re-reads
             * the rows, is dropped on that rollback.
             */
            $scope->setAttribute('settings', $written['settings']);
            $scope->syncOriginalAttribute('settings');
        }
    }

    /**
     * A settings map as the column will hold it: through JSON and back, the way the `array` cast stores it.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private static function asStored(array $settings): array
    {
        $stored = json_decode(json_encode($settings, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        return is_array($stored) ? $stored : [];
    }

    /**
     * Whether two stored values are the same value: maps without regard to key order, everything else exactly.
     *
     * ⚠️ NOT `DerivesGuardedColumns::sameGuardedValue()`, which compares scalars as strings so a form's `'255'`
     * proves a model's `255`. Here `1` and `'1'` are different settings, stored differently, and changing one to
     * the other is a change the log records.
     */
    private static function sameValue(mixed $a, mixed $b): bool
    {
        if (! is_array($a) || ! is_array($b)) {
            return $a === $b;
        }

        if (array_is_list($a) !== array_is_list($b) || count($a) !== count($b)) {
            return false;
        }

        if (! array_is_list($a)) {
            ksort($a);
            ksort($b);
        }

        if (array_keys($a) !== array_keys($b)) {
            return false;
        }

        foreach ($a as $key => $value) {
            if (! self::sameValue($value, $b[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * ⚠️ THE ROW, RE-READ UNDER `lockForUpdate()`, NOT THE CALLER'S COPY. `settings` is one JSON column holding every
     * key, so a merge into the caller's instance writes back whatever that instance loaded — and a key another
     * instance set in the meantime is silently removed. Reading inside the transaction makes the merge start from
     * the stored row.
     *
     * Through the model's scoped query, so a site or a site group outside the current org is not found.
     */
    private function lockedRow(Org|SiteGroup|Site $scope): Org|SiteGroup|Site
    {
        $row = $scope->newQuery()->whereKey($scope->getKey())->lockForUpdate()->first();

        if (! $row instanceof Org && ! $row instanceof SiteGroup && ! $row instanceof Site) {
            throw new RuntimeException(sprintf(
                'Refusing to change a setting on %s %s: the row does not exist, or is not visible from the '
                .'current org.',
                class_basename($scope),
                (string) $scope->getKey(),
            ));
        }

        return $row;
    }

    /**
     * ⚠️ THE CONTEXT'S ORG MUST OWN THE LEVEL BEING WRITTEN. The audit row is filed under the context's org, so a
     * write to another org's site would change that org's configuration while the trail landed in somebody else's
     * log — and an `Org` is unscoped, so no query below would notice. With no org context at all the write could
     * not be recorded, and an unauditable write is refused (ADR-020); a console command sets the context first.
     */
    private function refuseOutsideContext(Org|SiteGroup|Site $scope): void
    {
        $current = $this->context->orgId();
        $owner = $scope instanceof Org ? $scope->getKey() : $scope->getAttribute('org_id');

        if ($current !== null && (string) $owner === (string) $current) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to change a setting on %s %s: %s. A settings change is audited under the current org, so '
            .'the org must be the one that owns the level being changed (ADR-020). Set it with '
            .'app(Context::class)->setOrg(...) first.',
            class_basename($scope),
            (string) $scope->getKey(),
            $current === null ? 'there is no organisation context' : 'it belongs to another organisation',
        ));
    }

    /** @return array<string, mixed> */
    private static function settingsOf(Org|SiteGroup|Site $row): array
    {
        $settings = $row->getAttribute('settings');

        return is_array($settings) ? $settings : [];
    }
}
